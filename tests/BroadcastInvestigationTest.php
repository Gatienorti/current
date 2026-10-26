<?php

namespace HorizonLens\Tests;

use HorizonLens\Broadcasts\BroadcastRecorder;

final class BroadcastInvestigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('horizon-lens.broadcasts', ['visible' => true, 'enabled' => true, 'events' => ['OrderUpdated'], 'channels' => ['private-orders.*', 'presence-team.*']]);
    }

    public function test_explicit_context_is_redacted_bounded_escaped_and_channel_capture_is_opt_in(): void
    {
        $recorder = app(BroadcastRecorder::class);
        $one = $recorder->start('reverb', ['private-orders.123'], 'OrderUpdated', ['token' => 'private-token']);
        BroadcastRecorder::finish($one);
        $row = $this->store()->table('broadcasts')->first();
        $this->assertNull($row->payload_json);
        $this->assertNull($row->exact_channels_json);
        $this->assertSame('["Private"]', $row->channel_types_json);
        config()->set('horizon-lens.broadcasts.capture_channels', true);
        config()->set('horizon-lens.broadcasts.context', fn ($event, $channels, $payload) => ['payload' => $payload, 'metadata' => ['event' => $event, 'signature' => 'private-signature']]);
        $two = $recorder->start('reverb', ['private-orders.123', 'private-excluded.987', 'presence-team.1'], 'OrderUpdated', ['name' => '<script>alert(1)</script>', 'token' => 'private-token']);
        BroadcastRecorder::finish($two);
        $row = $this->store()->table('broadcasts')->where('id', $two['id'])->first();
        $this->assertStringNotContainsString('private-token', json_encode($row));
        $this->assertStringNotContainsString('private-signature', json_encode($row));
        $this->assertStringNotContainsString('private-excluded', $row->exact_channels_json);
        $this->get('/horizon-lens/broadcasts/'.$row->id)->assertOk()->assertSee('[REDACTED]')->assertSee('Private, Presence')->assertDontSee('<script>alert(1)</script>', false);
        config()->set('horizon-lens.context_max_bytes', 256);
        $large = $recorder->start('reverb', ['private-orders.1'], 'OrderUpdated', ['value' => str_repeat('a', 400)]);
        $this->assertSame('size_limit', $this->store()->table('broadcasts')->where('id', $large['id'])->value('payload_state'));
    }

    public function test_export_and_error_sanitizer_failure_do_not_lose_the_submission_outcome(): void
    {
        config()->set('horizon-lens.broadcasts.context', fn () => throw new \RuntimeException('private-export-error'));
        config()->set('horizon-lens.broadcasts.message_sanitizer', fn () => throw new \RuntimeException('private-sanitizer-error'));
        $context = app(BroadcastRecorder::class)->start('reverb', ['private-orders.1'], 'OrderUpdated');
        BroadcastRecorder::finish($context, new \RuntimeException('private-message'));
        $row = $this->store()->table('broadcasts')->first();
        $this->assertSame('failed', $row->outcome);
        $this->assertSame('failed', $row->payload_state);
        $this->assertSame('failed', $row->error_state);
        $this->assertNull($row->exception_message);
        $this->get('/horizon-lens/broadcasts/'.$row->id)->assertOk()->assertSee('Additional error capture was unavailable');
    }

    public function test_sanitized_failure_context_and_issue_views_keep_unfinished_distinct(): void
    {
        config()->set('horizon-lens.broadcasts.message_sanitizer', fn ($message) => 'Safe diagnostic');
        $recorder = app(BroadcastRecorder::class);
        $failed = $recorder->start('reverb', ['private-orders.1'], 'OrderUpdated');
        BroadcastRecorder::finish($failed, new \RuntimeException('private-message'));
        $unfinished = $recorder->start('reverb', ['private-orders.1'], 'OrderUpdated');
        $this->store()->table('broadcasts')->where('id', $failed['id'])->update(['duration_ms' => 1500]);
        $this->get('/horizon-lens/broadcasts?issue=slow')->assertOk()->assertSee($failed['id'])->assertDontSee($unfinished['id']);
        $this->get('/horizon-lens/broadcasts?issue=unfinished')->assertOk()->assertSee($unfinished['id'])->assertDontSee($failed['id']);
        $this->get('/horizon-lens/broadcasts/'.$failed['id'])->assertOk()->assertSee('Safe diagnostic')->assertDontSee('private-message')->assertSee('Stack context');
    }
}
