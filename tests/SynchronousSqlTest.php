<?php

namespace Current\Tests;

use Current\Prototype\Jobs\PaymentJob;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Events\JobPushed;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class SynchronousSqlTest extends TestCase
{
    private ?string $serverDatabase = null;

    private array $processes = [];

    private array $files = [];

    protected function setUp(): void
    {
        if (! getenv('CURRENT_ASYNC_DB_HOST')) {
            $this->markTestSkipped('Requires the disposable SQL integration server.');
        }
        parent::setUp();
        $connection = ['driver' => getenv('CURRENT_ASYNC_DB_DRIVER') ?: 'mysql', 'host' => getenv('CURRENT_ASYNC_DB_HOST'), 'port' => 3306, 'database' => 'current_async_test', 'username' => 'root', 'password' => getenv('CURRENT_ASYNC_DB_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
        if ($tls = getenv('CURRENT_ASYNC_TLS_DIR')) {
            $connection['options'] = [\PDO::MYSQL_ATTR_SSL_CA => $tls.'/ca.pem', \PDO::MYSQL_ATTR_SSL_CERT => $tls.'/client.pem', \PDO::MYSQL_ATTR_SSL_KEY => $tls.'/client-key.pem', \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true];
        }
        config()->set('database.connections.sync_admin', $connection);
        $this->serverDatabase = 'current_sync_test_'.strtolower((string) Str::ulid());
        app('db')->connection('sync_admin')->statement('CREATE DATABASE `'.$this->serverDatabase.'`');
        app('db')->purge('lens');
        config()->set('database.connections.lens', array_replace($connection, ['database' => $this->serverDatabase]));
        config()->set('current.telemetry.driver', 'sync');
        foreach (glob(dirname(__DIR__).'/database/migrations/*.php') as $migration) {
            (require $migration)->up();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0, SIGKILL);
            }
        }
        if ($this->serverDatabase) {
            app('db')->purge('lens');
            app('db')->purge('sync_peer');
            app('db')->connection('sync_admin')->statement('DROP DATABASE `'.$this->serverDatabase.'`');
            app('db')->purge('sync_admin');
        }
        foreach ($this->files as $file) {
            foreach (glob($file.'*') as $path) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_native_metric_snapshot_queries_replacement_and_pruning_on_sql_server(): void
    {
        dispatch(new PaymentJob('success')); $this->work();
        $now = intdiv(time(), 300) * 300 + 20; $finished = $now - 30;
        $this->store()->table('attempts')->update(['started_at' => $finished - 0.25, 'finished_at' => $finished]);
        $metrics = app(\Current\Native\Metrics::class);
        $this->assertSame(2, $metrics->snapshot($now));
        $this->assertSame(2, $metrics->snapshot($now));
        $this->assertSame(5, $this->store()->table('native_metrics')->count());
        $point = $metrics->series('queues', ['redis', 'lens-demo'], 1, $now)[11];
        $this->assertSame(1, $point['completed']);
        $this->assertEqualsWithDelta(250, $point['duration'], 0.01);
        $this->store()->table('native_metrics')->update(['expires_at' => time() - 1]);
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('current:prune'));
        $this->assertSame(0, $this->store()->table('native_metrics')->count());
        $metrics->reset($finished + 1);
        $this->assertSame(0, $metrics->snapshot($now));
        $this->store()->table('attempts')->update(['finished_at' => $finished + 2]);
        $this->assertSame(2, $metrics->snapshot($now));
        $point = $metrics->series('queues', ['redis', 'lens-demo'], 1, $now)[11];
        $this->assertTrue($point['partial']);
        $this->assertSame(1, $point['completed']);
        $this->assertSame(1, $this->store()->table('jobs')->count());
    }

    public function test_native_forget_prevents_synchronous_history_recreation_on_sql_server(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        $job = new PaymentJob('failure'); $job->tries = 1;
        dispatch($job); $this->work();
        $record = $this->store()->table('jobs')->sole();
        $payload = json_decode(app('queue.failer')->find($record->external_id)->payload, true);
        app(\Current\Native\Maintenance::class)->forget($record->id, false);
        app(\Current\Recorder::class)->enqueued($payload, 'redis', 'lens-demo');
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $this->assertSame(1, $this->store()->table('job_actions')->count());
        $payload['_current_dispatch_id'] = (string) Str::ulid();
        app(\Current\Recorder::class)->enqueued($payload, 'redis', 'lens-demo');
        $this->assertSame(1, $this->store()->table('jobs')->count());
    }

    public function test_native_batch_search_uses_literal_sql_wildcards_under_both_backslash_modes(): void
    {
        config()->set('queue.batching', ['database' => 'lens', 'table' => 'job_batches']);
        $db = $this->store()->connection();
        $db->getSchemaBuilder()->create('job_batches', function ($t) {
            $t->string('id')->primary(); $t->string('name'); $t->integer('total_jobs'); $t->integer('pending_jobs');
            $t->integer('failed_jobs'); $t->longText('failed_job_ids'); $t->text('options')->nullable();
            $t->integer('cancelled_at')->nullable(); $t->integer('created_at'); $t->integer('finished_at')->nullable();
        });
        foreach (['literal' => 'Literal %_! example', 'other' => 'Literal ordinary example'] as $id => $name) {
            $db->table('job_batches')->insert(['id' => $id, 'name' => $name, 'total_jobs' => 0, 'pending_jobs' => 0,
                'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => serialize([]), 'created_at' => time()]);
        }
        foreach ([false, true] as $noBackslash) {
            if ($noBackslash) { $db->statement("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_BACKSLASH_ESCAPES')"); }
            $page = app(\Current\Native\Sections::class)->page('batches', 'jobs', ['query' => '%_!']);
            $this->assertTrue($page['available']);
            $this->assertCount(1, $page['rows']);
            $this->assertSame('Literal %_! example', $page['rows'][0]['cells'][0]);
        }
    }

    public static function firstObserver(): array
    {
        return [['producer'], ['worker']];
    }

    private function waitFor(callable $condition): void
    {
        $until = microtime(true) + 5;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            usleep(5000);
        } while (microtime(true) < $until);
        $this->fail('Concurrent recorder did not reach the expected barrier.');
    }

    #[DataProvider('firstObserver')]
    public function test_concurrent_enqueue_and_worker_start_share_one_history(string $first): void
    {
        $payload = null;
        app('events')->forget(JobPushed::class);
        app('events')->forget(JobQueued::class);
        app('events')->listen(JobPushed::class, function ($event) use (&$payload) {
            $payload = json_encode($event->payload->decoded);
        });
        Queue::push(new PaymentJob('success'));
        $this->assertNotNull($payload);
        $barrier = tempnam(sys_get_temp_dir(), 'current-sync-race-');
        $this->files[] = $barrier;
        $settings = [];
        foreach (['database.connections.app.database', 'database.connections.lens', 'database.redis.options.prefix', 'database.redis.horizon.options.prefix', 'horizon.prefix', 'current.diagnostic_path', 'current.telemetry.driver'] as $key) {
            $settings[$key] = config($key);
        }
        file_put_contents($barrier, json_encode(compact('settings', 'payload', 'barrier', 'first')));
        $second = $first === 'producer' ? 'worker' : 'producer';
        foreach ([$first, $second] as $role) {
            $process = new Process([PHP_BINARY, __DIR__.'/telemetry/concurrent-recorder.php', $barrier, $role], dirname(__DIR__), timeout: 15);
            $this->processes[] = $process;
            $process->start();
            if ($role === $first) {
                $this->waitFor(fn () => is_file($barrier.'.'.$first.'.read'));
            }
        }
        $this->waitFor(fn () => is_file($barrier.'.'.$second.'.started'));
        $id = (int) file_get_contents($barrier.'.'.$second.'.started');
        // Before the fix the second reader reaches the missing-cycle read too.
        // After the fix it waits on the same identity's SQL lock instead.
        $this->waitFor(function () use ($barrier, $second, $id) {
            if (is_file($barrier.'.'.$second.'.read')) {
                return true;
            }
            $row = app('db')->connection('sync_admin')->selectOne('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [$id]);

            return str_contains(strtolower($row->INFO ?? ''), 'get_lock');
        });
        file_put_contents($barrier.'.release', '1');
        foreach ($this->processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        }
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $job = $this->store()->table('jobs')->sole();
        $this->assertSame('completed', $job->status);
        $this->assertSame('observed_enqueue', $job->coverage);
        $this->assertSame('captured', $job->arguments_state);
        $this->assertSame(1, $job->attempt_count);
        $this->assertSame($job->id, $this->store()->table('attempts')->sole()->job_id);
        $this->assertSame(4, $this->store()->table('steps')->where('status', 'completed')->count());
        $this->assertFileDoesNotExist(config('current.diagnostic_path'));
    }
    private function peer(): void
    {
        config()->set('database.connections.sync_peer', config('database.connections.lens'));
        app('db')->purge('sync_peer');
    }

    public function test_rollback_releases_identity_and_other_jobs_do_not_share_the_lock(): void
    {
        $this->peer();
        try {
            $this->store()->cycleTransaction('same-job', 'redis', function () {
                $this->store()->newJob('same-job', 'redis', 'test', []);
                config()->set('current.connection', 'sync_peer');
                try {
                    $value = $this->store()->cycleTransaction('unrelated-job', 'redis', fn () => 'independent');
                    $this->assertSame('independent', $value);
                } finally {
                    config()->set('current.connection', 'lens');
                }
                throw new \RuntimeException('rollback fixture');
            });
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback fixture', $e->getMessage());
        }
        $this->assertSame(0, $this->store()->table('jobs')->count());
        config()->set('current.connection', 'sync_peer');
        try {
            $this->assertSame('released', $this->store()->cycleTransaction('same-job', 'redis', fn () => 'released'));
        } finally {
            config()->set('current.connection', 'lens');
        }
    }

    public function test_lock_timeout_does_not_break_dispatch_or_business_execution(): void
    {
        $this->peer();
        $payload = (fn () => $this->createPayload(new PaymentJob('success'), 'lens-demo'))->call(Queue::connection());
        $uuid = json_decode($payload, true)['uuid'];
        config()->set('current.connection', 'sync_peer');
        try {
            $this->store()->cycleTransaction($uuid, 'redis', function () use ($payload) {
                config()->set('current.connection', 'lens');
                $started = microtime(true);
                Queue::pushRaw($payload, 'lens-demo');
                $this->assertLessThan(3, microtime(true) - $started, 'The observer lock wait must be bounded.');
                $this->assertSame(1, Queue::size('lens-demo'));
                $this->assertFileExists(config('current.diagnostic_path'));
                $this->assertSame(0, $this->store()->table('jobs')->count());
            });
        } finally {
            config()->set('current.connection', 'lens');
        }
        $this->work();
        $this->assertSame(0, Queue::size('lens-demo'));
        $this->assertSame(0, app('db')->connection('app')->table('failed_jobs')->count());
    }

    public function test_raw_retry_reusing_uuid_creates_a_new_cycle(): void
    {
        $payload = (fn () => $this->createPayload(new PaymentJob('success'), 'lens-demo'))->call(Queue::connection());
        Queue::pushRaw($payload, 'lens-demo');
        $this->work();
        $first = $this->store()->table('jobs')->sole();
        Queue::pushRaw($payload, 'lens-demo');
        $this->work();
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->assertSame(2, $this->store()->table('jobs')->where('external_id', $first->external_id)->where('status', 'completed')->count());
        $this->assertSame(2, $this->store()->table('attempts')->distinct()->count('job_id'));
        $this->assertFileDoesNotExist(config('current.diagnostic_path'));
    }

    public function test_application_transaction_is_not_committed_or_rolled_back_by_observer(): void
    {
        $this->store()->connection()->beginTransaction();
        try {
            Queue::push(new PaymentJob('success'));
            $this->assertSame(1, $this->store()->connection()->transactionLevel());
            $this->assertFileExists(config('current.diagnostic_path'));
            $this->assertSame(0, $this->store()->table('jobs')->count());
        } finally {
            $this->store()->connection()->rollBack();
        }
        $this->work();
        $this->assertSame(0, app('db')->connection('app')->table('failed_jobs')->count());
    }

    public function test_automatic_retries_and_releases_preserve_distinct_attempts(): void
    {
        Queue::push(new PaymentJob('retry'));
        $this->work(2);
        Queue::push(new PaymentJob('release'));
        $this->work(2);
        $this->assertSame(2, $this->store()->table('jobs')->where('status', 'completed')->where('attempt_count', 2)->count());
        $this->assertSame(4, $this->store()->table('attempts')->count());
        $this->assertSame(1, $this->store()->table('attempts')->whereNotNull('exception_class')->count());
        $this->assertSame(2, $this->store()->table('attempts')->where('status', 'released')->count());
        $this->assertFileDoesNotExist(config('current.diagnostic_path'));
    }

    public function test_simultaneous_attempt_inserts_for_unrelated_jobs_do_not_deadlock(): void
    {
        for ($i = 0; $i < 20; $i++) {
            Queue::push(new PaymentJob('success'));
        }
        $this->work(20);
        Queue::push(new PaymentJob('success'));
        Queue::push(new PaymentJob('success'));
        $barrier = tempnam(sys_get_temp_dir(), 'current-sync-inserts-');
        $this->files[] = $barrier;
        $settings = [];
        foreach (['database.connections.app.database', 'database.connections.lens', 'database.redis.options.prefix', 'database.redis.horizon.options.prefix', 'horizon.prefix', 'current.diagnostic_path', 'current.telemetry.driver'] as $key) {
            $settings[$key] = config($key);
        }
        file_put_contents($barrier, json_encode(['settings' => $settings, 'barrier' => $barrier, 'first' => 'both', 'mode' => 'attempt-inserts']));
        foreach (['worker1', 'worker2'] as $role) {
            $process = new Process([PHP_BINARY, __DIR__.'/telemetry/concurrent-recorder.php', $barrier, $role], dirname(__DIR__), timeout: 15);
            $this->processes[] = $process;
            $process->start();
        }
        $this->waitFor(fn () => is_file($barrier.'.worker1.read') && is_file($barrier.'.worker2.read'));
        file_put_contents($barrier.'.release', '1');
        foreach ($this->processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        }
        $this->assertSame(22, $this->store()->table('attempts')->count());
        $this->assertSame(22, $this->store()->table('jobs')->where('status', 'completed')->count());
        $this->assertSame(88, $this->store()->table('steps')->where('status', 'completed')->count());
        $this->assertFileDoesNotExist(config('current.diagnostic_path'));
    }

}
