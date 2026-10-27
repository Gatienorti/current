<?php
// Runs only inside the disposable consumer produced by history.php.
if (getenv('CURRENT_SUPERVISOR_EXPERIMENT') !== '1' || ! preg_match('~^/tmp/current-supervisor-[a-f0-9]{12}$~D', getenv('EXPERIMENT_ROOT') ?: '')) { exit(2); }
foreach (['CURRENT_SUPERVISOR_NATIVE', 'CURRENT_SUPERVISOR_HISTORY'] as $flag) { putenv($flag.'=1'); $_ENV[$flag] = $_SERVER[$flag] = '1'; }
putenv('APP_ENV=local'); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';
$_ENV['EXPERIMENT_ROOT'] = $_SERVER['EXPERIMENT_ROOT'] = getenv('EXPERIMENT_ROOT');
$app = require __DIR__.'/boot.php';
set_exception_handler(function (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use SupervisorExperiment\FixtureJob;
use Symfony\Component\Process\Process;

$root = getenv('EXPERIMENT_ROOT'); $original = file_get_contents($root.'/config/current.php');
$config = require $root.'/config/current.php';
$config['native'] = ['state_directory' => $root.'/balance-state', 'instance' => 'balance', 'binary' => dirname(__DIR__, 3).'/supervisor/current-supervisor',
    'grace_seconds' => 10, 'worker_limit' => 6, 'pools' => ['shared' => ['queues' => ['balance-hot', 'balance-cold'], 'workers' => 2, 'min_workers' => 1,
        'max_workers' => 6, 'timeout' => 3, 'balance' => 'auto', 'auto_scale_strategy' => 'size', 'balance_shift' => 3, 'balance_cooldown' => 1]]];
file_put_contents($root.'/config/current.php', '<?php return '.var_export($config, true).';');
config()->set('current.native', $config['native']);
$supervisor = app(Current\Native\Supervisor::class); $process = null; $collector = null; $peak = 0;
$store = app(Current\Store::class); $before = $store->table('jobs')->count();
function balanceWait(callable $condition, string $label, float $seconds = 30): void {
    global $process, $collector, $supervisor, $peak;
    $end = microtime(true) + $seconds;
    do {
        foreach ([$process, $collector] as $p) { if ($p && ! $p->isRunning()) { throw new RuntimeException('Process exited: '.$p->getOutput().$p->getErrorOutput()); } }
        $health = $supervisor->snapshot();
        if ($health['available']) {
            $running = array_sum(array_column($health['pools'], 'running')); $allocated = array_sum(array_column($health['pools'], 'workers'));
            if ($running > 6 || $allocated > 6) { throw new RuntimeException('Shared capacity exceeded'); }
            $peak = max($peak, $running);
        }
        if ($condition($health)) { return; }
        usleep(20000);
    } while (microtime(true) < $end);
    throw new RuntimeException('Timeout '.$label);
}
try {
    if (config('current.telemetry.driver') === 'redis') {
        $collector = new Process([PHP_BINARY, $root.'/artisan', 'current:collect'], $root); $collector->setTimeout(null); $collector->start();
    }
    $process = new Process([PHP_BINARY, $root.'/artisan', 'current:supervise'], $root); $process->setTimeout(null); $process->start();
    balanceWait(fn ($h) => $h['available'] && $h['state'] === 'running', 'initial workers');
    foreach (['hot', 'cold'] as $direction) {
        for ($i = 0; $i < 80; $i++) { Queue::push(new FixtureJob('balance-'.$direction.'-'.$i, 200000), '', 'balance-'.$direction); }
        balanceWait(function ($h) use ($direction) {
            foreach ($h['pools'] as $p) { if ($p['queue'] === 'balance-'.$direction && $p['running'] >= 4) { return true; } }
            return false;
        }, 'scale toward '.$direction);
        balanceWait(function () use ($direction) {
            for ($i = 0; $i < 80; $i++) { if ((int) Redis::hget('completed', 'balance-'.$direction.'-'.$i) !== 1) { return false; } }
            return true;
        }, 'finish '.$direction);
        balanceWait(fn ($h) => $h['available'] && array_sum(array_column($h['pools'], 'running')) === 2
            && array_sum(array_column($h['pools'], 'workers')) === 2, 'idle queue minima');
    }
    balanceWait(fn () => $store->table('jobs')->where('queue', 'like', 'balance-%')->where('status', 'completed')->count() === 160, 'retained histories');
    if ($store->table('jobs')->count() !== $before + 160) { throw new RuntimeException('Duplicate balancing histories'); }
    $process->signal(SIGTERM); $exit = $process->wait(); $process = null;
    if ($exit !== 0) { throw new RuntimeException('Unclean balanced shutdown'); }
    $config['native']['pools']['shared']['balance'] = 'simple'; $config['native']['pools']['shared']['workers'] = 5;
    file_put_contents($root.'/config/current.php', '<?php return '.var_export($config, true).';');
    $process = new Process([PHP_BINARY, $root.'/artisan', 'current:supervise'], $root); $process->setTimeout(null); $process->start();
    balanceWait(fn ($h) => $h['available'] && $h['state'] === 'running' && array_sum(array_column($h['pools'], 'running')) === 5, 'simple allocation');
    $counts = array_column($supervisor->snapshot()['pools'], 'workers'); sort($counts);
    if ($counts !== [2, 3]) { throw new RuntimeException('Simple queue allocation is uneven'); }
    $owner = $supervisor->snapshot()['owner'];
    if (! $supervisor->control($owner, 'pause', 'shared')) { throw new RuntimeException('Group pause rejected'); }
    balanceWait(fn ($h) => $h['available'] && $h['state'] === 'paused', 'group paused');
    foreach (['hot', 'cold'] as $direction) { Queue::push(new FixtureJob('simple-'.$direction), '', 'balance-'.$direction); }
    if (! $supervisor->control($owner, 'resume', 'shared')) { throw new RuntimeException('Group resume rejected'); }
    balanceWait(fn () => (int) Redis::hget('completed', 'simple-hot') === 1 && (int) Redis::hget('completed', 'simple-cold') === 1, 'simple queue jobs');
    balanceWait(fn () => $store->table('jobs')->where('queue', 'like', 'balance-%')->where('status', 'completed')->count() === 162, 'final histories');
    echo json_encode(['accepted' => true, 'jobs' => 162, 'peak_live_workers' => $peak, 'checks' => ['size_reallocation', 'idle_minima', 'shared_capacity', 'simple_distribution', 'group_pause_resume', 'exact_history']])."\n";
} finally {
    if ($process?->isRunning()) { $process->stop(12, SIGKILL); }
    if ($collector?->isRunning()) { $collector->stop(12, SIGKILL); }
    file_put_contents($root.'/config/current.php', $original);
}
