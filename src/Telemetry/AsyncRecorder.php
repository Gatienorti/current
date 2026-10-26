<?php

namespace Current\Telemetry;

use Current\SourceOutline;
use Current\Store;
use Current\Support;
use Current\Support\Sanitizer;
use Illuminate\Support\Str;
use Throwable;

final class AsyncRecorder
{
    private ?array $current = null;

    private array $stack = [];

    private int $sequence = 0;

    private string $worker;

    private \WeakMap $executions;

    private float $disabledUntil = 0;

    public function __construct(private Store $store, private Sanitizer $sanitizer, private Transport $transport)
    {
        $this->worker = (string) Str::ulid();
        $this->executions = new \WeakMap;
    }

    public function currentExecution(): ?array
    {
        return $this->current ? ['job_id' => $this->current['job']['id'], 'attempt_id' => $this->current['attempt']['id']] : null;
    }

    private function base(array $p, string $connection, string $queue, string $coverage): array
    {
        $cycle = $this->transport->cycle($p['uuid'], $connection);
        $now = $cycle['first_observed_at'];
        $id = $cycle['id'];

        return ['id' => $id, 'external_id' => mb_substr($p['uuid'], 0, 100), 'connection' => mb_substr($connection, 0, 100), 'queue' => mb_substr($queue, 0, 100),
            'job_class' => mb_substr((string) data_get($p, 'data.commandName', 'Unknown command'), 0, 240), 'status' => 'pending', 'coverage' => $coverage,
            'first_observed_at' => $now, 'observed_at' => $now, 'expires_at' => $cycle['expires_at'],
            'silenced' => is_bool($p['silenced'] ?? null) ? (int) $p['silenced'] : null, 'retry_of' => isset($p['retry_of']) ? mb_substr((string) $p['retry_of'], 0, 100) : null,
            'display_name' => is_string($p['displayName'] ?? null) ? mb_substr($p['displayName'], 0, 240) : null, 'max_tries' => is_int($p['maxTries'] ?? null) && $p['maxTries'] >= 0 ? $p['maxTries'] : null,
            'timeout' => is_int($p['timeout'] ?? null) && $p['timeout'] >= 0 ? $p['timeout'] : null, 'backoff' => is_string($p['backoff'] ?? null) || is_int($p['backoff'] ?? null) ? mb_substr((string) $p['backoff'], 0, 240) : null,
            'retry_until' => is_numeric($p['retryUntil'] ?? null) ? (float) $p['retryUntil'] : null, 'payload_created_at' => is_numeric($p['createdAt'] ?? null) ? (float) $p['createdAt'] : null,
            'batch_id' => is_string(data_get($p, 'data.batchId')) ? mb_substr($p['data']['batchId'], 0, 100) : null];
    }

    private function enabled($connection): bool
    {
        return config('current.enabled') && in_array($connection, config('current.queue_connections', []), true);
    }

    public function pushed($event): void
    {
        $this->guard(function () use ($event) {
            if (! $this->enabled($event->connectionName)) {
                return;
            }$p = $event->payload->decoded;
            if (empty($p['uuid'])) {
                return;
            }
            $row = $this->base($p, $event->connectionName, $event->queue, 'observed_enqueue');
            $this->emit(['job' => $row, 'enqueue' => $row, 'tags' => $this->sanitizer->tags($p['tags'] ?? [])]);
        });
    }

    public function queued($event): void
    {
        $this->guard(function () use ($event) {
            if (! $this->enabled($event->connectionName)) {
                return;
            }$p = $event->payload();
            if (empty($p['uuid'])) {
                return;
            }
            $row = $this->base($p, $event->connectionName, $event->queue, 'observed_enqueue');
            $details = is_object($event->job) ? app(Support\JobContext::class)->capture($event->job, $p) : [];
            if (in_array($details['arguments_state'] ?? '', ['captured', 'limited']) || in_array($details['metadata_state'] ?? '', ['captured', 'limited'])) {
                $details['context_captured_at'] = microtime(true);
            }
            $details['delay_seconds'] = is_int($event->delay) && $event->delay >= 0 ? $event->delay : ($event->delay === null ? 0 : null);
            $this->emit(['job' => $row, 'enqueue' => array_merge($row, $details), 'tags' => $this->sanitizer->tags($p['tags'] ?? [])]);
        });
    }

    public function processing($event): void
    {
        $this->current = null;
        $this->stack = [];
        $this->sequence = 0;
        $this->guard(function () use ($event) {
            if (! $this->enabled($event->connectionName) || ! $event->job->uuid()) {
                return;
            }
            $job = $this->base($event->job->payload(), $event->connectionName, $event->job->getQueue(), 'start_only');
            $a = array_merge(['id' => (string) Str::ulid(), 'job_id' => $job['id'], 'worker_id' => $this->worker, 'reported_attempt' => $event->job->attempts(), 'status' => 'processing', 'started_at' => microtime(true)], app(SourceOutline::class)->capture(data_get($event->job->payload(), 'data.commandName', '')));
            $this->current = ['job' => $job, 'attempt' => $a, 'revision' => 1];
            $this->executions[$event->job] = $this->current;
            $this->emit($this->current);
        });
    }

    public function exception($event): void
    {
        $this->guard(function () use ($event) {
            if (! isset($this->executions[$event->job])) {
                return;
            }
            $ctx = $this->executions[$event->job];
            $ctx['attempt'] = array_merge($ctx['attempt'], $this->sanitizer->exception($event->exception));
            $ctx['revision']++;
            $this->executions[$event->job] = $ctx;
            if (($this->current['attempt']['id'] ?? null) === $ctx['attempt']['id']) {
                $this->current = $ctx;
            }$this->emit($ctx);
        });
    }

    public function attempted($event): void
    {
        $j = $event->job;
        $this->finish($j, match (true) {
            $j->hasFailed() => 'failed',$j->isReleased() => 'released',! $event->successful() => 'exception',default => 'completed'
        });
    }

    public function timedOut($event): void
    {
        if ($event->job) {
            $this->finish($event->job, $event->job->hasFailed() ? 'failed' : 'timed_out');
        }
    }

    private function finish($job, string $status): void
    {
        if (! isset($this->executions[$job])) {
            return;
        }$ctx = $this->executions[$job];
        $this->guard(function () use ($ctx, $status) {
            $ctx['attempt']['status'] = $status;
            $ctx['attempt']['finished_at'] = microtime(true);
            $ctx['revision']++;
            $this->emit($ctx);
        });
        unset($this->executions[$job]);
        if (($this->current['attempt']['id'] ?? null) === $ctx['attempt']['id']) {
            $this->current = null;
            $this->stack = [];
        }
    }

    public function step(string $label, callable $callback): mixed
    {
        $step = null;
        $context = $this->current;
        if ($context && ++$this->sequence <= config('current.max_steps', 100)) {
            $step = ['id' => (string) Str::ulid(), 'attempt_id' => $context['attempt']['id'], 'parent_id' => end($this->stack) ?: null, 'sequence' => $this->sequence, 'label' => mb_substr($label, 0, 120), 'status' => 'running', 'started_at' => microtime(true)];
            $this->guard(fn () => $this->emit(array_merge($context, ['step' => $step, 'step_revision' => 1])));
            $this->stack[] = $step['id'];
        }
        try {
            $result = $callback();
            if ($step) {
                $step['status'] = 'completed';
                $step['finished_at'] = microtime(true);
                $this->guard(fn () => $this->emit(array_merge($context, ['step' => $step, 'step_revision' => 2])));
            }

            return $result;
        } catch (Throwable $e) {
            if ($step) {
                $step = array_merge($step, ['status' => 'failed', 'finished_at' => microtime(true)], $this->sanitizer->exception($e));
                $this->guard(fn () => $this->emit(array_merge($context, ['step' => $step, 'step_revision' => 2])));
            }throw $e;
        } finally {
            if ($step) {
                array_pop($this->stack);
            }
        }
    }

    private function emit(array $event): void
    {
        $this->transport->emit($event);
    }

    private function guard(callable $callback): mixed
    {
        if (microtime(true) < $this->disabledUntil) {
            return null;
        }
        try {
            return $callback();
        } catch (Throwable $e) {
            $this->disabledUntil = microtime(true) + 30;
            $this->transport->disconnect();
            @file_put_contents(config('current.diagnostic_path'), json_encode(['error_class' => $e::class, 'last_failure_at' => microtime(true)]));

            return null;
        }
    }
}
