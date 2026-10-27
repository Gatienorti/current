<?php
// Disposable application only. Current is loaded solely by the explicit history acceptance mode.
$loader = require getenv('EXPERIMENT_AUTOLOAD') ?: '/opt/vehify/vendor/autoload.php';
$history = getenv('CURRENT_SUPERVISOR_HISTORY') === '1';
if ($history) {
    if (getenv('CURRENT_SUPERVISOR_NATIVE') !== '1') { throw new RuntimeException('History acceptance requires native mode'); }
    $loader->addPsr4('Current\\', dirname(__DIR__, 3).'/src/');
    require_once __DIR__.'/NativeRedisQueue.php';
}
require_once __DIR__.'/FixtureJob.php';
$root = getenv('EXPERIMENT_ROOT');
if (! $root || ! preg_match('#^/tmp/current-supervisor-[a-f0-9]{12}$#D', $root)) { throw new RuntimeException('Disposable experiment root required'); }
$app = Illuminate\Foundation\Application::configure(basePath: $root)
    ->withRouting(using: function () {})
    ->withMiddleware()
    ->withExceptions()
    ->withProviders($history ? [Current\CurrentServiceProvider::class] : (getenv('CURRENT_SUPERVISOR_NATIVE') === '1' ? [] : [Laravel\Horizon\HorizonServiceProvider::class]))
    ->create();
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($history) {
    $app['queue']->addConnector('current-experiment', fn () => new class($app['redis']) extends Illuminate\Queue\Connectors\RedisConnector {
        public function connect(array $config) {
            return new SupervisorExperiment\NativeRedisQueue($this->redis, $config['queue'], $config['connection'], $config['retry_after'], $config['block_for'], $config['after_commit']);
        }
    });
}
// Match --quiet on plain Laravel workers; avoid measuring console formatting differences.
if (getenv('CURRENT_SUPERVISOR_NATIVE') !== '1') {
    Laravel\Horizon\WorkerCommandString::$command = 'exec @php artisan horizon:work --quiet';
} else {
    if (get_class(Illuminate\Support\Facades\Queue::connection()) !== ($history ? Current\Native\RedisQueue::class : Illuminate\Queue\RedisQueue::class) || isset($app->getLoadedProviders()[Laravel\Horizon\HorizonServiceProvider::class])) {
        throw new RuntimeException('Native experiment must not load Horizon provider or connector');
    }
}
Illuminate\Support\Facades\Queue::looping(function () {
    Illuminate\Support\Facades\Redis::hset('workers', (string) getmypid(), microtime(true));
});
return $app;
