<?php

namespace Current\Tests;

use Current\DebugPrompt;
use Current\HorizonRetry;
use Current\Prototype\Jobs\PaymentJob;

final class DebugPromptTest extends TestCase
{
    public function test_failed_job_prompt_contains_selected_evidence_and_escaped_data(): void
    {
        $job = new PaymentJob('failure');
        $job->tries = 1;
        dispatch($job);
        $this->work();
        $record = $this->store()->table('jobs')->first();
        $attempt = $this->store()->table('attempts')->first();
        $this->store()->table('attempts')->where('id', $attempt->id)->update([
            'exception_message' => '</textarea><script>bad()</script>',
        ]);
        $response = $this->get('/current/jobs/'.$record->id);
        $response->assertOk()->assertSee('Debug with AI')->assertSee('Copy debugging prompt')
            ->assertSee('untrusted diagnostic data')->assertSee($attempt->id)
            ->assertSee('Charge payment')->assertSee('Sanitized stack frames')
            ->assertDontSee('</textarea><script>bad()</script>', false)
            ->assertSee('&lt;/textarea&gt;&lt;script&gt;bad()', false);
        $uuid = app(HorizonRetry::class)->retry($record->external_id);
        $retry = $this->store()->findCycle($uuid, $record->connection);
        $this->work();
        $this->get('/current/jobs/'.$retry->id)->assertOk()
            ->assertSee('Earlier enqueue; not a fresh capture of this retry')
            ->assertSee($record->external_id);
    }

    public function test_successful_job_has_no_prompt_and_large_sections_are_bounded(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        $record = $this->store()->jobsWithObservationState()->first();
        $this->get('/current/jobs/'.$record->id)->assertOk()->assertDontSee('data-debug-open', false);
        $record->arguments_json = json_encode(['large' => str_repeat('x', 50000)]);
        $record->arguments_state = 'captured';
        $text = app(DebugPrompt::class)->make($record, null, [], [], $record, $record);
        $this->assertStringContainsString('Section truncated at 12,000 bytes', $text);
        $this->assertStringContainsString('Metadata snapshot', $text);
        $this->assertStringContainsString('"attempt_id": null', $text);
        $this->assertLessThan(18000, strlen($text));
    }
}
