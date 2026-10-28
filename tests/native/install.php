<?php
// Clean Composer consumer, Linux/Redis only. Does not install into a host application.
$root = dirname(__DIR__, 2);
$constraint = $argv[1] ?? '^12.0';
$profile = $argv[2] ?? 'local';
$drivers = explode(',', $argv[3] ?? 'sync');
if (!$drivers || array_diff($drivers, ['sync', 'redis']) || count(array_unique($drivers)) !== count($drivers)) { throw new InvalidArgumentException('Choose sync, redis, or sync,redis'); }
if (!preg_match('/^[a-z0-9-]+$/D', $profile)) { throw new InvalidArgumentException('Invalid profile'); }
$directory = sys_get_temp_dir().'/current-native-install-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
$manifest = ['name' => 'current/native-install-fixture', 'repositories' => [['type' => 'path', 'url' => $root, 'options' => ['symlink' => true, 'versions' => ['gatienorti/current' => 'dev-main']]]],
    'require' => ['gatienorti/current' => 'dev-main', 'laravel/framework' => $constraint], 'config' => ['allow-plugins' => false]];
// Match the existing historical floor matrix; old Laravel command registration
// predates Symfony Console 7.4. This is a fixture pin, not a host constraint.
if (in_array($constraint, ['11.44.0', '12.0.0'], true)) {
    $manifest['require']['symfony/console'] = '~7.3.0';
}
if (getenv('NATIVE_REDIS_CLIENT') === 'predis') {
    $version = getenv('NATIVE_PREDIS_VERSION') ?: '^3.0';
    if (! preg_match('/^(?:2\.3\.0|3\.3\.0|\^3\.0)$/D', $version)) { throw new InvalidArgumentException('Unsupported fixture Predis version.'); }
    $manifest['require']['predis/predis'] = $version;
}
file_put_contents($directory.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
function runNative(array $command, string $directory, array $environment): string {
    $p = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, $directory, $environment);
    if (!is_resource($p)) { throw new RuntimeException('Could not start fixture command'); }
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    if (proc_close($p) !== 0) { throw new RuntimeException('Native fixture failed: '.implode(' ', $command)."\n".$output); }
    return $output;
}
$environment = getenv();
runNative(['composer', 'update', '--no-interaction', '--no-progress', '--prefer-dist', '--no-plugins', '--no-scripts', '--no-security-blocking'], $directory, $environment);
$packages = json_decode(file_get_contents($directory.'/composer.lock'), true)['packages'];
if (in_array('laravel/horizon', array_column($packages, 'name'), true)) { throw new RuntimeException('Horizon installed in native consumer'); }
$results = [];
foreach ($drivers as $driver) {
$environment = array_merge($environment, ['CURRENT_SUPERVISOR_EXPERIMENT' => '1', 'NATIVE_PACKAGE' => '1', 'HISTORY_DRIVER' => $driver,
    'HISTORY_NAMED_POOLS' => '1', 'HISTORY_RESULT_NAME' => 'native-install-'.$profile.'-'.$driver, 'EXPERIMENT_AUTOLOAD' => $directory.'/vendor/autoload.php']);
$output = runNative([PHP_BINARY, $root.'/tests/native/fixtures/history.php'], $root, $environment);
$result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
if (!$result['accepted']) { throw new RuntimeException('Native execution failed'); }
$environment['EXPERIMENT_ROOT'] = $result['root'];
if (getenv('NATIVE_REQUIRE_LIGHTWEIGHT') === '1') {
    $lightweight = json_decode(runNative([PHP_BINARY, $root.'/tests/native/fixtures/lightweight.php'], $root, $environment), true, 512, JSON_THROW_ON_ERROR);
    if (! $lightweight['accepted']) { throw new RuntimeException('Native lightweight compatibility failed'); }
}
$http = json_decode(runNative([PHP_BINARY, $root.'/tests/native/fixtures/standalone-http.php'], $root, $environment), true, 512, JSON_THROW_ON_ERROR);
if (!$http['accepted']) { throw new RuntimeException('Native HTTP failed'); }
$results[$driver] = ['execution' => $result, 'http' => $http];
if (isset($lightweight)) { $results[$driver]['lightweight'] = $lightweight; }
if (getenv('NATIVE_BALANCING') === '1') {
    $results[$driver]['balancing'] = json_decode(runNative([PHP_BINARY, $root.'/tests/native/fixtures/balancing.php'], $root, $environment), true, 512, JSON_THROW_ON_ERROR);
    if (! $results[$driver]['balancing']['accepted']) { throw new RuntimeException('Native balancing failed'); }
}
if (getenv('NATIVE_OPERATIONS') === '1') {
    $results[$driver]['operations'] = json_decode(runNative([PHP_BINARY, $root.'/tests/native/fixtures/operations.php'], $root, $environment), true, 512, JSON_THROW_ON_ERROR);
    if (! $results[$driver]['operations']['accepted']) { throw new RuntimeException('Native operations failed'); }
}
foreach (['NATIVE_DEPLOYMENT' => 'deployment', 'NATIVE_TRANSITIONS' => 'transitions'] as $flag => $fixture) {
    if (getenv($flag) === '1') {
        $results[$driver][$fixture] = json_decode(runNative([PHP_BINARY, $root.'/tests/native/fixtures/'.$fixture.'.php'], $root, $environment), true, 512, JSON_THROW_ON_ERROR);
        if (! $results[$driver][$fixture]['accepted']) { throw new RuntimeException('Native '.$fixture.' failed'); }
    }
}
}
echo json_encode(['accepted' => true, 'profile' => $profile, 'consumer' => $directory, 'php' => PHP_VERSION, 'drivers' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
