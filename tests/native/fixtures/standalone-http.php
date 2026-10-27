<?php
if (getenv('CURRENT_SUPERVISOR_EXPERIMENT') !== '1' || !preg_match('~^/tmp/current-supervisor-[a-f0-9]{12}$~D', getenv('EXPERIMENT_ROOT') ?: '')) { exit(2); }
require getenv('EXPERIMENT_AUTOLOAD') ?: '/opt/vehify/vendor/autoload.php';
foreach (['CURRENT_SUPERVISOR_NATIVE', 'CURRENT_SUPERVISOR_HISTORY'] as $flag) { putenv($flag.'=1'); $_ENV[$flag] = $_SERVER[$flag] = '1'; }
putenv('APP_ENV=local'); $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';
$_ENV['EXPERIMENT_ROOT'] = $_SERVER['EXPERIMENT_ROOT'] = getenv('EXPERIMENT_ROOT');
$app = require __DIR__.'/boot.php';
if (class_exists(Laravel\Horizon\Horizon::class)) { throw new RuntimeException('Horizon unexpectedly installed'); }
config()->set('session.driver', 'array');
config()->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
config()->set('auth.defaults.guard', 'web');
Illuminate\Support\Facades\Auth::setUser(new Illuminate\Auth\GenericUser(['id' => 1]));
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$request = fn ($path) => Illuminate\Http\Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
Illuminate\Support\Facades\Gate::define('viewCurrent', fn ($user) => false);
$response = $kernel->handle($request('/current/dashboard'));
if ($response->getStatusCode() !== 403) { throw new RuntimeException('Standalone dashboard gate did not deny'); }
Illuminate\Support\Facades\Gate::define('viewCurrent', fn ($user) => true);
$paths = ['/current/dashboard', '/current/queues', '/current/metrics/jobs', '/current/metrics/queues', '/current/batches', '/current/silenced'];
$job = app(Current\Store::class)->table('jobs')->first();
$paths[] = '/current/jobs/'.$job->id;
foreach ($paths as $path) {
    $response = $kernel->handle($request($path));
    if ($response->getStatusCode() !== 200) { throw new RuntimeException($path.' returned '.$response->getStatusCode()); }
    if (str_contains($response->getContent(), 'horizon.index')) { throw new RuntimeException('Horizon route dependency'); }
}
echo json_encode(['accepted' => true, 'laravel' => $app->version(), 'horizon_installed' => false, 'denied_gate' => 403, 'pages' => $paths], JSON_PRETTY_PRINT)."\n";
