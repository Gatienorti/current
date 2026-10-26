<?php

namespace Current\Prototype\Jobs;

use Current\Current;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class OrderInvestigationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 0;

    public int $timeout = 5;

    public function __construct(public string $orderId = 'demo-map-1042')
    {
        $this->onQueue('lens-demo');
    }

    public function tags(): array
    {
        return ['order:'.$this->orderId, 'scenario:rich-details'];
    }

    public function lensArguments(): array
    {
        return [
            'order_id' => $this->orderId,
            'customer' => ['id' => 'customer-demo-17', 'region' => 'CA'],
            'items' => [['sku' => 'DEMO-001', 'quantity' => 2], ['sku' => 'DEMO-002', 'quantity' => 1]],
            'payment' => ['currency' => 'CAD', 'amount_minor' => 12900, 'api_token' => 'demo-token-never-store'],
        ];
    }

    public function lensMetadata(): array
    {
        return [
            'correlation_id' => 'order-investigation-demo', 'source' => 'isolated Lens lab',
            'workflow' => ['name' => 'Order fulfilment', 'version' => 1],
            'labels' => ['synthetic', 'pan-zoom-example'], 'authorization' => 'Bearer demo-secret',
        ];
    }

    public function handle(): void
    {
        foreach (['Load order', 'Validate order', 'Load customer', 'Check inventory', 'Reserve inventory', 'Calculate subtotal', 'Apply discounts', 'Calculate tax', 'Build invoice', 'Validate billing', 'Prepare payment'] as $label) {
            Current::step($label, fn () => usleep(8000));
        }
        Current::step('Charge payment', function () {
            usleep(12000);
            throw new GatewayDeclined('Synthetic payment declined. token=never-store-this');
        });
    }
}
