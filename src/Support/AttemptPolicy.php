<?php

namespace Current\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\RedisQueue;

/** Bounded scalar policy only: never deserialize a job or retain command arguments. */
final class AttemptPolicy
{
    private array $commands = [];

    public function starting(CommandStarting $event): void
    {
        $this->commands[] = $event;
    }

    public function finished(): void
    {
        array_pop($this->commands);
    }

    public function capture($event): ?string
    {
        try {
            $command = end($this->commands) ?: null;
            $options = [];
            if ($command && in_array($command->command, ['queue:work', 'horizon:work'], true)) {
                // Input has been bound to the command definition by JobProcessing.
                $options = $command->input->getOptions();
            }
            $payload = $event->job->payload();
            $policy = ['version' => 1];
            foreach (['tries' => 'maxTries', 'timeout' => 'timeout', 'backoff' => 'backoff'] as $option => $key) {
                $value = $payload[$key] ?? null;
                $source = $value !== null ? 'Job payload' : 'Worker option';
                if ($value === null) {
                    $value = $options[$option] ?? null;
                    if ($option === 'backoff' && $value !== null) {
                        $value = max($value, $options['delay'] ?? 0);
                    }
                }
                $value = $option === 'backoff' ? $this->backoff($value) : $this->number($value);
                $policy[$option] = ['value' => $value, 'source' => $value === null ? 'Not captured' : $source];
            }
            foreach (['retry_until' => 'retryUntil', 'max_exceptions' => 'maxExceptions'] as $key => $payloadKey) {
                $policy[$key] = ['value' => $this->number($payload[$payloadKey] ?? null), 'source' => 'Job payload', 'unset' => array_key_exists($payloadKey, $payload) && $payload[$payloadKey] === null];
            }
            $policy['fail_on_timeout'] = ['value' => is_bool($payload['failOnTimeout'] ?? null) ? $payload['failOnTimeout'] : null, 'source' => 'Job payload'];
            $retryAfter = null;
            $connection = app('queue')->connection($event->connectionName);
            if ($connection instanceof RedisQueue) {
                // Read the instantiated driver's value, not potentially changed config.
                $property = new \ReflectionProperty(RedisQueue::class, 'retryAfter');
                $retryAfter = $this->number($property->getValue($connection));
            }
            $policy['retry_after'] = ['value' => $retryAfter, 'source' => $retryAfter === null ? 'Not captured' : 'Redis connection'];
            return json_encode($policy, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    private function number(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/D', $value))) && $value >= 0 && $value <= 9999999999 ? (int) $value : null;
    }

    private function backoff(mixed $value): ?string
    {
        if (! is_int($value) && ! is_string($value)) { return null; }
        $value = (string) $value;
        return strlen($value) <= 240 && preg_match('/^\d+(,\d+)*$/D', $value) ? $value : null;
    }

    public static function warnings(array $policy, ?object $attempt): array
    {
        $value = fn ($key) => $policy[$key]['value'] ?? null;
        $warnings = [];
        if ($value('timeout') !== null && $value('retry_after') !== null && $value('timeout') >= $value('retry_after') && $value('timeout') > 0) {
            $warnings[] = 'Duplicate execution risk: timeout reaches or exceeds the Redis reservation. Keep retry_after longer than the effective timeout, with a safety margin.';
        }
        if ($value('timeout') === 0) {
            $warnings[] = 'No worker timeout: a long-running attempt may outlive its Redis reservation.';
        }
        if ($value('retry_until')) {
            $warnings[] = 'Retry deadline takes precedence over the attempts limit. Queue wait also consumes this deadline.';
        }
        if ($value('max_exceptions') !== 1 && $value('backoff') !== null && ! array_filter(explode(',', $value('backoff')), fn ($n) => (float) $n > 0) && ($value('tries') === 0 || $value('tries') > 1 || $value('retry_until'))) {
            $warnings[] = 'No retry backoff: repeated exceptions can be retried immediately.';
        }
        if ($attempt?->status === 'released' && $value('tries') > 0 && ! $value('retry_until')) {
            $warnings[] = 'This release consumes an attempt, even if middleware prevented the handler from running.';
        }
        return $warnings;
    }
}
