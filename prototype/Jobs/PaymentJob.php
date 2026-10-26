<?php

namespace Current\Prototype\Jobs;

use Current\Current;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 0;

    public function __construct(public string $scenario = 'success', public string $order = 'demo-1042')
    {
        $this->onQueue('lens-demo');
    }

    public function tags(): array
    {
        return ['order:'.$this->order, 'scenario:'.$this->scenario, 'private:must-not-be-recorded'];
    }

    public function handle(): void
    {
        if ($this->scenario === 'untraced') {
            return;
        }
        Current::step('Validate order', fn () => usleep(12000));
        Current::step('Reserve inventory', fn () => usleep(18000));
        if ($this->scenario === 'release' && $this->attempts() === 1) {
            $this->release(0);

            return;
        }
        Current::step('Charge payment', function () {
            usleep(25000);
            if ($this->scenario === 'failure' || ($this->scenario === 'retry' && $this->attempts() === 1)) {
                throw new GatewayDeclined('The simulated gateway declined this payment. token=demo-secret');
            }
        });
        Current::step('Confirm order', fn () => usleep(9000));
    }
}
