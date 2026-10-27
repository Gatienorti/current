<?php

namespace Current\Http;

use Current\ExecutionBackend;
use Current\Native\Retry;
use Current\RetrySubmission;
use Current\Store;
use Illuminate\Bus\BatchRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class NativeBatchesController
{
    public function show(string $id, Store $store, BatchRepository $batches)
    {
        abort_unless(ExecutionBackend::native(), 404);
        $batch = $batches->find($id); abort_unless($batch, 404);
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
        return view('current::native-batch', compact('batch', 'jobs', 'retryable') + ['section' => 'batches']);
    }

    public function retry(Request $request, string $id, Store $store, BatchRepository $batches)
    {
        abort_unless(ExecutionBackend::native(), 404);
        abort_unless(config('current.allow_retries', true) && Gate::has('operateCurrent') && Gate::allows('operateCurrent'), 403);
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
}
