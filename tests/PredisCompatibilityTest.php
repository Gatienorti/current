<?php

namespace Current\Tests;

use Current\Native\ObservationRedis;
use Current\Native\QueueLoad;
use Current\Native\RedisQueue;
use Current\Native\Registry;
use Current\Telemetry\Health;
use Current\Telemetry\Transport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

final class PredisCompatibilityTest extends TestCase
{
    public static function clients(): array { return [['phpredis'], ['predis']]; }
    public static function telemetryClients(): array { return [['phpredis', false], ['predis', false], ['phpredis', true], ['predis', true]]; }

    private function selectClient(string $client): void
    {
        if ($client === 'phpredis' && ! extension_loaded('redis')) { $this->markTestSkipped('Optional phpredis extension is not installed.'); }
        if ($client === 'predis' && ! class_exists(\Predis\Client::class)) {
            if (getenv('CURRENT_REQUIRE_PREDIS_TESTS') === '1') { $this->fail('Install optional Predis for its required compatibility checks.'); }
            $this->markTestSkipped('Optional Predis package is not installed.');
        }
        config()->set('database.redis.client', $client);
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        $manager = new RedisManager(app(), $client, config('database.redis'));
        app()->instance('redis', $manager);
        Redis::clearResolvedInstance('redis');
    }

    #[DataProvider('clients')]
    public function test_confirmed_native_direct_delayed_and_bulk_dispatches_execute_on_both_clients(string $client): void
    {
        $this->selectClient($client);
        $queue = app('queue')->connection('redis');
        $queue->push(new PredisFixtureJob('direct'));
        $queue->later(-1, new PredisFixtureJob('delayed'));
        $delayed = new PredisFixtureJob('bulk-delayed'); $delayed->delay(-1);
        $queue->bulk([new PredisFixtureJob('bulk-one'), new PredisFixtureJob('bulk-two'), $delayed]);
        $this->assertSame(5, $this->store()->table('jobs')->where('coverage', 'observed_enqueue')->count());
        $this->assertNotNull($queue->lastEnqueued);
        $this->work(5);
        $effects = Redis::hgetall('predis-effects'); ksort($effects);
        $this->assertSame(['bulk-delayed' => '1', 'bulk-one' => '1', 'bulk-two' => '1', 'delayed' => '1', 'direct' => '1'], $effects);
        $this->assertSame(5, $this->store()->table('jobs')->where('status', 'completed')->count());
        $this->assertSame(5, $this->store()->table('attempts')->where('status', 'completed')->count());
    }

    #[DataProvider('clients')]
    public function test_caller_pipeline_has_no_fabricated_enqueue_before_or_after_its_unobserved_exec(string $client): void
    {
        $this->selectClient($client);
        $connection = app('redis')->connection();
        if ($client === 'predis') {
            $pipeline = $connection->client()->pipeline();
            $factory = Mockery::mock(\Illuminate\Contracts\Redis\Factory::class);
            $factory->shouldReceive('connection')->andReturn(new PredisConnection($pipeline));
            $queue = new RedisQueue($factory, 'lens-demo', 'default', 5);
            $queue->setContainer(app()); $queue->setConnectionName('redis');
        } else {
            $pipeline = $connection->client()->pipeline();
            $queue = app('queue')->connection('redis');
        }
        $queue->push(new PredisFixtureJob('caller-pipeline'));
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $this->assertNull($queue->lastEnqueued);
        if ($client === 'predis') { $pipeline->execute(); } else { $pipeline->exec(); }
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $this->work();
        $job = $this->store()->table('jobs')->sole();
        $this->assertSame('completed', $job->status);
        $this->assertSame('start_only', $job->coverage);
        $this->assertSame('1', Redis::hget('predis-effects', 'caller-pipeline'));
    }

    #[DataProvider('clients')]
    public function test_caller_transaction_queued_reply_is_not_a_confirmed_dispatch(string $client): void
    {
        $this->selectClient($client);
        $queue = app('queue')->connection('redis');
        $raw = $queue->getConnection()->client();
        $raw->multi();
        try {
            $queue->push(new PredisFixtureJob('discarded'));
            $this->assertSame(0, $this->store()->table('jobs')->count());
            $this->assertNull($queue->lastEnqueued);
        } finally { $raw->discard(); }
        $this->assertSame(0, Redis::llen('queues:lens-demo'));
        $queue->push(new PredisFixtureJob('confirmed'));
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->work();
        $this->assertSame('1', Redis::hget('predis-effects', 'confirmed'));
    }

    public function test_predis_bulk_does_not_commit_a_callers_existing_transaction(): void
    {
        $this->selectClient('predis');
        $queue = app('queue')->connection('redis'); $raw = $queue->getConnection()->client();
        $raw->multi(); $raw->set('caller-owned-marker', 'queued');
        try {
            try { $queue->bulk([new PredisFixtureJob('must-not-commit')]); $this->fail('Existing transaction was adopted.'); }
            catch (\Predis\Response\ServerException $e) { $this->assertStringContainsString('MULTI', $e->getMessage()); }
        } finally { $raw->discard(); }
        $this->assertNull($raw->get('caller-owned-marker'));
        $this->assertSame(0, Redis::llen('queues:lens-demo'));
        $this->assertSame(0, $this->store()->table('jobs')->count());
    }

    public function test_predis_bulk_serialization_failure_aborts_without_committing_a_partial_queue(): void
    {
        $this->selectClient('predis');
        $queue = app('queue')->connection('redis');
        try {
            $queue->bulk([new PredisFixtureJob('valid-first'), new PredisUnserializableJob]);
            $this->fail('Serialization failure was hidden.');
        } catch (\RuntimeException $e) { $this->assertStringContainsString('Synthetic serialization failure', $e->getMessage()); }
        $this->assertSame(0, Redis::llen('queues:lens-demo'));
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $queue->push(new PredisFixtureJob('after-abort'));
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->work();
        $this->assertSame('1', Redis::hget('predis-effects', 'after-abort'));
    }

    #[DataProvider('clients')]
    public function test_partial_lua_exec_errors_never_publish_bulk_enqueue_success(string $client): void
    {
        $this->selectClient($client);
        Redis::set('queues:lens-demo:notify', 'wrong-type');
        $queue = app('queue')->connection('redis');
        try { $queue->bulk([new PredisFixtureJob('partial-one'), new PredisFixtureJob('partial-two')]); }
        catch (\RedisException|\Predis\Response\ServerException) { /* EXEC can contain committed work alongside command errors. */ }
        $this->assertSame(2, Redis::llen('queues:lens-demo'));
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $this->assertNull($queue->lastEnqueued);
        Redis::del('queues:lens-demo:notify');
        $this->work(2);
        $this->assertSame(2, $this->store()->table('jobs')->where('coverage', 'start_only')->where('status', 'completed')->count());
    }

    #[DataProvider('clients')]
    public function test_observation_connections_keep_prefix_and_timeouts_isolated_and_registry_works(string $client): void
    {
        $this->selectClient($client);
        config()->set('database.redis.default.timeout', 20);
        config()->set('database.redis.default.read_timeout', 20);
        config()->set('database.redis.default.read_write_timeout', 20);
        Redis::rpush('queues:observed', 'one', 'two');
        $connection = ObservationRedis::connect('default');
        $this->assertSame(2, $connection->llen('queues:observed'));
        $this->assertSame(2, (new QueueLoad)->counts('redis', ['observed'])['observed']);
        $this->assertSame(20, config('database.redis.default.timeout'));
        $this->assertSame(20, config('database.redis.default.read_write_timeout'));
        if ($client === 'predis') {
            $parameters = $connection->client()->getConnection()->getParameters();
            $this->assertSame(.5, $parameters->timeout);
            $this->assertSame(.5, $parameters->read_write_timeout);
            $this->assertFalse($parameters->persistent);
        }
        $health = ['available' => true, 'instance' => 'predis-fixture', 'owner' => str_repeat('a', 32), 'state' => 'running',
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'pools' => [['name' => 'orders', 'workers' => 2, 'min' => 1, 'max' => 2, 'running' => 2, 'state' => 'running']]];
        $registry = new Registry; $registry->publish($health, 'fixture-host');
        $rows = $registry->all();
        $this->assertTrue($rows['available']);
        $this->assertCount(1, $rows['instances']);
        $this->assertSame(2, $rows['instances'][0]['pools'][0]['running']);
        $this->assertArrayNotHasKey('owner', $rows['instances'][0]);
    }

    #[DataProvider('telemetryClients')]
    public function test_async_transport_and_actual_collector_accept_native_events_on_both_clients(string $client, bool $tls): void
    {
        if (! is_executable(dirname(__DIR__).'/collector/current-collector')) { $this->markTestSkipped('Build the optional collector for its wire acceptance test.'); }
        if ($tls && (! getenv('CURRENT_REDIS_TLS_HOST') || ! getenv('CURRENT_REDIS_TLS_DIR'))) { $this->markTestSkipped('Requires the disposable mutual-TLS Redis server.'); }
        $this->selectClient($client);
        if ($tls) {
            $directory = getenv('CURRENT_REDIS_TLS_DIR');
            $ssl = ['cafile' => $directory.'/ca.pem', 'local_cert' => $directory.'/client.pem', 'local_pk' => $directory.'/client-key.pem',
                'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => 'localhost'];
            $source = ['host' => getenv('CURRENT_REDIS_TLS_HOST'), 'scheme' => 'tls', 'port' => 6379, 'database' => 0,
                'username' => 'default', 'password' => 'current-test-only'];
            if ($client === 'predis') { $source['ssl'] = $ssl; }
            else { $source['context'] = ['stream' => $ssl]; }
            config()->set('database.redis.secure_telemetry', $source);
            config()->set('current.telemetry.redis_connection', 'secure_telemetry');
        }
        $original = config('database.connections.lens.database');
        $this->store()->connection()->statement('PRAGMA wal_checkpoint(TRUNCATE)');
        app('db')->purge('lens');
        $local = sys_get_temp_dir().'/current-predis-async-'.bin2hex(random_bytes(6)).'.sqlite';
        copy($original, $local);
        config()->set('database.connections.lens.database', $local);
        (require dirname(__DIR__).'/database/migrations/2026_10_27_000012_add_async_telemetry_receipts.php')->up();
        config()->set('current.telemetry.driver', 'redis');
        config()->set('current.telemetry.binary', dirname(__DIR__).'/collector/current-collector');
        $transport = new Transport;
        app()->instance(Transport::class, $transport);
        try {
            app('queue')->connection('redis')->push(new PredisFixtureJob('async'));
            $this->work();
            $this->assertSame(0, $this->store()->table('jobs')->count());
            $this->assertGreaterThan(0, $transport->client()->xLen('events:v2'));
            $before = (new Health($transport))->snapshot();
            $this->assertTrue($before['available']);
            $this->assertFalse($before['running']);
            $exit = Artisan::call('current:collect', ['--once' => true]); $output = Artisan::output();
            $this->assertSame(0, $exit, $output);
            $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
            $this->assertSame('observed_enqueue', $this->store()->table('jobs')->sole()->coverage);
            $this->assertSame(0, $transport->client()->xLen('events:v2'));
            if ($tls) { $this->assertSame('1', $transport->client()->hGetAll('health')['redis_tls_protocol']); }
            $this->assertSame('1', Redis::hget('predis-effects', 'async'));
        } finally {
            $prefix = $transport->settings()['prefix'];
            foreach ($transport->client()->keys('*') as $key) { $transport->client()->del(substr($key, strlen($prefix))); }
            $transport->disconnect();
            app('db')->purge('lens');
            config()->set('database.connections.lens.database', $original);
            foreach (glob($local.'*') as $file) { unlink($file); }
        }
    }

    #[DataProvider('clients')]
    public function test_named_authenticated_observation_and_transport_keep_the_application_connection_isolated(string $client): void
    {
        if (! $host = getenv('CURRENT_REDIS_AUTH_HOST')) { $this->markTestSkipped('Requires the disposable authenticated Redis server.'); }
        $this->selectClient($client);
        config()->set('database.redis.authenticated', ['host' => $host, 'port' => 6379, 'database' => 0,
            'username' => 'default', 'password' => 'current-test-only']);
        $connection = ObservationRedis::connect('authenticated');
        $this->assertSame(1, $connection->eval("redis.call('SET',KEYS[1],ARGV[1]); return 1", 1, 'private-auth-fixture', 'one'));
        $this->assertSame('one', $connection->get('private-auth-fixture'));
        $this->assertSame(0, Redis::exists('private-auth-fixture'));
        config()->set('current.telemetry.redis_connection', 'authenticated');
        $transport = new Transport;
        try {
            $transport->emit(['probe' => 'authenticated']);
            $this->assertSame(1, $transport->client()->xLen('events:v2'));
            $this->assertSame('1', $transport->client()->hGetAll('health')['accepted']);
            $this->assertFalse($transport->client()->get('private-auth-fixture'));
        } finally {
            $transport->client()->del('events:v2', 'health');
            $connection->del('private-auth-fixture');
            $transport->disconnect(); $connection->disconnect();
        }
    }

    #[DataProvider('clients')]
    public function test_observation_and_telemetry_stalls_remain_bounded_and_do_not_break_native_jobs(string $client): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('Requires PCNTL and local TCP.'); }
        $this->selectClient($client);
        $server = stream_socket_server('tcp://127.0.0.1:0'); $this->assertIsResource($server);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        config()->set('database.redis.stalled', ['host' => '127.0.0.1', 'port' => $port, 'database' => 0,
            'timeout' => 30, 'read_timeout' => 30, 'read_write_timeout' => 30]);
        $pid = pcntl_fork(); $this->assertNotSame(-1, $pid);
        if ($pid === 0) { $socket = stream_socket_accept($server, 3); if (! $socket) { exit(2); } sleep(5); exit(0); }
        fclose($server);
        try {
            $start = microtime(true);
            try { ObservationRedis::connect('stalled')->ping(); $this->fail('Stalled observer reported success.'); }
            catch (\RedisException|\Predis\CommunicationException) {}
            $this->assertLessThan(1.5, microtime(true) - $start);
            config()->set('current.telemetry.driver', 'redis');
            config()->set('current.telemetry.redis_connection', 'stalled');
            app()->instance(Transport::class, new Transport);
            $start = microtime(true);
            app('queue')->connection('redis')->push(new PredisFixtureJob('telemetry-unavailable'));
            $this->assertLessThan(1.5, microtime(true) - $start);
            $this->work();
            $this->assertSame('1', Redis::hget('predis-effects', 'telemetry-unavailable'));
            $this->assertSame(0, $this->store()->table('jobs')->count());
            $this->assertSame(30, config('database.redis.stalled.read_write_timeout'));
        } finally { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
    }

    public function test_transport_client_changes_revalidate_and_custom_tls_is_never_silently_mismatched(): void
    {
        if (! extension_loaded('redis')) { $this->markTestSkipped('Switching client types requires both optional clients.'); }
        $this->selectClient('predis');
        $transport = new Transport; $predis = $transport->client();
        $this->assertInstanceOf(\Current\Telemetry\PredisClient::class, $predis);
        config()->set('database.redis.client', 'phpredis');
        $this->assertInstanceOf(\Redis::class, $transport->client());
        config()->set('database.redis.default.scheme', 'tls');
        config()->set('database.redis.default.context.stream.verify_peer', false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires peer and hostname verification');
        $transport->settings();
    }

    #[DataProvider('clients')]
    public function test_observation_tls_preserves_certificate_authentication_and_verification(string $client): void
    {
        $host = getenv('CURRENT_REDIS_TLS_HOST'); $directory = getenv('CURRENT_REDIS_TLS_DIR');
        if (! $host || ! $directory) { $this->markTestSkipped('Requires the disposable mutual-TLS Redis server and certificates.'); }
        $this->selectClient($client);
        $ssl = ['cafile' => $directory.'/ca.pem', 'local_cert' => $directory.'/client.pem', 'local_pk' => $directory.'/client-key.pem',
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => 'localhost'];
        $source = ['host' => $host, 'scheme' => 'tls', 'port' => 6379, 'database' => 0, 'username' => 'default', 'password' => 'current-test-only'];
        if ($client === 'predis') { $source['ssl'] = $ssl; }
        else { $source['context'] = ['stream' => $ssl]; }
        config()->set('database.redis.secure', $source);
        $connection = ObservationRedis::connect('secure');
        $this->assertSame(1, $connection->eval("redis.call('SET',KEYS[1],ARGV[1]); return 1", 1, 'secure-proof', 'verified'));
        $this->assertSame('verified', $connection->get('secure-proof'));
        $this->assertSame(0, Redis::exists('secure-proof'));
        $connection->del('secure-proof'); $connection->disconnect();
        $ssl['cafile'] = $directory.'/wrong-ca.pem';
        if ($client === 'predis') { $source['ssl'] = $ssl; }
        else { $source['context'] = ['stream' => $ssl]; }
        config()->set('database.redis.secure', $source);
        try { @ObservationRedis::connect('secure')->ping(); $this->fail('Untrusted server certificate was accepted.'); }
        catch (\RedisException|\Predis\PredisException) { $this->addToAssertionCount(1); }
    }

    public function test_cached_redis_tls_settings_recheck_certificate_readability(): void
    {
        $this->selectClient('predis');
        $file = tempnam(sys_get_temp_dir(), 'current-redis-ca-');
        try {
            config()->set('database.redis.default.scheme', 'tls');
            config()->set('database.redis.default.ssl', ['cafile' => $file]);
            $transport = new Transport;
            $this->assertSame(realpath($file), $transport->settings()['redis_tls']['ca']);
            unlink($file); clearstatcache(true, $file);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('certificate files must be readable');
            $transport->settings();
        } finally { if (is_file($file)) { unlink($file); } }
    }

    #[DataProvider('clients')]
    public function test_native_execution_preserves_selected_client_mutual_tls_settings(string $client): void
    {
        $host = getenv('CURRENT_REDIS_TLS_HOST'); $directory = getenv('CURRENT_REDIS_TLS_DIR');
        if (! $host || ! $directory) { $this->markTestSkipped('Requires the disposable mutual-TLS Redis server.'); }
        $ssl = ['cafile' => $directory.'/ca.pem', 'local_cert' => $directory.'/client.pem', 'local_pk' => $directory.'/client-key.pem',
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => 'localhost'];
        $source = ['host' => $host, 'scheme' => 'tls', 'port' => 6379, 'database' => 0, 'username' => 'default', 'password' => 'current-test-only'];
        if ($client === 'predis') { $source['ssl'] = $ssl; }
        else { $source['context'] = ['stream' => $ssl]; }
        config()->set('database.redis.default', $source);
        $this->selectClient($client);
        app('queue')->connection('redis')->bulk([new PredisFixtureJob('tls-one'), new PredisFixtureJob('tls-two')]);
        $this->assertSame(2, $this->store()->table('jobs')->where('coverage', 'observed_enqueue')->count());
        $this->work(2);
        $this->assertSame(2, $this->store()->table('jobs')->where('status', 'completed')->count());
        $this->assertSame(['tls-one' => '1', 'tls-two' => '1'], Redis::hgetall('predis-effects'));
    }

    #[DataProvider('clients')]
    public function test_custom_tls_requires_a_capable_collector_and_rejects_an_untrusted_server(string $client): void
    {
        $host = getenv('CURRENT_REDIS_TLS_HOST'); $directory = getenv('CURRENT_REDIS_TLS_DIR');
        if (! $host || ! $directory) { $this->markTestSkipped('Requires the disposable mutual-TLS Redis server.'); }
        $this->selectClient($client);
        $ssl = ['cafile' => $directory.'/wrong-ca.pem', 'local_cert' => $directory.'/client.pem', 'local_pk' => $directory.'/client-key.pem',
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => 'localhost'];
        $source = ['host' => $host, 'scheme' => 'tls', 'port' => 6379, 'database' => 0, 'username' => 'default', 'password' => 'current-test-only'];
        if ($client === 'predis') { $source['ssl'] = $ssl; }
        else { $source['context'] = ['stream' => $ssl]; }
        config()->set('database.redis.custom_tls', $source);
        config()->set('current.telemetry.redis_connection', 'custom_tls');
        config()->set('current.telemetry.driver', 'redis');
        $old = tempnam(sys_get_temp_dir(), 'current-old-collector-');
        file_put_contents($old, "#!/usr/bin/env php\n<?php echo json_encode(['identity_protocol'=>2,'maintenance_protocol'=>1]);\n"); chmod($old, 0700);
        try {
            config()->set('current.telemetry.binary', $old);
            $this->assertSame(1, Artisan::call('current:collect', ['--once' => true]));
            $this->assertStringContainsString('configured Redis TLS options', Artisan::output());
            $binary = dirname(__DIR__).'/collector/current-collector';
            if (! is_executable($binary)) { $this->markTestSkipped('Build the matching collector for actual TLS rejection.'); }
            config()->set('current.telemetry.binary', $binary);
            $exit = Artisan::call('current:collect', ['--once' => true]); $output = Artisan::output();
            $this->assertSame(1, $exit, $output);
            $this->assertStringContainsString('Redis unavailable', $output);
            $this->assertStringNotContainsString($directory, $output);
        } finally { unlink($old); }
    }

    #[DataProvider('clients')]
    public function test_custom_tls_health_requires_fresh_matching_collector_capability(string $client): void
    {
        $host = getenv('CURRENT_REDIS_TLS_HOST'); $directory = getenv('CURRENT_REDIS_TLS_DIR');
        if (! $host || ! $directory) { $this->markTestSkipped('Requires the disposable mutual-TLS Redis server.'); }
        $this->selectClient($client);
        $ssl = ['cafile' => $directory.'/ca.pem', 'local_cert' => $directory.'/client.pem', 'local_pk' => $directory.'/client-key.pem',
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => 'localhost'];
        $source = ['host' => $host, 'scheme' => 'tls', 'port' => 6379, 'database' => 0, 'username' => 'default', 'password' => 'current-test-only'];
        if ($client === 'predis') { $source['ssl'] = $ssl; }
        else { $source['context'] = ['stream' => $ssl]; }
        config()->set('database.redis.custom_tls_health', $source);
        config()->set('current.telemetry.redis_connection', 'custom_tls_health');
        config()->set('current.telemetry.driver', 'redis');
        $transport = new Transport; $redis = $transport->client();
        try {
            $redis->set('owner', 'private-health-fixture');
            $redis->hMSet('health', ['owner_token' => 'private-health-fixture', 'heartbeat_at' => microtime(true),
                'protocol' => 2, 'maintenance_protocol' => 1, 'monitored_retention' => 1]);
            $health = new Health($transport);
            $this->assertTrue($health->snapshot()['available']);
            $this->assertFalse($health->snapshot()['running']);
            $redis->hSet('health', 'redis_tls_protocol', 1);
            $this->assertTrue($health->snapshot()['running']);
            $this->assertFalse($health->snapshot()['warning']);
        } finally { $redis->del('owner', 'health'); $transport->disconnect(); }
    }

    public function test_predis_shared_connection_parameters_are_preserved_by_private_clients(): void
    {
        if (! $host = getenv('CURRENT_REDIS_AUTH_HOST')) { $this->markTestSkipped('Requires the disposable authenticated Redis server.'); }
        $this->selectClient('predis');
        config()->set('database.redis.inherited', ['database' => 0]);
        config()->set('database.redis.options.parameters', ['host' => $host, 'port' => 6379, 'username' => 'default',
            'password' => 'current-test-only', 'timeout' => 30, 'read_write_timeout' => 30]);
        $connection = ObservationRedis::connect('inherited');
        $this->assertSame(1, $connection->eval("redis.call('SET',KEYS[1],ARGV[1]); return 1", 1, 'inherited-proof', 'one'));
        $this->assertSame(.5, $connection->client()->getConnection()->getParameters()->read_write_timeout);
        config()->set('current.telemetry.redis_connection', 'inherited');
        $transport = new Transport;
        try {
            $transport->emit(['probe' => 'inherited-parameters']);
            $this->assertSame($host, $transport->settings()['host']);
            $this->assertSame(1, $transport->client()->xLen('events:v2'));
        } finally { $connection->del('inherited-proof'); $transport->client()->del('events:v2', 'health'); $transport->disconnect(); }
    }

    #[DataProvider('clients')]
    public function test_transport_never_downgrades_an_unknown_tls_scheme_to_plaintext(string $client): void
    {
        $this->selectClient($client);
        config()->set('database.redis.default.scheme', 'TLS');
        $this->assertTrue((new Transport)->settings()['tls']);
        config()->set('database.redis.default.scheme', 'unsupported-tls');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('scheme=tcp or scheme=tls');
        (new Transport)->settings();
    }

    #[DataProvider('clients')]
    public function test_literal_ipv6_collector_address_is_bracketed_without_changing_the_php_host(string $client): void
    {
        $this->selectClient($client);
        config()->set('database.redis.default.host', '::1');
        $settings = (new Transport)->settings();
        $this->assertSame('[::1]:6379', $settings['address']);
        $this->assertSame('::1', $settings['host']);
        config()->set('database.redis.default.host', '[::1]');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unbracketed IPv6 literal');
        (new Transport)->settings();
    }

    #[DataProvider('clients')]
    public function test_observation_rejects_unknown_url_schemes_before_plaintext_connection(string $client): void
    {
        $this->selectClient($client);
        $source = config('database.redis.default');
        config()->set('database.redis.default', ['url' => 'https://'.$source['host'].':'.($source['port'] ?? 6379).'/0']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('supported Redis TCP/TLS URL scheme');
        ObservationRedis::connect('default');
    }

    #[DataProvider('clients')]
    public function test_explicit_url_tls_scheme_cannot_fall_back_to_plaintext(string $client): void
    {
        $this->selectClient($client);
        $source = config('database.redis.default');
        config()->set('database.redis.default', ['url' => 'redis://'.$source['host'].':'.($source['port'] ?? 6379).'/0?scheme=tls']);
        try {
            @ObservationRedis::connect('default')->ping();
            $this->fail('An explicit TLS connection succeeded against the plaintext fixture server.');
        } catch (\PHPUnit\Framework\AssertionFailedError $failure) { throw $failure; }
        catch (\Throwable) { $this->addToAssertionCount(1); }
    }

}

final class PredisFixtureJob implements ShouldQueue
{
    use Queueable;
    public function __construct(public string $label) {}
    public function handle(): void { Redis::hincrby('predis-effects', $this->label, 1); }
}

final class PredisUnserializableJob implements ShouldQueue
{
    public function __serialize(): array { throw new \RuntimeException('Synthetic serialization failure'); }
    public function handle(): void {}
}
