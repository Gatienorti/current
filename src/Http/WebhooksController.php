<?php

namespace HorizonLens\Http;

use HorizonLens\Store;
use HorizonLens\Webhooks\ReceiptGroups;
use HorizonLens\Webhooks\WebhookRelations;
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
            $query->whereIn('id', $store->table('webhook_jobs')->select('receipt_id')->whereIn('job_id', $failedJobs));
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            if (! empty($filters[$key])) {
                $query->where('received_at', $operator, (new \DateTimeImmutable($filters[$key], new \DateTimeZone('UTC')))->getTimestamp());
            }
        }

        $query = app(ReceiptGroups::class)->listing($query);

        return view('horizon-lens::webhooks', ['filters' => $filters, 'receipts' => $query->orderByDesc('received_at')->orderByDesc('id')->simplePaginate(25)->withQueryString()]);
    }

    public function show(string $id, Store $store)
    {
        $receipt = $store->table('webhook_receipts')->where('id', $id)->where('expires_at', '>', microtime(true))->first();
        abort_unless($receipt, 404);
        $groupQuery = app(ReceiptGroups::class)->receipts($receipt);
        $receiptCount = (clone $groupQuery)->count();
        $groupReceipts = (clone $groupQuery)->orderByDesc('received_at')->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $groupJobLinks = $store->table('webhook_jobs')->join('lens_jobs', 'lens_jobs.id', '=', 'lens_webhook_jobs.job_id')
            ->whereIn('receipt_id', $groupReceipts->pluck('id'))->where('lens_jobs.expires_at', '>', microtime(true))
            ->select('receipt_id', 'job_id', 'job_class')->get()->groupBy('receipt_id');
        $jobIds = $store->table('webhook_jobs')->where('receipt_id', $id)->pluck('job_id');
        $jobs = $store->jobsWithObservationState()->whereIn('id', $jobIds)->where('expires_at', '>', microtime(true))->get();

        $webhookDeliveries = app(WebhookRelations::class)->deliveries($jobIds);

        return view('horizon-lens::webhook-show', compact('receipt', 'jobs', 'webhookDeliveries', 'groupReceipts', 'receiptCount', 'groupJobLinks'));
    }
}
