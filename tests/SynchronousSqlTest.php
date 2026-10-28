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

    public function test_lightweight_launcher_transfers_sql_reader_and_publishes_runtime(): void
    {
        $binary = dirname(__DIR__).'/supervisor/current-supervisor';
        if (! is_executable($binary)) { $this->markTestSkipped('Requires the built Linux supervisor.'); }
        dispatch(new PaymentJob('success')); $this->work();
        $end = microtime(true) - 1;
        $this->store()->table('attempts')->update(['started_at' => $end - 2, 'finished_at' => $end]);
        $directory = sys_get_temp_dir().'/current-sql-controller-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
        $file = tempnam($directory, 'config-');
        // Use a new native queue connection; the existing worker connection belongs to Horizon.
        config()->set('queue.connections.native_test', array_replace(config('queue.connections.redis'), ['driver' => 'current-redis']));
        config()->set('current.execution_backend', 'native');
        config()->set('current.native', ['binary' => $binary, 'state_directory' => $directory, 'instance' => 'test', 'grace_seconds' => 10,
            'pools' => ['test' => ['connection' => 'native_test', 'queues' => ['lens-demo'], 'workers' => 1, 'timeout' => 2]]]);
        $sampler = null;
        try {
            $request = (new \Current\Native\LightweightLauncher)->configuration(['state_directory' => $directory, 'memory_limit_mb' => 256, 'grace_seconds' => 10], $file);
            $this->assertIsArray($request);
            $this->assertSame($this->store()->connection()->getConfig(), $request['reader']);
            $probe = new Process([$binary, '-runtime-sample', PHP_BINARY, dirname(__DIR__).'/src/Native/controller.php']);
            $probe->setInput(\Current\Native\LightweightLauncher::encode($request))->setTimeout(6)->run();
            $this->assertSame(0, $probe->getExitCode(), $probe->getErrorOutput());
            $this->assertSame("ready\n", $probe->getOutput());
            $sampler = new \Current\Native\RuntimeSampler($request['reader']);
            $snapshot = app(\Current\Native\RuntimeSnapshot::class);
            $redis = \Current\Native\ObservationRedis::connect('default');
            $key = $snapshot->key('redis', 'lens-demo');
            $deadline = microtime(true) + 7;
            do { $sampler->tick([['connection' => 'redis', 'queues' => ['lens-demo']]]); if ($redis->get($key)) { break; } usleep(20000); } while (microtime(true) < $deadline);
            $this->assertIsString($redis->get($key));
            $this->assertEqualsWithDelta(6, (new \Current\Native\RuntimeEstimate)->workSeconds('redis', ['lens-demo' => 3]), .01);
        } finally {
            $sampler?->stop(); unlink($file); rmdir($directory);
        }
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

    public function test_native_dashboard_and_metric_lists_preserve_counts_runtime_and_reset_on_sql_server(): void
    {
        config()->set('current.execution_backend', 'native');
        $now = (float) time();
        $sample = function (string $queue, ?string $status, float $finished, float $duration = .25, bool $expired = false) use ($now): void {
            $id = $this->store()->newJob((string) Str::uuid(), 'redis', $queue, ['data' => ['commandName' => 'DashboardSqlFixture']]);
            $this->store()->table('jobs')->where('id', $id)->update(['first_observed_at' => $finished, 'observed_at' => $finished,
                'status' => $status ?? 'pending', 'expires_at' => $expired ? $now - 1 : $now + 86400]);
            if ($status !== null) {
                $this->store()->table('attempts')->insert(['id' => (string) Str::ulid(), 'job_id' => $id, 'reported_attempt' => 1,
                    'worker_id' => 'server-dashboard-fixture', 'status' => $status, 'started_at' => $finished - $duration, 'finished_at' => $finished]);
            }
        };
        $sample('slow', 'completed', $now - 40, 5);
        $sample('busy', 'completed', $now - 20, .5);
        $sample('busy', 'completed', $now - 10, -.5);
        $sample('invalid', 'completed', $now - 5, -.1);
        $sample('failed', 'failed', $now - 2);
        $sample('pending', null, $now - 1);
        $sample('expired', 'completed', $now - 1, 20, true);
        $sample('old', 'completed', $now - 4001, 100);
        $local = ['available' => false];
        $registry = ['available' => true, 'invalid' => 0, 'instances' => []];
        $workload = ['available' => true, 'partial' => false, 'rows' => []];
        $dashboard = app(\Current\Native\Dashboard::class);
        $before = $dashboard->snapshot($local, $registry, $workload, $now);
        $this->assertTrue($before['counts']['available']);
        $this->assertSame(['recent' => 6, 'failed' => 1], $before['counts']['data']);
        $this->assertTrue($before['metrics']['available']);
        $this->assertSame(4.0, $before['metrics']['data']['rate']);
        $this->assertSame('slow', $before['metrics']['data']['max_runtime']['queue']);
        $this->assertEqualsWithDelta(5000, $before['metrics']['data']['max_runtime']['runtime_ms'], .01);
        $this->assertSame(['connection' => 'redis', 'queue' => 'busy', 'completed' => 2], $before['metrics']['data']['max_throughput']);
        $page = app(\Current\Native\Sections::class)->page('metrics', 'queues');
        $this->assertTrue($page['available']);
        $rows = collect($page['rows'])->keyBy(fn ($row) => $row['cells'][0]);
        $this->assertSame(2, $rows['redis / busy']['cells'][1]);
        $this->assertSame('500.0 ms', $rows['redis / busy']['cells'][2]);
        $this->assertSame(1, $rows['redis / invalid']['cells'][1]);
        $this->assertSame('—', $rows['redis / invalid']['cells'][2]);
        app(\Current\Native\Metrics::class)->reset($now - 30);
        $after = $dashboard->snapshot($local, $registry, $workload, $now);
        $this->assertTrue($after['metrics']['available']);
        $this->assertSame($before['counts'], $after['counts']);
        $this->assertSame(6.0, $after['metrics']['data']['rate']);
        $this->assertSame('busy', $after['metrics']['data']['max_runtime']['queue']);
        $this->assertSame(2, $after['metrics']['data']['max_throughput']['completed']);
        $resetPage = app(\Current\Native\Sections::class)->page('metrics', 'queues');
        $this->assertTrue($resetPage['available']);
        $this->assertCount(2, $resetPage['rows']);
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

    public function test_native_full_batch_retry_after_history_pruning_has_durable_server_identity_claims(): void
    {
        $this->nativeRetryBatchStorage();
        $batch = \Illuminate\Support\Facades\Bus::batch([new SqlRetryBatchFixture('one'), new SqlRetryBatchFixture('two')])
            ->name('Server SQL missing-history retry')->allowFailures()->onConnection('redis')->onQueue('lens-demo')->dispatch();
        $this->work(2);
        $originals = $this->store()->table('jobs')->get();
        $this->assertCount(2, $originals);
        $this->store()->table('jobs')->update(['expires_at' => time() - 1]);
        $this->assertSame(2, $this->store()->prune());
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $url = '/current/batches/'.$batch->id;
        $pass = $this->get($url)->assertOk()->viewData('retryPass');
        $cutoff = app(\Current\Native\BatchRetryPass::class)->cutoff($pass, $batch->id);
        $candidates = $originals->map(fn ($job) => app(\Current\Native\Retry::class)->batchJob($batch->id, $job->external_id, $cutoff));
        $this->assertSame([null, null], $candidates->pluck('id')->all());
        $this->post($url.'/retry', ['scope' => 'batch', 'retry_pass' => $pass])->assertRedirect($url);
        $this->assertSame(2, $this->store()->table('native_retry_claims')->count());
        $this->assertSame(2, $this->store()->table('jobs')->count());
        foreach ($candidates as $candidate) {
            $this->assertSame('submitted', app(\Current\RetrySubmission::class)->submit($candidate)['state']);
        }
        $this->post($url.'/retry', ['scope' => 'batch', 'retry_pass' => $pass])->assertRedirect($url);
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->assertSame(2, Queue::size('lens-demo'));
        $this->work(2);
        $this->assertSame([], $batch->fresh()->failedJobIds);
        $this->assertSame(2, $this->store()->table('jobs')->where('status', 'completed')->count());
        $effects = \Illuminate\Support\Facades\Redis::hgetall('sql-batch-effects'); ksort($effects);
        $this->assertSame(['one' => '1', 'two' => '1'], $effects);
    }

    public function test_native_unknown_retry_claim_blocks_competing_retry_and_forget_and_survives_history_expiry(): void
    {
        $this->nativeRetryBatchStorage();
        $job = new PaymentJob('failure'); $job->tries = 1;
        dispatch($job); $this->work();
        $original = $this->store()->table('jobs')->sole();
        $competingClaimChecked = false;
        app('events')->listen(\Illuminate\Queue\Events\JobRetryRequested::class, function () use ($original, &$competingClaimChecked) {
            $this->assertSame('requested', app(\Current\RetrySubmission::class)->submit($original)['state']);
            try {
                app(\Current\Native\Maintenance::class)->forget($original->id, true);
                $this->fail('A server-SQL claim with an unknown outcome cannot be forgotten.');
            } catch (\Current\Native\MaintenanceException $exception) {
                $this->assertStringContainsString('unconfirmed', $exception->getMessage());
            }
            $competingClaimChecked = true;
        });
        $lostAck = false;
        app('db')->listen(function ($event) use (&$lostAck) {
            if (! $lostAck && $event->connectionName === 'app' && str_starts_with(strtolower(ltrim($event->sql)), 'delete from')
                && str_contains($event->sql, 'failed_jobs')) {
                $lostAck = true;
                throw new \RuntimeException('Synthetic lost acknowledgement after failed-payload deletion.');
            }
        });
        try {
            app(\Current\RetrySubmission::class)->submit($original);
            $this->fail('The committed deletion has an intentionally lost acknowledgement.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('lost acknowledgement', $exception->getMessage());
        }
        $this->assertTrue($competingClaimChecked);
        $this->assertTrue($lostAck);
        $this->assertNull(app('queue.failer')->find($original->external_id));
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->assertSame('requested', json_decode($this->store()->table('native_retry_claims')->sole()->value, true)['state']);
        $this->assertSame('requested', app(\Current\RetrySubmission::class)->submit($original)['state']);
        $this->store()->table('jobs')->where('id', $original->id)->update(['expires_at' => time() - 1]);
        $this->assertSame(1, $this->store()->prune());
        $missingHistory = clone $original; $missingHistory->id = null;
        $this->assertSame('requested', app(\Current\RetrySubmission::class)->submit($missingHistory)['state']);
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->assertSame(1, Queue::size('lens-demo'));
        $this->assertSame(0, $this->store()->table('job_actions')->count());
    }

    public function test_native_retry_claim_pruning_crosses_the_batch_bound_and_preserves_live_server_receipts(): void
    {
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['identity_hash' => hash('sha256', 'expired-'.$i), 'value' => '{"state":"requested"}', 'expires_at' => time() - 1];
        }
        $live = hash('sha256', 'live');
        $rows[] = ['identity_hash' => $live, 'value' => '{"state":"submitted"}', 'expires_at' => time() + 86400];
        $this->store()->table('native_retry_claims')->insert($rows);
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('current:prune'));
        $claim = $this->store()->table('native_retry_claims')->sole();
        $this->assertSame($live, $claim->identity_hash);
        $this->assertSame('submitted', json_decode($claim->value, true)['state']);
    }

    private function nativeRetryBatchStorage(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('queue.batching', ['database' => 'app', 'table' => 'job_batches']);
        \Illuminate\Support\Facades\Schema::connection('app')->create('job_batches', function ($table) {
            $table->string('id')->primary(); $table->string('name'); $table->integer('total_jobs'); $table->integer('pending_jobs');
            $table->integer('failed_jobs'); $table->longText('failed_job_ids'); $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable(); $table->integer('created_at'); $table->integer('finished_at')->nullable();
        });
        \Illuminate\Support\Facades\Gate::define('viewCurrent', fn ($user) => true);
        \Illuminate\Support\Facades\Gate::define('operateCurrent', fn ($user) => true);
        $this->withSession(['_token' => 'sql-retry-csrf'])->withHeader('X-CSRF-TOKEN', 'sql-retry-csrf');
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

final class SqlRetryBatchFixture implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable, \Illuminate\Bus\Batchable;
    public int $tries = 1;
    public function __construct(public string $label) {}
    public function handle(): void
    {
        if (\Illuminate\Support\Facades\Redis::setnx('sql-batch-first:'.$this->label, 1)) { throw new \RuntimeException('Synthetic initial batch failure'); }
        \Illuminate\Support\Facades\Redis::hincrby('sql-batch-effects', $this->label, 1);
    }
}
