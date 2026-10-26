<?php

namespace HorizonLens\Http;

use HorizonLens\Broadcasts\BroadcastDebugPrompt;
use HorizonLens\Broadcasts\ReverbIntegration;
use HorizonLens\Broadcasts\SubmissionComparison;
use HorizonLens\Store;
use HorizonLens\Webhooks\WebhookRelations;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BroadcastsController
{
    public function index(Request $request, Store $store)
    {
        abort_unless(ReverbIntegration::visible(), 404);
        $filters = $request->validate([
            'issue' => 'nullable|in:slow,failed,unfinished', 'channel' => 'nullable|string|max:160', 'event' => 'nullable|string|max:160', 'outcome' => 'nullable|in:accepted,failed,unknown',
            'from' => 'nullable|date_format:Y-m-d\TH:i:s', 'to' => 'nullable|date_format:Y-m-d\TH:i:s',
        ]);
        if (! empty($filters['from']) && ! empty($filters['to']) && $filters['from'] >= $filters['to']) {
            throw ValidationException::withMessages(['to' => 'The end must be after the start.']);
        }
        $query = $store->table('broadcasts')->where('expires_at', '>', microtime(true));
        foreach (['event', 'outcome'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['channel'])) {
            $query->whereJsonContains('channels_json', $filters['channel']);
        }
        $issue = $filters['issue'] ?? null;
        if ($issue === 'slow') {
            $query->where('duration_ms', '>=', max(1, (int) config('horizon-lens.broadcasts.slow_ms', 1000)));
        } elseif ($issue === 'failed') {
            $query->where('outcome', 'failed');
        } elseif ($issue === 'unfinished') {
            $query->whereNull('finished_at');
        }
        foreach (['from' => '>=', 'to' => '<'] as $field => $operator) {
            if (! empty($filters[$field])) {
                $query->where('started_at', $operator, (new \DateTimeImmutable($filters[$field], new \DateTimeZone('UTC')))->getTimestamp());
            }
        }

        return view('horizon-lens::broadcasts', ['filters' => $filters, 'broadcasts' => $query->orderByDesc('started_at')->orderByDesc('id')->simplePaginate(25)->withQueryString()]);
    }

    public function compare(string $id, Request $request, Store $store)
    {
        abort_unless(ReverbIntegration::visible(), 404);
        $input = $request->validate(['with' => ['nullable', 'regex:/^[A-Z0-9]{26}$/D']]);
        $left = $store->table('broadcasts')->where('id', $id)->where('expires_at', '>', microtime(true))->first();
        abort_unless($left, 404);
        $right = null;
        $comparison = null;
        if (! empty($input['with'])) {
            $right = $store->table('broadcasts')->where('id', $input['with'])->where('expires_at', '>', microtime(true))->first();
            abort_unless($right, 404);
            $comparison = app(SubmissionComparison::class)->compare($left, $right);
        }
        $candidates = $store->table('broadcasts')->where('expires_at', '>', microtime(true))->where('id', '!=', $id)
            ->orderByDesc('started_at')->orderByDesc('id')->limit(100)->get();

        return view('horizon-lens::broadcast-compare', compact('left', 'right', 'comparison', 'candidates'));
    }

    public function show(string $id, Store $store)
    {
        abort_unless(ReverbIntegration::visible(), 404);
        $broadcast = $store->table('broadcasts')->where('id', $id)->where('expires_at', '>', microtime(true))->first();
        abort_unless($broadcast, 404);
        $job = $broadcast->job_id ? $store->jobsWithObservationState()->where('id', $broadcast->job_id)->where('expires_at', '>', microtime(true))->first() : null;

        $attempt = $job && $broadcast->attempt_id ? $store->table('attempts')->where('job_id', $job->id)->where('id', $broadcast->attempt_id)->first() : null;
        $relatedBroadcasts = $job ? $store->table('broadcasts')->where('job_id', $job->id)->where('expires_at', '>', microtime(true))->orderByDesc('started_at')->orderByDesc('id')->limit(100)->get() : collect();
        $relations = app(WebhookRelations::class);
        $webhookReceipts = $job ? $relations->receipts($job->id) : collect();
        $webhookDeliveries = $job ? $relations->deliveries([$job->id]) : collect();

        $debugPrompt = app(BroadcastDebugPrompt::class)->make($broadcast, $job, $attempt);

        return view('horizon-lens::broadcast-show', compact('debugPrompt', 'broadcast', 'job', 'attempt', 'relatedBroadcasts', 'webhookReceipts', 'webhookDeliveries'));
    }
}
