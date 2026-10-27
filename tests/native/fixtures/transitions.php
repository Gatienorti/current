<?php
// Stopped-producer telemetry transitions in one disposable app/history store.
if (getenv('CURRENT_SUPERVISOR_EXPERIMENT') !== '1' || ! preg_match('~^/tmp/current-supervisor-[a-f0-9]{12}$~D', getenv('EXPERIMENT_ROOT') ?: '')) { exit(2); }
foreach (['CURRENT_SUPERVISOR_NATIVE', 'CURRENT_SUPERVISOR_HISTORY'] as $flag) { putenv($flag.'=1'); $_ENV[$flag] = $_SERVER[$flag] = '1'; }
putenv('APP_ENV=local'); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';
$_ENV['EXPERIMENT_ROOT'] = $_SERVER['EXPERIMENT_ROOT'] = getenv('EXPERIMENT_ROOT');
$app = require __DIR__.'/boot.php';
set_exception_handler(function (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
use Illuminate\Support\Facades\{Artisan, Queue, Redis};
use SupervisorExperiment\FixtureJob;
use Symfony\Component\Process\Process;
if (($argv[1] ?? '') === 'produce') {
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $id = $argv[2].'-'.$i; Queue::push(new FixtureJob($id), '', 'transition');
        $ids[$id] = Queue::connection()->lastEnqueued['uuid'];
    }
    echo json_encode($ids, JSON_THROW_ON_ERROR); exit(0);
}
$root = getenv('EXPERIMENT_ROOT'); $original = file_get_contents($root.'/config/current.php');
$config = require $root.'/config/current.php';
$config['native'] = ['state_directory' => $root.'/transition-state', 'instance' => 'transition', 'binary' => dirname(__DIR__, 3).'/supervisor/current-supervisor',
    'grace_seconds' => 5, 'worker_limit' => 1, 'pools' => ['transition' => ['connection' => 'redis', 'queues' => ['transition'], 'workers' => 1,
        'min_workers' => 1, 'max_workers' => 1, 'timeout' => 3, 'memory' => 256, 'sleep' => 0, 'tries' => 3]]];
$store = app(Current\Store::class); $before = $store->table('jobs')->count(); $process = $collector = null; $results = [];
function transitionEnsure(bool $ok, string $label): void { if (! $ok) { throw new RuntimeException('Transition check: '.$label); } }
function transitionWait(callable $condition, string $label): void {
    global $process, $collector;
    $end = microtime(true) + 20;
    do {
        foreach ([$process, $collector] as $p) { transitionEnsure(! $p || $p->isRunning(), 'process exited: '.($p?->getOutput() ?? '').($p?->getErrorOutput() ?? '')); }
        if ($condition()) { return; } usleep(20000);
    } while (microtime(true) < $end);
    throw new RuntimeException('Transition timeout: '.$label);
}
function transitionMode(string $driver): void {
    global $config, $root;
    $config['telemetry']['driver'] = $driver;
    config()->set('current', $config);
    file_put_contents($root.'/config/current.php', '<?php return '.var_export($config, true).';');
}
function transitionLaunch(string $command): Process {
    global $root;
    $p = new Process([PHP_BINARY, $root.'/artisan', $command], $root); $p->setTimeout(null); $p->start(); return $p;
}
function transitionCollector(): void {
    global $collector;
    $collector = transitionLaunch('current:collect');
    transitionWait(fn () => app(Current\Telemetry\Health::class)->snapshot()['running'] ?? false, 'collector ready');
}
function transitionProducer(string $label): array {
    global $root;
    $p = new Process([PHP_BINARY, __FILE__, 'produce', $label], $root); $p->setTimeout(20); $p->run();
    transitionEnsure($p->isSuccessful(), 'producer failed: '.$p->getErrorOutput());
    return json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}
function transitionDrain(): void {
    $r = app(Current\Telemetry\Transport::class)->client();
    transitionWait(fn () => $r->xLen('events') + $r->xLen('events:v2') === 0, 'complete telemetry drain');
}
function transitionCollectorStop(): void {
    global $collector;
    transitionDrain(); $collector->signal(SIGTERM);
    transitionEnsure($collector->wait() === 0, 'collector stopped cleanly'); $collector = null;
}
try {
    foreach (['sync-to-redis', 'redis-to-sync'] as $direction) {
        $enqueue = $direction === 'sync-to-redis' ? 'sync' : 'redis';
        $execute = $enqueue === 'sync' ? 'redis' : 'sync';
        transitionMode($enqueue);
        if ($enqueue === 'redis') { transitionCollector(); }
        $ids = transitionProducer('transition-'.bin2hex(random_bytes(4)));
        transitionWait(fn () => $store->table('jobs')->whereIn('external_id', array_values($ids))->count() === 3, 'enqueues persisted');
        $captured = $store->table('jobs')->whereIn('external_id', array_values($ids))->get()->keyBy('external_id');
        foreach ($captured as $job) { transitionEnsure($job->coverage === 'observed_enqueue' && $job->attempt_count === 0, 'pending observation'); }
        if ($collector) { transitionCollectorStop(); }
        transitionMode($execute);
        if ($execute === 'redis') { transitionCollector(); }
        $process = transitionLaunch('current:supervise');
        transitionWait(fn () => (app(Current\Native\Supervisor::class)->snapshot()['state'] ?? '') === 'running', 'workers ready');
        transitionWait(fn () => $store->table('jobs')->whereIn('external_id', array_values($ids))->where('status', 'completed')->count() === 3, 'completed histories');
        foreach ($ids as $label => $uuid) {
            transitionEnsure((int) Redis::hget('completed', $label) === 1, 'one effect');
            transitionEnsure((int) Redis::hget('attempts', $label) === 1, 'one invocation');
            $rows = $store->table('jobs')->where('external_id', $uuid)->get();
            transitionEnsure($rows->count() === 1 && $rows[0]->id === $captured[$uuid]->id, 'canonical history preserved');
            transitionEnsure($rows[0]->first_observed_at === $captured[$uuid]->first_observed_at && $rows[0]->coverage === 'observed_enqueue', 'original enqueue evidence preserved');
        }
        transitionEnsure(Artisan::call('current:terminate', ['--instance' => 'transition', '--wait' => '8']) === 0, 'shutdown accepted and confirmed');
        transitionEnsure($process->wait() === 0, 'workers stopped'); $process = null;
        if ($collector) { transitionCollectorStop(); }
        $results[$direction] = ['jobs' => 3, 'canonical_id_preserved' => true, 'exact_effects' => true];
    }
    transitionEnsure($store->table('jobs')->count() === $before + 6, 'no additional dispatch histories');
    echo json_encode(['accepted' => true, 'checks' => $results], JSON_PRETTY_PRINT)."\n";
} finally {
    foreach ([$process, $collector] as $p) { if ($p && $p->isRunning()) { $p->stop(10, SIGTERM); } }
    file_put_contents($root.'/config/current.php', $original);
}
