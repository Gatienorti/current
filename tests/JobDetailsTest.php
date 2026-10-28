<?php

namespace Current\Tests;

use Current\Prototype\Jobs\OrderInvestigationJob;
use Current\Prototype\Jobs\PaymentJob;
use Current\Support\JobContext;
use Illuminate\Support\Facades\Queue;

final class JobDetailsTest extends TestCase
{
    public function test_real_enqueue_captures_explicit_context_and_policy_before_persistence(): void
    {
        dispatch(new OrderInvestigationJob('order-details-test'));
        $record = $this->store()->table('jobs')->first();
        $args = json_decode($record->arguments_json, true);
        $meta = json_decode($record->metadata_json, true);
        $this->assertSame('order-details-test', $args['order_id']);
        $this->assertSame('[REDACTED]', $args['payment']['api_token']);
        $this->assertSame('[REDACTED]', $meta['authorization']);
        $this->assertStringNotContainsString('demo-token-never-store', json_encode($record));
        $this->assertStringNotContainsString('Bearer demo-secret', json_encode($record));
        $this->assertSame('captured', $record->arguments_state);
        $this->assertSame(3, $record->max_tries);
        $this->assertSame(5, $record->timeout);
        $this->assertSame(0, $record->delay_seconds);
        $this->assertNotNull($record->context_captured_at);
        $this->work(3);
        $this->assertSame(36, $this->store()->table('steps')->count());
        $this->assertSame('failed', $this->store()->table('jobs')->first()->status);
        $response = $this->get('/current/jobs/'.$record->id)->assertOk()->assertSee('Args')->assertSee('Metadata')
            ->assertSee('Lifecycle')->assertSee('Attempt history')->assertSee('order-details-test')
            ->assertSee('[REDACTED]')->assertDontSee('demo-token-never-store')->assertDontSee('never-store-this');
        $this->assertLessThan(strpos($response->getContent(), 'id="job-details"'), strpos($response->getContent(), '<h2>Execution map'));
        $this->assertSame(2, substr_count($response->getContent(), 'data-payload-panel open'));
    }

    public function test_attempt_policy_records_actual_worker_options_and_driver_then_survives_config_changes(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        $attempt = $this->store()->table('attempts')->first();
        $policy = json_decode($attempt->policy_json, true);
        $this->assertSame(['value' => 2, 'source' => 'Worker option'], $policy['timeout']);
        $this->assertSame(['value' => 3, 'source' => 'Job payload'], $policy['tries']);
        $this->assertSame(['value' => 5, 'source' => 'Redis connection'], $policy['retry_after']);
        config()->set('queue.connections.redis.retry_after', 987654);
        $this->get('/current/jobs/'.$attempt->job_id)->assertOk()->assertSee('Worker option')
            ->assertSee('No retry backoff')->assertDontSee('987654');
        // A programmatic invocation outside the worker command has no borrowed options.
        $event = new \Illuminate\Queue\Events\JobProcessing('redis', new class {
            public function payload() { return []; }
        });
        $outside = json_decode(app(\Current\Support\AttemptPolicy::class)->capture($event), true);
        $this->assertNull($outside['timeout']['value']);
    }

    public function test_horizon_worker_options_are_captured_without_changing_execution(): void
    {
        dispatch(new PaymentJob('success'));
        $exit = \Illuminate\Support\Facades\Artisan::call('horizon:work', [
            'connection' => 'redis', '--queue' => 'lens-demo', '--once' => true,
            '--timeout' => 7, '--tries' => 8, '--sleep' => 0, '--memory' => 512,
        ]);
        $this->assertSame(0, $exit);
        $attempt = $this->store()->table('attempts')->first();
        $this->assertSame('completed', $attempt->status);
        $policy = json_decode($attempt->policy_json, true);
        $this->assertSame(['value' => 7, 'source' => 'Worker option'], $policy['timeout']);
        $this->assertSame(['value' => 3, 'source' => 'Job payload'], $policy['tries']);
    }

    public function test_configuration_warnings_use_captured_values_and_do_not_warn_from_missing_data(): void
    {
        $warnings = \Current\Support\AttemptPolicy::warnings([], null);
        $this->assertSame([], $warnings);
        $policy = ['timeout' => ['value' => 90], 'retry_after' => ['value' => 90], 'backoff' => ['value' => '0,0'], 'tries' => ['value' => 3]];
        $warnings = \Current\Support\AttemptPolicy::warnings($policy, (object) ['status' => 'released']);
        $this->assertCount(3, $warnings);
        $this->assertStringContainsString('Duplicate execution risk', $warnings[0]);
        $policy['timeout']['value'] = 60;
        $policy['backoff']['value'] = '5,30';
        $policy['retry_until']['value'] = time() + 3600;
        $warnings = \Current\Support\AttemptPolicy::warnings($policy, (object) ['status' => 'released']);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('deadline takes precedence', $warnings[0]);
    }

    public function test_timeline_preserves_unknown_finishes_and_historical_policy(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        $job = $this->store()->table('jobs')->first();
        $attempt = $this->store()->table('attempts')->first();
        $this->store()->table('attempts')->where('id', $attempt->id)->update(['finished_at' => null, 'status' => 'processing', 'policy_json' => null]);
        $this->store()->table('jobs')->where('id', $job->id)->update(['timeout' => null, 'max_tries' => null, 'backoff' => null]);
        config()->set('queue.connections.redis.retry_after', 987654);
        $response = $this->get('/current/jobs/'.$job->id)->assertOk()
            ->assertSee('Attempt timeline')->assertSee('Finish unknown')->assertSee('Execution settings')
            ->assertSee('Worker default not captured')->assertSee('Historical connection setting not captured')
            ->assertDontSee('987654')->assertDontSee('Between attempts');
        $response->assertSee('unresolved');
    }

    public function test_timeline_shows_measured_gaps_and_selectable_attempts(): void
    {
        dispatch(new OrderInvestigationJob('timeline-retry'));
        $this->work(3);
        $job = $this->store()->table('jobs')->first();
        $attempts = $this->store()->table('attempts')->orderBy('started_at')->get();
        foreach ($attempts as $index => $attempt) {
            $this->store()->table('attempts')->where('id', $attempt->id)->update([
                'started_at' => $job->first_observed_at + 10 + $index * 20,
                'finished_at' => $job->first_observed_at + 15 + $index * 20,
            ]);
        }
        $this->get('/current/jobs/'.$job->id.'?attempt='.$attempts[0]->id)->assertOk()
            ->assertSee('Between attempts')->assertSee('15.00 s')->assertSee('5.00 s')
            ->assertSee('cause not captured')->assertSee('attempt='.$attempts[1]->id, false);
        // An earlier open run makes an apparent gap ambiguous.
        $this->store()->table('attempts')->where('id', $attempts[0]->id)->update(['finished_at' => null]);
        $this->get('/current/jobs/'.$job->id)->assertOk()->assertDontSee('Between attempts');
    }

    public function test_unopted_job_arguments_are_not_unserialized_or_persisted_and_delays_are_observed(): void
    {
        config()->set('current.capture_payload', false);
        Queue::later(7, new PaymentJob('success', 'not-approved-business-data'));
        $record = $this->store()->table('jobs')->first();
        $this->assertSame('not_captured', $record->arguments_state);
        $this->assertNull($record->arguments_json);
        $this->assertNull($record->metadata_json);
        $this->assertSame(7, $record->delay_seconds);
        $this->assertSame(0, $record->attempt_count);
    }

    public function test_bad_context_callback_does_not_stop_dispatch_or_lifecycle_capture(): void
    {
        config()->set('current.job_context', fn () => throw new \RuntimeException('secret=callback-private'));
        dispatch(new PaymentJob('success'));
        $this->work();
        $record = $this->store()->table('jobs')->first();
        $this->assertSame('completed', $record->status);
        $this->assertSame('failed', $record->arguments_state);
        $this->assertNull($record->arguments_json);
        $this->assertNull($record->metadata_json);
        $this->assertStringNotContainsString('callback-private', json_encode($record));
        $this->assertSame(4, $this->store()->table('steps')->count());
    }

    public function test_callback_can_explicitly_export_existing_jobs_and_preserve_empty_json(): void
    {
        config()->set('current.job_context', fn (object $job) => ['arguments' => ['order_id' => $job->order], 'metadata' => []]);
        dispatch(new PaymentJob('success', 'callback-export'));
        $record = $this->store()->table('jobs')->first();
        $this->assertSame(['order_id' => 'callback-export'], json_decode($record->arguments_json, true));
        $this->assertSame('[]', $record->metadata_json);
        $this->assertSame('captured', $record->metadata_state);
    }

    public function test_json_limits_and_objects_do_not_execute_arbitrary_serializers(): void
    {
        $dangerous = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new \LogicException('Must not execute a data serializer');
            }
        };
        config()->set('current.context_max_depth', 2);
        config()->set('current.context_max_items', 8);
        config()->set('current.context_max_string', 32);
        config()->set('current.job_context', fn () => ['arguments' => ['password' => 'private-value', 'description' => 'token=unlabeled-secret', 'object' => $dangerous, 'deep' => ['a' => ['b' => ['c' => 'hidden']]], 'text' => str_repeat('x', 100)]]);
        $context = app(JobContext::class)->capture(new \stdClass);
        $this->assertSame('limited', $context['arguments_state']);
        $this->assertStringNotContainsString('private-value', $context['arguments_json']);
        $this->assertStringNotContainsString('unlabeled-secret', $context['arguments_json']);
        $this->assertStringContainsString('UNSUPPORTED VALUE OMITTED', $context['arguments_json']);
        $this->assertStringContainsString('DEPTH LIMIT', $context['arguments_json']);
        config()->set('current.context_max_bytes', 256);
        config()->set('current.context_max_string', 2048);
        config()->set('current.job_context', fn () => ['metadata' => ['large' => str_repeat('y', 500)]]);
        $context = app(JobContext::class)->capture(new \stdClass);
        $this->assertSame('size_limit', $context['metadata_state']);
        $this->assertNull($context['metadata_json']);
    }

    public function test_additive_migration_keeps_existing_records_and_does_not_backfill_arguments(): void
    {
        // Undo the newer batch index before recreating the pre-details schema.
        (require dirname(__DIR__).'/database/migrations/2026_10_28_000014_add_native_metric_snapshots.php')->down();
        $migration = require dirname(__DIR__).'/database/migrations/2026_10_25_000003_add_job_details.php';
        $migration->down();
        $this->store()->table('jobs')->insert(['id' => '01M5SS8W9PS7F565DGR2RY4YSJ', 'external_id' => 'older-record', 'connection' => 'redis', 'queue' => 'default', 'job_class' => 'OldJob', 'status' => 'completed', 'first_observed_at' => time(), 'observed_at' => time(), 'expires_at' => time() + 3600]);
        $migration->up();
        $record = $this->store()->table('jobs')->first();
        $this->assertSame('completed', $record->status);
        $this->assertNull($record->arguments_json);
        $this->assertNull($record->metadata_json);
        $this->assertNull($record->max_tries);
    }

    public function test_json_values_are_escaped_and_missing_snapshots_are_not_invented_empty_objects(): void
    {
        $value = '<img src=x onerror=alert(1)>';
        config()->set('current.job_context', fn () => ['arguments' => ['html' => $value], 'metadata' => []]);
        dispatch(new PaymentJob('success'));
        $record = $this->store()->table('jobs')->first();
        $response = $this->get('/current/jobs/'.$record->id)->assertOk()->assertSee($value)->assertDontSee($value, false);
        $this->assertSame('captured', $response->viewData('metadataSnapshot')['state']);
        config()->set('current.job_context', null);
        config()->set('current.capture_payload', false);
        dispatch(new PaymentJob('success'));
        $older = $this->store()->table('jobs')->where('id', '!=', $record->id)->first();
        $response = $this->get('/current/jobs/'.$older->id)->assertOk()->assertSee('Not captured');
        $this->assertNull($response->viewData('argumentsSnapshot')['json']);
    }
}
