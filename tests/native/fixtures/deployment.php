<?php
// Destructive signals target only this fixture's private launcher and its children.
if (getenv('CURRENT_SUPERVISOR_EXPERIMENT') !== '1' || ! preg_match('~^/tmp/current-supervisor-[a-f0-9]{12}$~D', getenv('EXPERIMENT_ROOT') ?: '')) { exit(2); }
foreach (['CURRENT_SUPERVISOR_NATIVE', 'CURRENT_SUPERVISOR_HISTORY'] as $flag) { putenv($flag.'=1'); $_ENV[$flag] = $_SERVER[$flag] = '1'; }
putenv('APP_ENV=local'); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';
$_ENV['EXPERIMENT_ROOT'] = $_SERVER['EXPERIMENT_ROOT'] = getenv('EXPERIMENT_ROOT');
$app = require __DIR__.'/boot.php';
set_exception_handler(function (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
use Illuminate\Support\Facades\{Artisan, Queue, Redis};
use SupervisorExperiment\FixtureJob;
use Symfony\Component\Process\Process;

$root = getenv('EXPERIMENT_ROOT'); $original = file_get_contents($root.'/config/current.php');
$originalQueue = file_get_contents($root.'/config/queue.php');
$queueDefinition = require $root.'/config/queue.php';
$originalRetry = $queueDefinition['connections']['redis']['retry_after'];
$config = require $root.'/config/current.php';
$config['native'] = ['state_directory' => $root.'/deployment-state', 'instance' => 'deployment', 'binary' => dirname(__DIR__, 3).'/supervisor/current-supervisor',
    'grace_seconds' => 4, 'worker_limit' => 1, 'pools' => ['deployment' => ['connection' => 'redis', 'queues' => ['deployment'], 'workers' => 1,
        'min_workers' => 1, 'max_workers' => 1, 'timeout' => 3, 'memory' => 256, 'sleep' => 0, 'tries' => 3]]];
$store = app(Current\Store::class); $supervisor = app(Current\Native\Supervisor::class);
$process = $collector = null; $checks = []; $zombies = []; $run = bin2hex(random_bytes(4));
function deploymentEnsure(bool $ok, string $label): void { if (! $ok) { throw new RuntimeException('Deployment check: '.$label); } }
function deploymentWait(callable $condition, string $label, float $seconds = 20): void {
    $end = microtime(true) + $seconds;
    do { if ($condition()) { return; } usleep(20000); } while (microtime(true) < $end);
    throw new RuntimeException('Deployment timeout: '.$label);
}
function deploymentLive(int $pid): bool {
    $status = @file_get_contents('/proc/'.$pid.'/status');
    return $status !== false && ! preg_match('/^State:\s+Z/m', $status);
}
function deploymentStart(): array {
    global $root, $config, $process, $supervisor;
    file_put_contents($root.'/config/current.php', '<?php return '.var_export($config, true).';');
    config()->set('current.native', $config['native']);
    $previousOwner = $supervisor->snapshot()['owner'] ?? null;
    $process = new Process([PHP_BINARY, $root.'/artisan', 'current:supervise'], $root); $process->setTimeout(null); $process->start();
    deploymentWait(function () use ($supervisor, $previousOwner) {
        global $process;
        deploymentEnsure($process->isRunning(), 'launcher exited: '.$process->getOutput().$process->getErrorOutput());
        $h = $supervisor->snapshot(); return ($h['state'] ?? '') === 'running' && $h['owner'] !== $previousOwner;
    }, 'new owned generation');
    $health = json_decode(file_get_contents($root.'/deployment-state/deployment.json'), true, flags: JSON_THROW_ON_ERROR);
    return [$health['pid'], $health['pools'][0]['slots'][0]['pid']];
}
function deploymentStop(): void {
    global $process;
    $exit = Artisan::call('current:terminate', ['--instance' => 'deployment', '--wait' => '8']);
    $output = Artisan::output(); deploymentEnsure($exit === 0, $output);
    deploymentEnsure($process->wait() === 0, 'clean launcher shutdown'); $process = null;
}
try {
    if (config('current.telemetry.driver') === 'redis') {
        $collector = new Process([PHP_BINARY, $root.'/artisan', 'current:collect'], $root); $collector->setTimeout(null); $collector->start();
        deploymentWait(fn () => app(Current\Telemetry\Health::class)->snapshot()['running'] ?? false, 'collector ownership');
    }
    foreach (($argv[1] ?? '') === 'output-only' ? [] : ['graceful', 'long-deadline', 'job-deadline', 'no-worker-timeout', 'forced', 'launcher-loss'] as $scenario) {
        $recovery = in_array($scenario, ['forced', 'launcher-loss'], true);
        $long = $scenario === 'long-deadline';
        $jobOverride = $scenario === 'job-deadline';
        $timeout = $long ? 2500 : ($scenario === 'no-worker-timeout' ? 0 : 3);
        $config['native']['grace_seconds'] = $long || $jobOverride ? 2530 : 4;
        $config['native']['pools']['deployment']['timeout'] = $timeout;
        $queueDefinition['connections']['redis']['retry_after'] = $long || $jobOverride ? 2700 : $originalRetry;
        file_put_contents($root.'/config/queue.php', '<?php return '.var_export($queueDefinition, true).';');
        config()->set('queue.connections.redis.retry_after', $queueDefinition['connections']['redis']['retry_after']);
        [$go, $worker] = deploymentStart();
        $args = explode("\0", file_get_contents('/proc/'.$worker.'/cmdline'));
        deploymentEnsure(in_array('--timeout='.$timeout, $args, true), 'actual worker receives the preserved timeout');
        $id = 'deployment-'.$run.'-'.$scenario;
        $job = new FixtureJob($id, $jobOverride ? 4000000 : ($recovery ? 2500000 : 2000000));
        if ($jobOverride) { $job->timeout = 2500; }
        Queue::push($job, '', 'deployment');
        $uuid = Queue::connection()->lastEnqueued['uuid'];
        deploymentWait(fn () => Redis::hexists('started', $id), 'handler started');
        if ($recovery) { deploymentEnsure(posix_kill($worker, SIGSTOP), 'stop only the owned busy worker'); }
        if ($scenario === 'launcher-loss') {
            $process->signal(SIGKILL); $process->wait(); $process = null;
            deploymentWait(fn () => ! deploymentLive($go) && ! deploymentLive($worker), 'parent-loss descendants stopped', 8);
            if (is_dir('/proc/'.$go)) { $zombies[] = $go; }
        } else { deploymentStop(); }
        deploymentEnsure(! deploymentLive($worker), 'owned worker exited');
        deploymentWait(fn () => $store->table('attempts')->whereIn('job_id', $store->table('jobs')->where('external_id', $uuid)->select('id'))->exists(), 'captured first attempt');
        if ($recovery) {
            deploymentEnsure((int) Redis::hget('completed', $id) === 0, 'interrupted handler has no business completion');
            deploymentWait(fn () => $store->table('attempts')->whereIn('job_id', $store->table('jobs')->where('external_id', $uuid)->select('id'))->whereNull('finished_at')->count() === 1, 'interrupted attempt remains incomplete');
            deploymentStart();
        }
        deploymentWait(fn () => (int) Redis::hget('completed', $id) === 1, 'exact recovered effect');
        deploymentWait(fn () => $store->table('jobs')->where('external_id', $uuid)->where('status', 'completed')->count() === 1, 'one completed dispatch history');
        if ($jobOverride) { deploymentEnsure((int) $store->table('jobs')->where('external_id', $uuid)->value('timeout') === 2500, 'job-level timeout override is retained'); }
        $attempts = $store->table('attempts')->whereIn('job_id', $store->table('jobs')->where('external_id', $uuid)->select('id'));
        deploymentEnsure($attempts->count() === ($recovery ? 2 : 1), 'exact observed attempts');
        deploymentEnsure((int) Redis::hget('attempts', $id) === ($recovery ? 2 : 1), 'exact handler invocations');
        if ($process) { deploymentStop(); }
        $checks[$scenario] = ['timeout' => $timeout, 'grace_seconds' => $config['native']['grace_seconds'], 'business_effects' => (int) Redis::hget('completed', $id), 'attempts' => $attempts->count(),
            'incomplete_attempts' => (clone $attempts)->whereNull('finished_at')->count(), 'worker_exited' => ! deploymentLive($worker)];
    }
    // Recycling workers can emit logs for months. The PHP relay must forward
    // output without retaining an ever-growing Symfony temporary stream.
    $marker = $root.'/output-ready-'.$run;
    $fake = $root.'/output-engine-'.$run;
    file_put_contents($fake, "#!/usr/bin/env php\n<?php if ((\$argv[1] ?? '') === '-runtime-sample') { exit(1); }\n".
        'for ($i=0;$i<128;$i++) { fwrite(STDOUT,str_repeat("o",65536)); fwrite(STDERR,str_repeat("e",65536)); }'.
        'file_put_contents('.var_export($marker, true).',"ready"); sleep(4);');
    chmod($fake, 0700); $config['native']['binary'] = $fake;
    file_put_contents($root.'/config/current.php', '<?php return '.var_export($config, true).';');
    $process = new Process([PHP_BINARY, $root.'/artisan', 'current:supervise'], $root); $process->disableOutput(); $process->setTimeout(90); $process->start();
    // This asserts storage bounds, not log throughput on a busy CI host.
    deploymentWait(fn () => is_file($marker), 'output burst forwarded', 60); usleep(400000);
    $largest = 0;
    foreach (glob('/proc/'.$process->getPid().'/fd/*') as $fd) {
        clearstatcache(true, $fd); $stat = @stat($fd);
        if ($stat && ($stat['mode'] & 0170000) === 0100000) { $largest = max($largest, $stat['size']); }
    }
    deploymentEnsure($process->wait() === 0, 'output relay exited cleanly'); $process = null;
    deploymentEnsure($largest < 4194304, 'launcher retained a large output temporary file: '.$largest);
    $checks['output_relay'] = ['forwarded_bytes' => 16777216, 'largest_retained_regular_fd_bytes' => $largest];
    unlink($marker); unlink($fake);
    echo json_encode(['accepted' => true, 'checks' => $checks, 'reparented_exit_records_requiring_container_init' => $zombies], JSON_PRETTY_PRINT)."\n";
} finally {
    foreach ([$process, $collector] as $p) { if ($p && $p->isRunning()) { $p->stop(10, SIGTERM); } }
    file_put_contents($root.'/config/current.php', $original);
    file_put_contents($root.'/config/queue.php', $originalQueue);
}
