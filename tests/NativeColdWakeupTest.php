<?php

namespace Current\Tests;

use Current\Native\Autoscaler;
use Current\Native\Plan;
use Current\Native\RuntimeSnapshot;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;

final class NativeColdWakeupTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'auto cold missing runtime' => ['auto', 'missing', 'ready', 0, 0, 'accepted', [['hot', 1]]],
            'auto cold stale runtime' => ['auto', 'stale', 'ready', 0, 0, 'accepted', [['hot', 1]]],
            'shared time cold missing runtime' => ['time', 'missing', 'ready', 0, 0, 'accepted', [['hot', 1]]],
            'auto known idle frees probe budget' => ['auto', 'missing', 'ready', 0, 4, 'accepted', [['cold', 2], ['hot', 1]]],
            'auto rejected reduction keeps budget' => ['auto', 'missing', 'ready', 0, 4, 'rejected', [['cold', 2]]],
            'auto lost reduction keeps budget' => ['auto', 'missing', 'ready', 0, 4, 'lost', [['cold', 2]]],
            'auto missing runtime keeps existing worker' => ['auto', 'missing', 'ready', 1, 0, 'accepted', []],
            'auto measured zero uses Horizon demand fallback' => ['auto', 'zero', 'ready', 1, 0, 'accepted', [['hot', 3]]],
            'bounded measured zero retains busy demand' => ['bounded', 'zero', 'ready', 1, 0, 'accepted', [['hot', 3]]],
            'bounded unknown cold duration probes' => ['bounded', 'missing', 'ready', 0, 0, 'accepted', [['hot', 1]]],
            'shared time measured zero retains one consumer' => ['time', 'zero', 'ready', 1, 0, 'accepted', []],
            'auto due delayed wakes zero' => ['auto', 'missing', 'delayed-due', 0, 0, 'accepted', [['hot', 1]]],
            'auto slow cold consumer retains due work' => ['auto', 'missing', 'delayed-due', 1, 0, 'accepted', []],
            'auto expired reservation wakes zero' => ['auto', 'missing', 'reserved-due', 0, 0, 'accepted', [['hot', 1]]],
            'auto future delayed holds zero' => ['auto', 'missing', 'delayed-future', 0, 0, 'accepted', []],
            'auto future reservation holds zero' => ['auto', 'missing', 'reserved-future', 0, 0, 'accepted', []],
            'shared time due delayed wakes zero' => ['time', 'missing', 'delayed-due', 0, 0, 'accepted', [['hot', 1]]],
            'shared time slow cold consumer retains due work' => ['time', 'missing', 'delayed-due', 1, 0, 'accepted', []],
            'shared time future reservation holds zero' => ['time', 'missing', 'reserved-future', 0, 0, 'accepted', []],
        ];
    }

    public static function recoveryCases(): array
    {
        $cases = [];
        foreach (['phpredis', 'predis'] as $client) {
            foreach (['ready', 'delayed', 'reserved'] as $location) { $cases[$client.' '.$location] = [$location, $client]; }
        }
        return $cases;
    }

    #[DataProvider('recoveryCases')]
    public function test_real_native_owner_starts_a_cold_laravel_consumer(string $location, string $client): void
    {
        if ($client === 'predis' && ! class_exists(\Predis\Client::class)) {
            if (getenv('CURRENT_REQUIRE_PREDIS_TESTS') === '1') { $this->fail('Install optional Predis for required compatibility checks.'); }
            $this->markTestSkipped('Optional Predis is not installed.');
        }
        if ($client === 'phpredis' && ! extension_loaded('redis')) { $this->markTestSkipped('Optional phpredis is not installed.'); }
        $binary = dirname(__DIR__).'/supervisor/current-supervisor';
        if (! is_executable($binary)) {
            if (getenv('CURRENT_REQUIRE_SUPERVISOR_TESTS') === '1') { $this->fail('Build the native supervisor before required process checks.'); }
            $this->markTestSkipped('Requires the built Linux supervisor.');
        }
        $directory = sys_get_temp_dir().'/current-cold-real-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('database.redis.client', $client);
        app()->instance('redis', new \Illuminate\Redis\RedisManager(app(), $client, config('database.redis')));
        Redis::clearResolvedInstance('redis');
        config()->set('current.native', ['instance' => 'cold', 'state_directory' => $directory, 'worker_limit' => 1,
            'grace_seconds' => 5, 'pools' => ['cold' => ['connection' => 'redis', 'queues' => ['cold-real'],
                'workers' => 0, 'min_workers' => 0, 'max_workers' => 1, 'timeout' => 2, 'balance' => 'auto',
                'auto_scale_strategy' => 'time', 'balance_cooldown' => 1, 'balance_shift' => 1]]]);
        $queue = app('queue')->connection('redis');
        $queue->push((new \Current\Prototype\Jobs\PaymentJob('success'))->onQueue('cold-real'), '', 'cold-real');
        if ($location !== 'ready') {
            $payload = Redis::lpop('queues:cold-real');
            Redis::zadd('queues:cold-real:'.$location, time() - 1, $payload);
        }
        $settings = [];
        foreach (['database', 'queue', 'current', 'cache'] as $key) { $settings[$key] = config($key); }
        file_put_contents($directory.'/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
        $worker = '<?php require '.var_export(dirname(__DIR__).'/bootstrap.php', true).'; '
            .'$app=require '.var_export(dirname(__DIR__).'/prototype/bootstrap/app.php', true).'; '
            .'$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
            .'config()->set(json_decode(file_get_contents(__DIR__."/settings.json"),true)); '
            .'app("db")->purge("app"); app("db")->purge("lens"); '
            .'app()->instance("redis",new \\Illuminate\\Redis\\RedisManager(app(),config("database.redis.client"),config("database.redis"))); '
            .'\\Illuminate\\Support\\Facades\\Redis::clearResolvedInstance("redis"); '
            .'exit(\\Illuminate\\Support\\Facades\\Artisan::call("queue:work", ["connection"=>"redis","--queue"=>"cold-real","--sleep"=>1,"--timeout"=>2,"--tries"=>3,"--memory"=>128]));';
        file_put_contents($directory.'/worker.php', $worker);
        $pool = app(Plan::class)->workerPools()[0];
        $plan = ['version' => 1, 'instance' => 'cold', 'state_directory' => $directory, 'grace_seconds' => 5, 'worker_limit' => 1,
            'pools' => [['name' => $pool['name'], 'workers' => 0, 'min_workers' => 0, 'max_workers' => 1,
                'group' => $pool['group'], 'group_limit' => 1, 'queue' => 'cold-real', 'connection' => 'redis', 'queues' => ['cold-real'],
                'directory' => dirname(__DIR__), 'command' => [PHP_BINARY, $directory.'/worker.php']]]];
        file_put_contents($directory.'/plan.json', json_encode($plan));
        $process = new \Symfony\Component\Process\Process([$binary, '-config', $directory.'/plan.json']);
        $process->setTimeout(null); $process->start();
        $supervisor = app(\Current\Native\Supervisor::class);
        try {
            $deadline = microtime(true) + 4;
            do { $health = $supervisor->snapshot(); if ($health['available']) { break; } usleep(20000); } while (microtime(true) < $deadline && $process->isRunning());
            $this->assertTrue($health['available'], $process->getErrorOutput());
            $this->assertSame(0, $health['pools'][0]['workers']);
            $this->assertSame(0, $health['pools'][0]['running']);
            // No duration has ever been observed for this route.
            $this->assertNull(Redis::get(app(RuntimeSnapshot::class)->key('redis', 'cold-real')));
            (new Autoscaler)->tick();
            $deadline = microtime(true) + 6;
            do { $status = $this->store()->table('jobs')->where('queue', 'cold-real')->value('status'); if ($status === 'completed') { break; } usleep(20000); } while (microtime(true) < $deadline && $process->isRunning());
            $this->assertSame('completed', $status, $process->getErrorOutput());
            $this->assertSame(1, $this->store()->table('attempts')->count());
            $this->assertSame(0, Redis::llen('queues:cold-real'));
            $this->assertSame(0, Redis::zcard('queues:cold-real:delayed'));
            $this->assertSame(0, Redis::zcard('queues:cold-real:reserved'));
            $this->assertTrue($supervisor->control($health['owner'], 'stop', null));
            $this->assertSame(0, $process->wait());
        } finally {
            if ($process->isRunning()) { $process->stop(6, SIGKILL); }
            foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory);
        }
    }

    #[DataProvider('cases')]
    public function test_confirmed_cold_demand_wakes_without_inventing_runtime_or_capacity(string $balance, string $runtime, string $demand, int $hot, int $cold, string $replyMode, array $expected): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('Requires PCNTL and local Unix sockets.'); }
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        $directory = sys_get_temp_dir().'/current-cold-wake-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
        $bounded = $balance === 'bounded';
        if ($bounded) { $balance = 'auto'; }
        $common = ['connection' => 'redis', 'workers' => 0, 'min_workers' => 0, 'max_workers' => 4, 'timeout' => 2,
            'balance' => $balance, 'balance_cooldown' => 1, 'balance_shift' => 2];
        $pools = $balance === 'auto'
            ? ['shared' => $common + ['queues' => ['cold-wake-hot', 'cold-wake-cold'], 'auto_scale_strategy' => 'time', 'balance_policy' => $bounded ? 'bounded' : 'horizon']]
            : ['hot' => $common + ['queues' => ['cold-wake-hot']], 'cold' => $common + ['queues' => ['cold-wake-cold']]];
        config()->set('current.native', ['instance' => 'cold', 'state_directory' => $directory, 'worker_limit' => 4, 'grace_seconds' => 10, 'pools' => $pools]);
        if ($demand === 'ready') { Redis::rpush('queues:cold-wake-hot', 'fixture-one', 'fixture-two', 'fixture-three'); }
        else {
            [$type, $when] = explode('-', $demand);
            Redis::zadd('queues:cold-wake-hot:'.$type, time() + ($when === 'due' ? -1 : 3600), 'due-fixture');
            $this->assertSame(['cold-wake-hot' => 0], (new \Current\Native\QueueLoad)->counts('redis', ['cold-wake-hot']));
        }
        $snapshot = app(RuntimeSnapshot::class);
        $routes = ['redis' => ['cold-wake-hot', 'cold-wake-cold']];
        $snapshot->publish(['observed_at' => microtime(true), 'rows' => array_map(fn ($queue) => ['connection' => 'redis', 'queue' => $queue, 'runtime' => $runtime === 'zero' ? 0.0 : null], $routes['redis'])], $routes);
        if ($runtime === 'stale') { Redis::set($snapshot->key('redis', 'cold-wake-hot'), json_encode(['observed_at' => microtime(true) - 301, 'runtime' => .1])); }
        $health = []; $labels = [];
        foreach (app(Plan::class)->workerPools() as $pool) {
            $label = $pool['queues'][0] === 'cold-wake-hot' ? 'hot' : 'cold'; $workers = $label === 'hot' ? $hot : $cold;
            $labels[$pool['name']] = $label;
            $health[] = ['name' => $pool['name'], 'connection' => 'redis', 'queues' => $pool['queues'], 'state' => 'running',
                'workers' => $workers, 'min_workers' => 0, 'max_workers' => 4, 'group' => $pool['group'] ?? '',
                'group_limit' => $pool['group_limit'] ?? 0, 'queue' => isset($pool['group']) ? $pool['queues'][0] : '',
                'slots' => array_fill(0, $workers, ['state' => 'running', 'pid' => 123, 'starts' => 1])];
        }
        file_put_contents($directory.'/cold.json', json_encode(['version' => 1, 'instance' => 'cold', 'owner' => str_repeat('a', 32),
            'state' => 'running', 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'pools' => $health]));
        $server = stream_socket_server('unix://'.$directory.'/cold.sock'); $this->assertIsResource($server);
        $pid = pcntl_fork(); $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $requests = [];
            while (count($requests) < 8 && ($connection = @stream_socket_accept($server, .25))) {
                stream_set_timeout($connection, 1); $requests[] = json_decode(fgets($connection, 4097), true);
                if ($replyMode !== 'lost') { fwrite($connection, json_encode(['accepted' => $replyMode === 'accepted'])."\n"); }
                fclose($connection);
            }
            file_put_contents($directory.'/requests.json', json_encode($requests)); fclose($server); exit(0);
        }
        try {
            (new Autoscaler)->tick(); pcntl_waitpid($pid, $status); $pid = null;
            $this->assertSame(0, pcntl_wexitstatus($status));
            $requests = json_decode(file_get_contents($directory.'/requests.json'), true);
            $this->assertSame($expected, array_map(fn ($request) => [$labels[$request['pool']], $request['workers']], $requests));
            foreach ($requests as $request) { $this->assertSame(str_repeat('a', 32), $request['owner']); $this->assertSame('scale', $request['action']); }
            // Probe planning must never synthesize a duration observation.
            $raw = json_decode(Redis::get($snapshot->key('redis', 'cold-wake-hot')), true);
            $this->assertSame($runtime === 'zero' ? 0 : ($runtime === 'stale' ? .1 : null), $raw['runtime']);
        } finally {
            if ($pid) { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
            fclose($server); foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory);
        }
    }
}
