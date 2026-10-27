<?php

namespace Current\Webhooks;

use Current\Store;
use Current\Telemetry\JobReferences;
use Illuminate\Support\Collection;

final class WebhookRelations
{
    public function __construct(private Store $store) {}

    public function receipts(string $jobId): Collection
    {
        $retained = $this->store->table('jobs')->where('id', $jobId)->where('expires_at', '>', microtime(true))->exists();
        if (! $retained) {
            return collect();
        }

        return $this->store->table('webhook_receipts')
            ->whereIn('id', app(JobReferences::class)->matching($this->store->table('webhook_jobs')->select('receipt_id'), [$jobId]))
            ->where('expires_at', '>', microtime(true))->orderByDesc('received_at')->orderByDesc('id')->limit(100)->get();
    }

    public function deliveries(iterable $jobIds): Collection
    {
        $rows = app(JobReferences::class)->matching($this->store->table('webhook_deliveries'),
            $this->store->table('jobs')->select('id')->whereIn('id', $jobIds)->where('expires_at', '>', microtime(true)))
            ->where('expires_at', '>', microtime(true))->orderByDesc('started_at')->orderByDesc('id')->limit(100)->get();
        return app(JobReferences::class)->normalize($rows);
    }
}
