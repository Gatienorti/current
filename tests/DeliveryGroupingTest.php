<?php

namespace HorizonLens\Tests;

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use HorizonLens\Prototype\Jobs\OutgoingWebhookJob;
use HorizonLens\Webhooks\DeliveryGroups;
use HorizonLens\Webhooks\OutgoingRecorder;
use Illuminate\Support\Facades\Http;

final class DeliveryGroupingTest extends TestCase
{
    public function test_helper_groups_retries_but_separate_calls_remain_separate_and_filters_search_history(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 503)->push([], 200)->push([], 202)]);
        Http::currentWebhook()->retry(2, 0)->post('https://receiver.example.test/webhook');
        Http::currentWebhook()->post('https://receiver.example.test/webhook');
        $rows = $this->store()->table('webhook_deliveries')->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame($rows[0]->delivery_key, $rows[1]->delivery_key);
        $this->assertNotSame($rows[1]->delivery_key, $rows[2]->delivery_key);
        $page = $this->get('/horizon-lens/webhooks/outgoing')->assertOk();
        $page->assertDontSee($rows[0]->id)->assertSee($rows[1]->id)->assertSee($rows[2]->id)->assertSee('2 attempts');
        $this->get('/horizon-lens/webhooks/outgoing?status=503')->assertOk()->assertSee($rows[1]->id)->assertDontSee($rows[2]->id);
        $this->get('/horizon-lens/webhooks/outgoing/'.$rows[0]->id)->assertOk()->assertSee($rows[1]->id)->assertSee('503')->assertSee('200');
        $this->store()->table('webhook_deliveries')->where('id', $rows[0]->id)->update(['expires_at' => time() - 1]);
        $this->get('/horizon-lens/webhooks/outgoing?status=503')->assertOk()->assertSee('No outgoing attempts observed');
    }

    public function test_explicit_identity_crosses_calls_but_not_destination_endpoint_or_method(): void
    {
        config()->set('horizon-lens.webhooks.outgoing.destinations', [
            'demo-receiver' => 'https://receiver.example.test/webhook',
            'other' => 'https://other.example.test/webhook',
        ]);
        Http::fake(['*' => Http::response([], 202)]);
        Http::currentWebhook('stable-private-delivery')->post('https://receiver.example.test/webhook');
        Http::currentWebhook('stable-private-delivery')->post('https://receiver.example.test/webhook');
        Http::currentWebhook('stable-private-delivery')->post('https://other.example.test/webhook');
        Http::currentWebhook('stable-private-delivery')->put('https://receiver.example.test/webhook');
        config()->set('horizon-lens.webhooks.outgoing.destinations.demo-receiver', 'https://receiver.example.test/changed');
        Http::currentWebhook('stable-private-delivery')->post('https://receiver.example.test/changed');
        $rows = $this->store()->table('webhook_deliveries')->orderBy('id')->get();
        $this->assertSame($rows[0]->delivery_key, $rows[1]->delivery_key);
        $this->assertCount(4, $rows->pluck('delivery_key')->unique());
        $this->assertStringNotContainsString('stable-private-delivery', json_encode($rows));
    }

    public function test_stable_identity_links_attempts_from_separate_queued_executions(): void
    {
        foreach ([1, 2] as $run) {
            dispatch(new OutgoingWebhookJob('success', 'logical-delivery'));
            $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'lens-outgoing-demo', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        }
        $rows = $this->store()->table('webhook_deliveries')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->job_id, $rows[1]->job_id);
        $this->assertSame($rows[0]->delivery_key, $rows[1]->delivery_key);
        $this->get('/horizon-lens/webhooks/outgoing/'.$rows[1]->id)->assertOk()->assertSee($rows[0]->job_id)->assertSee($rows[1]->job_id);
    }

    public function test_overlapping_attempts_and_missing_completions_do_not_imply_final_success(): void
    {
        $first = new Promise;
        $second = new Promise;
        $counter = 0;
        $handler = OutgoingRecorder::middleware()(function () use (&$counter, $first, $second) {
            return ++$counter === 1 ? $first : $second;
        });
        $a = $handler(new Request('POST', 'https://receiver.example.test/webhook'), ['current_delivery_id' => 'overlap']);
        $b = $handler(new Request('POST', 'https://receiver.example.test/webhook'), ['current_delivery_id' => 'overlap']);
        $second->resolve(new Response(200));
        $b->wait();
        $rows = $this->store()->table('webhook_deliveries')->orderBy('id')->get();
        $group = app(DeliveryGroups::class)->listing($this->store()->table('webhook_deliveries')->where('expires_at', '>', microtime(true)))->first();
        $this->assertSame(2, $group->attempt_count);
        $this->assertSame(1, $group->unfinished_count);
        $this->get('/horizon-lens/webhooks/outgoing?outcome=unfinished')->assertOk()->assertSee($rows[1]->id)->assertSee('Completion not observed for 1');
        $first->resolve(new Response(503));
        $a->wait();
        $this->get('/horizon-lens/webhooks/outgoing/'.$rows[1]->id)->assertOk()->assertSee('Overlapping');
    }

    public function test_ungrouped_legacy_calls_remain_independent_and_invalid_identity_does_not_break_requests(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Http::post('https://receiver.example.test/webhook');
        Http::post('https://receiver.example.test/webhook');
        $this->assertSame(200, Http::withOptions(['current_delivery_id' => str_repeat('x', 161)])->post('https://receiver.example.test/webhook')->status());
        $this->assertSame(3, $this->store()->table('webhook_deliveries')->whereNull('delivery_key')->count());
        $groups = app(DeliveryGroups::class)->listing($this->store()->table('webhook_deliveries'))->get();
        $this->assertCount(3, $groups);
    }
}
