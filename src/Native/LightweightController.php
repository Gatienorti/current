<?php

namespace Current\Native;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Process\Process;

/** Runs the existing control algorithms without booting application providers again. */
final class LightweightController
{
    public function run(array $request, bool $probe = false): int
    {
        if (($request['version'] ?? null) !== 1 || ! is_array($request['reader'] ?? null)
            || ! is_array($request['configuration'] ?? null) || ! is_array($request['runtime'] ?? null)
            || ! is_array($request['pools'] ?? null) || ! is_string($request['directory'] ?? null)
            || ! str_starts_with($request['directory'], '/') || ! is_dir($request['directory'])) {
            throw new \RuntimeException('Invalid controller configuration.');
        }
        $runtime = $request['runtime'];
        $file = $request['runtime_file'] ?? null;
        if (! is_string($file) || dirname($file) !== ($runtime['state_directory'] ?? null)
            || ! preg_match('/^config-[a-zA-Z0-9]+$/D', basename($file)) || is_link($file)
            || ! is_file($file) || ! is_int($runtime['memory_limit_mb'] ?? null)
            || $runtime['memory_limit_mb'] < 16 || $runtime['memory_limit_mb'] > 65536
            || ! is_int($runtime['grace_seconds'] ?? null) || $runtime['grace_seconds'] < 1 || $runtime['grace_seconds'] > Plan::MAX_GRACE_SECONDS) { throw new \RuntimeException('Invalid controller state.'); }
        try {
            $app = new class($request['directory']) extends Container {
                public function __construct(private string $directory) {}
                public function basePath($path = '') { return $this->directory.($path !== '' ? '/'.$path : ''); }
            };
            Container::setInstance($app);
            $app->instance('events', new Dispatcher($app));
            $app->instance('config', new Repository($request['configuration']));
            Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
            $redis = new RedisManager($app, config('database.redis.client', 'phpredis'), config('database.redis'));
            $app->instance('redis', $redis);
            $queues = new QueueManager($app);
            $queues->addConnector('current-redis', fn () => new RedisConnector($redis));
            $app->instance('queue', $queues);
            $pools = (new Plan)->workerPools();
            if ($pools !== $request['pools']) { throw new \RuntimeException('Controller pool transfer failed.'); }
            if ($probe) { return 0; }

            $process = null; $stopping = false; $memoryExceeded = false;
            $sampler = new RuntimeSampler($request['reader']); $autoscaler = new Autoscaler; $registry = new Registry;
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () use (&$process, &$stopping) {
                    $stopping = true;
                    if ($process?->isRunning()) { $process->signal(SIGTERM); }
                });
            }
            try {
                if (memory_get_usage(true) >= $runtime['memory_limit_mb'] * 1048576) { return 1; }
                if ($stopping) { return 0; }
                $process = new Process([config('current.native.binary'), '-config', $file, '-parent-pid', (string) getmypid()], $request['directory']);
                $process->setTimeout(null)->start();
                if ($stopping) { $process->signal(SIGTERM); }
                while ($process->isRunning()) {
                    if (! $memoryExceeded && memory_get_usage(true) > $runtime['memory_limit_mb'] * 1048576) {
                        fwrite(STDERR, "Native launcher exceeded current.native.memory_limit; draining workers before restart.\n");
                        $memoryExceeded = true; $process->signal(SIGTERM); $sampler->stop();
                    }
                    $this->forward($process);
                    if (! $memoryExceeded) { $sampler->tick($pools); $autoscaler->tick(); }
                    $registry->tick();
                    usleep(200000);
                }
                $registry->tick(final: true); $this->forward($process);
                return $memoryExceeded ? 1 : ($process->getExitCode() ?? 1);
            } finally {
                $sampler->stop();
                if ($process?->isRunning()) { $process->stop($runtime['grace_seconds'] + 2); }
            }
        } finally {
            if (! $probe && is_file($file)) { unlink($file); }
        }
    }

    private function forward(Process $process): void
    {
        fwrite(STDOUT, $process->getIncrementalOutput()); $process->clearOutput();
        fwrite(STDERR, $process->getIncrementalErrorOutput()); $process->clearErrorOutput();
    }
}
