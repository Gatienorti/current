<?php

namespace Current\Tests;

use DOMDocument;
use DOMXPath;
use Current\Prototype\Jobs\PaymentJob;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;

final class InvestigationTest extends TestCase
{
    private function link(string $html, string $xpath): string
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $value = (new DOMXPath($document))->evaluate('string('.$xpath.')');
        $this->assertNotSame('', $value);

        return $value;
    }

    public function test_long_running_attempt_remains_processing_with_an_explicit_missing_finish_notice(): void
    {
        dispatch(new PaymentJob('success'));
        $reserved = Queue::connection('redis')->pop('lens-demo');
        app('events')->dispatch(new JobProcessing('redis', $reserved));
        $attempt = $this->store()->table('attempts')->first();
        $this->store()->table('attempts')->where('id', $attempt->id)->update(['started_at' => microtime(true) - 1800]);
        $response = $this->get('/current/jobs/processing')->assertOk()->assertSee('PaymentJob')->assertSee('Finish not observed')->assertDontSee('Incomplete');
        $this->assertSame('processing', $response->viewData('jobs')->first()->display_status);
        $this->get('/current/jobs/'.$attempt->job_id)->assertOk()->assertSee('worker may still be running')->assertSee('does not collect worker heartbeats');
    }

    public function test_finishing_an_older_overlapping_attempt_does_not_replace_the_newer_outcome(): void
    {
        dispatch(new PaymentJob('success'));
        $older = Queue::connection('redis')->pop('lens-demo');
        app('events')->dispatch(new JobProcessing('redis', $older));
        // Deliberately overlapping event fixture; real reservation expiry is covered separately.
        $newer = clone $older;
        app('events')->dispatch(new JobProcessing('redis', $newer));
        $response = $this->get('/current/monitoring?history=overlapping')->assertOk()->assertSee('Multiple open attempts');
        $this->assertSame(1, $response->viewData('total'));
        app('events')->dispatch(new JobAttempted('redis', $newer));
        $this->assertSame('completed', $this->store()->table('jobs')->first()->status);
        $older->markAsFailed();
        app('events')->dispatch(new JobAttempted('redis', $older));
        $record = $this->store()->table('jobs')->first();
        $this->assertSame('completed', $record->status);
        $response = $this->get('/current/jobs/completed')->assertOk();
        $this->assertSame(1, $response->viewData('total'));
        $this->assertSame('completed', $response->viewData('jobs')->first()->display_status);
        $this->assertSame(['failed', 'completed'], $this->store()->table('attempts')->orderBy('started_at')->pluck('status')->all());
    }

    public function test_unresolved_exception_outcome_is_unknown_and_legacy_history_links_are_translated(): void
    {
        dispatch(new PaymentJob('success'));
        $reserved = Queue::connection('redis')->pop('lens-demo');
        app('events')->dispatch(new JobProcessing('redis', $reserved));
        app('events')->dispatch(new JobAttempted('redis', $reserved, true));
        $this->assertSame('unknown', $this->store()->table('jobs')->first()->status);
        $this->assertSame(1, $this->get('/current/monitoring?status=unknown')->assertOk()->viewData('total'));
        $this->get('/current/monitoring?status=incomplete&queue=lens-demo')->assertRedirect(route('current.monitoring', ['queue' => 'lens-demo', 'history' => 'unfinished']));
        $this->get('/current/monitoring?status=conflicting')->assertRedirect(route('current.monitoring', ['history' => 'overlapping']));
    }

    public function test_filtered_second_page_survives_opening_details_changing_attempt_and_returning(): void
    {
        dispatch(new PaymentJob('retry'));
        $this->work(2);
        $real = $this->store()->table('jobs')->first();
        for ($i = 0; $i < 20; $i++) {
            $id = $this->store()->newJob('later-'.$i, 'redis', 'lens-demo', ['data' => ['commandName' => 'LaterJob'], 'tags' => ['scenario:retry']]);
            $this->store()->table('jobs')->where('id', $id)->update(['status' => 'completed']);
        }
        $list = '/current/jobs/completed?queue=lens-demo&tags=scenario%3Aretry';
        $next = $this->get($list)->assertOk()->viewData('jobs')->nextPageUrl();
        $return = parse_url($next, PHP_URL_PATH).'?'.parse_url($next, PHP_URL_QUERY);
        $page = $this->get($next)->assertOk()->assertSee($real->external_id);
        $href = $this->link($page->getContent(), '//a[contains(@class,"job-name")]/@href');
        $detail = $this->get($href)->assertOk();
        $this->assertSame($return, $detail->viewData('backUrl'));
        $this->assertSame('completed', $detail->viewData('section'));
        $earlier = $this->link($detail->getContent(), '//nav[@aria-label="Execution attempts"]/a[last()]/@href');
        $previousAttempt = $this->get($earlier)->assertOk();
        $this->assertSame(1, (int) $previousAttempt->viewData('attempt')->reported_attempt);
        $previousAttempt->assertDontSee('class="back-link"', false);
        $back = $previousAttempt->viewData('backUrl');
        $this->assertSame($return, $back);
        $returned = $this->get($back)->assertOk()->assertSee($real->external_id);
        $this->assertSame('scenario:retry', $returned->viewData('filters')['tags']);
        $this->assertCount(1, $returned->viewData('jobs'));
    }

    public function test_return_context_accepts_only_known_local_list_routes(): void
    {
        $id = $this->store()->newJob('return-test', 'redis', 'demo', ['data' => ['commandName' => 'ReturnJob']]);
        foreach (['https://example.com/current/monitoring', '//example.com/current', 'javascript:alert(1)', '/current/webhooks', '/current/monitoring#fragment', '/current/monitoring\\evil'] as $return) {
            $response = $this->get('/current/jobs/'.$id.'?'.http_build_query(['return' => $return]))->assertOk();
            $this->assertSame(route('current.monitoring'), $response->viewData('backUrl'));
            $this->assertNull($response->viewData('returnTo'));
        }
        $valid = '/current/monitoring?queue=demo&job_class=App%5CJobs%5CTest';
        $this->assertSame($valid, $this->get('/current/jobs/'.$id.'?'.http_build_query(['return' => $valid]))->assertOk()->viewData('backUrl'));
    }

    public function test_attempt_selection_defaults_to_its_matching_failed_step_in_one_inspector(): void
    {
        dispatch(new PaymentJob('failure'));
        $this->work(3);
        $record = $this->store()->table('jobs')->first();
        $detail = $this->get('/current/jobs/'.$record->id)->assertOk();
        $selected = $detail->viewData('failedStep');
        $this->assertNotNull($selected);
        $this->assertSame($detail->viewData('attempt')->id, $selected->attempt_id);
        $this->assertSame('step-'.$selected->id, $detail->viewData('defaultInspector'));
        $olderId = $detail->viewData('attempts')->last()->id;
        $older = $this->get('/current/jobs/'.$record->id.'?attempt='.$olderId)->assertOk();
        $this->assertSame($olderId, $older->viewData('failedStep')->attempt_id);
        $this->assertNotSame($selected->id, $older->viewData('failedStep')->id);
    }
}
