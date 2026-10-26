<?php

namespace Current\Tests\Benchmarks;

use Current\Current;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyntheticJob implements ShouldQueue
{
    use Queueable;

    public static int $completed = 0;

    public function __construct(public bool $steps) {}

    public function tags(): array
    {
        return ['benchmark:synthetic', 'benchmark:queue'];
    }

    public function handle(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $work = static fn () => usleep(1000);
            if ($this->steps) {
                Current::step('Synthetic operation '.$i, $work);
            } else {
                $work();
            }
        }
        self::$completed++;
    }
}
