<?php

// Isolated consumer fixture: confirm optimization and compatibility fallbacks.
if (getenv('NATIVE_PACKAGE') !== '1' || getenv('NATIVE_REQUIRE_LIGHTWEIGHT') !== '1') { exit(1); }
foreach (['CURRENT_SUPERVISOR_NATIVE', 'CURRENT_SUPERVISOR_HISTORY'] as $flag) { putenv($flag.'=1'); }
$app = require __DIR__.'/boot.php';
$root = getenv('EXPERIMENT_ROOT');
$file = $root.'/config/current.php'; $original = file_get_contents($file);
$configuration = require $file;
$configuration['native']['pools'] = ['idle' => ['connection' => 'redis', 'queues' => ['lightweight-unused'], 'workers' => 0, 'min_workers' => 0, 'max_workers' => 1, 'timeout' => 3]];
$configuration['native']['environments'] = []; $configuration['native']['defaults'] = [];
$configuration['native']['instance'] = 'lightweight';
$configuration['native']['lightweight_launcher'] = true;
$process = null; $checks = [];
try {
    foreach (['default', 'opt-out', 'env', 'php-options'] as $mode) {
        $configuration['native']['lightweight_launcher'] = $mode !== 'opt-out';
        config()->set('current.native', $configuration['native']);
        file_put_contents($file, '<?php return '.var_export($configuration, true).';');
        $command = [PHP_BINARY];
        if ($mode === 'php-options') { array_push($command, '-d', 'memory_limit=512M'); }
        array_push($command, $root.'/artisan', 'current:supervise');
        if ($mode === 'env') { $command[] = '--env=local'; }
        $process = new Symfony\Component\Process\Process($command, $root); $process->setTimeout(null)->start();
        $supervisor = new Current\Native\Supervisor; $deadline = microtime(true) + 10;
        do {
            if (! $process->isRunning()) { throw new RuntimeException('Launcher exited during '.$mode); }
            $health = $supervisor->snapshot();
            if (($health['state'] ?? '') === 'running') { break; }
            usleep(20000);
        } while (microtime(true) < $deadline);
        if (($health['state'] ?? '') !== 'running') { throw new RuntimeException('Launcher not ready during '.$mode); }
        $light = str_contains(file_get_contents('/proc/'.$process->getPid().'/cmdline'), '/src/Native/controller.php');
        if ($light !== ($mode === 'default')) { throw new RuntimeException('Unexpected handoff during '.$mode); }
        $process->signal(SIGTERM); $process->setTimeout(15)->wait();
        if ($process->getExitCode() !== 0) { throw new RuntimeException('Unclean shutdown during '.$mode); }
        if (glob($configuration['native']['state_directory'].'/controller-*') || glob($configuration['native']['state_directory'].'/config-*')) {
            throw new RuntimeException('Private handoff/runtime file survived normal shutdown');
        }
        $checks[] = $mode;
    }
    echo json_encode(['accepted' => true, 'checks' => $checks])."\n";
} finally {
    if ($process?->isRunning()) { $process->stop(12); }
    file_put_contents($file, $original);
}
