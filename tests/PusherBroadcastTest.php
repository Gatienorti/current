<?php

namespace Current\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Current\Broadcasts\BroadcastIntegration;
use Current\Broadcasts\ObservedPusherBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

final class PusherBroadcastTest extends TestCase
{
    public function test_real_pusher_sdk_sends_are_observed_without_changing_payload_auth_errors_or_fail_open_behavior(): void
    {
        if (! class_exists(\Pusher\Pusher::class)) {
            $this->markTestSkipped('Requires the optional Pusher SDK; run with the ADAPT vendor profile.');
        }
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], '{}'), new Response(403, [], 'Synthetic rejection'), new Response(200, [], '{}'),
        ]));
        $handler->push(Middleware::history($history));
        config()->set('current.broadcasts', ['enabled' => true, 'visible' => null,
            'events' => ['OrderUpdated'], 'channels' => ['private-orders.*']]);
        config()->set('broadcasting.default', 'hosted');
        config()->set('broadcasting.connections.hosted', [
            'driver' => 'pusher', 'key' => 'synthetic-key', 'secret' => 'synthetic-secret', 'app_id' => '12345',
            'options' => ['cluster' => 'eu', 'useTLS' => true], 'client_options' => ['handler' => $handler, 'timeout' => 2],
        ]);
        BroadcastIntegration::configure();
        BroadcastIntegration::configure(); // Idempotent; connection identity stays intact.
        $manager = app(BroadcastManager::class);
        $manager->purge('hosted');
        $this->assertTrue(BroadcastIntegration::visible());
        $driver = $manager->connection('hosted');
        $this->assertInstanceOf(ObservedPusherBroadcaster::class, $driver);
        $event = new class implements ShouldBroadcastNow
        {
            public string $socket = '123.456';

            public function broadcastOn(): array
            {
                return [new PrivateChannel('orders.42')];
            }

            public function broadcastAs(): string
            {
                return 'OrderUpdated';
            }

            public function broadcastWith(): array
            {
                return ['token' => 'private-payload'];
            }
        };
        event($event);
        $row = $this->store()->table('broadcasts')->first();
        $this->assertSame('hosted', $row->connection);
        $this->assertSame('accepted', $row->outcome);
        $this->assertStringNotContainsString('private-payload', json_encode($row));
        $this->assertStringNotContainsString('orders.42', json_encode($row));
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('123.456', $body['socket_id']);
        $this->assertSame(['private-orders.42'], $body['channels'] ?? [$body['channel'] ?? null], json_encode(array_keys($body)));
        $this->assertSame(['token' => 'private-payload'], json_decode($body['data'], true));
        $this->assertSame('api-eu.pusher.com', $history[0]['request']->getUri()->getHost());
        $this->get('/current/broadcasts/'.$row->id)->assertOk()->assertSee('hosted')->assertSee('OrderUpdated');

        $driver->channel('orders.{id}', fn ($user, $id) => $id === '42');
        $request = Request::create('/broadcasting/auth', 'POST', ['channel_name' => 'private-orders.42', 'socket_id' => '123.456']);
        $request->setUserResolver(fn () => auth()->user());
        $auth = $driver->auth($request);
        if (is_string($auth)) {
            $auth = json_decode($auth, true);
        }
        $this->assertSame('synthetic-key:'.hash_hmac('sha256', '123.456:private-orders.42', 'synthetic-secret'), $auth['auth']);

        try {
            event($event);
            $this->fail('Pusher rejection must remain an application exception.');
        } catch (BroadcastException) {
            $this->assertSame(1, $this->store()->table('broadcasts')->where('outcome', 'failed')->count());
        }
        Schema::connection('lens')->drop('lens_broadcasts');
        event($event); // An unavailable observer cannot prevent the real SDK call.
        $this->assertCount(3, $history);
    }

    public function test_only_supported_connections_are_wrapped_when_opted_in(): void
    {
        config()->set('broadcasting.connections', [
            'cloud' => ['driver' => 'pusher'], 'local' => ['driver' => 'reverb'], 'other' => ['driver' => 'log'],
        ]);
        config()->set('current.broadcasts.enabled', false);
        BroadcastIntegration::configure();
        $this->assertSame('pusher', config('broadcasting.connections.cloud.driver'));
        config()->set('broadcasting.default', 'cloud');
        $this->assertTrue(BroadcastIntegration::visible());
        config()->set('current.broadcasts.enabled', true);
        BroadcastIntegration::configure();
        $this->assertSame('current-pusher', config('broadcasting.connections.cloud.driver'));
        $this->assertSame('current-reverb', config('broadcasting.connections.local.driver'));
        $this->assertSame('log', config('broadcasting.connections.other.driver'));
    }
}
