<?php

// Run from a PHP environment with Composer, Redis, SQLite, and PCNTL available.
// Each row resolves this package as a path dependency in a fresh temporary app.
$root = dirname(__DIR__, 2);
$matrix = json_decode(file_get_contents(__DIR__.'/matrix.json'), true, flags: JSON_THROW_ON_ERROR);
$names = array_slice($argv, 1) ?: array_keys($matrix);
foreach ($names as $name) {
    if (! isset($matrix[$name])) {
        throw new InvalidArgumentException("Unknown compatibility row: {$name}");
    }
}

function runCommand(array $command, string $directory, array $environment): void
{
    $process = proc_open($command, [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $directory, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start compatibility command.');
    }
    while (! feof($pipes[1])) {
        echo fread($pipes[1], 8192);
    }
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Compatibility command failed: '.implode(' ', $command));
    }
}

foreach ($names as $name) {
    $row = $matrix[$name];
    $directory = sys_get_temp_dir().'/current-'.$name.'-'.bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);
    foreach (['bootstrap.php', 'phpunit.xml', 'tests', 'prototype', 'database', 'collector'] as $entry) {
        $source = $root.'/'.$entry;
        $files = is_dir($source)
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS))
            : [new SplFileInfo($source)];
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (str_starts_with($relative, 'prototype/storage/') || str_starts_with($relative, 'prototype/bootstrap/cache/') || str_contains($relative, '/.env')) {
                continue;
            }
            $target = $directory.'/'.$relative;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            copy($file->getPathname(), $target);
            if ($relative === 'collector/current-collector') {
                chmod($target, 0755);
            }
        }
    }
    foreach (['bootstrap/cache', 'storage/data', 'storage/logs', 'storage/framework/views', 'storage/framework/cache', 'storage/framework/sessions'] as $path) {
        mkdir($directory.'/prototype/'.$path, 0700, true);
    }
    $manifest = [
        'name' => 'current/compatibility-fixture',
        'repositories' => [['type' => 'path', 'url' => $root, 'options' => ['symlink' => true, 'versions' => ['gatienorti/current' => 'dev-main']]]],
        'require' => array_merge([
            'gatienorti/current' => 'dev-main',
            'laravel/framework' => $row['framework'], 'laravel/horizon' => $row['horizon'],
            'mockery/mockery' => '^1.6', 'phpunit/phpunit' => '^10.5 || ^11.5',
        ], $row['dependencies'] ?? []),
        'config' => ['allow-plugins' => false],
    ];
    file_put_contents($directory.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    $environment = getenv();
    $environment['CURRENT_REQUIRE_COLLECTOR_TESTS'] = is_executable($root.'/collector/current-collector') ? '1' : '0';
    $environment['LENS_VENDOR_AUTOLOAD'] = $directory.'/vendor/autoload.php';
    $environment['APP_PACKAGES_CACHE'] = $directory.'/prototype/bootstrap/cache/packages.php';
    $environment['APP_SERVICES_CACHE'] = $directory.'/prototype/bootstrap/cache/services.php';
    $environment['APP_CONFIG_CACHE'] = $directory.'/prototype/bootstrap/cache/config.php';
    $environment['APP_ROUTES_CACHE'] = $directory.'/prototype/bootstrap/cache/routes.php';
    $environment['APP_ENV'] = 'testing';
    $environment['HORIZON_PREFIX'] = 'lens_compat_'.bin2hex(random_bytes(8)).':';
    echo "\nCompatibility row: {$name}; PHP ".PHP_VERSION."; artifacts: {$directory}\n";
    $install = ['composer', 'update', '--no-interaction', '--prefer-dist', '--no-progress', '--no-scripts', '--no-plugins'];
    if (($row['historical'] ?? false) || ! str_starts_with($row['framework'], '^')) {
        // Historical fixtures may have advisories. Permit them only in this disposable
        // test consumer, keep audit reporting, and never change host/package policy.
        $install[] = '--no-security-blocking';
    }
    runCommand($install, $directory, $environment);
    // Host-specific optional smoke test is run separately against Vehify's installed tree.
    runCommand([PHP_BINARY, 'vendor/bin/phpunit', '-c', 'phpunit.xml', '--exclude-group', 'vehify'], $directory, $environment);
    echo "PASS {$name}\n";
}
