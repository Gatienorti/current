<?php

namespace Current\Http;

use Current\Store;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class RetryController
{
    public function __invoke(Request $request, string $id, Store $store)
    {
        abort_unless(config('current.allow_retries', true), 403);
        $native = \Current\ExecutionBackend::native();
        if ($native) {
            abort_unless(\Illuminate\Support\Facades\Gate::has('operateCurrent') && \Illuminate\Support\Facades\Gate::allows('operateCurrent'), 403);
        }
        $job = $store->jobsWithObservationState()->where('id', $id)->where('expires_at', '>', microtime(true))->first();
        abort_unless($job, 404);
        abort_unless($job->display_status === 'failed', 409, 'Only failed jobs can be retried.');
        try {
            $record = app(\Current\RetrySubmission::class)->submit($job);
            $uuid = $record['uuid'] ?? null;
            $retry = $uuid ? $store->findCycle($uuid, $job->connection, $record['dispatch_id'] ?? null) : null;
            if ($retry && (! $native || $retry->id !== $id)) {
                return redirect()->route('current.show', ['id' => $retry->id, 'watch' => 1])->withFragment('execution-map');
            }
        } catch (HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            // A transport error can occur after enqueue. Never automatically resend.
        }

        return redirect()->route('current.show', ['id' => $id])->with('current_retry_notice', $native ? 'The retry outcome could not be confirmed. Check failed jobs before taking another action.' : 'The retry outcome could not be confirmed. Check Horizon before taking another action.');
    }
}
