<?php

namespace Current\Tests;

use Current\Prototype\Jobs\PaymentJob;
use Current\Recorder;
use ReflectionProperty;

final class OverheadTest extends TestCase
{
    public function test_list_omits_context_columns_and_unused_aggregates_but_detail_keeps_context(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        $db = $this->store()->connection();
        $db->enableQueryLog();
        $response = $this->get('/current/monitoring?queue=lens-demo&status=completed')->assertOk();
        $queries = collect($db->getQueryLog())->pluck('query')->implode("\n");
        $db->disableQueryLog();
        $job = $response->viewData('jobs')->first();
        $this->assertSame('completed', $job->display_status);
        $this->assertSame(1, $response->viewData('total'));
        $this->assertFalse(property_exists($job, 'arguments_json'));
        $this->assertFalse(property_exists($job, 'metadata_json'));
        $this->assertStringNotContainsString('observed_outcomes', $queries);
        $this->assertStringNotContainsString('group by "queue"', $queries);
        $this->assertStringNotContainsString('"lens_jobs".*', $queries);
        $this->assertNotNull($this->get('/current/jobs/'.$job->id)->assertOk()->viewData('job')->arguments_json);
        $this->assertSame('lens-demo', $this->get('/current/dashboard')->assertOk()->viewData('queueSummary')->first()->queue);
    }

    public function test_health_heartbeat_is_throttled_without_buffering_steps_and_resumes_when_due(): void
    {
        $db = $this->store()->connection();
        $db->enableQueryLog();
        dispatch(new PaymentJob('success'));
        // Hold the current interval open deterministically, even on a slow CI host.
        (new ReflectionProperty(Recorder::class, 'nextHeartbeatAt'))->setValue(app(Recorder::class), hrtime(true) / 1e9 + 3600);
        $this->work();
        $writes = collect($db->getQueryLog())->filter(fn ($query) =>
            in_array('last_observed_at', $query['bindings'], true)
            && preg_match('/^(insert|update)/i', $query['query'])
        );
        $db->disableQueryLog();
        $this->assertCount(1, $writes);
        $this->assertSame(4, $this->store()->table('steps')->where('status', 'completed')->count());
        $this->assertSame('completed', $this->store()->table('attempts')->first()->status);
        $this->assertNotNull($this->store()->table('meta')->where('key', 'last_observed_at')->value('value'));

        // Expire the monotonic deadline without a slow wall-clock sleep.
        (new ReflectionProperty(Recorder::class, 'nextHeartbeatAt'))->setValue(app(Recorder::class), 0);
        $this->store()->table('meta')->where('key', 'last_observed_at')->update(['value' => '0']);
        dispatch(new PaymentJob('success', 'second'));
        $this->assertGreaterThan(0, (float) $this->store()->table('meta')->where('key', 'last_observed_at')->value('value'));
        $this->assertSame(2, $this->store()->table('jobs')->count());
    }

    public function test_competing_first_heartbeat_cannot_disable_subsequent_job_observation(): void
    {
        $db = $this->store()->connection();
        $competed = false;
        $db->listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($db, &$competed): void {
            if ($competed || $query->connection !== $db || ! in_array('last_observed_at', $query->bindings, true)) { return; }
            $competed = true;
            // Commit another recorder's first heartbeat immediately after this
            // recorder's first heartbeat operation. A check-then-insert races here.
            $db->table('lens_meta')->upsert([['key' => 'last_observed_at', 'value' => (string) microtime(true)]], ['key'], ['value']);
        });
        dispatch(new PaymentJob('success', 'heartbeat-first'));
        dispatch(new PaymentJob('success', 'heartbeat-next'));
        $this->assertTrue($competed);
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->assertSame(1, $this->store()->table('meta')->where('key', 'last_observed_at')->count());
        $this->assertFileDoesNotExist(config('current.diagnostic_path'));
    }
}
