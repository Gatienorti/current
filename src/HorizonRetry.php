<?php

namespace Current;

use Illuminate\Contracts\Bus\Dispatcher;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Jobs\RetryFailedJob;

/** Horizon owns payload preparation, retry lineage and queue execution. */
final class HorizonRetry
{
    public function available(object $job): bool
    {
        $failed = app(JobRepository::class)->findFailed($job->external_id);

        return $failed && $failed->connection === $job->connection && $failed->queue === $job->queue;
    }

    public function retry(string $id): string
    {
        $command = new class($id) extends RetryFailedJob
        {
            public ?string $retryUuid = null;

            protected function preparePayload($id, $payload)
            {
                $this->retryUuid = (string) $id;

                return parent::preparePayload($id, $payload);
            }
        };
        app(Dispatcher::class)->dispatchSync($command);
        if (! $command->retryUuid) {
            throw new \RuntimeException('No retry was created.');
        }

        return $command->retryUuid;
    }
}
