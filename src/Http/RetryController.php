<?php

namespace Current\Http;

use Current\HorizonRetry;
use Current\Store;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class RetryController
{
    public function __invoke(Request $request, string $id, Store $store, HorizonRetry $horizon)
    {
        abort_unless(config('current.allow_retries', true), 403);
        $job = $store->jobsWithObservationState()->where('id', $id)->where('expires_at', '>', microtime(true))->first();
        abort_unless($job, 404);
        abort_unless($job->display_status === 'failed', 409, 'Only failed jobs can be retried.');
        $key = 'retry:'.$id;
        try {
            $state = $store->table('meta')->where('key', $key)->value('value');
            if (! $state) {
                abort_unless($horizon->available($job), 409, 'Horizon no longer retains this failed job.');
                // Atomic claim: a repeated POST cannot enqueue the same retry twice.
                if ($store->table('meta')->insertOrIgnore(['key' => $key, 'value' => '{"state":"requested"}'])) {
                    $uuid = $horizon->retry($job->external_id);
                    $state = json_encode(['state' => 'submitted', 'uuid' => $uuid], JSON_THROW_ON_ERROR);
                    $store->table('meta')->where('key', $key)->update(['value' => $state]);
                } else {
                    $state = $store->table('meta')->where('key', $key)->value('value');
                }
            }
            $uuid = json_decode($state ?? '{}', true)['uuid'] ?? null;
            $retry = $uuid ? $store->findCycle($uuid, $job->connection) : null;
            if ($retry) {
                return redirect()->route('current.show', ['id' => $retry->id, 'watch' => 1])->withFragment('execution-map');
            }
        } catch (HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable) {
            // A transport error can occur after enqueue. Never automatically resend.
        }

        return redirect()->route('current.show', ['id' => $id])->with('current_retry_notice', 'The retry outcome could not be confirmed. Check Horizon before taking another action.');
    }
}
