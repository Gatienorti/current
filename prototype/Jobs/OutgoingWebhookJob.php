<?php

namespace HorizonLens\Prototype\Jobs;

use HorizonLens\Lens;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

final class OutgoingWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $scenario = 'success', public ?string $deliveryId = null)
    {
        $this->onQueue('lens-outgoing-demo');
    }

    public function handle(): void
    {
        Lens::step('Prepare event', fn () => null);
        // Fixtures only: this job can never issue a real HTTP request.
        app()->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::preventStrayRequests();
        Http::fake(['https://receiver.example.test/*' => $this->scenario === 'connection'
            ? Http::failedConnection('synthetic private failure')
            : ($this->scenario === 'retry' ? Http::sequence()->push([], 503)->push([], 200) : Http::response([], 202))]);
        Lens::step('Deliver webhook', fn () => Http::currentWebhook($this->deliveryId)->retry(2, 0)->withToken('synthetic-private-token')
            ->withOptions(['current_webhook' => [
                'event_id' => 'evt-outgoing-'.$this->scenario, 'event_type' => 'order.updated',
                'payload' => ['order_id' => 'demo-1042', 'token' => 'synthetic-private-token'],
                'metadata' => ['source' => 'synthetic sender'],
            ]])
            ->post('https://receiver.example.test/webhook?secret=synthetic-query', ['secret' => 'synthetic-body'])->throw());
    }
}
