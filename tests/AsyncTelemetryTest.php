<?php

namespace Current\Tests;

use Current\Current;
use Current\Prototype\Jobs\PaymentJob;
use Current\Telemetry\Health;
use Current\Telemetry\Transport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Jobs\RetryFailedJob;
use Symfony\Component\Process\Process;

final class AsyncTelemetryTest extends TestCase
{
    private array $processes = [];

    private array $files = [];

    private ?string $serverDatabase = null;

    protected function setUp(): void
    {
        if (! is_executable(dirname(__DIR__).'/collector/current-collector')) {
            if (getenv('CURRENT_REQUIRE_COLLECTOR_TESTS') === '1') {
                $this->fail('The compatibility collector binary is missing or not executable.');
            }
            $this->markTestSkipped('Build the optional Go collector to run its integration tests.');
        }
        parent::setUp();
        if ($host = getenv('CURRENT_ASYNC_DB_HOST')) {
            $driver = getenv('CURRENT_ASYNC_DB_DRIVER') ?: 'mysql';
            $connection = ['driver' => $driver, 'host' => $host, 'port' => 3306, 'database' => 'current_async_test', 'username' => 'root', 'password' => getenv('CURRENT_ASYNC_DB_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
            if ($tls = getenv('CURRENT_ASYNC_TLS_DIR')) {
                $connection['options'] = [\PDO::MYSQL_ATTR_SSL_CA => $tls.'/ca.pem', \PDO::MYSQL_ATTR_SSL_CERT => $tls.'/client.pem', \PDO::MYSQL_ATTR_SSL_KEY => $tls.'/client-key.pem', \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true];
            }
            config()->set('database.connections.async_admin', $connection);
            $this->serverDatabase = 'current_async_test_'.strtolower((string) Str::ulid());
            app('db')->connection('async_admin')->statement('CREATE DATABASE `'.$this->serverDatabase.'`');
            app('db')->purge('lens');
            config()->set('database.connections.lens', array_replace($connection, ['database' => $this->serverDatabase]));
            foreach (glob(dirname(__DIR__).'/database/migrations/*.php') as $migration) {
                if (str_contains($migration, '000012')) {
                    continue;
                }
                (require $migration)->up();
            }
        } else {
            $source = config('database.connections.lens.database');
            $this->store()->connection()->statement('PRAGMA wal_checkpoint(TRUNCATE)');
            app('db')->purge('lens');
            $local = sys_get_temp_dir().'/current-async-test-'.bin2hex(random_bytes(8)).'.sqlite';
            copy($source, $local);
            config()->set('database.connections.lens.database', $local);
            $this->files = array_merge($this->files, [$local, $local.'-wal', $local.'-shm']);
        }
        (require dirname(__DIR__).'/database/migrations/2026_10_27_000012_add_async_telemetry_receipts.php')->up();
        config()->set('current.telemetry.driver', 'redis');
        config()->set('current.telemetry.binary', dirname(__DIR__).'/collector/current-collector');
    }

    protected function tearDown(): void
    {
        if (! $this->app) {
            parent::tearDown();

            return;
        }
        foreach ($this->processes as $p) {
            if ($p->isRunning()) {
                $p->stop(2);
            }
        }
        $transport = app(Transport::class);
        try {
            $s = $transport->settings();
            $r = $transport->client();
            // keys() is only used against this test's unique prefix.
            $keys = $r->keys('*');
            foreach ($keys as $key) {
                $r->del(substr($key, strlen($s['prefix'])));
            }
        } catch (\Throwable) {
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        if ($this->serverDatabase) {
            app('db')->purge('lens');
            app('db')->connection('async_admin')->statement('DROP DATABASE `'.$this->serverDatabase.'`');
            app('db')->purge('async_admin');
        } else {
            @unlink(config('database.connections.lens.database').'.collector.lock');
        }
        parent::tearDown();
    }

    private function collector(bool $once = true, array $overrides = []): Process
    {
        $binary = config('current.telemetry.binary');
        $this->assertTrue(is_executable($binary), 'Build the opt-in collector before running these integration tests.');
        $file = tempnam(sys_get_temp_dir(), 'current-test-collector-');
        chmod($file, 0600);
        $this->files[] = $file;
        file_put_contents($file, json_encode(array_replace(app(Transport::class)->settings() + ['once' => $once], $overrides)));
        $p = new Process([$binary, $file]);
        $p->setTimeout($once ? 15 : null);
        $p->start();
        $this->processes[] = $p;

        return $p;
    }

    private function drain(): void
    {
        $p = $this->collector();
        $p->wait();
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
    }

    public function test_collector_requires_alias_migration_without_consuming_pending_events(): void
    {
        $migration = require dirname(__DIR__).'/database/migrations/2026_10_28_000015_add_async_identity_aliases.php';
        $migration->down();
        app(\Current\Recorder::class)->enqueued(['uuid' => (string) Str::uuid(), 'data' => ['commandName' => PaymentJob::class]], 'redis', 'lens-demo');
        $redis = app(Transport::class)->client();
        $pending = $redis->xLen('events:v2');
        $this->assertGreaterThan(0, $pending);
        $collector = $this->collector();
        $collector->wait();
        $this->assertFalse($collector->isSuccessful());
        $this->assertStringContainsString('migrations', $collector->getErrorOutput());
        $this->assertSame($pending, $redis->xLen('events:v2'));
        $migration->up();
        $this->drain();
        $this->assertSame(1, $this->store()->table('jobs')->count());
    }

    public function test_provisional_identity_reconciles_retained_history_and_tombstones_late_events(): void
    {
        $payload = ['uuid' => (string) Str::uuid(), '_current_dispatch_id' => (string) Str::ulid(), 'data' => ['commandName' => PaymentJob::class]];
        app(\Current\Recorder::class)->enqueued($payload, 'redis', 'lens-demo');
        $this->drain();
        $original = $this->store()->table('jobs')->sole();
        $candidate = (string) Str::ulid();
        $row = array_replace((array) $original, ['id' => $candidate, 'first_observed_at' => microtime(true), 'expires_at' => $original->expires_at + 1000]);
        $event = ['version' => 2, 'id' => (string) Str::ulid(), 'observed_at' => microtime(true), 'job' => $row, 'enqueue' => $row];
        $redis = app(Transport::class)->client();
        $redis->xAdd('events:v2', '*', ['data' => json_encode($event)]);
        $this->drain();
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $alias = $this->store()->table('async_aliases')->where('id', $candidate)->sole();
        $this->assertSame($original->id, $alias->canonical_id);
        $this->assertEqualsWithDelta($original->expires_at, $alias->job_expires_at, 0.001);
        $this->assertGreaterThan($row['expires_at'], $alias->expires_at);
        $this->assertEqualsWithDelta($original->expires_at, $this->store()->table('jobs')->sole()->expires_at, 0.001);

        // Conflicting and expired envelopes must not poison unrelated valid work.
        $bad = $event;
        $bad['id'] = (string) Str::ulid();
        $bad['job']['external_id'] = $bad['enqueue']['external_id'] = 'different';
        $redis->xAdd('events:v2', '*', ['data' => json_encode($bad)]);
        $this->store()->table('async_aliases')->where('id', $candidate)->update(['job_expires_at' => microtime(true) - 1]);
        $event['id'] = (string) Str::ulid();
        $redis->xAdd('events:v2', '*', ['data' => json_encode($event)]);
        app(\Current\Recorder::class)->enqueued(array_replace($payload, ['uuid' => (string) Str::uuid()]), 'redis', 'lens-demo');
        $this->drain();
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->assertSame(0, $redis->xLen('events:v2'));
        $this->assertSame('1', $redis->hGet('health', 'invalid'));
        $this->assertSame('1', $redis->hGet('health', 'expired'));
        $this->assertSame(3, $this->store()->table('async_aliases')->count());
        $this->store()->table('async_aliases')->update(['expires_at' => microtime(true) - 1]);
        $this->assertSame(0, Artisan::call('current:prune'));
        $this->assertSame(0, $this->store()->table('async_aliases')->count());
    }

    public function test_async_webhook_and_late_broadcast_links_survive_identity_cache_eviction(): void
    {
        config()->set('current.webhooks.enabled', true);
        config()->set('current.broadcasts', ['visible' => true, 'enabled' => true, 'events' => ['OrderUpdated'], 'channels' => ['private-orders.*']]);
        \Illuminate\Support\Facades\Route::post('/__async-links', function () {
            dispatch(new \Current\Prototype\Jobs\OutgoingWebhookJob('retry'));
            return response('accepted', 202);
        })->middleware('current.webhook:async-provider');
        $this->post('/__async-links')->assertStatus(202);
        $receipt = $this->store()->table('webhook_receipts')->sole();
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $this->assertSame(1, $this->store()->table('webhook_jobs')->count());
        $transport = app(Transport::class);
        foreach ($transport->client()->keys('cycle:v2:*') as $key) {
            $transport->client()->del(substr($key, strlen($transport->settings()['prefix'])));
        }
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'lens-outgoing-demo', '--once' => true, '--sleep' => 0, '--tries' => 1]));
        $delivery = $this->store()->table('webhook_deliveries')->first();
        $this->drain();
        $job = $this->store()->table('jobs')->sole();
        $this->assertNotSame($job->id, $delivery->job_id);
        $this->assertSame('completed', $job->status);
        $this->get('/current/webhooks/outgoing')->assertOk()->assertSee(route('current.show', ['id' => $job->id]), false)->assertDontSee(route('current.show', ['id' => $delivery->job_id]), false);
        $this->get('/current/webhooks/'.$receipt->id)->assertOk()->assertSee(route('current.show', ['id' => $job->id]), false);
        $this->get('/current/webhooks/outgoing/'.$delivery->id)->assertOk()->assertSee(route('current.show', ['id' => $job->id]), false);
        $this->get('/current/jobs/'.$job->id)->assertOk()->assertSee($delivery->id)->assertSee($receipt->id)->assertDontSee(route('current.show', ['id' => $delivery->job_id]), false);

        // A related record arrives AFTER the last telemetry event. No repair event follows.
        $context = app(\Current\Broadcasts\BroadcastRecorder::class)->start('reverb', ['private-orders.42'], 'OrderUpdated');
        \Current\Broadcasts\BroadcastRecorder::finish($context);
        $broadcast = $this->store()->table('broadcasts')->sole();
        $this->store()->table('broadcasts')->where('id', $broadcast->id)->update(['job_id' => $delivery->job_id, 'attempt_id' => $delivery->attempt_id]);
        $this->get('/current/broadcasts/'.$broadcast->id)->assertOk()->assertSee(route('current.show', ['id' => $job->id]), false)->assertSee($receipt->id);
        $this->get('/current/jobs/'.$job->id)->assertOk()->assertSee($broadcast->id);
        $this->store()->table('attempts')->where('job_id', $job->id)->update(['status' => 'failed']);
        $this->get('/current/webhooks?outcome=job_failed')->assertOk()->assertSee($receipt->id);
        $this->store()->table('jobs')->where('id', $job->id)->update(['expires_at' => microtime(true) - 1]);
        $this->get('/current/broadcasts/'.$broadcast->id)->assertOk()->assertDontSee(route('current.show', ['id' => $job->id]), false);
    }

    public function test_old_collector_is_rejected_and_legacy_cache_cannot_supply_a_v2_identity(): void
    {
        $transport = app(Transport::class);
        $redis = $transport->client();
        $uuid = (string) Str::uuid();
        $legacy = ['id' => (string) Str::ulid(), 'first_observed_at' => microtime(true), 'expires_at' => microtime(true) + 1000];
        $redis->set('cycle:'.hash('sha256', 'redis|'.$uuid), json_encode($legacy));
        $this->assertNotSame($legacy['id'], $transport->cycle($uuid, 'redis')['id']);
        app(\Current\Recorder::class)->enqueued(['uuid' => $uuid, 'data' => ['commandName' => PaymentJob::class]], 'redis', 'lens-demo');
        $binary = tempnam(sys_get_temp_dir(), 'old-collector-');
        $this->files[] = $binary;
        file_put_contents($binary, "#!/bin/sh\nprintf '%s\\n' '{\"name\":\"current-collector\",\"protocol\":1}'\n");
        chmod($binary, 0700);
        config()->set('current.telemetry.binary', $binary);
        $this->assertSame(1, Artisan::call('current:collect', ['--once' => true]));
        $this->assertStringContainsString('Upgrade the collector', Artisan::output());
        $this->assertGreaterThan(0, $redis->xLen('events:v2'));
        $this->assertSame(0, $redis->xLen('events'));
        $redis->set('owner', 'old-test-owner');
        $redis->hMSet('health', ['heartbeat_at' => microtime(true), 'protocol' => 2, 'owner_token' => 'previous-owner']);
        $this->assertFalse(app(\Current\Telemetry\Health::class)->snapshot()['running']);
    }

    public function test_upgrade_drains_entire_legacy_backlog_before_provisional_events(): void
    {
        app(\Current\Recorder::class)->enqueued(['uuid' => (string) Str::uuid(), 'data' => ['commandName' => PaymentJob::class]], 'redis', 'lens-demo');
        $redis = app(Transport::class)->client();
        $event = json_decode(array_values($redis->xRange('events:v2', '-', '+'))[0]['data'], true);
        $legacy = $event;
        $legacy['version'] = 1;
        $legacyId = (string) Str::ulid();
        $legacy['job']['id'] = $legacy['enqueue']['id'] = $legacyId;
        for ($i = 0; $i < 101; $i++) {
            $legacy['id'] = (string) Str::ulid();
            $redis->xAdd('events', '*', ['data' => json_encode($legacy)]);
        }
        $this->assertSame(102, app(\Current\Telemetry\Health::class)->snapshot()['pending']);
        $this->drain();
        $this->assertSame($legacyId, $this->store()->table('jobs')->sole()->id);
        $this->assertSame($legacyId, $this->store()->table('async_aliases')->where('id', $event['job']['id'])->value('canonical_id'));
        $this->assertSame(0, $redis->xLen('events'));
        $this->assertSame(0, $redis->xLen('events:v2'));
    }

    public function test_native_maintenance_preserves_clear_evidence_and_blocks_concurrent_forgotten_replay(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        \Illuminate\Support\Facades\Gate::define('viewCurrent', fn () => true);
        dispatch(new PaymentJob('success'));
        dispatch((new PaymentJob('success'))->delay(3600));
        $operation = (string) Str::ulid();
        $record = app(\Current\Native\ClearQueue::class)->run('redis', 'lens-demo', $operation, false);
        $this->assertSame(2, $record['removed']);
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $this->drain();
        $this->assertSame(2, $this->store()->jobsWithObservationState()->whereRaw($this->store()->outcomeSql()." = 'cleared'")->count());
        $failed = new PaymentJob('failure'); $failed->tries = 1;
        dispatch($failed); $this->work();
        $redis = app(Transport::class)->client();
        $events = $redis->xRange('events:v2', '-', '+');
        $this->drain();
        $job = $this->store()->table('jobs')->where('status', 'failed')->sole();
        $source = array_values($events)[0];
        $collector = $this->collector(false);
        $wait = function ($condition) use ($collector) {
            $deadline = microtime(true) + 15;
            do { if ($condition()) { return; } $this->assertTrue($collector->isRunning(), $collector->getErrorOutput()); usleep(20000); } while (microtime(true) < $deadline);
            $this->fail('Timed out waiting for live maintenance collection.');
        };
        $wait(fn () => (app(Health::class)->snapshot()['running'] ?? false));
        $provisional = (string) Str::ulid();
        $raw = json_decode($source['data'], true);
        $raw['job']['id'] = $provisional;
        if (isset($raw['enqueue'])) { $raw['enqueue']['id'] = $provisional; }
        if (isset($raw['attempt'])) { $raw['attempt']['job_id'] = $provisional; }
        // Force old snapshots to contend with the history removal transaction.
        for ($i = 0; $i < 100; $i++) {
            $raw['id'] = (string) Str::ulid();
            $redis->xAdd('events:v2', '*', ['data' => json_encode($raw)]);
        }
        app(\Current\Native\Maintenance::class)->forget($job->id, false);
        foreach ($events as $event) {
            $snapshot = json_decode($event['data'], true); $snapshot['id'] = (string) Str::ulid();
            $snapshot['job']['id'] = $provisional;
            if (isset($snapshot['enqueue'])) { $snapshot['enqueue']['id'] = $provisional; }
            if (isset($snapshot['attempt'])) { $snapshot['attempt']['job_id'] = $provisional; }
            $redis->xAdd('events:v2', '*', ['data' => json_encode($snapshot)]);
        }
        $wait(fn () => $redis->xLen('events:v2') === 0);
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->assertSame(0, $this->store()->table('attempts')->count());
        $this->assertSame(0, $this->store()->table('steps')->count());
        $this->assertNull(app('queue.failer')->find($job->external_id));
        $this->assertSame(0, app(Health::class)->snapshot()['dropped']);
        $collector->signal(SIGTERM); $collector->wait();
        $this->assertTrue($collector->isSuccessful(), $collector->getErrorOutput());
    }

    public function test_native_queued_event_without_explicit_queue_retains_context(): void
    {
        $job = new PaymentJob('success');
        $payload = null;
        app('events')->listen(\Illuminate\Queue\Events\JobQueued::class, function ($event) use (&$payload) { $payload = $event->payload; });
        Queue::push($job);
        app(\Current\Recorder::class)->queued(new \Illuminate\Queue\Events\JobQueued(
            'redis', null, null, $job, $payload, null,
        ));
        $this->drain();
        $record = $this->store()->table('jobs')->sole();
        $this->assertSame('lens-demo', $record->queue);
        $this->assertSame('observed_enqueue', $record->coverage);
        $this->assertSame('captured', $record->arguments_state);
        $this->assertSame('captured', $record->metadata_state);
        $this->assertFalse(is_file(config('current.diagnostic_path')));
    }

    public function test_dispatch_generations_keep_late_context_and_cache_misses_in_their_own_history(): void
    {
        config()->set('current.job_context', fn ($job) => ['arguments' => ['order' => $job->order]]);
        $uuid = (string) Str::uuid();
        $original = ['uuid' => $uuid, '_current_dispatch_id' => (string) Str::ulid(), 'data' => ['commandName' => PaymentJob::class]];
        $retry = array_replace($original, ['_current_dispatch_id' => (string) Str::ulid()]);
        $recorder = app(\Current\Recorder::class);
        $recorder->enqueued($original, 'redis', 'lens-demo');
        $recorder->enqueued($retry, 'redis', 'lens-demo');
        // Original context arrives after the retry, with a duplicate enqueue too.
        $recorder->queued(new \Illuminate\Queue\Events\JobQueued('redis', 'lens-demo', null,
            new PaymentJob('success', 'original-only'), json_encode($original), null));
        $recorder->enqueued($original, 'redis', 'lens-demo');
        $this->drain();
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $first = $this->store()->table('jobs')->where('dispatch_id', $original['_current_dispatch_id'])->sole();
        $second = $this->store()->table('jobs')->where('dispatch_id', $retry['_current_dispatch_id'])->sole();
        $this->assertSame($uuid, $first->external_id);
        $this->assertSame($uuid, $second->external_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertStringContainsString('original-only', $first->arguments_json);
        $this->assertNull($second->arguments_json);

        $transport = app(Transport::class);
        $clearIdentities = function () use ($transport) {
            $redis = $transport->client();
            foreach ($redis->keys('cycle:*') as $key) {
                $redis->del(substr($key, strlen($transport->settings()['prefix'])));
            }
        };
        $clearIdentities();
        $this->assertNotSame($first->id, $transport->cycle($uuid, 'redis', $original['_current_dispatch_id'])['id']);
        $this->assertNotSame($second->id, $transport->cycle($uuid, 'redis', $retry['_current_dispatch_id'])['id']);
        $this->store()->table('jobs')->where('id', $first->id)->update(['expires_at' => microtime(true) - 1]);
        $clearIdentities();
        $recorder->enqueued($original, 'redis', 'lens-demo');
        $this->drain();
        $this->assertSame(3, $this->store()->table('jobs')->count());
        $this->assertNotSame($first->id, $this->store()->findCycle($uuid, 'redis', $original['_current_dispatch_id'])->id);
        $this->assertSame($second->id, $this->store()->findCycle($uuid, 'redis', $retry['_current_dispatch_id'])->id);
    }

    public function test_real_job_history_is_deferred_and_replay_does_not_duplicate_or_regress_it(): void
    {
        dispatch(new PaymentJob('retry', 'café-🚀'));
        $this->work(2);
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $r = app(Transport::class)->client();
        $events = $r->xRange('events:v2', '-', '+');
        $this->assertStringNotContainsString('demo-secret', json_encode($events));
        $this->assertFalse(app(Health::class)->snapshot()['running']);
        $this->get('/current/monitoring')->assertOk()->assertSee('Telemetry collector is not running.');
        $this->drain();
        $job = $this->store()->table('jobs')->sole();
        $this->assertSame('completed', $job->status);
        $this->assertSame(2, $job->attempt_count);
        $this->assertSame('captured', $job->arguments_state);
        $this->assertContains('order:café-🚀', $this->store()->table('tags')->pluck('tag')->all());
        $this->assertSame(['released', 'completed'], $this->store()->table('attempts')->orderBy('started_at')->pluck('status')->all());
        $this->get('/current/jobs/'.$job->id)->assertOk()->assertSee('Execution map')->assertSee('Metadata');
        $before = json_encode($this->store()->table('steps')->orderBy('id')->get());
        foreach (array_reverse($events) as $event) {
            // New envelope identities force revision checks as well as receipt deduplication.
            $data = json_decode($event['data'], true);
            $data['id'] = (string) Str::ulid();
            $event['data'] = json_encode($data);
            $r->xAdd('events:v2', '*', $event);
            $r->xAdd('events:v2', '*', $event);
        }
        $this->drain();
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
        $this->assertSame(2, $this->store()->table('attempts')->count());
        $this->assertSame($before, json_encode($this->store()->table('steps')->orderBy('id')->get()));
        $this->assertSame(0, $r->xLen('events:v2'));
    }

    public function test_full_buffer_never_breaks_business_code_and_is_visible(): void
    {
        config()->set('current.telemetry.max_pending', 1);
        dispatch(new PaymentJob('success'));
        $this->work();
        $this->assertSame(42, Current::step('Outside worker', fn () => 42));
        $status = app(Health::class)->snapshot();
        $this->assertTrue($status['warning']);
        $this->assertGreaterThan(0, $status['dropped']);
        $this->assertFileExists(config('current.diagnostic_path'));
        $this->assertSame(0, $this->store()->table('jobs')->count());
    }

    public function test_invalid_and_expired_events_do_not_block_valid_events(): void
    {
        $r = app(Transport::class)->client();
        $r->xAdd('events:v2', '*', ['data' => '{broken-json']);
        dispatch(new PaymentJob('success'));
        $this->work();
        $events = $r->xRange('events:v2', '-', '+');
        $stale = json_decode(array_values($events)[1]['data'], true);
        $stale['id'] = (string) Str::ulid();
        $stale['observed_at'] = microtime(true) - 7200;
        $r->xAdd('events:v2', '*', ['data' => json_encode($stale)]);
        $this->drain();
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
        $this->assertSame(1, (int) $r->hGet('health', 'invalid'));
        $this->assertSame(1, (int) $r->hGet('health', 'expired'));
        $this->assertGreaterThanOrEqual(2, app(Health::class)->snapshot()['dropped']);
    }

    public function test_database_failure_retains_pending_events_and_restart_recovers(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        if ($this->serverDatabase) {
            $db = $this->store()->connection()->getPdo();
            $db->beginTransaction();
            $events = app(Transport::class)->client()->xRange('events:v2', '-', '+', 1);
            $id = json_decode(array_values($events)[0]['data'], true)['id'];
            $db->prepare('INSERT INTO lens_async_receipts VALUES (?,?)')->execute([$id, microtime(true) + 3600]);
        } else {
            $db = new \PDO('sqlite:'.config('database.connections.lens.database'));
            $db->exec('BEGIN IMMEDIATE');
        }
        try {
            $p = $this->collector();
            $p->wait();
            $this->assertFalse($p->isSuccessful(), $p->getOutput().$p->getErrorOutput().' pending='.app(Transport::class)->client()->xLen('events:v2').' health='.json_encode(app(Transport::class)->client()->hGetAll('health')));
            $this->assertGreaterThan(0, app(Transport::class)->client()->xLen('events:v2'));
        } finally {
            if ($this->serverDatabase) {
                $db->rollBack();
            } else {
                $db->exec('ROLLBACK');
            }
        }
        $this->drain();
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
    }

    public function test_second_collector_is_rejected_and_interrupted_owner_can_recover(): void
    {
        $first = $this->collector(false);
        $until = microtime(true) + 5;
        do {
            if (app(Health::class)->snapshot()['running']) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $until);
        $this->assertTrue(app(Health::class)->snapshot()['running']);
        $second = $this->collector();
        $second->wait();
        $this->assertFalse($second->isSuccessful());
        // A different Redis namespace must not bypass database ownership.
        $differentNamespace = $this->collector(true, ['prefix' => app(Transport::class)->settings()['prefix'].'other:']);
        $differentNamespace->wait();
        $this->assertFalse($differentNamespace->isSuccessful());
        $this->assertStringContainsString('already owns this database', $differentNamespace->getErrorOutput());
        $first->signal(SIGKILL);
        $first->wait();
        $blocked = $this->collector();
        $blocked->wait();
        $this->assertFalse($blocked->isSuccessful(), 'A killed owner must retain its lease until expiry.');
        // Shorten only this test's lease rather than waiting thirty seconds.
        $r = app(Transport::class)->client();
        $r->expire('owner', 1);
        $until = microtime(true) + 3;
        while ($r->exists('owner') && microtime(true) < $until) {
            usleep(20000);
        }
        $this->assertFalse((bool) $r->exists('owner'));
        dispatch(new PaymentJob('success'));
        $this->work();
        $this->drain();
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
    }

    public function test_existing_cycle_is_reconciled_without_sql_on_the_producer_path(): void
    {
        config()->set('current.telemetry.driver', 'sync');
        dispatch(new PaymentJob('success'));
        $job = $this->store()->table('jobs')->sole();
        config()->set('current.telemetry.driver', 'redis');
        $transport = app(Transport::class);
        $sql = [];
        $this->store()->connection()->beforeExecuting(function ($query) use (&$sql) { $sql[] = $query; });
        $candidate = $transport->cycle($job->external_id, $job->connection);
        $this->assertNotSame($job->id, $candidate['id']);
        app(\Current\Recorder::class)->enqueued(['uuid' => $job->external_id, 'data' => ['commandName' => PaymentJob::class]], $job->connection, $job->queue);
        $this->assertSame([], $sql);
        $this->assertLessThanOrEqual(2 * config('current.telemetry.max_age_seconds'), $transport->client()->ttl('cycle:v2:'.hash('sha256', $job->connection.'|'.$job->external_id)));
        $this->drain();
        $this->assertSame($job->id, $this->store()->table('jobs')->sole()->id);
        $this->assertSame($job->id, $this->store()->table('async_aliases')->where('id', $candidate['id'])->value('canonical_id'));
    }

    public function test_transport_outage_does_not_prevent_job_execution(): void
    {
        config()->set('database.redis.current_down', ['host' => '127.0.0.1', 'port' => 16399, 'database' => 0]);
        config()->set('current.telemetry.redis_connection', 'current_down');
        dispatch(new PaymentJob('success'));
        $this->work();
        $this->assertSame(0, Queue::size('lens-demo'));
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $this->assertFileExists(config('current.diagnostic_path'));
        $this->assertFalse(app(Health::class)->snapshot()['available']);
        $this->assertSame('business result', Current::step('Outside work', fn () => 'business result'));
        config()->set('current.telemetry.redis_connection', 'default');
    }

    public function test_horizon_manual_retry_raw_enqueue_keeps_lineage(): void
    {
        dispatch(new PaymentJob('failure'));
        $this->work(3);
        $this->drain();
        $original = $this->store()->table('jobs')->sole();
        $this->assertSame('failed', $original->status);
        app()->call([new RetryFailedJob($original->external_id), 'handle']);
        $this->drain();
        $retry = $this->store()->table('jobs')->where('id', '!=', $original->id)->sole();
        $this->assertSame('observed_enqueue', $retry->coverage);
        $this->assertSame($original->external_id, $retry->retry_of);
        $this->assertNotSame($original->external_id, $retry->external_id);
        $this->work(3);
        $this->drain();
        $this->assertSame('failed', $this->store()->table('jobs')->where('id', $retry->id)->value('status'));
    }

    public function test_unsupported_sql_connection_options_are_rejected_explicitly(): void
    {
        $original = config('database.connections.lens');
        try {
            foreach (['mysql', 'mariadb'] as $driver) {
                $base = ['driver' => $driver, 'host' => '127.0.0.1', 'database' => 'current', 'username' => 'current', 'password' => 'secret'];
                config()->set('database.connections.lens', $base);
                $this->assertSame($driver, app(Transport::class)->settings()['sql']['driver']);
                foreach (['options' => [\PDO::ATTR_TIMEOUT => 1], 'read' => ['host' => 'replica'], 'url' => 'mysql://unusable'] as $key => $value) {
                    config()->set('database.connections.lens', $base + [$key => $value]);
                    try {
                        app(Transport::class)->settings();
                        $this->fail('Unsupported options must not be ignored.');
                    } catch (\RuntimeException $e) {
                        $this->assertStringContainsString('not supported', $e->getMessage());
                    }
                }
            }
        } finally {
            config()->set('database.connections.lens', $original);
        }
    }

    public function test_collector_installation_verifies_checksum_and_preserves_existing_binary(): void
    {
        $source = config('current.telemetry.binary');
        $target = sys_get_temp_dir().'/current-install-test-'.bin2hex(random_bytes(8));
        $this->files[] = $target;
        config()->set('current.telemetry.binary', $target);
        try {
            $this->assertSame(1, Artisan::call('current:collector-install', ['binary' => $source, '--sha256' => str_repeat('0', 64)]));
            $this->assertFileDoesNotExist($target);
            $checksum = hash_file('sha256', $source);
            $this->assertSame(0, Artisan::call('current:collector-install', ['binary' => $source, '--sha256' => $checksum]), Artisan::output());
            $this->assertTrue(is_executable($target));
            $this->assertSame($checksum, hash_file('sha256', $target));
            $this->assertSame(1, Artisan::call('current:collector-install', ['binary' => $source, '--sha256' => $checksum]));
            $this->assertSame($checksum, hash_file('sha256', $target));
            $this->assertSame(0, Artisan::call('current:collector-install', ['binary' => $source, '--sha256' => $checksum, '--force' => true]));
        } finally {
            config()->set('current.telemetry.binary', $source);
        }
    }

    public function test_sustained_collection_recovers_and_drains_without_missing_history(): void
    {
        $duration = (int) getenv('CURRENT_ASYNC_SOAK_SECONDS');
        if ($duration < 60) {
            $this->markTestSkipped('Opt-in sustained run: CURRENT_ASYNC_SOAK_SECONDS >= 60.');
        }
        $duration = min($duration, 1800);
        $r = app(Transport::class)->client();
        $p = $this->collector(false);
        $started = microtime(true);
        $restartAt = $started + 45;
        $hardKillAt = $started + $duration / 2;
        $hardKilled = false;
        $jobs = $attempts = $restarts = $peakPending = 0;
        while (microtime(true) - $started < $duration) {
            $scenario = ['success', 'retry', 'release', 'failure'][$jobs % 4];
            dispatch(new PaymentJob($scenario, 'soak-'.$jobs));
            $this->work(3);
            $jobs++;
            $attempts += match ($scenario) {
                'success' => 1, 'retry', 'release' => 2, 'failure' => 3
            };
            $peakPending = max($peakPending, $r->xLen('events:v2'));
            $this->assertSame(0, (int) $r->hGet('health', 'rejected'));
            $this->assertFileDoesNotExist(config('current.diagnostic_path'));
            if (! $hardKilled && microtime(true) >= $hardKillAt) {
                $p->signal(SIGKILL);
                $p->wait();
                // Leave accepted work queued throughout the real ownership lease expiry.
                dispatch(new PaymentJob('success', 'soak-backlog'));
                $this->work();
                $jobs++;
                $attempts++;
                $this->assertGreaterThan(0, $r->xLen('events:v2'));
                $until = microtime(true) + 35;
                while ($r->exists('owner') && microtime(true) < $until) {
                    usleep(100000);
                }
                $this->assertFalse((bool) $r->exists('owner'));
                $p = $this->collector(false);
                $hardKilled = true;
                $restarts++;
                $restartAt = microtime(true) + 45;
            } elseif (microtime(true) >= $restartAt) {
                $p->signal(SIGTERM);
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $p = $this->collector(false);
                $restarts++;
                $restartAt = microtime(true) + 45;
            }
            usleep(100000);
        }
        $p->signal(SIGTERM);
        $p->wait();
        $this->drain();
        $this->assertSame($jobs, $this->store()->table('jobs')->count());
        $this->assertSame($attempts, $this->store()->table('attempts')->count());
        $this->assertSame($attempts, (int) $this->store()->table('jobs')->sum('attempt_count'));
        $this->assertSame(0, $this->store()->table('attempts')->whereNull('finished_at')->count());
        $this->assertSame(0, $r->xLen('events:v2'));
        $this->assertSame(0, (int) $r->hGet('health', 'invalid'));
        $this->assertSame(0, (int) $r->hGet('health', 'expired'));
        fwrite(STDOUT, "\nSOAK ".json_encode(['seconds' => round(microtime(true) - $started, 1), 'driver' => config('database.connections.lens.driver'), 'jobs' => $jobs, 'attempts' => $attempts, 'restarts' => $restarts, 'peak_pending' => $peakPending, 'accepted' => (int) $r->hGet('health', 'accepted'), 'processed' => (int) $r->hGet('health', 'processed')])."\n");
        $this->assertSame((int) $r->hGet('health', 'accepted'), (int) $r->hGet('health', 'processed'));
    }

    public function test_graceful_stop_acknowledges_an_in_flight_committed_batch(): void
    {
        if (! $this->serverDatabase) {
            $this->markTestSkipped('Server SQL shutdown boundary check.');
        }
        dispatch(new PaymentJob('success'));
        $this->work();
        $db = $this->store()->connection();
        $db->unprepared('CREATE TRIGGER current_test_slow BEFORE INSERT ON lens_async_receipts FOR EACH ROW DO SLEEP(0.05)');
        try {
            $p = $this->collector(false);
            $r = app(Transport::class)->client();
            $until = microtime(true) + 5;
            $pending = 0;
            do {
                try {
                    $pending = ($r->xPending('events:v2', 'collector')[0] ?? 0);
                } catch (\Throwable) {
                }
                if ($pending > 0) {
                    break;
                }
                usleep(5000);
            } while (microtime(true) < $until);
            $this->assertGreaterThan(0, $pending);
            $p->signal(SIGTERM);
            $p->setTimeout(10);
            $p->wait();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
            $this->assertSame(0, $r->xLen('events:v2'));
            $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
        } finally {
            $db->unprepared('DROP TRIGGER current_test_slow');
        }
    }

    public function test_sql_tls_requires_trust_hostname_and_encryption(): void
    {
        $tls = getenv('CURRENT_ASYNC_TLS_DIR');
        if (! $tls || ! $this->serverDatabase) {
            $this->markTestSkipped('Requires disposable TLS-enforced SQL server.');
        }
        $cipher = (array) $this->store()->connection()->selectOne("SHOW STATUS LIKE 'Ssl_cipher'");
        $this->assertNotEmpty($cipher['Value']);
        dispatch(new PaymentJob('success'));
        $this->work();
        $settings = app(Transport::class)->settings()['sql'];
        $cases = [
            array_replace_recursive($settings, ['tls' => ['ca' => $tls.'/wrong-ca.pem']]),
            array_replace_recursive($settings, ['tls' => ['enabled' => false]]),
        ];
        if ($wrongHost = getenv('CURRENT_ASYNC_TLS_WRONG_HOST')) {
            $cases[] = array_replace($settings, ['host' => $wrongHost]);
        }
        foreach ($cases as $sql) {
            $p = $this->collector(true, ['sql' => $sql]);
            $p->wait();
            $this->assertFalse($p->isSuccessful());
            $this->assertGreaterThan(0, app(Transport::class)->client()->xLen('events:v2'));
            $this->assertSame(0, $this->store()->table('jobs')->count());
        }
        $this->drain();
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
        $original = config('database.connections.lens.options');
        try {
            config()->set('database.connections.lens.options', [\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('requires server certificate verification');
            app(Transport::class)->settings();
        } finally {
            config()->set('database.connections.lens.options', $original);
        }
    }

    public function test_server_database_requires_transactional_tables(): void
    {
        if (! $this->serverDatabase) {
            $this->markTestSkipped('Server SQL engine check.');
        }
        dispatch(new PaymentJob('success'));
        $this->work();
        $db = $this->store()->connection();
        $db->statement('ALTER TABLE lens_async_receipts ENGINE=MyISAM');
        try {
            $p = $this->collector();
            $p->wait();
            $this->assertFalse($p->isSuccessful());
            $this->assertStringContainsString('must exist and use InnoDB', $p->getErrorOutput());
            $this->assertSame(0, $this->store()->table('jobs')->count());
            $this->assertGreaterThan(0, app(Transport::class)->client()->xLen('events:v2'));
        } finally {
            $db->statement('ALTER TABLE lens_async_receipts ENGINE=InnoDB');
        }
        $this->drain();
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
    }

    public function test_server_database_rolls_back_the_whole_batch_on_write_failure(): void
    {
        if (! $this->serverDatabase) {
            $this->markTestSkipped('Server SQL transaction failure check.');
        }
        dispatch(new PaymentJob('success'));
        $this->work();
        $db = $this->store()->connection();
        $db->unprepared("CREATE TRIGGER current_test_fail BEFORE INSERT ON lens_attempts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test rollback'");
        try {
            $p = $this->collector();
            $p->wait();
            $this->assertFalse($p->isSuccessful());
            foreach (['jobs', 'attempts', 'async_receipts', 'async_versions'] as $table) {
                $this->assertSame(0, $this->store()->table($table)->count(), $table);
            }
            $this->assertGreaterThan(0, app(Transport::class)->client()->xLen('events:v2'));
        } finally {
            $db->unprepared('DROP TRIGGER current_test_fail');
        }
        $this->drain();
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
    }

    public function test_server_database_connection_loss_exits_and_restart_recovers(): void
    {
        if (! $this->serverDatabase) {
            $this->markTestSkipped('Server SQL session ownership check.');
        }
        $p = $this->collector(false);
        $until = microtime(true) + 5;
        while (! app(Health::class)->snapshot()['running'] && microtime(true) < $until) {
            usleep(20000);
        }
        $this->assertTrue(app(Health::class)->snapshot()['running']);
        $lock = substr('current:'.hash('sha256', $this->serverDatabase), 0, 63);
        $connection = $this->store()->connection()->selectOne('SELECT IS_USED_LOCK(?) AS owner', [$lock])->owner;
        $this->assertGreaterThan(0, (int) $connection);
        $this->store()->connection()->statement('KILL CONNECTION '.(int) $connection);
        dispatch(new PaymentJob('success'));
        $this->work();
        $p->wait();
        $this->assertFalse($p->isSuccessful());
        $this->assertGreaterThan(0, app(Transport::class)->client()->xLen('events:v2'));
        $this->drain();
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
    }

    public function test_collect_command_and_pruning_work_with_the_public_configuration(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        $this->assertSame(0, Artisan::call('current:collect', ['--once' => true]), Artisan::output());
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
        $this->store()->table('async_receipts')->update(['expires_at' => microtime(true) - 1]);
        $this->store()->table('async_versions')->update(['expires_at' => microtime(true) - 1]);
        $this->assertSame(0, Artisan::call('current:prune'));
        $this->assertSame(0, $this->store()->table('async_receipts')->count());
        $this->assertSame(0, $this->store()->table('async_versions')->count());
    }
}
