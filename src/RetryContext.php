<?php

namespace HorizonLens;

final class RetryContext
{
    public function __construct(private Store $store) {}

    public function parent(object $job): ?object
    {
        if (! $job->retry_of) {
            return null;
        }
        $matches = $this->store->table('jobs')->where('external_id', $job->retry_of)
            ->where('connection', $job->connection)->where('queue', $job->queue)
            ->where('job_class', $job->job_class)->where('first_observed_at', '<=', $job->first_observed_at)
            ->where('expires_at', '>', microtime(true))->where('id', '!=', $job->id)->limit(2)->get();

        // Reused UUIDs do not establish which retained dispatch was retried.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function forJob(object $job): array
    {
        $parent = $this->parent($job);
        $sources = [];
        $seen = [$job->id => true];
        $ancestor = $parent;
        for ($depth = 0; $ancestor && $depth < 20 && ! isset($seen[$ancestor->id]); $depth++) {
            $seen[$ancestor->id] = true;
            foreach (['arguments', 'metadata'] as $field) {
                if (! isset($sources[$field]) && $job->{$field.'_json'} === null
                    && in_array($job->{$field.'_state'}, [null, 'not_captured'], true)
                    && $ancestor->{$field.'_json'} !== null
                    && in_array($ancestor->{$field.'_state'}, ['captured', 'limited'], true)) {
                    $sources[$field] = $ancestor;
                }
            }
            $ancestor = $this->parent($ancestor);
        }
        $candidates = $this->store->jobsWithObservationState()->where('retry_of', $job->external_id)
            ->where('connection', $job->connection)->where('queue', $job->queue)
            ->where('job_class', $job->job_class)->where('expires_at', '>', microtime(true))
            ->orderByDesc('first_observed_at')->orderByDesc('id')->limit(21)->get();
        $children = $candidates->take(20)->filter(fn ($child) => $this->parent($child)?->id === $job->id);

        return ['parent' => $parent, 'children' => $children, 'limited' => $candidates->count() > 20, 'sources' => $sources];
    }
}
