<?php

// Creates only disposable /tmp consumers. Requires the isolated lab's Redis network.
$package = dirname(__DIR__, 2);
$resume = $argv[1] ?? null;
$directory = $resume ?? sys_get_temp_dir().'/current-beta-install-'.bin2hex(random_bytes(5));
if ($resume !== null && (! preg_match('#^'.preg_quote(sys_get_temp_dir(), '#').'/current-beta-install-[a-f0-9]{10}$#D', $directory) || ! is_file($directory.'/composer.lock'))) {
    throw new InvalidArgumentException('Resume accepts only a disposable current-beta-install consumer.');
}
$environment = getenv();
foreach (['APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE', 'LENS_VENDOR_AUTOLOAD'] as $key) {
    unset($environment[$key]);
}
$environment['APP_ENV'] = 'testing';
$environment['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
$environment['SESSION_DRIVER'] = 'array';
$environment['CACHE_STORE'] = 'array';
$environment['QUEUE_CONNECTION'] = 'redis';
$environment['REDIS_HOST'] = 'lens-redis';
$environment['REDIS_PREFIX'] = 'current_fresh_'.bin2hex(random_bytes(6)).':';
$environment['HORIZON_PREFIX'] = 'current_fresh_horizon_'.bin2hex(random_bytes(6)).':';
$environment['DB_CONNECTION'] = 'sqlite';
$environment['DB_DATABASE'] = $directory.'/database/application.sqlite';
$environment['LOG_CHANNEL'] = 'single';
$environment['COMPOSER_ALLOW_SUPERUSER'] = '1';

function runInstallCommand(array $command, string $cwd, array $environment): void
{
    echo '> '.implode(' ', $command)."\n";
    $process = proc_open($command, [0 => STDIN, 1 => ['file', $cwd.'/current-install-check.log', 'a'], 2 => ['redirect', 1]], $pipes, $cwd, $environment);
    if (! is_resource($process) || proc_close($process) !== 0) {
        echo substr(file_get_contents($cwd.'/current-install-check.log'), -6000);
        throw new RuntimeException('Installation check command failed. See '.$cwd.'/current-install-check.log');
    }
}

echo "Fresh consumer: {$directory}\n";
if ($resume === null) {
    runInstallCommand(['composer', 'create-project', 'laravel/laravel', $directory, '^13.0', '--no-install', '--no-scripts', '--no-interaction', '--prefer-dist'], sys_get_temp_dir(), $environment);
    touch($environment['DB_DATABASE']);
    runInstallCommand(['composer', 'config', 'repositories.current', json_encode(['type' => 'path', 'url' => $package, 'options' => ['symlink' => true, 'versions' => ['gatienorti/current' => 'dev-main']]])], $directory, $environment);
    runInstallCommand(['composer', 'require', 'gatienorti/current:dev-main', '--no-interaction', '--prefer-dist', '--no-progress'], $directory, $environment);
    runInstallCommand([PHP_BINARY, $package.'/tests/installation/smoke.php', $directory], $directory, $environment);
    runInstallCommand([PHP_BINARY, 'artisan', 'config:cache'], $directory, $environment);
    runInstallCommand([PHP_BINARY, 'artisan', 'route:cache'], $directory, $environment);
    runInstallCommand([PHP_BINARY, $package.'/tests/installation/smoke.php', $directory], $directory, $environment);
    runInstallCommand([PHP_BINARY, 'artisan', 'optimize:clear'], $directory, $environment);
}
runInstallCommand(['composer', 'require', 'laravel/reverb:^1.7', '--with-all-dependencies', '--no-interaction', '--prefer-dist', '--no-progress'], $directory, $environment);
runInstallCommand([PHP_BINARY, $package.'/tests/installation/optional.php', $directory], $directory, $environment);
echo ($resume === null ? "PASS fresh jobs-only and optional-integration installation." : "PASS resumed optional-integration installation.")." Artifacts: {$directory}\n";
