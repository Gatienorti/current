<?php
// Explicitly opt-in, disposable lab only. Never uses a host application or global Redis clear.
// Unit regressions load only the declared fixture helpers, without booting a lab.
if (defined('CURRENT_HISTORY_FIXTURE_HELPERS_ONLY') && CURRENT_HISTORY_FIXTURE_HELPERS_ONLY) { return; }
if (getenv('CURRENT_SUPERVISOR_EXPERIMENT') !== '1') { exit("Set CURRENT_SUPERVISOR_EXPERIMENT=1 in the isolated lab.\n"); }
foreach (['CURRENT_SUPERVISOR_NATIVE', 'CURRENT_SUPERVISOR_HISTORY'] as $flag) { putenv($flag.'=1'); $_ENV[$flag] = $_SERVER[$flag] = '1'; }
require getenv('EXPERIMENT_AUTOLOAD') ?: '/opt/vehify/vendor/autoload.php';
use Symfony\Component\Process\Process;
use SupervisorExperiment\FixtureJob;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;

$root = '/tmp/current-supervisor-'.bin2hex(random_bytes(6));
$prefix = basename($root).':';
$workers = 2;
$native = getenv('CURRENT_SUPERVISOR_NATIVE') === '1';
if ($native) { putenv('CURRENT_SUPERVISOR_CHECKS_ONLY=1'); }
foreach (['bootstrap/cache', 'config', 'storage/logs', 'storage/framework/cache', 'storage/framework/views'] as $dir) { mkdir($root.'/'.$dir, 0700, true); }
file_put_contents($root.'/artisan', "<?php\nrequire ".var_export(__DIR__.'/artisan.php', true).";\n");
file_put_contents($root.'/bootstrap/providers.php', '<?php return [];');
$config = [
    'app' => ['name' => 'Supervisor Experiment', 'env' => 'local', 'debug' => false, 'key' => 'base64:'.base64_encode(random_bytes(32)), 'timezone' => 'UTC', 'url' => 'http://localhost'],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $root.'/history.sqlite', 'busy_timeout' => 5000, 'journal_mode' => 'WAL', 'foreign_key_constraints' => true]], 'redis' => ['client' => getenv('NATIVE_REDIS_CLIENT') ?: 'phpredis', 'options' => ['prefix' => $prefix], 'default' => ['host' => getenv('NATIVE_REDIS_HOST') ?: 'lens-redis', 'port' => 6379, 'database' => 0]]],
    'queue' => ['default' => 'redis', 'connections' => ['redis' => ['driver' => 'current-experiment', 'connection' => 'default', 'queue' => 'experiment', 'retry_after' => 6, 'block_for' => 1, 'after_commit' => false]], 'failed' => ['driver' => 'database-uuids', 'database' => 'sqlite', 'table' => 'failed_jobs']],
    'cache' => ['default' => 'redis', 'stores' => ['redis' => ['driver' => 'redis', 'connection' => 'default']], 'prefix' => 'cache:'],
    'logging' => ['default' => 'single', 'channels' => ['single' => ['driver' => 'single', 'path' => $root.'/storage/logs/experiment.log']]],
    'horizon' => ['defaults' => [], 'use' => 'default', 'prefix' => $prefix.'horizon:', 'environments' => ['testing' => ['experiment' => ['connection' => 'redis', 'queue' => ['experiment'], 'balance' => false, 'minProcesses' => $workers, 'maxProcesses' => $workers, 'tries' => 3, 'timeout' => 3, 'sleep' => 1, 'memory' => 128, 'backoff' => 0]]]],
];
touch($root.'/history.sqlite');
$config['current'] = ['execution_backend' => 'native', 'connection' => 'sqlite', 'telemetry' => ['redis_connection' => 'default', 'driver' => getenv('HISTORY_DRIVER') ?: 'sync', 'namespace' => basename($root), 'binary' => dirname(__DIR__, 3).'/collector/current-collector'], 'diagnostic_path' => $root.'/diagnostics.json'];
if (getenv('NATIVE_PACKAGE') === '1') {
    $config['queue']['connections']['redis']['driver'] = 'current-redis';
    $config['current']['native'] = ['state_directory' => $root.'/supervisor-state', 'instance' => 'history', 'binary' => dirname(__DIR__, 3).'/supervisor/current-supervisor', 'grace_seconds' => 10,
        'pools' => ['imports' => ['connection' => 'redis', 'queues' => ['experiment'], 'workers' => 1, 'max_workers' => 70, 'timeout' => 3],
            'notifications' => ['connection' => 'redis', 'queues' => ['secondary'], 'workers' => 1, 'max_workers' => 4, 'timeout' => 3]]];
}
if (getenv('NATIVE_PROVISIONING') === '1') {
    $nativeOptions = &$config['current']['native'];
    $nativeOptions['worker_limit'] = 2;
    $nativeOptions['defaults'] = $nativeOptions['pools'];
    $nativeOptions['defaults']['imports']['queues'] = ['unused', 'must-not-be-consumed'];
    $nativeOptions['defaults']['disabled'] = $nativeOptions['defaults']['notifications'];
    $nativeOptions['pools'] = [];
    $nativeOptions['environments'] = ['local' => ['imports' => ['queues' => ['experiment']], 'disabled' => false]];
    unset($nativeOptions);
}
foreach ($config as $name => $value) { file_put_contents($root.'/config/'.$name.'.php', '<?php return '.var_export($value, true).';'); }
foreach (['APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'LENS_VENDOR_AUTOLOAD'] as $key) { putenv($key); unset($_ENV[$key], $_SERVER[$key]); }
putenv('EXPERIMENT_ROOT='.$root); putenv('APP_ENV=local');
$_ENV['EXPERIMENT_ROOT'] = $_SERVER['EXPERIMENT_ROOT'] = $root;
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';
$app = require __DIR__.'/boot.php';
set_exception_handler(function (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
$r = Redis::connection();
function until(callable $condition, string $label, float $seconds = 30): void {
    global $stage; $stage = $label;
    $end = microtime(true) + $seconds;
    do { global $collector; if ($collector && !$collector->isRunning()) { throw new RuntimeException('Collector exited: '.$collector->getOutput().$collector->getErrorOutput()); } if ($condition()) { return; } usleep(10000); } while (microtime(true) < $end);
    global $r;
    try {
        if (isset($r)) fwrite(STDERR, json_encode(['timeout' => $label, 'started' => $r->hgetall('started'), 'completed' => $r->hgetall('completed'), 'attempts' => $r->hgetall('attempts'), 'terminal' => $r->hgetall('terminal'), 'queue_size' => Queue::connection()->size('experiment')])."\n");
    } catch (Throwable $diagnosticError) { reportFixtureFailure($diagnosticError, 'timeout-diagnostics'); }
    throw new RuntimeException('Timeout: '.$label);
}
function ensure(bool $condition, string $label): void { if (! $condition) { global $stage; $stage = $label; throw new RuntimeException('FAIL '.$label); } }
function reportFixtureFailure(Throwable $e, string $phase, array $processes = []): void {
    global $root, $stage, $checks;
    try {
        $report = ['fixture_failure' => $phase, 'driver' => config('current.telemetry.driver'), 'stage' => $stage ?? 'initialization',
            'error_class' => get_class($e), 'completed_checks' => array_keys($checks ?? []), 'processes' => []];
        foreach ($processes as $p) {
            try { $report['processes'][] = ['status' => $p->getStatus(), 'running' => $p->isRunning(), 'exit_code' => $p->getExitCode()]; }
            catch (Throwable $statusError) { $report['processes'][] = ['status_error_class' => get_class($statusError)]; }
        }
        $diagnostic = $root.'/diagnostics.json';
        if (is_file($diagnostic) && filesize($diagnostic) <= 65536) {
            $record = json_decode(file_get_contents($diagnostic), true);
            $report['recorder_error_class'] = is_string($record['error_class'] ?? null) ? substr($record['error_class'], 0, 256) : null;
        }
        // Keep private owner tokens, process proof, payloads and command lines out of diagnostics.
        $path = $root.'/supervisor-state/history.json';
        if (is_file($path) && filesize($path) <= 262144) {
            $health = json_decode(file_get_contents($path), true);
            if (is_array($health)) {
                $report['health'] = ['state' => $health['state'] ?? null, 'updated_at' => $health['updated_at'] ?? null, 'pools' => []];
                foreach (array_slice($health['pools'] ?? [], 0, 16) as $pool) {
                    $report['health']['pools'][] = ['name' => $pool['name'] ?? null, 'state' => $pool['state'] ?? null,
                        'workers' => $pool['workers'] ?? null, 'slot_states' => array_column(array_slice($pool['slots'] ?? [], 0, 128), 'state')];
                }
            }
        }
        fwrite(STDERR, json_encode($report, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n");
    } catch (Throwable $diagnosticError) {
        fwrite(STDERR, json_encode(['fixture_failure' => $phase, 'error_class' => get_class($e), 'diagnostic_error_class' => get_class($diagnosticError)])."\n");
    }
}
function stopModePreserving(array $processes, ?Throwable $original): void {
    try { stopMode($processes); }
    catch (Throwable $cleanup) { reportFixtureFailure($cleanup, 'cleanup', $processes); if ($original === null) { throw $cleanup; } }
}
function descendants(int $pid): array {
    $result = [$pid];
    for ($i = 0; $i < count($result); $i++) {
        foreach (glob('/proc/'.$result[$i].'/task/*/children') as $file) {
            $children = @file_get_contents($file);
            foreach (preg_split('/\s+/', trim($children ?: ''), -1, PREG_SPLIT_NO_EMPTY) as $child) {
                if (! in_array((int) $child, $result, true)) { $result[] = (int) $child; }
            }
        }
    }
    return $result;
}
function rss(array $pids): int {
    $sum = 0; foreach ($pids as $pid) { if (preg_match('/VmRSS:\s+(\d+)/', @file_get_contents('/proc/'.$pid.'/status') ?: '', $m)) { $sum += (int) $m[1]; } } return $sum;
}
function startMode(string $mode): array {
    global $root, $workers, $r;
    $r->del('workers');
    $worker = [PHP_BINARY, $root.'/artisan', 'queue:work', 'redis', '--queue=experiment', '--sleep=1', '--tries=3', '--timeout=3', '--memory=128', '--no-interaction', '--quiet'];
    $commands = match ($mode) {
        'horizon' => [[PHP_BINARY, $root.'/artisan', 'horizon', '--environment=testing']],
        'go' => [array_merge([dirname(__DIR__, 3).'/supervisor/current-supervisor', '-workers', (string) $workers, '-grace', '10s', '--'], $worker)],
        'direct' => array_fill(0, $workers, $worker),
    };
    if ($mode === 'go' && getenv('HISTORY_NAMED_POOLS') === '1') {
        $secondary = array_map(fn ($arg) => $arg === '--queue=experiment' ? '--queue=secondary' : $arg, $worker);
        $config = ['version' => 1, 'instance' => 'history', 'state_directory' => $root.'/supervisor-state', 'grace_seconds' => 10,
            'pools' => [
                ['name' => 'imports', 'workers' => 1, 'min_workers' => 1, 'max_workers' => 70, 'directory' => $root, 'command' => $worker],
                ['name' => 'notifications', 'workers' => 1, 'min_workers' => 1, 'max_workers' => 4, 'directory' => $root, 'command' => $secondary],
            ]];
        file_put_contents($root.'/supervisor.json', json_encode($config, JSON_THROW_ON_ERROR));
        $commands = [[dirname(__DIR__, 3).'/supervisor/current-supervisor', '-config', $root.'/supervisor.json']];
    }
    if (getenv('NATIVE_PACKAGE') === '1') { $commands = [[PHP_BINARY, $root.'/artisan', 'current:supervise']];
        if (getenv('NATIVE_PROVISIONING') === '1') { $commands[0][] = getenv('NATIVE_REQUIRE_LIGHTWEIGHT') === '1' ? '--environment=local' : '--env=local'; }
    }
    $processes = [];
    try {
        foreach ($commands as $i => $command) {
            $p = new Process($command, $root); $p->setTimeout(null);
            $log = fopen($root.'/'.$mode.'-'.$i.'.log', 'a');
            $p->start(static function ($type, $data) use ($log) { fwrite($log, $data); });
            $processes[] = $p;
        }
        until(function () use ($processes, $workers, $r) {
            foreach ($processes as $p) { if (! $p->isRunning()) { throw new RuntimeException('Supervisor exited: '.$p->getErrorOutput().' '.$p->getOutput()); } }
            return $r->hlen('workers') === $workers;
        }, $mode.' worker readiness');
        return $processes;
    } catch (Throwable $e) { reportFixtureFailure($e, 'startup', $processes); stopModePreserving($processes, $e); throw $e; }
}
function stopMode(array $processes): void {
    $pids = [];
    foreach ($processes as $p) {
        // getPid refreshes process status too; a child can exit between checks.
        $pid = $p->getPid();
        if ($pid !== null && $p->isRunning()) {
            $pids = array_merge($pids, descendants($pid));
            try { $p->signal(SIGTERM); } catch (\Symfony\Component\Process\Exception\ExceptionInterface $e) { if ($p->isRunning()) { throw $e; } }
        }
    }
    foreach ($processes as $p) {
        try { $p->stop(12, SIGKILL); } catch (\Symfony\Component\Process\Exception\ExceptionInterface $e) { if ($p->isRunning()) { throw $e; } }
    }
    // Detect and clean only descendants of this experiment's processes.
    $survivors = array_filter($pids, fn ($pid) => is_dir('/proc/'.$pid));
    foreach ($survivors as $pid) { @posix_kill($pid, SIGKILL); }
    ensure(! $survivors, 'all owned processes exited');
}

if (getenv('NATIVE_PACKAGE') === '1') {
    ensure(Illuminate\Support\Facades\Artisan::call('current:install') === 0, 'initial public installation');
} else {
    foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $migration) { (require $migration)->up(); }
}
Illuminate\Support\Facades\Schema::create('failed_jobs', function ($table) {
    $table->id(); $table->string('uuid')->unique(); $table->text('connection'); $table->text('queue');
    $table->longText('payload'); $table->longText('exception'); $table->timestamp('failed_at')->useCurrent();
});
$store = app(Current\Store::class);
$active = []; $collector = null;
$checks = []; $failure = null; $stage = 'startup';
try {
    if (config('current.telemetry.driver') === 'redis') {
        $collector = new Process([PHP_BINARY, $root.'/artisan', 'current:collect'], $root);
        $collector->setTimeout(null); $collector->start();
        usleep(150000);
        ensure($collector->isRunning(), 'collector startup: '.$collector->getOutput().$collector->getErrorOutput());
    }
    if (getenv('NATIVE_PACKAGE') === '1') {
        ensure(!class_exists(Laravel\Horizon\Horizon::class), 'Horizon absent from clean vendor');
        ensure(Illuminate\Support\Facades\Artisan::call('current:install') === 0, 'public installer succeeds without Horizon');
        $checks['standalone_install'] = 'PASS: clean Composer vendor without Horizon; public current:install and current-redis connector';
    }
    $active = startMode('go');
    if (getenv('NATIVE_REQUIRE_LIGHTWEIGHT') === '1') {
        ensure(str_contains(file_get_contents('/proc/'.$active[0]->getPid().'/cmdline'), '/src/Native/controller.php'), 'packaged lightweight launcher is active');
        $checks['lightweight_launcher'] = 'PASS: same PID exec into packaged controller';
    }
    // Standard and delayed dispatch, retry, release and terminal failure.
    foreach (['ok', 'retry', 'release', 'fail'] as $scenario) { Queue::push(new FixtureJob($scenario, 5000, $scenario)); }
    Queue::later(1, new FixtureJob('delayed'));
    until(fn () => $store->table('jobs')->whereIn('status', ['completed', 'failed'])->count() === 5, 'complete history', 45);
    ensure($store->table('attempts')->count() === 9, 'exact attempt count');
    ensure($store->table('steps')->count() === 9, 'instrumented steps');
    $enqueueCoverage = $store->table('jobs')->where('coverage', 'observed_enqueue')->count();
    if ($enqueueCoverage !== 5) {
        $diagnostic = is_file($root.'/diagnostics.json') ? json_decode(file_get_contents($root.'/diagnostics.json'), true) : [];
        ensure(false, 'all enqueues captured: '.json_encode(['driver' => config('current.telemetry.driver'),
            'jobs' => $store->table('jobs')->select('status', 'coverage')->get(), 'recorder_error_class' => $diagnostic['error_class'] ?? null]));
    }
    ensure($store->table('jobs')->where('arguments_state', 'captured')->count() === 5, 'arguments captured');
    ensure($store->table('jobs')->where('metadata_state', 'captured')->count() === 5, 'metadata captured');
    ensure(!str_contains(json_encode($store->table('jobs')->get()), 'fixture-secret'), 'arguments redacted before persistence');
    ensure($store->connection()->table('failed_jobs')->count() === 1, 'Laravel retained failure');
    $checks['normal_delayed_retry_release_failure_context_steps'] = 'PASS: 5 jobs, 9 attempts, 9 steps, redacted context, one retained failure';

    // queue:retry uses pushRaw and the same UUID; it must create a new retained cycle.
    $failed = $store->table('jobs')->where('status', 'failed')->sole();
    Illuminate\Support\Facades\Artisan::call('queue:retry', ['id' => [$failed->external_id]]);
    until(fn () => (int) $r->hget('attempts', 'fail') === 6 && $store->table('attempts')->count() === 12 && $store->table('attempts')->whereNull('finished_at')->count() === 0, 'manual retry execution and drain');
    $retryCycles = $store->table('jobs')->count() - 5;
    ensure($retryCycles === 1, 'manual retry has its own cycle');
    ensure($store->table('jobs')->where('external_id', $failed->external_id)->distinct()->count('dispatch_id') === 2, 'same UUID, distinct dispatch identities');
    ensure($store->table('attempts')->count() === 12, 'manual retry preserves first history');
    $checks['raw_manual_retry'] = $retryCycles === 1 ? 'PASS: two distinct cycles retain the same UUID, 12 total attempts' : 'FAIL: async UUID identity merges the manual retry into the original cycle; 12 attempts remain, separate cycle required';

    // Laravel defers the adapter call until commit; rollback must create no queue/history entry.
    $db = Illuminate\Support\Facades\DB::connection();
    $db->beginTransaction(); Queue::push((new FixtureJob('rolled-back'))->afterCommit()); $db->rollBack();
    usleep(100000);
    ensure(!$r->hexists('started', 'rolled-back') && $store->table('jobs')->count() === 5 + $retryCycles, 'rollback does not dispatch or record');
    $db->beginTransaction(); Queue::push((new FixtureJob('committed'))->afterCommit()); $db->commit();
    until(fn () => $store->table('jobs')->where('status', 'completed')->count() === 5, 'after commit completes');
    ensure($store->table('jobs')->count() === 6 + $retryCycles, 'after commit single history');
    $checks['after_commit'] = 'PASS: rollback invisible, committed job recorded once';
    Queue::push(new FixtureJob('crash', 5000, 'crash'));
    Queue::push(new FixtureJob('timeout', 5000, 'timeout'));
    until(fn () => $store->table('jobs')->where('status', 'completed')->count() === 7, 'worker loss recovery', 45);
    ensure($store->table('attempts')->count() === 17, 'recovery attempts retained');
    ensure($store->table('attempts')->whereNull('finished_at')->exists(), 'killed attempt is not invented success');
    $checks['worker_loss_timeout'] = 'PASS: reservation recovery; missing finish remains incomplete';
    for ($i = 0; $i < 12; $i++) { Queue::push(new FixtureJob('concurrent-'.$i)); }
    until(fn () => $store->table('jobs')->where('status', 'completed')->count() === 19, 'concurrent producer and workers');
    ensure($store->table('jobs')->count() === 20 + $retryCycles && $store->table('attempts')->count() === 29, 'exact concurrent histories');
    $checks['concurrency'] = 'PASS: 12 additional dispatches while two workers consume, exact histories';
    // Delay only this producer's observations; workers keep recording normally.
    $late = null;
    $app['events']->listen(Illuminate\Queue\Events\JobQueued::class, function ($event) use (&$late) { $late = $event; });
    config()->set('current.enabled', false);
    Queue::push(new FixtureJob('late-producer'));
    config()->set('current.enabled', true);
    $payload = $late->payload();
    until(fn () => $store->table('jobs')->where('external_id', $payload['uuid'])->where('status', 'completed')->count() === 1, 'worker before producer');
    $original = $store->table('jobs')->where('external_id', $payload['uuid'])->sole();
    ensure($original->coverage === 'start_only', 'producer genuinely delayed');
    // Retry the identical raw payload, keeping Laravel's UUID unchanged.
    Queue::pushRaw($late->payload);
    until(fn () => $store->table('jobs')->where('external_id', $payload['uuid'])->where('status', 'completed')->count() === 2, 'second generation before old producer');
    app(Current\Recorder::class)->enqueued($payload, 'redis', 'experiment');
    app(Current\Recorder::class)->queued($late);
    app(Current\Recorder::class)->enqueued($payload, 'redis', 'experiment');
    until(fn () => $store->table('jobs')->where('id', $original->id)->value('arguments_state') === 'captured', 'late context routed to original generation');
    ensure($store->table('jobs')->where('external_id', $payload['uuid'])->count() === 2, 'duplicate enqueue is idempotent');
    $newer = $store->table('jobs')->where('external_id', $payload['uuid'])->where('id', '!=', $original->id)->sole();
    ensure($newer->arguments_state === null, 'old context never leaks into raw retry');
    ensure($store->table('jobs')->count() === 23 && $store->table('attempts')->count() === 31, 'ordered identities exact');
    $checks['late_producer_and_duplicate'] = 'PASS: original context arrives after newer same-UUID dispatch; histories stay separate';
        if (getenv('HISTORY_NAMED_POOLS') === '1') {
        Queue::push(new FixtureJob('secondary-pool'), '', 'secondary');
        until(fn () => $store->table('jobs')->where('queue', 'secondary')->where('status', 'completed')->count() === 1, 'second pool consumes its own queue');
        until(fn () => (json_decode(file_get_contents($root.'/supervisor-state/history.json'), true)['state'] ?? null) === 'running', 'named pool process health');
        $health = json_decode(file_get_contents($root.'/supervisor-state/history.json'), true);
        ensure(array_column($health['pools'], 'name') === ['imports', 'notifications'], 'named health pools');
        ensure(array_column($health['pools'], 'workers') === [1, 1] && array_column($health['pools'], 'max_workers') === [70, 4], 'allocation separate from limits');
        $duplicate = new Process([getenv('NATIVE_PACKAGE') === '1' ? dirname(__DIR__, 3).'/supervisor/current-supervisor' : dirname(__DIR__, 3).'/supervisor/current-supervisor', '-config', $root.'/supervisor.json'], $root);
        $duplicate->run();
        ensure($duplicate->getExitCode() === 1 && str_contains($duplicate->getErrorOutput(), 'already owned'), 'duplicate owner rejected');
        if (getenv('NATIVE_PACKAGE') === '1') {
            until(fn () => count(app(Current\Native\Registry::class)->all()['instances']) === 1, 'launcher publishes shared registry');
            $registered = app(Current\Native\Registry::class)->all()['instances'][0];
            ensure($registered['instance'] === 'history' && array_column($registered['pools'], 'name') === ['imports', 'notifications'], 'shared registry matches actual pools');
            $checks['shared_registry'] = 'PASS: public launcher publishes bounded shared process inventory';
        }
        $checks['named_pools'] = 'PASS: imports and notifications consume separate queues; allocations/ranges retained; duplicate owner rejected';
        config()->set('current.native', ['state_directory' => $root.'/supervisor-state', 'instance' => 'history']);
        $supervisor = app(Current\Native\Supervisor::class);
        ensure($supervisor->snapshot()['available'], 'PHP health reader accepts Go snapshot');
        Queue::push(new FixtureJob('drain-control', 500000), '', 'secondary');
        until(fn () => $r->hexists('started', 'drain-control'), 'busy worker before pause');
        ensure(!$r->hexists('completed', 'drain-control'), 'pause requested while job running');
        ensure($supervisor->control($health['owner'], 'pause', 'notifications'), 'pause accepted through PHP socket client');
        until(fn () => ($supervisor->snapshot()['pools'][1]['state'] ?? null) === 'paused', 'pool drains and pauses');
        ensure((int)$r->hget('completed', 'drain-control') === 1, 'in-flight job completed before pause');
        Queue::push(new FixtureJob('waiting-control'), '', 'secondary');
        Queue::push(new FixtureJob('other-pool-control'));
        until(fn () => (int)$r->hget('completed', 'other-pool-control') === 1, 'other pool remains active');
        ensure(!$r->hexists('started', 'waiting-control'), 'paused pool does not consume new work');
        ensure(!$supervisor->control(str_repeat('0', 32), 'resume', 'notifications'), 'stale owner rejected by actual Go server');
        ensure($supervisor->control($health['owner'], 'resume', 'notifications'), 'resume accepted');
        until(fn () => (int)$r->hget('completed', 'waiting-control') === 1 && $store->table('jobs')->where('status', 'completed')->count() === 25, 'resumed work and history complete');
        ensure($store->table('jobs')->count() === 27 && $store->table('attempts')->count() === 35, 'control lifecycle exact counts');
        $checks['pause_resume'] = 'PASS: PHP reads Go health, drains busy pool, queues work while paused, other pool continues, stale owner rejected, resume completes once';
        Queue::push(new FixtureJob('restart-control', 1000000), '', 'secondary');
        until(fn () => $r->hexists('started', 'restart-control'), 'busy worker before restart');
        $busyPid = json_decode($r->hget('started', 'restart-control'), true)['pid'];
        // The health file may still describe the previous resume generation.
        // Anchor restart checks to the worker actually executing this job.
        until(function () use ($root, $busyPid) {
            $health = json_decode(file_get_contents($root.'/supervisor-state/history.json'), true);
            return $health['pools'][1]['slots'][0]['pid'] === $busyPid && $health['pools'][1]['state'] === 'running';
        }, 'health catches up with busy worker');
        $before = json_decode(file_get_contents($root.'/supervisor-state/history.json'), true);
        ensure(!$r->hexists('completed', 'restart-control'), 'restart requested while job running');
        ensure($supervisor->control($health['owner'], 'restart', 'notifications'), 'restart accepted');
        until(function () use ($root, $before, $busyPid) {
            $now = json_decode(file_get_contents($root.'/supervisor-state/history.json'), true);
            return $now['pools'][1]['slots'][0]['pid'] !== $busyPid
                && $now['pools'][1]['slots'][0]['pid'] > 0
                && $now['pools'][1]['slots'][0]['starts'] > $before['pools'][1]['slots'][0]['starts']
                && $now['pools'][1]['state'] === 'running';
        }, 'new worker generation running');
        $after = json_decode(file_get_contents($root.'/supervisor-state/history.json'), true);
        ensure($before['pools'][0]['slots'][0]['pid'] === $after['pools'][0]['slots'][0]['pid'], 'other pool unchanged by restart');
        ensure((int)$r->hget('completed', 'restart-control') === 1, 'busy job drained exactly once');
        Queue::push(new FixtureJob('after-restart-control'), '', 'secondary');
        until(fn () => (int)$r->hget('completed', 'after-restart-control') === 1 && $store->table('jobs')->where('status', 'completed')->count() === 27, 'replacement consumes next job');
        ensure($store->table('jobs')->count() === 29 && $store->table('attempts')->count() === 37, 'restart lifecycle exact counts');
        $checks['restart'] = 'PASS: busy pool drains before replacement, other pool PID unchanged, replacement consumes next job once';
    }
    $beforeBulk = $store->table('jobs')->count();
    $beforeBulkAttempts = $store->table('attempts')->count();
    Queue::bulk([new FixtureJob('bulk-worker-immediate'), (new FixtureJob('bulk-worker-delayed'))->delay(1)]);
    until(fn () => (int)$r->hget('completed', 'bulk-worker-immediate') === 1 && (int)$r->hget('completed', 'bulk-worker-delayed') === 1
        && $store->table('jobs')->where('status', 'completed')->count() === $beforeBulk, 'bulk workers and persisted histories complete');
    ensure($store->table('jobs')->count() === $beforeBulk + 2 && $store->table('attempts')->count() === $beforeBulkAttempts + 2, 'bulk exact history counts');
    $checks['bulk_execution'] = 'PASS: immediate and delayed bulk jobs executed once with separate complete histories';
    if (getenv('NATIVE_PACKAGE') === '1') {
        $second = new Process([PHP_BINARY, $root.'/artisan', 'current:supervise', '--instance=inventory-second'], $root);
        $second->setTimeout(null); $second->start();
        $secondFailure = null;
        try {
            until(function () use ($second) {
                ensure($second->isRunning(), 'second inventory launcher stays running');
                $instances = collect(app(Current\Native\Registry::class)->all()['instances'])->keyBy('instance');
                return count($instances) === 2 && $instances->get('inventory-second')['state'] === 'running';
            }, 'two independently launched instances visible');
            ensure(Artisan::call('current:terminate', ['--instance' => 'inventory-second', '--wait' => '10']) === 0, 'public terminate accepts existing second instance');
            $second->wait(); ensure($second->getExitCode() === 0, 'targeted terminate exits second launcher cleanly');
            $instances = collect(app(Current\Native\Registry::class)->all()['instances'])->keyBy('instance');
            ensure($instances->get('inventory-second')['state'] === 'stopped', 'second instance publishes stopped');
            ensure($instances->get('history')['state'] === 'running', 'stopping second instance leaves first running');
            $checks['multiple_instance_registry'] = 'PASS: two actual independent launchers visible; stopping one preserves the other';
        } catch (Throwable $e) {
            $secondFailure = $e; reportFixtureFailure($e, 'second-instance', [$second]); throw $e;
        } finally {
            if ($second->isRunning()) { stopModePreserving([$second], $secondFailure); }
        }
    }
    ensure(!is_file($root.'/diagnostics.json'), 'no recorder diagnostics');
    if (getenv('NATIVE_PACKAGE') === '1') { ensure(Artisan::call('current:terminate', ['--all' => true, '--wait' => '10']) === 0, 'public terminate all app-local instances'); $checks['deployment_terminate'] = 'PASS: instance and all-local termination use recorded ownership without parsing a new worker plan'; }
    stopMode($active); $active = [];
    if (getenv('HISTORY_NAMED_POOLS') === '1') {
        ensure(json_decode(file_get_contents($root.'/supervisor-state/history.json'), true)['state'] === 'stopped', 'named pool stopped snapshot');
        if (getenv('NATIVE_PACKAGE') === '1') {
            ensure((app(Current\Native\Registry::class)->all()['instances'][0]['state'] ?? null) === 'stopped', 'launcher publishes final stopped state');
        }
    }
    if ($collector) { $collector->signal(SIGTERM); $collector->wait(); ensure($collector->getExitCode() === 0, 'collector clean shutdown: '.$collector->getExitCode().' '.$collector->getOutput().$collector->getErrorOutput()); $checks['public_collector_shutdown'] = 'PASS: current:collect SIGTERM exits zero in isolated local environment'; }
    $result = ['scope' => 'native Laravel + Current, Go fixed pool; isolated acceptance, not a benchmark', 'accepted' => $retryCycles === 1, 'driver' => config('current.telemetry.driver'), 'php' => PHP_VERSION, 'laravel' => $app->version(), 'horizon_provider_loaded' => isset($app->getLoadedProviders()[Laravel\Horizon\HorizonServiceProvider::class]), 'queue_class' => get_class(Queue::connection()), 'checks' => $checks, 'jobs' => $store->table('jobs')->count(), 'attempts' => $store->table('attempts')->count(), 'steps' => $store->table('steps')->count(), 'root' => $root];
    if (getenv('HISTORY_NAMED_POOLS') === '1') {
        $result['scope'] = 'native Laravel + Current with two named Go pools on separate queues; isolated acceptance, not a benchmark';
        $result['supervisor'] = json_decode(file_get_contents($root.'/supervisor-state/history.json'), true);
    }
    $resultName = getenv('HISTORY_RESULT_NAME') ?: 'native-history-'.config('current.telemetry.driver').(getenv('HISTORY_NAMED_POOLS') === '1' ? '-pools' : '');
    ensure((bool) preg_match('/^[a-z0-9_-]{1,64}$/D', $resultName), 'safe result name');
    file_put_contents(__DIR__.'/'.$resultName.'.json', json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    ensure($retryCycles === 1, 'native backend acceptance blocked by same-UUID retry identity');
} catch (Throwable $e) {
    $failure = $e; reportFixtureFailure($e, 'original', array_merge($active, $collector ? [$collector] : [])); throw $e;
} finally {
    $cleanupFailure = null;
    foreach ([
        function () use ($active) { if ($active) { stopMode($active); } },
        function () use ($collector) { if ($collector && $collector->isRunning()) { $collector->stop(5); } },
        function () use ($prefix, $root) {
            $raw = new \Redis; $raw->connect(getenv('NATIVE_REDIS_HOST') ?: 'lens-redis');
            foreach ([$prefix.'*', '{current:'.basename($root).':*'] as $pattern) {
                $cursor = null;
                do { $keys = $raw->scan($cursor, $pattern, 1000); if ($keys) { $raw->del($keys); } } while ($cursor !== 0);
            }
        },
    ] as $cleanup) {
        try { $cleanup(); }
        catch (Throwable $e) { reportFixtureFailure($e, 'cleanup'); $cleanupFailure ??= $e; }
    }
    if ($failure === null && $cleanupFailure !== null) { throw $cleanupFailure; }
}
