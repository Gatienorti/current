<?php

// Private-pipe entrypoint. No application bootstrap, providers, Redis or HTTP.
// The Go parent retains its input/output bounds, deadline and process-group cleanup.
ini_set('display_errors', '0');
ini_set('log_errors', '0');

try {
    if (PHP_SAPI !== 'cli') { exit(1); }
    $input = stream_get_contents(STDIN, 262145);
    if (strlen($input) > 262144) { exit(1); }
    $request = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
    $autoload = $request['autoload'] ?? null;
    if (! is_string($autoload) || ! str_starts_with($autoload, '/') || ! is_file($autoload)
        || ! is_array($request['source'] ?? null) || ! is_array($request['routes'] ?? null)) { exit(1); }
    require $autoload;
    require_once __DIR__.'/RuntimeSnapshot.php';
    $container = new \Illuminate\Container\Container;
    \Illuminate\Container\Container::setInstance($container);
    $container->instance('config', new \Illuminate\Config\Repository);
    $snapshot = new \Current\Native\RuntimeSnapshot;
    $snapshot->validateRoutes($request['routes']);
    $database = (new \Illuminate\Database\Connectors\ConnectionFactory($container))->make($request['source'], 'current_runtime_read');
    $output = json_encode($snapshot->sample($request['routes'], $database), JSON_THROW_ON_ERROR);
    $database->disconnect();
    if (strlen($output) > 262144) { exit(1); }
    fwrite(STDOUT, $output);
} catch (\Throwable) {
    exit(1); // SQL failures and connection secrets never leave the private reader.
}
