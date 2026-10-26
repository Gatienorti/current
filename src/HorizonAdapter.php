<?php

namespace HorizonLens;

use Illuminate\Bus\BatchRepository;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

/** Read-only Horizon 5 integration; tested combinations are in docs/COMPATIBILITY.md. */
final class HorizonAdapter
{
    public function dashboard(): array
    {
        return [
            'sampled_at' => time(),
            'recent_label' => 'Jobs Past '.$this->period((int) config('horizon.trim.recent', 60)),
            'failed_label' => 'Failed Jobs Past '.$this->period((int) config('horizon.trim.recent_failed', config('horizon.trim.failed', 10080))),
            'metrics' => $this->read(function () {
                $metrics = app(MetricsRepository::class);

                return [
                    'rate' => $metrics->jobsProcessedPerMinute(),
                    'max_runtime' => $metrics->queueWithMaximumRuntime(),
                    'max_throughput' => $metrics->queueWithMaximumThroughput(),
                ];
            }),
            'counts' => $this->read(fn () => [
                'recent' => app(JobRepository::class)->countRecent(),
                'failed' => app(JobRepository::class)->countRecentlyFailed(),
            ]),
            'masters' => $this->read(function () {
                $masters = collect(app(MasterSupervisorRepository::class)->all());

                return [
                    'status' => $masters->isEmpty() ? 'inactive' : ($masters->every(fn ($master) => $master->status === 'paused') ? 'paused' : 'running'),
                    'paused' => $masters->where('status', 'paused')->count(),
                ];
            }),
            'supervisors' => $this->read(fn () => collect(app(SupervisorRepository::class)->all())->map(fn ($supervisor) => [
                'name' => mb_substr($supervisor->name, 0, 240),
                'master' => mb_substr($supervisor->master ?? '', 0, 240),
                'status' => in_array($supervisor->status ?? '', ['running', 'paused'], true) ? $supervisor->status : 'unknown',
                'connection' => mb_substr(data_get($supervisor, 'options.connection', ''), 0, 100),
                'queues' => mb_substr(data_get($supervisor, 'options.queue', ''), 0, 500),
                'balance' => data_get($supervisor, 'options.balance') ?: 'Disabled',
                'processes' => collect($supervisor->processes)->sum(),
            ])->sortBy('name')->values()->all()),
            'wait' => $this->read(fn () => collect(app(WaitTimeCalculator::class)->calculate())->sortDesc()->take(1)->all()),
            'workload' => $this->read(fn () => collect(app(WorkloadRepository::class)->get())->map(function ($queue) {
                $split = collect($queue['split_queues'] ?? [])->map(fn ($part) => [
                    'name' => mb_substr($part['name'], 0, 100), 'length' => (int) $part['length'], 'wait' => (float) $part['wait'],
                ])->values()->all();

                return [
                    'name' => $split ? implode(', ', array_column($split, 'name')) : mb_substr($queue['name'], 0, 500),
                    'length' => (int) $queue['length'], 'processes' => (int) $queue['processes'],
                    'wait' => (float) $queue['wait'], 'split' => $split,
                ];
            })->sortBy('name')->values()->all()),
        ];
    }

    public function queueTopology(): array
    {
        return $this->read(function () {
            $queues = [];
            foreach (app(SupervisorRepository::class)->all() as $supervisor) {
                $connection = (string) data_get($supervisor, 'options.connection', '');
                $configured = explode(',', (string) data_get($supervisor, 'options.queue', ''));
                foreach ($configured as $queue) {
                    if ($connection !== '' && trim($queue) !== '') {
                        $key = $connection.':'.trim($queue);
                        $queues[$key] ??= ['connection' => $connection, 'queue' => trim($queue), 'dedicated' => 0, 'groups' => [], 'supervisors' => []];
                    }
                }
                foreach ((array) $supervisor->processes as $key => $count) {
                    [$connection, $names] = explode(':', $key, 2);
                    $names = array_values(array_unique(array_filter(array_map('trim', explode(',', $names)))));
                    foreach ($names as $name) {
                        $id = $connection.':'.$name;
                        $queues[$id] ??= ['connection' => $connection, 'queue' => $name, 'dedicated' => 0, 'groups' => [], 'supervisors' => []];
                        if (count($names) === 1) {
                            $queues[$id]['dedicated'] += (int) $count;
                        } else {
                            $queues[$id]['groups'][$key] ??= ['queues' => $names, 'workers' => 0];
                            $queues[$id]['groups'][$key]['workers'] += (int) $count;
                        }
                    }
                }
                foreach ($configured as $queue) {
                    $key = (string) data_get($supervisor, 'options.connection', '').':'.trim($queue);
                    if (isset($queues[$key])) {
                        $master = $supervisor->master ?? '';
                        $name = $supervisor->name;
                        $queues[$key]['supervisors'][] = [
                            'name' => $master !== '' && str_starts_with($name, $master.':') ? substr($name, strlen($master) + 1) : $name,
                            'full_name' => $name, 'status' => $supervisor->status ?? 'unknown',
                            'balance' => data_get($supervisor, 'options.balance') ?: 'disabled',
                            'workers' => collect($supervisor->processes)->sum(),
                            'allocations' => (array) $supervisor->processes,
                        ];
                    }
                }
            }

            return $queues;
        });
    }

    private function read(callable $callback): array
    {
        try {
            return ['available' => true, 'data' => $callback()];
        } catch (Throwable) {
            return ['available' => false, 'data' => null];
        }
    }

    private function period(int $minutes): string
    {
        if ($minutes > 0 && $minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days === 1 ? 'Day' : $days.' Days';
        }
        if ($minutes > 0 && $minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? 'Hour' : $hours.' Hours';
        }

        return $minutes === 1 ? 'Minute' : $minutes.' Minutes';
    }

    public function page(string $section, string $metricKind = 'jobs'): array
    {
        $page = match ($section) {
            'metrics' => ['title' => 'Metrics', 'description' => 'Job and queue measurements reported by Horizon.', 'metricKind' => $metricKind, 'headers' => [$metricKind === 'jobs' ? 'Job' : 'Queue', 'Window completions', 'Reported runtime'], 'empty' => $metricKind === 'jobs' ? 'No Horizon job measurements available' : 'No Horizon queue measurements available', 'note' => 'Horizon counters since its latest snapshot; runtime is its reported average in milliseconds. Missing measurements do not prove the queues are idle. Up to 50 '.($metricKind === 'jobs' ? 'jobs' : 'queues').' are shown.'],
            'batches' => ['title' => 'Batches', 'description' => 'Inspect Laravel batches alongside your queued work.', 'headers' => ['Batch', 'Total jobs', 'Pending', 'Failed', ''], 'empty' => 'No batches in retained batch storage', 'note' => 'Read from Laravel’s batch repository. Up to 50 batches are shown. Batch membership does not establish job dependency order.'],
        };

        try {
            $rows = match ($section) {
                'metrics' => $this->metrics($metricKind),
                'batches' => $this->batches(),
            };

            return array_merge($page, ['rows' => $rows, 'available' => true, 'section' => $section]);
        } catch (Throwable) {
            // A missing table/Redis outage is unavailable coverage, never an empty result.
            return array_merge($page, ['rows' => [], 'available' => false, 'section' => $section]);
        }
    }

    public function url(string $path = ''): string
    {
        return rtrim(route('horizon.index'), '/').'/'.ltrim($path, '/');
    }

    private function metrics(string $kind): array
    {
        $metrics = app(MetricsRepository::class);
        $names = $kind === 'jobs' ? $metrics->measuredJobs() : $metrics->measuredQueues();
        $rows = [];
        foreach (array_slice($names, 0, 50) as $name) {
            $count = $kind === 'jobs' ? $metrics->throughputForJob($name) : $metrics->throughputForQueue($name);
            $runtime = $count > 0 ? ($kind === 'jobs' ? $metrics->runtimeForJob($name) : $metrics->runtimeForQueue($name)) : null;
            $rows[] = ['cells' => [mb_substr($name, 0, 240), $count, $runtime === null ? '—' : number_format($runtime, 1).' ms']];
        }

        return $rows;
    }

    private function batches(): array
    {
        return array_map(fn ($batch) => [
            'cells' => [mb_substr($batch->name ?: $batch->id, 0, 240), $batch->totalJobs, $batch->pendingJobs, $batch->failedJobs],
            'link' => $this->url('batches/'.rawurlencode($batch->id)), 'link_label' => 'Open in Horizon ↗',
        ], app(BatchRepository::class)->get(50));
    }
}
