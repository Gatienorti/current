<?php

namespace Current\Native;

use Current\Store;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Throwable;

final class Sections
{
    public function page(string $section, string $metricKind, array $filters = []): array
    {
        $page = $section === 'batches'
            ? ['title' => 'Batches', 'headers' => ['Batch', 'Total jobs', 'Pending', 'Failed', ''], 'empty' => 'No matching retained batches', 'note' => 'Laravel batch repository · 50 batches per page']
            : ['title' => 'Metrics', 'headers' => [$metricKind === 'jobs' ? 'Job' : 'Connection / queue', 'Completed attempts', 'Mean runtime', 'Attempts / minute'],
                'empty' => 'No captured completions in the past hour', 'note' => 'Captured completed attempts in the past hour. Missing telemetry is excluded; these are not total queue throughput or queue wait estimates. Up to 100 groups.'];
        try {
            if ($section === 'batches') {
                $repository = app(BatchRepository::class);
                $query = $filters['query'] ?? '';
                $before = $filters['before_id'] ?? null;
                $page += ['batchQuery' => $query, 'batchBefore' => $before, 'batchNext' => null,
                    'batchSearchSupported' => $repository instanceof DatabaseBatchRepository];
                if ($query !== '') {
                    if (! $page['batchSearchSupported']) { throw new \RuntimeException('Batch search requires database batch storage.'); }
                    // Use an explicit escape character so %, _ and ! remain
                    // literal under SQLite and either MySQL SQL backslash mode.
                    $pattern = '%'.strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
                    $ids = app('db')->connection(config('queue.batching.database'))->table(config('queue.batching.table', 'job_batches'))
                        ->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("id LIKE ? ESCAPE '!'", [$pattern]))
                        ->when($before, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit(51)->pluck('id');
                    $batches = $ids->map(fn ($id) => $repository->find($id))->filter()->values();
                } else { $batches = collect($repository->get(51, $before)); }
                if ($batches->count() > 50) { $page['batchNext'] = $batches[49]->id; }
                $rows = $batches->take(50)->map(fn ($batch) => ['cells' => [mb_substr($batch->name ?: $batch->id, 0, 240), $batch->totalJobs, $batch->pendingJobs, $batch->failedJobs], 'link' => route('current.batches.show', ['id' => $batch->id]), 'link_label' => 'Inspect batch'])->all();
            } else {
                $now = microtime(true);
                $reset = app(Metrics::class)->resetAt();
                $since = max($now - 3600, $reset);
                if ($reset > $now - 3600) { $page['empty'] = 'No captured completions since the metric reset'; }
                $minutes = max(1 / 60, ($now - $since) / 60);
                $fields = $metricKind === 'jobs' ? ['j.job_class'] : ['j.connection', 'j.queue'];
                $data = app(Store::class)->table('attempts as a')->join('lens_jobs as j', 'j.id', '=', 'a.job_id')
                    ->where('j.expires_at', '>', microtime(true))->where('a.status', 'completed')->where('a.finished_at', '>=', $since)->where('a.finished_at', '<=', $now)
                    ->select($fields)
                    ->selectRaw('COUNT(*) as completions, AVG(CASE WHEN a.started_at IS NOT NULL AND a.finished_at >= a.started_at THEN (a.finished_at - a.started_at) * 1000 END) as runtime')
                    ->groupBy($fields)->orderByDesc('completions')->limit(100)->get();
                $rows = $data->map(fn ($r) => ['cells' => [$metricKind === 'jobs' ? mb_substr($r->job_class, 0, 240) : $r->connection.' / '.$r->queue,
                    (int) $r->completions, $r->runtime === null ? '—' : number_format($r->runtime, 1).' ms', number_format($r->completions / $minutes, 2)],
                    'link' => route('current.metrics.'.$metricKind.'.history', $metricKind === 'jobs' ? ['job_class' => $r->job_class] : ['connection' => $r->connection, 'queue' => $r->queue]), 'link_label' => 'View history'])->all();
            }
            return $page + ['rows' => $rows, 'available' => true, 'section' => $section, 'metricKind' => $metricKind];
        } catch (Throwable) {
            return $page + ['rows' => [], 'available' => false, 'section' => $section, 'metricKind' => $metricKind];
        }
    }
}
