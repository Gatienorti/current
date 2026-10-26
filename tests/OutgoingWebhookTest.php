<?php

namespace Current\Tests;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Current\Prototype\Jobs\OutgoingWebhookJob;
use Current\Webhooks\OutgoingRecorder;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

final class OutgoingWebhookTest extends TestCase
{
    public function test_http_retries_are_separate_private_attempts_linked_to_the_sending_job(): void
    {
        dispatch(new OutgoingWebhookJob('retry'));
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'lens-outgoing-demo', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        $rows = $this->store()->table('webhook_deliveries')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([503, 200], $rows->pluck('http_status')->all());
        $this->assertNotNull($rows[0]->job_id);
        $this->assertSame($rows[0]->job_id, $rows[1]->job_id);
        $this->assertSame($rows[0]->attempt_id, $rows[1]->attempt_id);
        $this->assertGreaterThanOrEqual(0, $rows[0]->duration_ms);
        $this->assertStringNotContainsString('synthetic-', json_encode($rows));
        $this->get('/current/webhooks/outgoing/'.$rows[0]->id)->assertOk()->assertSee('OutgoingWebhookJob')->assertSee('503');
        $this->get('/current/webhooks/outgoing?status=200&destination=demo-receiver')->assertOk()->assertSee($rows[1]->id)->assertDontSee($rows[0]->id);
        $this->get('/current/webhooks/outgoing?from=2030-01-01T00:00:00')->assertOk()->assertSee('No outgoing attempts observed');
        app()->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::fake(['*' => Http::response([], 200)]);
        Http::post('https://receiver.example.test/webhook');
        $this->assertNull($this->store()->table('webhook_deliveries')->orderByDesc('id')->first()->job_id);
    }

    public function test_disabled_unmatched_destinations_and_secrets_are_excluded(): void
    {
        Http::fake(['*' => Http::response(['secret' => 'private-response'], 400)]);
        $this->assertSame(400, Http::withToken('private-header')->post('https://receiver.example.test/webhook?token=private-query', ['secret' => 'private-body'])->status());
        $row = $this->store()->table('webhook_deliveries')->first();
        $this->assertSame('https://receiver.example.test:443/webhook', $row->endpoint);
        $this->assertStringNotContainsString('private-', json_encode($row));
        foreach (['http://receiver.example.test/webhook', 'https://other.example.test/webhook', 'https://receiver.example.test/other'] as $url) {
            Http::post($url);
        }
        config()->set('current.webhooks.outgoing.enabled', false);
        Http::post('https://receiver.example.test/webhook');
        $this->assertSame(1, $this->store()->table('webhook_deliveries')->count());
    }

    public function test_transport_failure_still_throws_and_storage_failure_does_not_change_http_behavior(): void
    {
        Http::fake(['*' => Http::failedConnection('private-error')]);
        try {
            Http::post('https://receiver.example.test/webhook');
            $this->fail('Expected the original connection exception.');
        } catch (ConnectionException $error) {
            $this->assertStringContainsString('private-error', $error->getMessage());
        }
        $row = $this->store()->table('webhook_deliveries')->first();
        $this->assertNull($row->http_status);
        $this->assertNotNull($row->exception_class);
        $this->assertNotNull($row->finished_at);
        $this->assertStringNotContainsString('private-error', json_encode($row));
        Schema::connection('lens')->drop('lens_webhook_deliveries');
        Http::swap(new Factory);
        Http::globalMiddleware(OutgoingRecorder::middleware());
        Http::fake(['*' => Http::response(['ok' => true], 202)]);
        $this->assertTrue(Http::post('https://receiver.example.test/webhook')->json('ok'));
    }

    public function test_finish_storage_failure_preserves_the_response_and_rejection_identity(): void
    {
        $pending = new Promise;
        $other = new Promise;
        $error = new \RuntimeException('private-rejection');
        $handler = OutgoingRecorder::middleware()(fn ($request) => $request->getMethod() === 'POST' ? $pending : $other);
        $a = $handler(new Request('POST', 'https://receiver.example.test/webhook'), []);
        $b = $handler(new Request('PUT', 'https://receiver.example.test/webhook'), []);
        Schema::connection('lens')->drop('lens_webhook_deliveries');
        $response = new Response(202);
        $pending->resolve($response);
        $other->reject($error);
        $this->assertSame($response, $a->wait());
        try {
            $b->wait();
            $this->fail('Expected original rejection.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
    }

    public function test_overlapping_promises_keep_their_own_outcomes_and_retention_and_access_apply(): void
    {
        $first = new Promise;
        $second = new Promise;
        $handler = OutgoingRecorder::middleware()(fn ($request) => $request->getMethod() === 'POST' ? $first : $second);
        $a = $handler(new Request('POST', 'https://receiver.example.test/webhook'), []);
        $b = $handler(new Request('PUT', 'https://receiver.example.test/webhook'), []);
        $second->resolve(new Response(204));
        $first->resolve(new Response(503));
        $this->assertSame(204, $b->wait()->getStatusCode());
        $this->assertSame(503, $a->wait()->getStatusCode());
        $rows = $this->store()->table('webhook_deliveries')->get()->keyBy('method');
        $this->assertSame(503, $rows['POST']->http_status);
        $this->assertSame(204, $rows['PUT']->http_status);
        $this->actingAs(new GenericUser(['id' => 2, 'is_admin' => false]));
        $this->get('/current/webhooks/outgoing')->assertForbidden();
        $this->get('/current/webhooks/outgoing/'.$rows['POST']->id)->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 1, 'is_admin' => true]));
        $this->store()->table('webhook_deliveries')->update(['expires_at' => time() - 1]);
        $this->get('/current/webhooks/outgoing/'.$rows['POST']->id)->assertNotFound();
        $this->assertSame(2, app(OutgoingRecorder::class)->prune());
    }
}
