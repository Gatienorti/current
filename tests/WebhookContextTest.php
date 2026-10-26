<?php

namespace HorizonLens\Tests;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use HorizonLens\Prototype\Jobs\OutgoingWebhookJob;
use HorizonLens\Webhooks\OutgoingRecorder;
use HorizonLens\Webhooks\WebhookContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

final class WebhookContextTest extends TestCase
{
    public function test_explicit_incoming_context_is_opt_in_redacted_and_escaped(): void
    {
        Route::post('/__context', function (\Illuminate\Http\Request $request) {
            $request->attributes->set('current.webhook.context', [
                'event_id' => 'evt-123', 'event_type' => 'order.created',
                'payload' => ['name' => '<script>alert(1)</script>', 'nested' => ['password' => 'private-value']],
                'metadata' => ['signature' => 'private-value'], 'response' => ['accepted' => true],
            ]);

            return response('unchanged', 202);
        })->middleware('current.webhook:test');
        $this->post('/__context')->assertStatus(202)->assertSee('unchanged');
        $row = $this->store()->table('webhook_receipts')->first();
        $this->assertNull($row->payload_json);
        $this->assertNull($row->event_id);
        config()->set('horizon-lens.webhooks.capture_context', true);
        $this->post('/__context')->assertStatus(202);
        $row = $this->store()->table('webhook_receipts')->where('event_id', 'evt-123')->first();
        $this->assertSame('captured', $row->payload_state);
        $this->assertStringNotContainsString('private-value', json_encode($row));
        $this->get('/horizon-lens/webhooks/'.$row->id)->assertOk()->assertSee('order.created')->assertDontSee('<script>alert(1)</script>', false)->assertSee('[REDACTED]');
        $this->get('/horizon-lens/webhooks?event_id=evt-123&event_type=order.created&status=202')->assertOk()->assertSee($row->id);
        $this->get('/horizon-lens/webhooks?event_id=evt-123&event_type=wrong')->assertOk()->assertSee('No receipts observed');
    }

    public function test_outgoing_context_and_response_capture_preserve_body_and_redact_before_storage(): void
    {
        config()->set('horizon-lens.webhooks.capture_context', true);
        config()->set('horizon-lens.webhooks.response_context', fn ($response, $destination) => ['status' => $response->getStatusCode(), 'destination' => $destination, 'api_key' => 'private-value']);
        Http::fake(['*' => Http::response(['accepted' => true], 202)]);
        $response = Http::withOptions(['current_webhook' => [
            'event_id' => 'out-123', 'event_type' => 'order.created',
            'payload' => ['order' => 42, 'token' => 'private-value'], 'metadata' => ['source' => 'test'],
        ]])->post('https://receiver.example.test/webhook');
        $this->assertTrue($response->json('accepted'));
        $row = $this->store()->table('webhook_deliveries')->first();
        $this->assertSame('out-123', $row->event_id);
        $this->assertSame('captured', $row->response_state);
        $this->assertStringNotContainsString('private-value', json_encode($row));
        $this->get('/horizon-lens/webhooks/outgoing/'.$row->id)->assertOk()->assertSee('Response data')->assertSee('[REDACTED]');
        $this->get('/horizon-lens/webhooks/outgoing?event_id=out-123&event_type=order.created')->assertOk()->assertSee($row->id);
        config()->set('horizon-lens.webhooks.response_context', fn () => throw new \RuntimeException('private-value'));
        $this->assertSame(202, Http::post('https://receiver.example.test/webhook')->status());
        $this->assertSame('failed', $this->store()->table('webhook_deliveries')->orderByDesc('id')->first()->response_state);
    }

    public function test_limits_unsupported_values_and_disabled_callbacks_are_explicit(): void
    {
        $this->assertSame('not_captured', WebhookContext::capture(fn () => throw new \RuntimeException)['payload_state']);
        config()->set('horizon-lens.webhooks.capture_context', true);
        config()->set('horizon-lens.context_max_bytes', 256);
        $this->assertSame('size_limit', WebhookContext::capture(['payload' => ['value' => str_repeat('a', 300)]])['payload_state']);
        $this->assertSame('limited', WebhookContext::capture(['payload' => new \stdClass])['payload_state']);
        $this->assertSame('failed', WebhookContext::capture(fn () => throw new \RuntimeException)['payload_state']);
        $this->assertNull(WebhookContext::capture(['event_id' => 'token=secret-value'])['event_id']);
    }

    public function test_failure_filters_keep_http_transport_and_unknown_distinct(): void
    {
        $promise = new Promise;
        $handler = OutgoingRecorder::middleware()(fn () => $promise);
        $pending = $handler(new Request('POST', 'https://receiver.example.test/webhook'), []);
        $unfinished = $this->store()->table('webhook_deliveries')->first();
        $this->get('/horizon-lens/webhooks/outgoing?outcome=unfinished')->assertOk()->assertSee($unfinished->id);
        $this->get('/horizon-lens/webhooks/outgoing?outcome=transport_error')->assertOk()->assertSee('No outgoing attempts observed');
        $promise->resolve(new Response(503));
        $pending->wait();
        $this->get('/horizon-lens/webhooks/outgoing?outcome=http_error&status=503')->assertOk()->assertSee($unfinished->id);
        $this->get('/horizon-lens/webhooks/outgoing?outcome=unfinished')->assertOk()->assertSee('No outgoing attempts observed');
        dispatch(new OutgoingWebhookJob('connection'));
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'lens-outgoing-demo', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        $this->get('/horizon-lens/webhooks/outgoing?outcome=transport_error')->assertOk()->assertSee('Transport error')->assertDontSee($unfinished->id);
    }
}
