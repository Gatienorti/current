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
