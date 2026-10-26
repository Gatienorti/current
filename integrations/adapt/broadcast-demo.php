<?php

require __DIR__.'/bootstrap.php';

use App\Events\ChatMessageSent;
use App\Events\WorkoutAnalysisCompleted;
use HorizonLens\Broadcasts\BroadcastIntegration;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;

$app = bootAdaptLab();
$app->make(Kernel::class)->bootstrap();
Artisan::call('migrate', ['--force' => true]);
$socket = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$server = new Process([PHP_BINARY, __DIR__.'/reverb-server.php', (string) $port]);
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
    if (! $ready) {
        throw new RuntimeException('Isolated Reverb did not start.');
    }
    config()->set('horizon-lens.broadcasts', ['enabled' => true, 'visible' => null,
        'events' => ['ChatMessageSent', 'workout.analysis.completed'], 'channels' => ['private-chat.*', 'private-user.*']]);
    config()->set('horizon-lens.broadcasts.capture_channels', true);
    config()->set('horizon-lens.broadcasts.context', fn ($event, $channels, $payload) => [
        'payload' => array_intersect_key($payload, array_flip(['has_new_analysis', 'match_percentage'])),
        'metadata' => ['demo' => true, 'event' => $event, 'token' => 'redacted-synthetic-token'],
    ]);
    config()->set('horizon-lens.broadcasts.message_sanitizer', fn ($message) => 'Synthetic Reverb rejected the demo signing secret.');
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb', [
        'driver' => 'reverb', 'key' => 'current-lab-key', 'secret' => 'current-lab-secret', 'app_id' => 'current-lab',
        'options' => ['host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http', 'useTLS' => false],
        'client_options' => ['timeout' => 2, 'connect_timeout' => 1],
    ]);
    BroadcastIntegration::configure();
    app(BroadcastManager::class)->purge('reverb');
    event(new ChatMessageSent(['text' => 'Synthetic demo'], null, 987654));
    dispatch((new BroadcastEvent(new WorkoutAnalysisCompleted(987654, [])))->onQueue('current-broadcast-demo'));
    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'current-broadcast-demo', '--once' => true, '--sleep' => 0, '--tries' => 1]);
    dispatch((new BroadcastEvent(new WorkoutAnalysisCompleted(987655, ['match_percentage' => 82])))->onQueue('current-broadcast-demo'));
    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'current-broadcast-demo', '--once' => true, '--sleep' => 0, '--tries' => 1]);
    config()->set('broadcasting.connections.reverb.secret', 'wrong-synthetic-secret');
    app(BroadcastManager::class)->purge('reverb');
    try {
        event(new ChatMessageSent([], null, 123));
    } catch (BroadcastException) {
    }
    config()->set('horizon-lens.broadcasts.authorization', ['enabled' => true, 'routes' => ['current.demo.broadcast-auth']]);
    Route::post('/__current-demo-broadcast-auth', function (Request $request) {
        abort((int) $request->input('demo_status', 403));
    })->name('current.demo.broadcast-auth');
    $http = app(Illuminate\Contracts\Http\Kernel::class);
    foreach ([403, 419, 500] as $status) {
        $request = Request::create('/__current-demo-broadcast-auth', 'POST', ['demo_status' => $status, 'channel_name' => 'private-chat.987654', 'socket_id' => '123.456']);
        $request->headers->set('Accept', 'application/json');
        $response = $http->handle($request);
        $http->terminate($request, $response);
    }
    echo "Four synthetic submissions and three simulated auth-route failures recorded in ADAPT preview; isolated Reverb stopped afterward.\n";
} finally {
    $server->stop(1);
}
