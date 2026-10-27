<?php

namespace Current\Native;

/** Isolate Laravel 11.44/12/13's retry preparation contract in one adapter. */
final class RetainedRetryCommand extends \Illuminate\Queue\Console\RetryCommand
{
    public function enqueue(object $failed): void
    {
        $this->retryJob($failed);
    }
}
