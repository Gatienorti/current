<?php

namespace Current\AdaptTests;

use App\Events\ChatMessageSent;
use App\Events\WorkoutAnalysisCompleted;
use Current\AdaptLab\Fixtures;
use Current\Broadcasts\BroadcastIntegration;
use Current\Store;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

final class BroadcastIntegrationTest extends TestCase
{
    private ?Process $server = null;

    private int $port;

    public function createApplication()
    {
        $app = bootAdaptLab(sys_get_temp_dir().'/current-adapt-broadcast-'.bin2hex(random_bytes(6)));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
        $fixtures = Fixtures::seed();
        $this->actingAs($fixtures['users']['operator']);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->server = new Process([PHP_BINARY, dirname(__DIR__).'/reverb-server.php', (string) $this->port]);
        $this->server->start();
        $ready = false;
        for ($i = 0; $i < 60; $i++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.05);
            if ($connection) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(50000);
        }
        $this->assertTrue($ready, $this->server->getErrorOutput());
        config()->set('current.broadcasts', ['enabled' => true, 'visible' => null,
            'events' => ['ChatMessageSent', 'workout.analysis.completed'],
            'channels' => ['private-chat.*', 'private-user.*']]);
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb', [
            'driver' => 'reverb', 'key' => 'current-lab-key', 'secret' => 'current-lab-secret', 'app_id' => 'current-lab',
            'options' => ['host' => '127.0.0.1', 'port' => $this->port, 'scheme' => 'http', 'useTLS' => false],
            'client_options' => ['timeout' => 2, 'connect_timeout' => 1],
        ]);
        BroadcastIntegration::configure();
        app(BroadcastManager::class)->purge('reverb');
    }

    protected function tearDown(): void
    {
        $this->server?->stop(1);
        \Mockery::close();
        parent::tearDown();
    }

    public static function broadcastDrivers(): array
    {
        return ['Reverb driver' => ['reverb'], 'Pusher driver' => ['pusher']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('broadcastDrivers')]
    public function test_actual_adapt_events_submit_through_both_drivers_to_an_isolated_protocol_server(string $driver): void
    {
        config()->set('broadcasting.connections.reverb.driver', $driver);
        BroadcastIntegration::configure();
        app(BroadcastManager::class)->purge('reverb');
        $this->assertSame('current-'.$driver, config('broadcasting.connections.reverb.driver'));

        event(new ChatMessageSent(['text' => 'private-message'], null, 987654));
        event(new WorkoutAnalysisCompleted(987654, ['completed_summary' => 'private-health-summary']));
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'default', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        $rows = app(Store::class)->table('broadcasts')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['accepted', 'accepted'], $rows->pluck('outcome')->all());
        $this->assertNull($rows[0]->job_id);
        $this->assertNotNull($rows[1]->job_id);
        $this->assertStringNotContainsString('987654', json_encode($rows));
        $this->assertStringNotContainsString('private-message', json_encode($rows));
        $this->assertStringNotContainsString('private-health-summary', json_encode($rows));
        $this->get('/current/broadcasts?event=workout.analysis.completed')->assertOk()->assertSee($rows[1]->id)->assertDontSee($rows[0]->id);
        $this->get('/current/broadcasts/'.$rows[1]->id)->assertOk()->assertSee($rows[1]->job_id)->assertSee('Worker attempt started')->assertSee('Job enqueue observed');
        $this->get('/current/jobs/'.$rows[1]->job_id)->assertOk()->assertSee($rows[1]->id)->assertSee('Related broadcast submissions');
        config()->set('current.webhooks.enabled', true);
        Route::post('/__broadcast-flow', function () {
            event(new WorkoutAnalysisCompleted(987654, []));

            return response('accepted', 202);
        })->middleware('current.webhook:synthetic-origin');
        $this->post('/__broadcast-flow')->assertStatus(202);
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'default', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        $linked = app(Store::class)->table('broadcasts')->orderByDesc('id')->first();
        $this->get('/current/broadcasts/'.$linked->id)->assertOk()->assertSee('synthetic-origin');

        // Use Laravel's actual Reverb/Pusher channel authorization callback without a client socket.
        config()->set('current.broadcasts.authorization', ['enabled' => true, 'routes' => ['current.test.broadcast-auth']]);
        $broadcaster = app(BroadcastManager::class)->connection('reverb');
        $broadcaster->channel('current-test.{id}', fn ($user, $id) => false);
        Route::post('/__current-broadcast-auth', fn (Request $request) => $broadcaster->auth($request))->name('current.test.broadcast-auth');
        $this->postJson('/__current-broadcast-auth', ['channel_name' => 'private-current-test.123', 'socket_id' => '123.456'])->assertForbidden();
        $authFailure = app(Store::class)->table('broadcast_auth_failures')->first();
        $this->assertSame(403, $authFailure->http_status);
        $this->assertStringNotContainsString('123.456', json_encode($authFailure));
        $this->get('/current/broadcasts/authorization')->assertOk()->assertSee('Forbidden response');
        $this->get('/current/broadcasts/'.$rows[0]->id.'/compare?with='.$rows[1]->id)->assertOk()->assertSee('Compare broadcasts');
        $this->get('/current/broadcasts/'.$rows[1]->id)->assertOk()->assertSee('Debug with AI')->assertSee($rows[1]->job_id);

        $this->get('/current/broadcasts/dashboard')->assertOk()->assertSee('Broadcast dashboard')->assertSee('Approved channel patterns');
        $this->get('/current/webhooks/dashboard')->assertOk()->assertSee('Webhook dashboard');

        // A real Reverb HTTP rejection stays an exception and a failed observation.
        config()->set('broadcasting.connections.reverb.secret', 'wrong-synthetic-secret');
        app(BroadcastManager::class)->purge('reverb');
        try {
            event(new ChatMessageSent([], null, 123));
            $this->fail('Expected rejection.');
        } catch (BroadcastException) {
            $this->assertSame('failed', app(Store::class)->table('broadcasts')->orderByDesc('id')->value('outcome'));
        }
        // Observer storage failure must not break a valid broadcast.
        config()->set('broadcasting.connections.reverb.secret', 'current-lab-secret');
        app(BroadcastManager::class)->purge('reverb');
        Schema::connection('lens')->drop('lens_broadcasts');
        event(new ChatMessageSent([], null, 123));
        (require dirname(__DIR__, 3).'/database/migrations/2026_10_25_000008_add_broadcasts.php')->up();
        (require dirname(__DIR__, 3).'/database/migrations/2026_10_25_000009_add_broadcast_context.php')->up();
        $this->server->stop(1);
        // Server outage must not hide navigation.
        $this->assertTrue(BroadcastIntegration::visible());
        $this->get('/current/broadcasts')->assertOk()->assertSee('No broadcast submissions observed');
    }
}
