<?php

namespace Current\Native;

use Current\Store;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Queue\QueueManager;
use Illuminate\Redis\RedisManager;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/** Optional CLI handoff; uncertainty retains the fully booted Laravel launcher. */
final class LightweightLauncher
{
    public const MAX_BYTES = 8388608;

    public function prepare(InputInterface $input, array $runtime, string $runtimeFile): ?array
    {
        try {
            if (config('current.native.lightweight_launcher', true) !== true || PHP_OS_FAMILY !== 'Linux'
                || ! function_exists('pcntl_exec') || ! function_exists('posix_geteuid')
                || ! $input instanceof ArgvInput || $input->getOption('env') !== null) { return null; }
            // A new interpreter would lose explicit -d/-c/-n startup options.
            $argv = explode("\0", file_get_contents('/proc/self/cmdline'));
            $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
            if (($argv[2] ?? null) !== 'current:supervise' || $script === false || realpath($argv[1] ?? '') !== $script || str_starts_with($argv[1] ?? '', '-')) { return null; }
            $request = $this->configuration($runtime, $runtimeFile);
            if ($request === null || strlen($encoded = self::encode($request)) > 262144) { return null; }
            $probe = new \Symfony\Component\Process\Process([config('current.native.binary'), '-runtime-sample', PHP_BINARY, __DIR__.'/controller.php'], base_path());
            $probe->setInput($encoded)->setTimeout(6)->run();
            return $probe->isSuccessful() && $probe->getOutput() === "ready\n" ? $request : null;
        } catch (Throwable) { return null; }
    }

    public function configuration(array $runtime, string $runtimeFile): ?array
    {
        try {
            foreach ([Plan::class, Autoscaler::class, QueueLoad::class, RuntimeEstimate::class, Registry::class, Supervisor::class, RuntimeSnapshot::class, Store::class] as $class) {
                if (app($class)::class !== $class) { return null; }
            }
            if (app('queue')::class !== QueueManager::class || app('redis')::class !== RedisManager::class
                || app('events')::class !== \Illuminate\Events\Dispatcher::class) { return null; }
            if ((new \ReflectionProperty(RedisManager::class, 'customCreators'))->getValue(app('redis')) !== []
                || ! $this->transferableLifecycle()) { return null; }
            $reader = app(RuntimeSnapshot::class)->readerSource();
            if ($reader === null) { return null; }
            $pools = app(Plan::class)->workerPools(); $connections = []; $redisNames = [];
            foreach ($pools as $pool) {
                if (app('queue')->connection($pool['connection'])::class !== RedisQueue::class) { return null; }
                $connections[$pool['connection']] = config('queue.connections.'.$pool['connection']);
                $redisNames[] = $connections[$pool['connection']]['connection'] ?? 'default';
            }
            $redisNames[] = config('current.native.registry.redis_connection', 'default');
            $redis = ['client' => config('database.redis.client', 'phpredis'), 'options' => config('database.redis.options', [])];
            if (! ObservationRedis::supportedClient()) { return null; }
            foreach (array_unique($redisNames) as $name) {
                if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name)) { return null; }
                $redis[$name] = config('database.redis.'.$name);
                if (! is_string($redis[$name]) && ! is_array($redis[$name])) { return null; }
            }
            $history = config('current.connection');
            $configuration = [
                'current' => ['execution_backend' => 'native', 'connection' => $history, 'native' => config('current.native')],
                'database' => ['redis' => $redis, 'connections' => [$history => config('database.connections.'.$history)]],
                'queue' => ['default' => array_key_first($connections), 'connections' => $connections],
            ];
            $configuration['current']['native']['environment'] ??= app()->environment();
            $request = ['version' => 1, 'configuration' => $configuration, 'reader' => $reader, 'runtime' => $runtime,
                'runtime_file' => $runtimeFile, 'directory' => base_path(), 'pools' => $pools,
                'autoload' => dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2).'/autoload.php'];
            self::encode($request);
            return $request;
        } catch (Throwable) { return null; }
    }

    private function transferableLifecycle(): bool
    {
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        if ((new \ReflectionMethod($kernel, 'terminate'))->getDeclaringClass()->getName() !== \Illuminate\Foundation\Console\Kernel::class
            || (new \ReflectionProperty($kernel, 'commandLifecycleDurationHandlers'))->getValue($kernel) !== []
            || (new \ReflectionMethod(app(), 'terminate'))->getDeclaringClass()->getName() !== \Illuminate\Foundation\Application::class
            || app('events')->hasListeners(\Illuminate\Foundation\Events\Terminating::class)) { return false; }
        // These built-in view hooks only clear memory that exec discards anyway.
        foreach ((new \ReflectionProperty(app(), 'terminatingCallbacks'))->getValue(app()) as $callback) {
            if (! $this->definedWithin($callback, \Illuminate\View\ViewServiceProvider::class, ['register', 'registerFactory', 'registerBladeEngine'])) { return false; }
        }
        $deferred = \Illuminate\Support\Defer\DeferredCallbackCollection::class;
        if (class_exists($deferred) && count(app($deferred)) !== 0) { return false; }
        // Wildcards can observe command completion; never silently discard them.
        if ((new \ReflectionProperty(app('events'), 'wildcards'))->getValue(app('events')) !== []) { return false; }
        foreach (app('events')->getRawListeners()[CommandFinished::class] ?? [] as $callback) {
            if ($this->definedWithin($callback, \Current\CurrentServiceProvider::class, ['boot'])) { continue; }
            if ($this->definedWithin($callback, \Illuminate\Foundation\Providers\FoundationServiceProvider::class, ['registerDeferHandler'])) { continue; }
            return false;
        }
        return true;
    }

    private function definedWithin(mixed $callback, string $class, array $methods): bool
    {
        if (! $callback instanceof \Closure) { return false; }
        $reflection = new \ReflectionFunction($callback);
        if ($reflection->getClosureScopeClass()?->getName() !== $class) { return false; }
        foreach ($methods as $method) {
            if (! method_exists($class, $method)) { continue; }
            $origin = new \ReflectionMethod($class, $method);
            if ($reflection->getFileName() === $origin->getFileName() && $reflection->getStartLine() >= $origin->getStartLine()
                && $reflection->getEndLine() <= $origin->getEndLine()) { return true; }
        }
        return false;
    }

    public static function encode(array $request): string
    {
        $check = static function ($value, int $depth = 0) use (&$check): void {
            if ($depth > 48) { throw new \RuntimeException('Controller configuration exceeds bounds.'); }
            if (is_array($value)) { foreach ($value as $item) { $check($item, $depth + 1); } }
            elseif (! is_null($value) && ! is_string($value) && ! is_int($value) && ! is_bool($value) && !(is_float($value) && is_finite($value))) {
                throw new \RuntimeException('Controller configuration is not transferable.');
            }
        };
        $check($request);
        $encoded = json_encode($request, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) > self::MAX_BYTES || json_decode($encoded, true, 64, JSON_THROW_ON_ERROR) !== $request) {
            throw new \RuntimeException('Controller configuration exceeds transfer bounds.');
        }
        return $encoded;
    }

    /** Returns only if exec fails; the caller then uses the original Laravel loop. */
    public function replace(array $request): void
    {
        $path = null;
        try {
            $encoded = self::encode($request);
            $directory = $request['runtime']['state_directory'];
            $path = tempnam($directory, 'controller-');
            if ($path === false || dirname($path) !== realpath($directory) || ! chmod($path, 0600)
                || file_put_contents($path, $encoded) !== strlen($encoded)) { return; }
            // Match the environment Symfony would pass to the original child,
            // including Dotenv values stored in $_ENV rather than putenv().
            $process = new \Symfony\Component\Process\Process([PHP_BINARY]);
            $environment = (new \ReflectionMethod($process, 'getDefaultEnv'))->invoke($process);
            foreach ($environment as $key => $value) {
                if ($value === false) { unset($environment[$key]); continue; }
                if (! is_string($key) || str_contains($key, '=') || str_contains($key, "\0")
                    || (! is_scalar($value) && $value !== null) || str_contains((string) $value, "\0")) { return; }
                $environment[$key] = (string) $value;
            }
            @pcntl_exec(PHP_BINARY, [__DIR__.'/controller.php', $path], $environment);
        } catch (Throwable) {
            // No credentials or connection errors on the fallback path.
        } finally {
            if ($path && is_file($path)) { unlink($path); }
        }
    }
}
