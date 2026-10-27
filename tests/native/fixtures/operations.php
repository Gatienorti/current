<?php
if (getenv('CURRENT_SUPERVISOR_EXPERIMENT') !== '1' || ! preg_match('~^/tmp/current-supervisor-[a-f0-9]{12}$~D', getenv('EXPERIMENT_ROOT') ?: '')) { exit(2); }
foreach (['CURRENT_SUPERVISOR_NATIVE', 'CURRENT_SUPERVISOR_HISTORY'] as $flag) { putenv($flag.'=1'); $_ENV[$flag] = $_SERVER[$flag] = '1'; }
putenv('APP_ENV=local'); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';
$_ENV['EXPERIMENT_ROOT'] = $_SERVER['EXPERIMENT_ROOT'] = getenv('EXPERIMENT_ROOT');
$app = require __DIR__.'/boot.php';
set_exception_handler(function (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use SupervisorExperiment\BatchFixtureJob;

$root = getenv('EXPERIMENT_ROOT'); $process = null; $collector = null;
// Balancing runs earlier in the same disposable app and rewrites pool settings.
// Reset them: this check isolates batch semantics from SQLite writer contention.
$settings = require $root.'/config/current.php';
$settings['native']['instance'] = 'operations';
$settings['native']['pools'] = ['operations' => ['connection' => 'redis', 'queues' => ['experiment'], 'workers' => 1, 'max_workers' => 1, 'timeout' => 3]];
$settings['native']['defaults'] = []; $settings['native']['environments'] = [];
file_put_contents($root.'/config/current.php', '<?php return '.var_export($settings, true).';');
config()->set('current.native', $settings['native']);
config()->set('current.native.instance', 'operations');
$batchDatabase = null; $batchConnection = 'batch_sql';
// Laravel's batch repository uses read-then-write transactions. Keep its SQLite
// application store separate from the asynchronously written history database.
$databaseConfig = require $root.'/config/database.php';
touch($root.'/batch.sqlite');
$databaseConfig['connections']['batch_sql'] = ['driver' => 'sqlite', 'database' => $root.'/batch.sqlite', 'busy_timeout' => 5000, 'journal_mode' => 'WAL'];
file_put_contents($root.'/config/database.php', '<?php return '.var_export($databaseConfig, true).';');
config()->set('database.connections.batch_sql', $databaseConfig['connections']['batch_sql']);
if ($sqlHost = getenv('NATIVE_BATCH_SQL_HOST')) {
    $batchDatabase = 'current_batch_'.substr(basename($root), -12);
    $sql = ['driver' => 'mysql', 'host' => $sqlHost, 'port' => 3306, 'database' => 'current_async_test', 'username' => 'root', 'password' => getenv('NATIVE_BATCH_SQL_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
    config()->set('database.connections.batch_admin', $sql);
    app('db')->connection('batch_admin')->statement('CREATE DATABASE `'.$batchDatabase.'`');
    $sql['database'] = $batchDatabase;
    $databaseConfig = require $root.'/config/database.php'; $databaseConfig['connections']['batch_sql'] = $sql;
    file_put_contents($root.'/config/database.php', '<?php return '.var_export($databaseConfig, true).';');
    config()->set('database.connections.batch_sql', $sql); $batchConnection = 'batch_sql';
    $settings['native']['pools']['operations']['workers'] = 2; $settings['native']['pools']['operations']['max_workers'] = 2;
    file_put_contents($root.'/config/current.php', '<?php return '.var_export($settings, true).';');
    config()->set('current.native', $settings['native']);
}
config()->set('queue.batching', ['database' => $batchConnection, 'table' => 'job_batches']);
$queueConfig = require $root.'/config/queue.php'; $queueConfig['batching'] = config('queue.batching');
// Publish SQLite batches only after their counter transaction commits.
$queueConfig['connections']['redis']['after_commit'] = true;
config()->set('queue.connections.redis.after_commit', true);
file_put_contents($root.'/config/queue.php', '<?php return '.var_export($queueConfig, true).';');
Schema::connection($batchConnection)->create('job_batches', function ($t) {
    $t->string('id')->primary(); $t->string('name'); $t->integer('total_jobs'); $t->integer('pending_jobs'); $t->integer('failed_jobs');
    $t->longText('failed_job_ids'); $t->mediumText('options')->nullable(); $t->integer('cancelled_at')->nullable(); $t->integer('created_at'); $t->integer('finished_at')->nullable();
});
$store = app(Current\Store::class);
function operationWait(callable $condition, string $label): void {
    global $process, $collector;
    $end = microtime(true) + 25;
    do {
        foreach ([$process, $collector] as $p) { if ($p && ! $p->isRunning()) { throw new RuntimeException('Process exited '.$p->getOutput().$p->getErrorOutput()); } }
        if ($condition()) { return; } usleep(20000);
    } while (microtime(true) < $end);
    global $batch, $store;
    fwrite(STDERR, json_encode(['timeout' => $label, 'batch' => isset($batch) ? $batch->fresh()->toArray() : null, 'batch_history' => isset($batch) ? $store->table('jobs')->where('batch_id', $batch->id)->get()->all() : [], 'worker_output' => $process?->getOutput(), 'worker_error' => $process?->getErrorOutput()])."\n");
    throw new RuntimeException('Timeout '.$label);
}
try {
    if (config('current.telemetry.driver') === 'redis') {
        $collector = new Process([PHP_BINARY, $root.'/artisan', 'current:collect'], $root); $collector->setTimeout(null); $collector->start();
    }
    $process = new Process([PHP_BINARY, $root.'/artisan', 'current:supervise', '--instance=operations'], $root); $process->setTimeout(null); $process->start();
    operationWait(fn () => (app(Current\Native\Supervisor::class)->snapshot()['state'] ?? '') === 'running', 'worker startup');
    $batch = Bus::batch([(new BatchFixtureJob('a'))->afterCommit(), (new BatchFixtureJob('b'))->afterCommit()])->name('Native operations fixture')->allowFailures()->onConnection('redis')->onQueue('experiment')->dispatch();
    operationWait(fn () => $batch->fresh()->failedJobs === 2 && $store->table('jobs')->where('batch_id', $batch->id)->where('status', 'failed')->count() === 2, 'captured batch failures');
    $failed = $store->jobsWithObservationState()->where('batch_id', $batch->id)->get();
    foreach ($failed as $job) { app(Current\RetrySubmission::class)->submit($job); app(Current\RetrySubmission::class)->submit($job); }
    operationWait(fn () => $batch->fresh()->finished() && $store->table('jobs')->where('batch_id', $batch->id)->count() === 4, 'batch retries');
    if (array_sum(Redis::hgetall('batch-success')) !== 2 || $batch->fresh()->failedJobIds !== []) { throw new RuntimeException('Batch retry effects differed'); }
    operationWait(fn () => $store->table('jobs')->where('batch_id', $batch->id)->where('status', 'completed')->count() === 2, 'final attempt telemetry');
    // Move only these synthetic records into a closed bucket to verify the public
    // scheduler command without a five-minute wall-clock wait. No timing benchmark.
    $end = intdiv(time(), 300) * 300;
    $ids = $store->table('jobs')->where('batch_id', $batch->id)->pluck('id');
    $store->table('attempts')->whereIn('job_id', $ids)->update(['started_at' => $end - 10.02, 'finished_at' => $end - 10]);
    if (Artisan::call('current:snapshot') !== 0) { throw new RuntimeException('Snapshot command failed'); }
    $point = app(Current\Native\Metrics::class)->series('jobs', [BatchFixtureJob::class])[11];
    if ($point['completed'] !== 2 || $point['failed'] !== 2) { throw new RuntimeException('Snapshot counts differed'); }
    config()->set('session.driver', 'array'); config()->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']); config()->set('auth.defaults.guard', 'web');
    Illuminate\Support\Facades\Auth::setUser(new Illuminate\Auth\GenericUser(['id' => 1]));
    Illuminate\Support\Facades\Gate::define('viewCurrent', fn ($user) => true);
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    foreach (['/current/batches/'.$batch->id, '/current/metrics/jobs/history?'.http_build_query(['job_class' => BatchFixtureJob::class]), '/current/dashboard'] as $path) {
        $response = $kernel->handle(Illuminate\Http\Request::create($path, 'GET'));
        if ($response->getStatusCode() !== 200) { throw new RuntimeException('Native operations page failed: '.$path); }
    }
    // Stop actual workers before administrative waiting-queue maintenance.
    if (Artisan::call('current:terminate', ['--instance' => 'operations', '--wait' => '10']) !== 0) { throw new RuntimeException('Maintenance shutdown failed'); }
    $process->wait(); if ($process->getExitCode() !== 0) { throw new RuntimeException('Maintenance launcher exited unsuccessfully'); } $process = null;
    $before = $store->table('jobs')->count();
    Illuminate\Support\Facades\Queue::push(new SupervisorExperiment\FixtureJob('maintenance-waiting'));
    Illuminate\Support\Facades\Queue::later(3600, new SupervisorExperiment\FixtureJob('maintenance-delayed'));
    if (Artisan::call('current:clear', ['connection' => 'redis', '--queue' => 'experiment']) !== 0) { throw new RuntimeException('Native clear failed'); }
    operationWait(fn () => $store->table('jobs')->whereNotNull('cleared_at')->count() === 2, 'clear observations projected');
    if (Redis::llen('queues:experiment') !== 0 || Redis::zcard('queues:experiment:delayed') !== 0) { throw new RuntimeException('Queue clear left waiting entries'); }
    $oldFailure = $store->jobsWithObservationState()->where('batch_id', $batch->id)->whereRaw($store->outcomeSql()." = 'failed'")->first();
    if (! $oldFailure || Artisan::call('current:forget', ['id' => $oldFailure->id, '--history-only' => true]) !== 0) { throw new RuntimeException('Failed history removal failed'); }
    if ($store->table('jobs')->where('id', $oldFailure->id)->exists()) { throw new RuntimeException('Failed history remained visible'); }
    if (Artisan::call('current:clear-metrics') !== 0 || Artisan::call('current:snapshot') !== 0 || $store->table('native_metrics')->where('kind', '!=', 'window')->count() !== 0) { throw new RuntimeException('Metric reset rebuilt old windows'); }
    echo json_encode(['accepted' => true, 'batch_jobs' => 2, 'batch_store' => $batchConnection, 'workers' => $settings['native']['pools']['operations']['workers'], 'batch_retry_dispatches' => 4, 'checks' => ['batch_membership', 'shared_retry_claim', 'exact_effects', 'snapshot_command', 'metric_history_page', 'batch_page', 'dashboard', 'clear_waiting', 'forget_history', 'metric_reset']])."\n";
} finally {
    if ($process?->isRunning()) { $process->stop(12, SIGKILL); }
    if ($collector?->isRunning()) { $collector->stop(12, SIGKILL); }
    if ($batchDatabase) { app('db')->purge('batch_sql'); app('db')->connection('batch_admin')->statement('DROP DATABASE `'.$batchDatabase.'`'); }
}
