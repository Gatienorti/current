<?php

namespace Current\Http;

use Current\ExecutionBackend;
use Current\Native\Retry;
use Current\Native\BatchRetryPass;
use Current\RetrySubmission;
use Current\Store;
use Illuminate\Bus\BatchRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class NativeBatchesController
{
    public function show(Request $request, string $id, Store $store, BatchRepository $batches)
    {
        abort_unless(ExecutionBackend::native(), 404);
        $batch = $batches->find($id); abort_unless($batch, 404);
        $input = $request->validate(['retry_pass' => ['sometimes', 'string', 'max:1024'], 'retry_after' => ['sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/D']]);
        $retryPass = $input['retry_pass'] ?? app(BatchRetryPass::class)->start($id);
        app(BatchRetryPass::class)->cutoff($retryPass, $id);
        $retryAfter = $input['retry_after'] ?? null;
        $jobs = $store->jobsWithObservationState()->where('batch_id', $id)->where('expires_at', '>', microtime(true))
            ->orderByDesc('first_observed_at')->orderByDesc('id')->cursorPaginate(50)->withQueryString();
        $retryable = []; $canRetry = config('current.allow_retries', true) && Gate::has('operateCurrent') && Gate::allows('operateCurrent');
        if ($canRetry) {
            foreach ($jobs as $job) {
                if ($job->display_status !== 'failed' || ! in_array($job->external_id, $batch->failedJobIds, true)) { continue; }
                try {
                    if (! $store->table('meta')->where('key', 'retry:'.$job->id)->exists() && app(Retry::class)->available($job)) { $retryable[] = $job->id; }
                } catch (Throwable) {}
            }
        }
        return view('current::native-batch', compact('batch', 'jobs', 'retryable', 'canRetry', 'retryPass', 'retryAfter') + ['section' => 'batches']);
    }

    public function retry(Request $request, string $id, Store $store, BatchRepository $batches)
    {
        abort_unless(ExecutionBackend::native(), 404);
        abort_unless(config('current.allow_retries', true) && Gate::has('operateCurrent') && Gate::allows('operateCurrent'), 403);
        if ($request->input('scope') === 'batch') { return $this->retryBatch($request, $id, $batches); }
        $input = $request->validate(['jobs' => ['required', 'array', 'min:1', 'max:50'], 'jobs.*' => ['required', 'string', 'distinct', 'regex:/^[A-Z0-9]{26}$/D']]);
        $batch = $batches->find($id); abort_unless($batch, 404);
        // Validate the complete submitted selection before any enqueue, then share
        // the same per-dispatch claim as the single-job retry endpoint.
        $jobs = $store->jobsWithObservationState()->whereIn('id', $input['jobs'])->where('batch_id', $id)->where('expires_at', '>', microtime(true))->get();
        abort_unless($jobs->count() === count($input['jobs']), 409, 'Batch selection changed. Refresh before retrying.');
        foreach ($jobs as $job) {
            abort_unless($job->display_status === 'failed' && in_array($job->external_id, $batch->failedJobIds, true), 409, 'Batch selection changed. Refresh before retrying.');
        }
        $submitted = 0; $unconfirmed = 0;
        foreach ($jobs as $job) {
            try {
                $result = app(RetrySubmission::class)->submit($job);
                if (($result['state'] ?? null) === 'submitted') { $submitted++; } else { $unconfirmed++; }
            } catch (Throwable) { $unconfirmed++; }
        }
        return redirect()->route('current.batches.show', ['id' => $id])->with('current_batch_notice',
            $submitted.' selected retries submitted or already submitted.'.($unconfirmed ? ' '.$unconfirmed.' unavailable or unconfirmed; inspect their job history before another action.' : ''));
    }

    private function retryBatch(Request $request, string $id, BatchRepository $batches)
    {
        $input = $request->validate(['retry_pass' => ['required', 'string', 'max:1024'], 'retry_after' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/D']]);
        $before = app(BatchRetryPass::class)->cutoff($input['retry_pass'], $id);
        $batch = $batches->find($id); abort_unless($batch, 404);
        // The caller supplies only a cursor, never executable IDs. Laravel's
        // retained batch owns the selection. Bound failed-store and enqueue IO.
        $ids = array_values(array_unique(array_filter($batch->failedJobIds, fn ($uuid) => is_string($uuid)
            && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $uuid) && strcmp($uuid, $input['retry_after'] ?? '') > 0)));
        sort($ids, SORT_STRING);
        $page = array_slice($ids, 0, 50); $submitted = 0; $unconfirmed = 0;
        foreach ($page as $uuid) {
            try {
                $job = app(Retry::class)->batchJob($id, $uuid, $before);
                $result = app(RetrySubmission::class)->submit($job);
                if (($result['state'] ?? null) === 'submitted') { $submitted++; } else { $unconfirmed++; }
            } catch (Throwable) { $unconfirmed++; }
        }
        $next = count($ids) > 50 ? end($page) : null;
        $parameters = array_filter(['id' => $id, 'retry_pass' => $next ? $input['retry_pass'] : null, 'retry_after' => $next]);
        $notice = $submitted.' batch retries submitted or already submitted. '.$unconfirmed.' unavailable, changed, or unconfirmed.'
            .($next ? ' Continue the pass to inspect the remaining failures.' : ' This pass has inspected all remaining retained failed IDs.')
            .' New dispatches created during this pass are excluded. Unknown outcomes are never automatically resent.';
        if ($request->wantsJson()) {
            return response()->json(['submitted' => $submitted, 'unconfirmed' => $unconfirmed, 'inspected' => count($page),
                'complete' => $next === null, 'next' => $next ? ['retry_pass' => $input['retry_pass'], 'retry_after' => $next] : null,
                'resume_url' => route('current.batches.show', ['retry_pass' => $input['retry_pass']] + $parameters), 'notice' => $notice]);
        }
        return redirect()->route('current.batches.show', $parameters)->with('current_batch_notice', $notice);
    }
}
