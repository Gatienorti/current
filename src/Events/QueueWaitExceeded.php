<?php

namespace Current\Events;

/** Queue clearance estimate exceeded a configured threshold; not observed job latency. */
final class QueueWaitExceeded
{
    public function __construct(
        public readonly string $connection,
        public readonly array $queues,
        public readonly float $estimatedSeconds,
        public readonly int $thresholdSeconds,
        public readonly int $observedAt,
    ) {}
}
