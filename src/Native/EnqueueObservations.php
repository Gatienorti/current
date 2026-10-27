<?php

namespace Current\Native;

/** Internal adapter boundary; application queue events retain their normal timing. */
interface EnqueueObservations
{
    public function observeEnqueue(callable $observation, int $bytes): void;
}
