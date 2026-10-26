<?php

namespace HorizonLens\Tests\Concerns;

use HorizonLens\Prototype\Jobs\OutgoingWebhookJob;
use HorizonLens\Webhooks\WebhookRelations;
use Illuminate\Support\Facades\Route;

trait ChecksWebhookFlow
{
    public function test_receipt_job_and_delivery_links_use_observed_ids_and_respect_retention(): void
    {
        config()->set('horizon-lens.webhooks.enabled', true);
        config()->set('horizon-lens.webhooks.outgoing', ['enabled' => true, 'destinations' => ['demo-receiver' => 'https://receiver.example.test/webhook']]);
        Route::post('/__current-flow', function () {
            dispatch(new OutgoingWebhookJob('retry'));

            return response('accepted', 202);
        })->middleware('current.webhook:flow-provider');
        $this->post('/__current-flow')->assertStatus(202);
        $receipt = $this->store()->table('webhook_receipts')->first();
        $jobId = $this->store()->table('webhook_jobs')->where('receipt_id', $receipt->id)->value('job_id');
        $this->assertNotNull($jobId);
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'lens-outgoing-demo', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        $deliveries = $this->store()->table('webhook_deliveries')->where('job_id', $jobId)->orderBy('id')->get();
        $this->assertCount(2, $deliveries);
        $this->assertSame([503, 200], $deliveries->pluck('http_status')->all());
        $receiptPage = $this->get('/'.config('horizon-lens.path').'/webhooks/'.$receipt->id)->assertOk()->assertSee('Related webhooks')->assertSee('Outgoing');
        foreach ($deliveries as $delivery) {
            $receiptPage->assertSee(route('horizon-lens.webhooks.outgoing.show', ['id' => $delivery->id]), false);
            $this->get('/'.config('horizon-lens.path').'/webhooks/outgoing/'.$delivery->id)->assertOk()->assertSee(route('horizon-lens.webhooks.show', ['id' => $receipt->id]), false);
        }
        $this->get('/'.config('horizon-lens.path').'/jobs/'.$jobId)->assertOk()->assertSee('Incoming receipts')->assertSee('Outgoing delivery attempts')
            ->assertSee(route('horizon-lens.webhooks.show', ['id' => $receipt->id]), false)
            ->assertSee($deliveries[0]->attempt_id);

        // A second independent receipt/job must not acquire the first job's deliveries.
        $this->post('/__current-flow')->assertStatus(202);
        $second = $this->store()->table('webhook_receipts')->where('id', '!=', $receipt->id)->first();
        $this->get('/'.config('horizon-lens.path').'/webhooks/'.$second->id)->assertOk()->assertDontSee($deliveries[0]->id)->assertDontSee('id="related-webhooks"', false);
        $relations = app(WebhookRelations::class);
        $this->assertCount(1, $relations->receipts($jobId));
        $this->assertCount(2, $relations->deliveries([$jobId]));
        $this->store()->table('webhook_deliveries')->where('id', $deliveries[0]->id)->update(['expires_at' => time() - 1]);
        $this->assertCount(1, $relations->deliveries([$jobId]));
        $this->store()->table('webhook_receipts')->where('id', $receipt->id)->update(['expires_at' => time() - 1]);
        $this->assertCount(0, $relations->receipts($jobId));
        $this->store()->table('jobs')->where('id', $jobId)->update(['expires_at' => time() - 1]);
        $this->assertCount(0, $relations->deliveries([$jobId]));
        $this->get('/'.config('horizon-lens.path').'/webhooks/outgoing/'.$deliveries[1]->id)->assertOk()->assertDontSee('flow-provider');
    }
}
