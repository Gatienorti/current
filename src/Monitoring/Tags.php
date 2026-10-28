<?php

namespace Current\Monitoring;

use Current\ExecutionBackend;
use Current\Native\MonitoredTags;
use Current\Store;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\TagRepository;
use Laravel\Horizon\Jobs\StopMonitoringTag;

/** Curated monitoring reads. Horizon's retained executable payload is never exposed here. */
final class Tags
{
    public function __construct(private MonitoredTags $native, private Store $store) {}

    public function add(string $tag): void
    {
        $this->native->validateTag($tag);
        if (ExecutionBackend::native()) { $this->native->add($tag); }
        else { app(TagRepository::class)->monitor($tag); }
    }

    public function remove(string $tag): void
    {
        $this->native->validateTag($tag);
        if (ExecutionBackend::native()) { $this->native->remove($tag); }
        else { (new StopMonitoringTag($tag))->handle(app(JobRepository::class), app(TagRepository::class)); }
    }

    public function summary(int $page = 1): array
    {
        if (ExecutionBackend::native()) { $all = $this->native->all(); }
        else {
            $all = [];
            foreach (app(TagRepository::class)->monitoring() as $tag) {
                if (! is_string($tag)) { continue; }
                try { $this->native->validateTag($tag); } catch (\InvalidArgumentException) { continue; }
                $all[$tag] = ['started_at' => null, 'retention_days' => null];
            }
            ksort($all);
        }
        $selected = array_slice($all, ($page - 1) * 25, 25, true); $rows = [];
        foreach ($selected as $tag => $policy) {
            if (ExecutionBackend::native()) {
                $query = $this->nativeQuery($tag, $policy);
                $total = (clone $query)->count();
                $failed = (clone $query)->whereRaw($this->store->outcomeSql()." = 'failed'")->count();
            } else {
                $repository = app(TagRepository::class); $total = $repository->count($tag); $failed = $repository->count('failed:'.$tag);
            }
            $rows[] = $policy + ['tag' => $tag, 'total' => (int) $total, 'failed' => (int) $failed];
        }
        return ['available' => true, 'rows' => $rows, 'total' => count($all), 'page' => $page,
            'previous' => $page > 1, 'next' => count($all) > $page * 25, 'native' => ExecutionBackend::native()];
    }

    public function history(string $tag, bool $failed, int $offset = 0): array
    {
        $this->native->validateTag($tag);
        if (ExecutionBackend::native()) {
            $policy = $this->native->all()[$tag] ?? null; abort_unless($policy, 404);
            $query = $this->nativeQuery($tag, $policy);
            if ($failed) { $query->whereRaw($this->store->outcomeSql()." = 'failed'"); }
            $total = (clone $query)->count();
            $jobs = $query->orderByDesc('observed_at')->orderByDesc('id')->cursorPaginate(25)->withQueryString();
            return ['jobs' => $jobs, 'rows' => null, 'total' => $total, 'policy' => $policy, 'native' => true];
        }
        $repository = app(TagRepository::class);
        abort_unless(in_array($tag, $repository->monitoring(), true), 404);
        $key = $failed ? 'failed:'.$tag : $tag;
        $ids = $repository->paginate($key, $offset, 25);
        $records = app(JobRepository::class)->getJobs($ids, $offset);
        $rows = [];
        foreach ($records as $record) {
            $payload = json_decode($record->payload ?? '{}', true);
            $class = data_get($payload, 'data.commandName');
            $class = is_string($class) && strlen($class) <= 240 && preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/D', $class) ? $class : 'Unknown command';
            $uuid = is_string($record->id ?? null) ? substr($record->id, 0, 100) : '';
            if ($uuid === '') { continue; }
            $status = in_array($record->status ?? '', ['pending', 'reserved', 'completed', 'failed'], true) ? $record->status : 'unknown';
            $rows[] = ['uuid' => $uuid, 'job_class' => $class, 'status' => $status === 'reserved' ? 'processing' : $status,
                'queue' => mb_substr((string) ($record->queue ?? ''), 0, 100),
                'url' => app(\Current\HorizonAdapter::class)->url(($status === 'failed' ? 'failed/' : 'jobs/recent/').rawurlencode($uuid))];
        }
        return ['jobs' => null, 'rows' => $rows, 'total' => (int) $repository->count($key), 'policy' => null, 'native' => false,
            'offset' => $offset, 'previous' => $offset > 0, 'next' => count($ids) === 25];
    }

    private function nativeQuery(string $tag, array $policy)
    {
        return $this->store->jobsWithObservationState()->where('expires_at', '>', microtime(true))
            ->where('observed_at', '>=', $policy['started_at'])
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('lens_tags')->whereColumn('lens_tags.job_id', 'lens_jobs.id')->where('tag', $tag));
    }
}
