<?php

namespace Current\Telemetry;

use Current\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/** Resolve recorded links even when their write followed the last job event. */
final class JobReferences
{
    public function __construct(private Store $store) {}

    public function canonical(string $id): string
    {
        return $this->store->table('async_aliases')->where('id', $id)->value('canonical_id') ?? $id;
    }

    public function normalize(Collection $records): Collection
    {
        $aliases = $this->store->table('async_aliases')->whereIn('id', $records->pluck('job_id')->filter())
            ->pluck('canonical_id', 'id');
        return $records->map(function ($record) use ($aliases) {
            $record = clone $record;
            $record->job_id = $aliases->get($record->job_id, $record->job_id);
            return $record;
        });
    }

    public function matching(Builder $query, iterable|Builder $jobIds): Builder
    {
        return $query->where(function ($query) use ($jobIds) {
            $query->whereIn('job_id', $jobIds)->orWhereIn('job_id', $this->store->table('async_aliases')
                ->select('id')->whereIn('canonical_id', $jobIds));
        });
    }
}
