<?php

// Run against a disposable Laravel app installed through Composer, not a host checkout.
use Current\Current;
use HorizonLens\Store;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Horizon;
use Laravel\Reverb\ReverbServiceProvider;

$root = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
});
function check(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAIL '.$label);
    }
    echo 'PASS '.$label."\n";
}
$driver = $argv[2] ?? 'sqlite';
if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
    throw new InvalidArgumentException('Expected sqlite, mysql or mariadb.');
}
$connectionDriver = $argv[3] ?? $driver;
if ($connectionDriver !== $driver && ! ($driver === 'mariadb' && $connectionDriver === 'mysql')) {
    throw new InvalidArgumentException('Only MariaDB may use the alternate mysql driver.');
}
if (in_array($driver, ['mysql', 'mariadb'], true)) {
    config()->set('database.connections.current_mysql', [
        'driver' => $connectionDriver, 'host' => 'current-install-'.$driver, 'database' => 'current',
        'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '', 'strict' => true,
    ]);
    config()->set('horizon-lens.connection', 'current_mysql');
}
config()->set([
    'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'session.driver' => 'array', 'cache.default' => 'array',
    'queue.default' => 'redis', 'queue.failed.driver' => 'null',
    'database.redis.client' => 'phpredis',
    'database.redis.default.host' => 'lens-redis',
    'database.redis.options.prefix' => 'current_install_'.bin2hex(random_bytes(6)).':',
    'database.redis.horizon.host' => 'lens-redis',
    'database.redis.horizon.options.prefix' => 'current_install_horizon_'.bin2hex(random_bytes(6)).':',
]);
check(Artisan::call('current:install') === 0, 'installer through discovered provider');
check(Artisan::call('current:install') === 0, 'repeat installation');
check(app(Store::class)->connection()->table('migrations')->count() === 11, 'all eleven package migrations');
check(! config('horizon-lens.webhooks.enabled') && ! config('horizon-lens.webhooks.outgoing.enabled') && ! config('horizon-lens.broadcasts.enabled'), 'optional collectors default off');
check(! class_exists(ReverbServiceProvider::class), 'jobs-only install needs no Reverb');
check(config('horizon-lens.path') === 'current', 'canonical Current path');
check(str_ends_with(route('horizon-lens.index'), '/current'), 'registered Current route');
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $http->handle(Request::create('/current'));
check($response->getStatusCode() === 403, 'anonymous dashboard denied');
$app['auth']->guard()->setUser(new GenericUser(['id' => 1]));
Gate::define('viewHorizon', fn ($user) => true);
Horizon::auth(fn ($request) => true);
$response = $http->handle(Request::create('/current'));
check($response->getStatusCode() === 200, 'authorized dashboard available');
$response = $http->handle(Request::create('/current/webhooks/dashboard'));
check($response->getStatusCode() === 200 && str_contains($response->getContent(), 'capture: disabled'), 'webhook dashboard has explicit disabled coverage');
check($http->handle(Request::create('/current/broadcasts/dashboard'))->getStatusCode() === 404, 'broadcast dashboard hidden without Reverb');
check(! Schema::connection(config('database.default'))->hasTable('migrations'), 'application database is untouched');

class CurrentInstallJob implements ShouldQueue
{
    public int $tries = 2;

    public string $apiToken = 'fixture-secret-never-retain';

    public function __construct(public bool $fail = false) {}

    public function handle(): void
    {
        Current::step('Validate input', fn () => true);
        Current::step('Perform work', function () {
            if ($this->fail) {
                throw new RuntimeException('fixture failure');
            }
        });
    }
}
Queue::pushOn('current-install', new CurrentInstallJob);
Queue::pushOn('current-install', new CurrentInstallJob(true));
check(Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'current-install', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 2]) === 0, 'real queue worker');
$store = app(Store::class);
$jobs = $store->jobsWithObservationState()->get();
check($jobs->count() === 2, 'both jobs retained');
check($jobs->pluck('display_status')->sort()->values()->all() === ['completed', 'failed'], 'success and terminal retry outcome');
check($store->table('attempts')->count() === 3, 'retry attempt history');
check($store->table('steps')->count() === 6, 'Current step API');
check(! str_contains(json_encode($jobs), 'fixture-secret-never-retain'), 'redaction before persistence');
check($jobs->every(fn ($job) => $job->arguments_state === 'captured'), 'automatic arguments');
$response = $http->handle(Request::create('/current/jobs/'.$jobs->first()->id));
check($response->getStatusCode() === 200, 'job investigation rendering');
$response = $http->handle(Request::create('/current'));
check($response->getStatusCode() === 200, 'dashboard with populated outcomes');
$response = $http->handle(Request::create('/current/monitoring?status=failed&queue=current-install'));
check($response->getStatusCode() === 200 && str_contains($response->getContent(), $jobs->firstWhere('display_status', 'failed')->external_id)
    && ! str_contains($response->getContent(), $jobs->firstWhere('display_status', 'completed')->external_id), 'combined status and queue filters');
$store->table('jobs')->update(['expires_at' => time() - 1]);
check(Artisan::call('current:prune') === 0 && $store->table('jobs')->count() === 0 && $store->table('steps')->count() === 0, 'retention cleanup');
echo 'Driver: '.$store->connection()->getDriverName()."\n";
if ($driver !== 'sqlite') {
    echo 'Server: '.$store->connection()->selectOne('SELECT VERSION() AS version')->version."\n";
    echo 'SQL mode: '.$store->connection()->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode."\n";
}
