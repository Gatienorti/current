<?php

namespace Current\Tests\Fixtures;

use Current\Current;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class BlockingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 2;

    public bool $failOnTimeout = true;

    public function __construct(public int $sleepSeconds = 2)
    {
        $this->onQueue('lens-demo');
    }

    public function handle(): void
    {
        Current::step('Wait at checkpoint', fn () => sleep($this->sleepSeconds));
    }
}
