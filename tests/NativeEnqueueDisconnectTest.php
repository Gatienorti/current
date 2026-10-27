<?php

namespace Current\Tests;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;

final class NativeEnqueueDisconnectTest extends TestCase
{
    public function test_lost_exec_reply_does_not_fabricate_enqueue_and_workers_can_recover_history(): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('Requires the Linux process integration environment.'); }
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        $source = config('database.redis.default');
        $prefix = config('database.redis.options.prefix');
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($server, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $marker = tempnam(sys_get_temp_dir(), 'current-exec-ack-'); unlink($marker);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            // Test-only TCP relay: forward the transaction, confirm its two queue
            // entries using an independent connection, then discard its reply.
            $client = stream_socket_accept($server, 5);
            $upstream = stream_socket_client('tcp://'.$source['host'].':'.($source['port'] ?? 6379), $errno, $error, 2);
            if (! $client || ! $upstream) { exit(2); }
            $seen = ''; $end = microtime(true) + 8;
            while (microtime(true) < $end) {
                $read = [$client, $upstream]; $write = $except = [];
                if (! stream_select($read, $write, $except, 1)) { continue; }
                foreach ($read as $socket) {
                    $bytes = fread($socket, 65536);
                    if ($bytes === '' || $bytes === false) { exit(3); }
                    if ($socket === $client) {
                        $seen .= $bytes;
                        for ($offset = 0; $offset < strlen($bytes);) {
                            $written = fwrite($upstream, substr($bytes, $offset));
                            if (! $written) { exit(4); } $offset += $written;
                        }
                        if (str_contains(strtoupper($seen), "*1\r\n$4\r\nEXEC\r\n")) {
                            $probe = new \Redis;
                            $probe->connect($source['host'], $source['port'] ?? 6379, 1);
                            if (! empty($source['password'])) { $probe->auth($source['password']); }
                            $probe->select($source['database'] ?? 0);
                            $deadline = microtime(true) + 2;
                            do { $count = $probe->lLen($prefix.'queues:lens-demo'); if ($count === 2) { break; } usleep(5000); } while (microtime(true) < $deadline);
                            if ($count !== 2) { exit(5); }
                            file_put_contents($marker, 'committed');
                            fclose($client); fclose($upstream); fclose($server); exit(0);
                        }
                    } else {
                        fwrite($client, $bytes);
                    }
                }
            }
            exit(6);
        }
        fclose($server);
        try {
            $redisConfig = config('database.redis');
            $redisConfig['default'] = array_replace($source, ['host' => '127.0.0.1', 'port' => $port, 'read_timeout' => 3, 'max_retries' => 0]);
            $manager = new \Illuminate\Redis\RedisManager(app(), 'phpredis', $redisConfig);
            $queue = new \Current\Native\RedisQueue($manager, 'lens-demo', 'default', 5, null, false);
            $queue->setContainer(app()); $queue->setConnectionName('redis');
            try {
                $queue->bulk([new LostAckJob('one'), new LostAckJob('two')], '', 'lens-demo');
            } catch (\RedisException) { /* phpredis may return false or throw after EOF. */ }
            pcntl_waitpid($pid, $status); $pid = null;
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame('committed', file_get_contents($marker));
            $this->assertNull($queue->lastEnqueued);
            $this->assertSame(0, $this->store()->table('jobs')->count());
            $this->assertSame(2, Redis::llen('queues:lens-demo'));
            $this->work(2);
            $this->assertSame(['one' => '1', 'two' => '1'], Redis::hgetall('lost-ack-effects'));
            $this->assertSame(2, $this->store()->table('jobs')->where('status', 'completed')->count());
            $this->assertSame(0, $this->store()->table('jobs')->where('coverage', 'observed_enqueue')->count());
        } finally {
            if ($pid) { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
            @unlink($marker);
        }
    }
}

final class LostAckJob implements ShouldQueue
{
    use Queueable;
    public function __construct(public string $label) {}
    public function handle(): void { Redis::hincrby('lost-ack-effects', $this->label, 1); }
}
