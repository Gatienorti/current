<?php

namespace Current\Http;

use Current\Store;
use Current\Webhooks\ReceiptGroups;
use Current\Webhooks\WebhookRelations;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WebhooksController
{
    public function index(Request $request, Store $store)
    {
        $filters = $request->validate(['event_id' => 'nullable|string|max:160', 'event_type' => 'nullable|string|max:160',
            'outcome' => 'nullable|in:http_error,unfinished,invalid_signature,job_failed', 'provider' => 'nullable|string|max:80', 'status' => 'nullable|integer|min:100|max:599',
            'from' => 'nullable|date_format:Y-m-d\TH:i:s', 'to' => 'nullable|date_format:Y-m-d\TH:i:s']);
        if (! empty($filters['from']) && ! empty($filters['to']) && $filters['from'] >= $filters['to']) {
            throw ValidationException::withMessages(['to' => 'The end must be after the start.']);
        }
        $query = $store->table('webhook_receipts')->where('expires_at', '>', microtime(true));
        if (! empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }
        if (! empty($filters['status'])) {
            $query->where('http_status', $filters['status']);
        }
        foreach (['event_id', 'event_type'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        $outcome = $filters['outcome'] ?? '';
        if ($outcome === 'http_error') {
            $query->where('http_status', '>=', 400);
        } elseif ($outcome === 'unfinished') {
            $query->whereNull('finished_at');
        } elseif ($outcome === 'invalid_signature') {
            $query->where('signature', 'invalid');
        } elseif ($outcome === 'job_failed') {
            $failedJobs = $store->table('jobs')->select('id')->where('expires_at', '>', microtime(true))->whereRaw($store->outcomeSql()." = 'failed'");
            $query->whereIn('id', app(\Current\Telemetry\JobReferences::class)->matching($store->table('webhook_jobs')->select('receipt_id'), $failedJobs));
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            if (! empty($filters[$key])) {
                $query->where('received_at', $operator, (new \DateTimeImmutable($filters[$key], new \DateTimeZone('UTC')))->getTimestamp());
            }
        }

        $query = app(ReceiptGroups::class)->listing($query);

        return view('current::webhooks', ['filters' => $filters, 'receipts' => $query->orderByDesc('received_at')->orderByDesc('id')->simplePaginate(25)->withQueryString()]);
    }

    public function show(string $id, Store $store)
    {
        $receipt = $store->table('webhook_receipts')->where('id', $id)->where('expires_at', '>', microtime(true))->first();
        abort_unless($receipt, 404);
        $groupQuery = app(ReceiptGroups::class)->receipts($receipt);
        $receiptCount = (clone $groupQuery)->count();
        $groupReceipts = (clone $groupQuery)->orderByDesc('received_at')->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $groupJobLinks = $store->table('webhook_jobs')->leftJoin('lens_async_aliases as aliases', 'aliases.id', '=', 'lens_webhook_jobs.job_id')->join('lens_jobs', 'lens_jobs.id', '=', $store->connection()->raw('COALESCE(aliases.canonical_id,lens_webhook_jobs.job_id)'))
            ->whereIn('receipt_id', $groupReceipts->pluck('id'))->where('lens_jobs.expires_at', '>', microtime(true))
            ->select('receipt_id', 'job_class')->selectRaw('lens_jobs.id AS job_id')->distinct()->get()->groupBy('receipt_id');
        $jobIds = app(\Current\Telemetry\JobReferences::class)->normalize($store->table('webhook_jobs')->where('receipt_id', $id)->get())->pluck('job_id')->unique();
        $jobs = $store->jobsWithObservationState()->whereIn('id', $jobIds)->where('expires_at', '>', microtime(true))->get();

        $webhookDeliveries = app(WebhookRelations::class)->deliveries($jobIds);

        return view('current::webhook-show', compact('receipt', 'jobs', 'webhookDeliveries', 'groupReceipts', 'receiptCount', 'groupJobLinks'));
    }
}
