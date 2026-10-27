<?php

namespace Current\Tests;

use Current\Native\Autoscaler;
use Illuminate\Support\Facades\Redis;

final class NativeAutoscalerTest extends TestCase
{
    public function test_pool_reallocation_respects_the_instance_budget_and_control_confirmation(): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('Requires local Unix socket and pcntl.'); }
        config()->set('current.execution_backend', 'native');
        foreach ([['accepted', 'size'], ['rejected', 'size'], ['lost', 'size'], ['accepted', 'time']] as [$replyMode, $strategy]) {
            $directory = sys_get_temp_dir().'/current-scale-'.bin2hex(random_bytes(6));
            mkdir($directory, 0700);
            config()->set('queue.connections.redis.driver', 'current-redis');
            config()->set('current.native', ['state_directory' => $directory, 'instance' => 'test', 'worker_limit' => 4, 'grace_seconds' => 10,
                'pools' => [
                    'hot' => ['connection' => 'redis', 'queues' => ['hot'], 'workers' => 1, 'max_workers' => 4, 'timeout' => 2, 'balance' => $strategy, 'target_clear_seconds' => 100, 'balance_shift' => 2],
                    'cold' => ['connection' => 'redis', 'queues' => ['cold'], 'workers' => 3, 'max_workers' => 4, 'timeout' => 2, 'balance' => $strategy, 'target_clear_seconds' => 100, 'balance_shift' => 2],
                ]]);
            if ($strategy === 'time') {
                dispatch(new \Current\Prototype\Jobs\PaymentJob('success'));
                $this->work();
                $job = $this->store()->table('jobs')->sole();
                $end = microtime(true) - 1;
                $this->store()->table('jobs')->where('id', $job->id)->update(['queue' => 'hot']);
                $this->store()->table('attempts')->where('job_id', $job->id)->update(['started_at' => $end - 10, 'finished_at' => $end]);
            }
            $snapshot = app(\Current\Native\RuntimeSnapshot::class);
            $routes = ['redis' => ['hot', 'cold']];
            $snapshot->publish($snapshot->sample($routes), $routes);
            Redis::del('queues:hot', 'queues:cold');
            Redis::rpush('queues:hot', ...array_fill(0, 30, 'fixture'));
            $pools = [];
            foreach (['hot' => 1, 'cold' => 3] as $name => $workers) {
                $pools[] = ['name' => $name, 'state' => 'running', 'workers' => $workers, 'min_workers' => 1, 'max_workers' => 4,
                    'slots' => array_fill(0, $workers, ['state' => 'running', 'pid' => 123, 'starts' => 1])];
            }
            file_put_contents($directory.'/test.json', json_encode(['version' => 1, 'instance' => 'test', 'owner' => str_repeat('a', 32),
                'state' => 'running', 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'pools' => $pools]));
            $server = stream_socket_server('unix://'.$directory.'/test.sock');
            $this->assertNotFalse($server);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                $requests = [];
                for ($i = 0; $i < ($replyMode === 'accepted' ? 2 : 1); $i++) {
                    $connection = @stream_socket_accept($server, 3);
                    if (! $connection) { exit(2); }
                    stream_set_timeout($connection, 2);
                    $requests[] = json_decode(fgets($connection, 4097), true);
                    if ($replyMode !== 'lost') { fwrite($connection, json_encode(['accepted' => $replyMode === 'accepted'])."\n"); }
                    fclose($connection);
                }
                file_put_contents($directory.'/requests.json', json_encode($requests));
                fclose($server);
                exit(0);
            }
            try {
                (new Autoscaler)->tick();
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
                $requests = json_decode(file_get_contents($directory.'/requests.json'), true);
                $this->assertSame('cold', $requests[0]['pool']);
                $this->assertSame(1, $requests[0]['workers']);
                $this->assertCount($replyMode === 'accepted' ? 2 : 1, $requests);
                if ($replyMode === 'accepted') {
                    $this->assertSame('hot', $requests[1]['pool']);
                    $this->assertSame(3, $requests[1]['workers']);
                }
            } finally {
                fclose($server);
                foreach (glob($directory.'/*') as $path) { unlink($path); }
                rmdir($directory);
            }
        }
    }
    public function test_group_strategies_reallocate_by_queue_and_hold_on_missing_evidence(): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('Requires Unix sockets and pcntl.'); }
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        foreach (['size', 'log', 'time', 'missing-time', 'unavailable', 'paused'] as $case) {
            $directory = sys_get_temp_dir().'/current-group-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
            $strategy = $case === 'missing-time' ? 'time' : (in_array($case, ['paused', 'unavailable'], true) ? 'size' : $case);
            config()->set('current.native', ['state_directory' => $directory, 'instance' => 'test', 'worker_limit' => 20, 'grace_seconds' => 10,
                'pools' => ['shared' => ['queues' => ['hot', 'cold'], 'workers' => 10, 'min_workers' => 1, 'max_workers' => 10,
                    'timeout' => 2, 'balance' => 'auto', 'auto_scale_strategy' => $strategy, 'balance_shift' => 3]]]);
            if ($case === 'time') {
                foreach (['hot' => 0.1, 'cold' => 10] as $q => $duration) {
                    dispatch((new \Current\Prototype\Jobs\PaymentJob('success'))->onQueue($q));
                    \Illuminate\Support\Facades\Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $q, '--once' => true]);
                    $id = $this->store()->table('jobs')->where('queue', $q)->value('id'); $end = microtime(true) - 1;
                    $this->store()->table('attempts')->where('job_id', $id)->update(['started_at' => $end - $duration, 'finished_at' => $end]);
                }
            }
            if ($case === 'missing-time') { $this->store()->table('attempts')->update(['finished_at' => null]); }
            $snapshot = app(\Current\Native\RuntimeSnapshot::class);
            $routes = ['redis' => ['hot', 'cold']];
            $snapshot->publish($snapshot->sample($routes), $routes);
            Redis::del('queues:hot', 'queues:cold');
            Redis::rpush('queues:hot', ...array_fill(0, 300, 'fixture'));
            Redis::rpush('queues:cold', ...array_fill(0, 10, 'fixture'));
            if ($case === 'unavailable') { Redis::del('queues:cold'); Redis::set('queues:cold', 'wrong-type'); }
            $pools = []; $names = [];
            foreach (app(\Current\Native\Plan::class)->workerPools() as $p) {
                $names[$p['queues'][0]] = $p['name'];
                $pools[] = ['name' => $p['name'], 'state' => $case === 'paused' && $p['queues'][0] === 'cold' ? 'paused' : 'running',
                    'workers' => 5, 'min_workers' => 1, 'max_workers' => 9, 'group' => 'shared', 'group_limit' => 10, 'queue' => $p['queues'][0],
                    'slots' => array_fill(0, 5, ['state' => 'running', 'pid' => 123, 'starts' => 1])];
            }
            file_put_contents($directory.'/test.json', json_encode(['version' => 1, 'instance' => 'test', 'owner' => str_repeat('a', 32),
                'state' => 'running', 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'pools' => $pools]));
            $server = stream_socket_server('unix://'.$directory.'/test.sock'); $pid = pcntl_fork();
            $this->assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                $requests = [];
                while ($connection = @stream_socket_accept($server, 1)) {
                    stream_set_timeout($connection, 1); $requests[] = json_decode(fgets($connection, 4097), true);
                    fwrite($connection, "{\"accepted\":true}\n"); fclose($connection);
                }
                file_put_contents($directory.'/requests.json', json_encode($requests)); fclose($server); exit(0);
            }
            try {
                (new Autoscaler)->tick(); pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
                $requests = json_decode(file_get_contents($directory.'/requests.json'), true);
                $expected = match ($case) {
                    'size' => [['cold', 2], ['hot', 8]],
                    'log' => [['cold', 3], ['hot', 7]],
                    'time' => [['hot', 2], ['cold', 8]],
                    default => [],
                };
                $this->assertSame(array_map(fn ($r) => [$names[$r[0]], $r[1]], $expected), array_map(fn ($r) => [$r['pool'], $r['workers']], $requests), $case);
            } finally {
                fclose($server); foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory);
                Redis::del('queues:hot', 'queues:cold');
            }
        }
    }

}
