<?php

namespace Current;

use Current\Support\Sanitizer;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Str;
use Laravel\Horizon\Events\JobPushed;
use Throwable;
use WeakMap;

final class Recorder
{
    private ?Telemetry\AsyncRecorder $asyncRecorder = null;

    private function async(): ?Telemetry\AsyncRecorder
    {
        return config('current.telemetry.driver', 'sync') === 'redis'
            ? ($this->asyncRecorder ??= app(Telemetry\AsyncRecorder::class))
            : null;
    }

    private WeakMap $executions;

    private ?array $current = null;

    public function currentExecution(): ?array
    {
        if ($async = $this->async()) {
            return $async->currentExecution();
        }

        return $this->current;
    }

    private array $stack = [];

    private int $sequence = 0;

    private float $disabledUntil = 0;

    private string $workerId;

    /** Monotonic deadline; this heartbeat is not an exact last-event timestamp. */
    private float $nextHeartbeatAt = 0;

    public function __construct(private Store $store, private Sanitizer $sanitizer)
    {
        $this->executions = new WeakMap;
        $this->workerId = (string) Str::ulid();
    }

    public function pushed(JobPushed $event): void
    {
        $this->enqueued($event->payload->decoded, $event->connectionName, $event->queue);
    }

    /** Record a confirmed enqueue; adapters must call only after the queue accepts it. */
    public function enqueued(array $payload, string $connection, string $queue): void
    {
        if ($async = $this->async()) {
            $async->enqueued($payload, $connection, $queue);

            return;
        }

        if (! $this->enabled($connection)) {
            return;
        }
        $this->guard(function () use ($payload, $connection, $queue) {
            if (! empty($payload['uuid'])) {
                $this->store->cycleTransaction($payload['uuid'], $connection, function () use ($payload, $connection, $queue) {
                    // Redis makes the job visible before the producer receives
                    // JobPushed. A worker may already have captured its attempt.
                    $action = $this->store->jobAction($payload['uuid'], $connection, Support\DispatchIdentity::fromPayload($payload));
                    if ($action?->kind === 'forgotten') { return; }
                    $cycle = $this->store->findCycle($payload['uuid'], $connection, Support\DispatchIdentity::fromPayload($payload));
                    if ($cycle && $cycle->coverage === 'start_only') {
                        $this->store->table('jobs')->where('id', $cycle->id)->update([
                            'coverage' => 'observed_enqueue',
                        ]);

                        return;
                    }

                    if ($cycle && Support\DispatchIdentity::fromPayload($payload)) {
                        return;
                    }

                    $this->store->newJob($payload['uuid'], $connection, $queue, $payload, 'observed_enqueue', $action?->kind === 'cleared' ? (float) $action->observed_at : null);
                });
            }
        });
    }

    public function processing(JobProcessing $event): void
    {
        if ($async = $this->async()) {
            $async->processing($event);

            return;
        }

        $this->current = null;
        $this->stack = [];
        $this->sequence = 0;
        if (! $this->enabled($event->connectionName) || ! $event->job->uuid()) {
            return;
        }
        $this->guard(function () use ($event) {
            $job = $event->job;
            $outline = app(SourceOutline::class)->capture(data_get($job->payload(), 'data.commandName', ''));
            $context = $this->store->cycleTransaction($job->uuid(), $event->connectionName, function () use ($event, $job, $outline) {
                $action = $this->store->jobAction($job->uuid(), $event->connectionName, Support\DispatchIdentity::fromPayload($job->payload()));
                if ($action?->kind === 'forgotten') { return null; }
                $cycle = $this->store->findCycle($job->uuid(), $event->connectionName, Support\DispatchIdentity::fromPayload($job->payload()));
                $jobId = $cycle?->id ?? $this->store->newJob($job->uuid(), $event->connectionName, $job->getQueue(), $job->payload(), 'start_only', $action?->kind === 'cleared' ? (float) $action->observed_at : null);
                $attemptId = (string) Str::ulid();
                $this->store->table('attempts')->insert(array_merge([
                    'id' => $attemptId, 'job_id' => $jobId, 'worker_id' => $this->workerId,
                    'reported_attempt' => $job->attempts(), 'status' => 'processing', 'started_at' => microtime(true),
                    'policy_json' => app(Support\AttemptPolicy::class)->capture($event),
                ], $outline));
                $this->store->table('jobs')->where('id', $jobId)->update([
                    'status' => $this->store->latestOutcome($jobId), 'observed_at' => microtime(true),
                    'attempt_count' => $this->store->connection()->raw('attempt_count + 1'),
                ]);

                return ['job_id' => $jobId, 'attempt_id' => $attemptId];
            });
            if ($context === null) { return; }
            $this->executions[$job] = $context;
            $this->current = $context;
        });
    }

    public function queued(JobQueued $event): void
    {
        if ($async = $this->async()) {
            $async->queued($event);

            return;
        }

        if (! $this->enabled($event->connectionName)) {
            return;
        }
        $this->guard(function () use ($event) {
            $payload = $event->payload();
            if (! is_string($payload['uuid'] ?? null)) {
                return;
            }
            $details = is_object($event->job) ? app(Support\JobContext::class)->capture($event->job, $payload) : [];
            if (in_array($details['arguments_state'] ?? '', ['captured', 'limited'], true) || in_array($details['metadata_state'] ?? '', ['captured', 'limited'], true)) {
                $details['context_captured_at'] = microtime(true);
            }
            $details['delay_seconds'] = is_int($event->delay) && $event->delay >= 0 ? $event->delay : ($event->delay === null ? 0 : null);
            $this->store->transaction(function () use ($payload, $event, $details) {
                if ($cycle = $this->store->findCycle($payload['uuid'], $event->connectionName, Support\DispatchIdentity::fromPayload($payload))) {
                    $this->store->table('jobs')->where('id', $cycle->id)->update($details);
                }
            });
        });
    }

    public function exception(JobExceptionOccurred|JobFailed $event): void
    {
        if ($async = $this->async()) {
            $async->exception($event);

            return;
        }

        if (! isset($this->executions[$event->job])) {
            return;
        }
        $context = $this->executions[$event->job];
        $this->guard(function () use ($event, $context) {
            $fields = $this->sanitizer->exception($event->exception);
            $this->store->table('attempts')->where('id', $context['attempt_id'])->update($fields);
            $this->store->table('jobs')->where('id', $context['job_id'])->update(['exception_class' => $fields['exception_class']]);
        });
    }

    public function attempted(JobAttempted $event): void
    {
        if ($async = $this->async()) {
            $async->attempted($event);

            return;
        }

        $job = $event->job;
        $status = match (true) {
            $job->hasFailed() => 'failed',
            $job->isReleased() => 'released',
            // successful() is shared by Laravel 11–13; the exception property
            // changed in 13. Release must still be checked separately above.
            ! $event->successful() => 'exception',
            default => 'completed',
        };
        $this->finish($job, $status);
    }

    public function timedOut(JobTimedOut $event): void
    {
        if ($async = $this->async()) {
            $async->timedOut($event);

            return;
        }

        if ($event->job) {
            $this->finish($event->job, $event->job->hasFailed() ? 'failed' : 'timed_out');
        }
    }

    private function finish(Job $job, string $status): void
    {
        if (! isset($this->executions[$job])) {
            return;
        }
        $context = $this->executions[$job];
        $monitoredExpiry = ExecutionBackend::native() ? app(Native\MonitoredTags::class)->expiresAt($job->payload()) : null;
        $this->guard(function () use ($context, $status, $job, $monitoredExpiry) {
            $this->store->cycleTransaction($job->uuid(), $job->getConnectionName(), function () use ($context, $status, $monitoredExpiry) {
                $this->store->table('attempts')->where('id', $context['attempt_id'])->whereNull('finished_at')->update([
                    'status' => $status, 'finished_at' => microtime(true),
                ]);
                $this->store->table('jobs')->where('id', $context['job_id'])->update([
                    'status' => $this->store->latestOutcome($context['job_id']), 'observed_at' => microtime(true),
                ]);
                if ($monitoredExpiry !== null) {
                    $this->store->table('jobs')->where('id', $context['job_id'])->where('expires_at', '<', $monitoredExpiry)
                        ->update(['expires_at' => $monitoredExpiry]);
                }
            });
        });
        unset($this->executions[$job]);
        if ($this->current === $context) {
            $this->current = null;
            $this->stack = [];
        }
    }

    public function step(string $label, callable $callback): mixed
    {
        if ($async = $this->async()) {
            return $async->step($label, $callback);
        }

        $step = null;
        if ($this->current && ++$this->sequence <= config('current.max_steps', 100)) {
            $step = $this->guard(function () use ($label) {
                $id = (string) Str::ulid();
                $this->store->table('steps')->insert([
                    'id' => $id, 'attempt_id' => $this->current['attempt_id'],
                    'parent_id' => end($this->stack) ?: null, 'sequence' => $this->sequence,
                    'label' => mb_substr($label, 0, 120), 'status' => 'running', 'started_at' => microtime(true),
                ]);

                return $id;
            });
        }
        if ($step) {
            $this->stack[] = $step;
        }
        try {
            $result = $callback();
            if ($step) {
                $this->guard(fn () => $this->store->table('steps')->where('id', $step)->update([
                    'status' => 'completed', 'finished_at' => microtime(true),
                ]));
            }

            return $result;
        } catch (Throwable $error) {
            if ($step) {
                $this->guard(fn () => $this->store->table('steps')->where('id', $step)->update(array_merge([
                    'status' => 'failed', 'finished_at' => microtime(true),
                ], $this->sanitizer->exception($error))));
            }
            throw $error;
        } finally {
            if ($step) {
                array_pop($this->stack);
            }
        }
    }

    private function enabled(string $connection): bool
    {
        return config('current.enabled') && in_array($connection, config('current.queue_connections', []), true);
    }

    private function guard(callable $callback): mixed
    {
        if (microtime(true) < $this->disabledUntil) {
            return null;
        }
        try {
            $result = $callback();
            $now = hrtime(true) / 1e9;
            if ($now >= $this->nextHeartbeatAt) {
                // Producers/workers can publish their first heartbeat together.
                // One atomic statement avoids a duplicate-key race opening the circuit.
                $this->store->table('meta')->upsert([['key' => 'last_observed_at', 'value' => (string) microtime(true)]], ['key'], ['value']);
                $this->nextHeartbeatAt = $now + 5;
            }

            return $result;
        } catch (Throwable $error) {
            $this->disabledUntil = microtime(true) + 30;
            // One bounded diagnostic per open circuit; no payload or exception message.
            try {
                $path = config('current.diagnostic_path');
                if ($path) {
                    @file_put_contents($path, json_encode(['last_failure_at' => microtime(true), 'error_class' => $error::class]));
                }
            } catch (Throwable) {
                // The observer must not throw even if diagnostics fail.
            }

            return null;
        }
    }
}
