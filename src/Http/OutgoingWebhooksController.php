<?php

namespace HorizonLens\Http;

use HorizonLens\Store;
use HorizonLens\Webhooks\DeliveryGroups;
use HorizonLens\Webhooks\WebhookRelations;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class OutgoingWebhooksController
{
    public function index(Request $request, Store $store)
    {
        $filters = $request->validate(['event_id' => 'nullable|string|max:160', 'event_type' => 'nullable|string|max:160',
            'outcome' => 'nullable|in:http_error,unfinished,transport_error', 'destination' => 'nullable|string|max:80', 'status' => 'nullable|integer|min:100|max:599',
            'from' => 'nullable|date_format:Y-m-d\TH:i:s', 'to' => 'nullable|date_format:Y-m-d\TH:i:s']);
        if (! empty($filters['from']) && ! empty($filters['to']) && $filters['from'] >= $filters['to']) {
            throw ValidationException::withMessages(['to' => 'The end must be after the start.']);
        }
        $query = $store->table('webhook_deliveries')->where('expires_at', '>', microtime(true));
        if (! empty($filters['destination'])) {
            $query->where('destination', $filters['destination']);
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
        } elseif ($outcome === 'transport_error') {
            $query->whereNull('http_status')->whereNotNull('exception_class');
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $operator) {
            if (! empty($filters[$key])) {
                $query->where('started_at', $operator, (new \DateTimeImmutable($filters[$key], new \DateTimeZone('UTC')))->getTimestamp());
            }
        }

        $query = app(DeliveryGroups::class)->listing($query);

        return view('horizon-lens::outgoing-webhooks', ['filters' => $filters, 'deliveries' => $query->orderByDesc('started_at')->orderByDesc('id')->simplePaginate(25)->withQueryString()]);
    }

    public function show(string $id, Store $store)
    {
        $delivery = $store->table('webhook_deliveries')->where('id', $id)->where('expires_at', '>', microtime(true))->first();
        abort_unless($delivery, 404);
        $groupQuery = app(DeliveryGroups::class)->attempts($delivery);
        $groupSummary = (clone $groupQuery)->selectRaw('COUNT(*) as attempts, MIN(started_at) as first_started, MAX(finished_at) as last_finished, SUM(CASE WHEN finished_at IS NULL THEN 1 ELSE 0 END) as unfinished')->first();
        $deliveryAttempts = (clone $groupQuery)->orderByDesc('started_at')->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $retainedJobIds = $store->table('jobs')->whereIn('id', $deliveryAttempts->pluck('job_id')->filter())->where('expires_at', '>', microtime(true))->pluck('id');
        $job = $delivery->job_id ? $store->jobsWithObservationState()->where('id', $delivery->job_id)->where('expires_at', '>', microtime(true))->first() : null;

        $webhookReceipts = $job ? app(WebhookRelations::class)->receipts($job->id) : collect();

        return view('horizon-lens::outgoing-webhook-show', compact('delivery', 'job', 'webhookReceipts', 'deliveryAttempts', 'groupSummary', 'retainedJobIds'));
    }
}
