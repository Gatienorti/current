<?php

// Synthetic integration acceptance check for a fresh disposable Composer consumer.
use Current\Current;
use HorizonLens\Broadcasts\ReverbIntegration;
use HorizonLens\Store;
use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Horizon;
use Symfony\Component\Process\Process;

$root = $argv[1];
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
});
function verify(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAIL '.$label);
    }
    echo 'PASS '.$label."\n";
}
class CurrentOptionalJob implements ShouldQueue
{
    public function handle(): void
    {
        app()->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::preventStrayRequests();
        Http::fake(['https://receiver.example.test/webhook' => Http::sequence()->push([], 503)->push([], 202)]);
        Current::step('Send webhook', fn () => Http::currentWebhook('install-logical-delivery')->retry(2, 0)->post('https://receiver.example.test/webhook'));
        Current::step('Broadcast update', fn () => app(BroadcastManager::class)->connection('reverb')->broadcast(['private-install.42'], 'InstallUpdated', ['order' => 42, 'token' => 'never-retain-install-secret']));
    }
}

config()->set([
    'database.redis.options.prefix' => 'current_optional_'.bin2hex(random_bytes(6)).':',
    'database.redis.horizon.host' => 'lens-redis',
    'database.redis.horizon.options.prefix' => 'current_optional_horizon_'.bin2hex(random_bytes(6)).':',
    'queue.failed.driver' => 'null',
]);
$http = $app->make(Illuminate\Contracts\Http\Kernel::class);
$store = app(Store::class);
verify(ReverbIntegration::visible(), 'Reverb installed later makes Broadcasts visible without reinstalling Current');
verify(! config('horizon-lens.broadcasts.enabled'), 'installing Reverb does not opt into payload/submission capture');
verify($http->handle(Request::create('/current/broadcasts/dashboard'))->getStatusCode() === 403, 'optional dashboard still denies anonymous access');
$app['auth']->guard()->setUser(new GenericUser(['id' => 1]));
Gate::define('viewHorizon', fn ($user) => true);
Horizon::auth(fn ($request) => true);
verify($http->handle(Request::create('/current/broadcasts/dashboard'))->getStatusCode() === 200, 'later Reverb capability needs no Current reinstall');
Http::preventStrayRequests();
Http::fake(['https://receiver.example.test/webhook' => Http::response([], 202)]);
Http::post('https://receiver.example.test/webhook', ['token' => 'never-retain-install-secret']);
Route::post('/__current-install-hook', function (Request $request) {
    $request->attributes->set('current.webhook.signature', 'valid'); // Trusted synthetic fixture only.
    $request->attributes->set('current.webhook.group', ['event_id' => 'install-event', 'scope' => 'synthetic-account']);
    $request->attributes->set('current.webhook.context', ['event_id' => 'install-event', 'payload' => ['order' => 42, 'token' => 'never-retain-install-secret']]);
    if (config('horizon-lens.webhooks.enabled')) {
        Queue::pushOn('current-optional', new CurrentOptionalJob);
    }

    return response('accepted', 202);
})->middleware('current.webhook:install-fixture');
verify($http->handle(Request::create('/__current-install-hook', 'POST'))->getStatusCode() === 202, 'default-off incoming handler unchanged');
verify($store->table('webhook_receipts')->count() === 0 && $store->table('webhook_deliveries')->count() === 0, 'webhook default-off collection');

$socket = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$server = new Process([PHP_BINARY, __DIR__.'/reverb-server.php', $root, (string) $port]);
$server->start();
try {
    $ready = false;
    for ($i = 0; $i < 60; $i++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
        if ($connection) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    verify($ready, 'isolated loopback Reverb server ready');
    config()->set([
        'horizon-lens.webhooks.enabled' => true,
        'horizon-lens.webhooks.capture_context' => true,
        'horizon-lens.webhooks.outgoing.enabled' => true,
        'horizon-lens.webhooks.outgoing.destinations' => ['install-receiver' => 'https://receiver.example.test/webhook'],
        'horizon-lens.broadcasts.enabled' => true,
        'horizon-lens.broadcasts.events' => ['InstallUpdated'],
        'horizon-lens.broadcasts.channels' => ['private-install.*'],
        'horizon-lens.broadcasts.capture_channels' => true,
        'horizon-lens.broadcasts.context' => fn ($event, $channels, $payload) => ['payload' => $payload, 'metadata' => ['source' => 'fresh-install']],
        'horizon-lens.broadcasts.authorization' => ['enabled' => true, 'paths' => ['__current-install-auth']],
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => [
            'driver' => 'reverb', 'key' => 'current-install-key', 'secret' => 'current-install-secret', 'app_id' => 'current-install',
            'options' => ['host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http', 'useTLS' => false],
            'client_options' => ['timeout' => 2, 'connect_timeout' => 1],
        ],
    ]);
    ReverbIntegration::configure();
    app(BroadcastManager::class)->purge('reverb');
    foreach ([1, 2] as $iteration) {
        verify($http->handle(Request::create('/__current-install-hook', 'POST'))->getStatusCode() === 202, 'opt-in incoming response preserved');
        verify(Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'current-optional', '--once' => true, '--sleep' => 0, '--tries' => 1]) === 0, 'linked real queue job consumed');
    }
    $receipts = $store->table('webhook_receipts')->get();
    $deliveries = $store->table('webhook_deliveries')->get();
    $broadcasts = $store->table('broadcasts')->get();
    verify($receipts->count() === 2 && $receipts->pluck('receipt_key')->unique()->count() === 1, 'repeated incoming grouping');
    verify($store->table('webhook_jobs')->count() === 2, 'receipt-to-job links');
    verify($deliveries->count() === 4 && $deliveries->pluck('delivery_key')->unique()->count() === 1, 'outgoing retries grouped across two jobs');
    verify($broadcasts->count() === 2 && $broadcasts->every(fn ($row) => $row->outcome === 'accepted' && $row->job_id !== null), 'real Reverb submissions linked to queue jobs');
    verify(! str_contains(json_encode([$receipts, $deliveries, $broadcasts]), 'never-retain-install-secret'), 'optional exports redacted before persistence');
    foreach (['/current/webhooks/dashboard', '/current/webhooks/'.$receipts[0]->id, '/current/webhooks/outgoing/'.$deliveries[0]->id, '/current/broadcasts/dashboard', '/current/broadcasts/'.$broadcasts[0]->id, '/current/broadcasts/'.$broadcasts[0]->id.'/compare?with='.$broadcasts[1]->id] as $path) {
        verify($http->handle(Request::create($path))->getStatusCode() === 200, 'optional investigation '.$path);
    }
    $broadcaster = app(BroadcastManager::class)->connection('reverb');
    $broadcaster->channel('install.{id}', fn ($user, $id) => false);
    Route::post('/__current-install-auth', fn (Request $request) => $broadcaster->auth($request));
    $request = Request::create('/__current-install-auth', 'POST', ['channel_name' => 'private-install.42', 'socket_id' => '1.2']);
    $request->headers->set('Accept', 'application/json');
    verify($http->handle($request)->getStatusCode() === 403, 'actual channel authorization denial preserved');
    verify($store->table('broadcast_auth_failures')->count() === 1, 'authorization observation without user/socket capture');
    verify($http->handle(Request::create('/current/broadcasts/authorization'))->getStatusCode() === 200, 'authorization investigation renders');
    config()->set('broadcasting.connections.reverb.secret', 'wrong-install-secret');
    app(BroadcastManager::class)->purge('reverb');
    try {
        app(BroadcastManager::class)->connection('reverb')->broadcast(['private-install.42'], 'InstallUpdated', []);
        throw new RuntimeException('Expected signing rejection.');
    } catch (BroadcastException) {
        $failed = $store->table('broadcasts')->orderByDesc('id')->first();
        verify($failed->outcome === 'failed', 'real Reverb rejection observed');
        $page = $http->handle(Request::create('/current/broadcasts/'.$failed->id));
        verify($page->getStatusCode() === 200 && str_contains($page->getContent(), 'Debug with AI'), 'failed broadcast prompt available');
    }
    foreach (['jobs', 'webhook_receipts', 'webhook_deliveries', 'broadcasts', 'broadcast_auth_failures'] as $table) {
        $store->table($table)->update(['expires_at' => time() - 1]);
    }
    verify(Artisan::call('current:prune') === 0, 'combined feature pruning');
    foreach (['jobs', 'webhook_receipts', 'webhook_jobs', 'webhook_deliveries', 'broadcasts', 'broadcast_auth_failures'] as $table) {
        verify($store->table($table)->count() === 0, 'expired '.$table.' removed');
    }
} finally {
    $server->stop(1);
}

echo "PASS optional integration acceptance complete.\n";
