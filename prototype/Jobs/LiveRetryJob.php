<?php

namespace HorizonLens\Prototype\Jobs;

use Current\Current;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class LiveRetryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('lens-live-retry');
    }

    public function handle(): void
    {
        foreach (['Load order', 'Validate order', 'Reserve inventory', 'Charge payment'] as $label) {
            Current::step($label, function () use ($label) {
                sleep(3);
                if ($label === 'Charge payment') {
                    throw new GatewayDeclined('Synthetic live retry failure');
                }
            });
        }
    }
}
