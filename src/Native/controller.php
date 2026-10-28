<?php

// Private local entrypoint. Probe input is piped through Go's bounded sampler;
// exec input uses a short-lived owner-only file, never credentials in argv.
ini_set('display_errors', '0'); ini_set('log_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux') { exit(1); }
    $probe = $argc === 1 || array_slice($argv, 1) === ['current:runtime-snapshot', '--no-interaction', '--no-ansi'];
    if ($probe) {
        $raw = stream_get_contents(STDIN, 262145);
        if (strlen($raw) > 262144) { exit(1); }
    } else {
        $path = $argv[1]; $directory = dirname($path);
        if ($argc !== 2 || ! str_starts_with($path, '/') || ! preg_match('/^controller-[a-zA-Z0-9]+$/D', basename($path))
            || is_link($directory) || ! is_dir($directory) || (fileperms($directory) & 0077)
            || fileowner($directory) !== posix_geteuid() || is_link($path)) { exit(1); }
        $file = fopen($path, 'rb'); $stat = fstat($file);
        if (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) || $stat['uid'] !== posix_geteuid()
            || $stat['size'] > 8388608) { fclose($file); exit(1); }
        $raw = stream_get_contents($file, 8388609); fclose($file); unlink($path);
    }
    $request = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); unset($raw);
    if (! is_array($request) || ! is_string($request['autoload'] ?? null) || ! str_starts_with($request['autoload'], '/')
        || ! is_file($request['autoload']) || (! $probe && ($request['runtime']['state_directory'] ?? null) !== $directory)) { exit(1); }
    $loader = require $request['autoload'];
    $loader->addPsr4('Current\\', dirname(__DIR__).'/');
    $result = (new \Current\Native\LightweightController)->run($request, $probe);
    if ($probe && $result === 0) { fwrite(STDOUT, "ready\n"); }
    exit($result);
} catch (\Throwable) {
    if (isset($probe) && ! $probe) { fwrite(STDERR, "Native lightweight launcher failed; check configuration or disable CURRENT_NATIVE_LIGHTWEIGHT_LAUNCHER.\n"); }
    exit(1);
}
