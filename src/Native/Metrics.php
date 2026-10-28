<?php

namespace Current\Native;

use Current\Store;
use InvalidArgumentException;
use RuntimeException;

/** Scheduled aggregates of captured attempts; never adds writes to the worker event path. */
final class Metrics
{
    public static function key(string $kind, array $dimensions): string
    {
        return hash('sha256', json_encode([$kind, $dimensions], JSON_THROW_ON_ERROR));
    }

    public function reset(?float $now = null): void
    {
        $now ??= microtime(true);
        if (! is_finite($now) || $now <= 0) { throw new InvalidArgumentException('Invalid reset time.'); }
        $store = app(Store::class);
        $store->cycleTransaction('native-metrics', 'snapshot', function () use ($store, $now) {
            $cutoff = max($now, $this->resetAt());
            $store->table('meta')->updateOrInsert(['key' => 'native_metrics_reset_at'], ['value' => (string) $cutoff]);
            $store->table('native_metrics')->delete();
            $store->table('native_queue_samples')->delete();
        });
    }

    public function resetAt(): float
    {
        return (float) (app(Store::class)->table('meta')->where('key', 'native_metrics_reset_at')->value('value') ?? 0);
    }

    public function snapshot(?int $now = null): int
    {
        $now ??= time();
        $hours = config('current.native.metrics.retention_hours', 24);
        if (! is_int($hours) || $hours < 1 || $hours > 168) { throw new InvalidArgumentException('Native metric retention_hours must be between 1 and 168.'); }
        $end = intdiv($now, 300) * 300;
        $store = app(Store::class);
        // Redis observation happens outside the SQL lock/transaction. A failed
        // sample cannot prevent closed attempt aggregates from being stored.
        $samples = [];
        try { $samples = app(QueueSamples::class)->observe(); } catch (\Throwable) {}
        // Recompute three closed windows to include recently arriving async records.
        // One history-owned lock makes retries/multiple schedulers replace, not add.
        return $store->cycleTransaction('native-metrics', 'snapshot', function () use ($store, $now, $end, $hours, $samples) {
            $count = 0; $reset = $this->resetAt();
            if ($now >= $reset) {
                foreach ($samples as $sample) {
                    $previous = $store->table('native_queue_samples')->where('metric_key', $sample['metric_key'])->where('bucket', $end)->value('observed_at');
                    if ($previous === null || (float) $previous <= $now) {
                        $store->table('native_queue_samples')->upsert([$sample + ['bucket' => $end, 'observed_at' => $now, 'expires_at' => $now + $hours * 3600]], ['metric_key', 'bucket']);
                    }
                }
            }
            for ($bucket = $end - 900; $bucket < $end; $bucket += 300) {
                if ($bucket + 300 <= $reset) { continue; }
                $rows = [];
                foreach (['jobs', 'queues'] as $kind) {
                    $groupLimit = $kind === 'queues' ? RuntimeSnapshot::MAX_PAIRS : 1000;
                    $fields = $kind === 'jobs' ? ['j.job_class'] : ['j.connection', 'j.queue'];
                    $groups = $store->table('attempts as a')->join('lens_jobs as j', 'j.id', '=', 'a.job_id')
                        ->where('j.expires_at', '>', $now)->where('a.finished_at', '>=', max($bucket, $reset))->where('a.finished_at', '<', $bucket + 300)
                        ->whereIn('a.status', ['completed', 'failed'])->select($fields)
                        ->selectRaw("SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed, SUM(CASE WHEN a.status = 'failed' THEN 1 ELSE 0 END) AS failed")
                        ->selectRaw('COUNT(CASE WHEN a.started_at IS NOT NULL AND a.finished_at >= a.started_at THEN 1 END) AS duration_samples')
                        ->selectRaw('AVG(CASE WHEN a.started_at IS NOT NULL AND a.finished_at >= a.started_at THEN (a.finished_at - a.started_at) * 1000 END) AS runtime_ms')
                        ->groupBy($fields)->limit($groupLimit + 1)->get();
                    if ($groups->count() > $groupLimit) { throw new RuntimeException('Native metric group limit exceeded; no partial snapshot was saved.'); }
                    foreach ($groups as $group) {
                        $dimensions = $kind === 'jobs' ? [$group->job_class] : [$group->connection, $group->queue];
                        $rows[] = ['metric_key' => self::key($kind, $dimensions), 'bucket' => $bucket, 'kind' => $kind,
                            'label' => mb_substr(implode(' / ', $dimensions), 0, 240), 'completed' => (int) $group->completed, 'failed' => (int) $group->failed,
                            'duration_samples' => (int) $group->duration_samples, 'runtime_ms' => $group->runtime_ms === null ? null : (float) $group->runtime_ms,
                            'observed_at' => $now, 'expires_at' => $bucket + 300 + $hours * 3600];
                    }
                }
                $rows[] = ['metric_key' => 'window', 'bucket' => $bucket, 'kind' => 'window', 'label' => '', 'completed' => 0, 'failed' => 0,
                    'duration_samples' => 0, 'runtime_ms' => null, 'observed_at' => $now, 'expires_at' => $bucket + 300 + $hours * 3600];
                // Replace the complete bounded window so removed/expired source groups
                // cannot leave an old aggregate behind during the correction period.
                $store->table('native_metrics')->where('bucket', $bucket)->delete();
                foreach (array_chunk($rows, 100) as $chunk) { $store->table('native_metrics')->insert($chunk); }
                $count += count($rows) - 1;
            }
            return $count;
        });
    }

    public function series(string $kind, array $dimensions, int $hours = 1, ?int $now = null): array
    {
        if (! in_array($kind, ['jobs', 'queues'], true) || ! in_array($hours, [1, 24], true)) { throw new InvalidArgumentException('Invalid metric selection.'); }
        $now ??= time(); $reset = $this->resetAt(); $end = intdiv($now, 300) * 300; $start = $end - $hours * 3600;
        $rows = app(Store::class)->table('native_metrics')->whereIn('metric_key', ['window', self::key($kind, $dimensions)])
            ->where('bucket', '>=', $start)->where('bucket', '<', $end)->where('expires_at', '>', $now)->get();
        $windows = $rows->where('kind', 'window')->keyBy('bucket'); $metrics = $rows->where('kind', $kind)->keyBy('bucket');
        $series = [];
        for ($bucket = $start; $bucket < $end; $bucket += 300) {
            $row = $metrics->get($bucket); $available = $windows->has($bucket) && $bucket + 300 > $reset;
            $series[] = ['at' => $bucket, 'partial' => $reset > $bucket && $reset < $bucket + 300, 'available' => $available, 'completed' => $available ? (int) ($row->completed ?? 0) : null,
                'failed' => $available ? (int) ($row->failed ?? 0) : null, 'duration' => ! $available || $row?->runtime_ms === null ? null : (float) $row->runtime_ms,
                'duration_samples' => $available ? (int) ($row->duration_samples ?? 0) : null];
        }
        return $series;
    }
}
