<?php

namespace Current\Tests;

use Current\Prototype\Jobs\PaymentJob;
use Illuminate\Auth\GenericUser;

final class RefreshTest extends TestCase
{
    public function test_refresh_returns_data_regions_without_chrome_or_filter_choice_queries(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        $url = '/current/monitoring?queue=lens-demo&status=completed';
        $full = $this->get($url)->assertOk()->assertSee('data-job-filters', false)->assertSee('<html', false);
        $db = $this->store()->connection();
        $db->enableQueryLog();
        $partial = $this->get($url, ['X-Current-Refresh' => '1'])->assertOk()
            ->assertHeader('X-Current-Refresh', '1')->assertSee('PaymentJob')
            ->assertSee('data-live-region="jobs"', false)->assertSee('data-live-region="coverage"', false)
            ->assertDontSee('<html', false)->assertDontSee('<script', false)
            ->assertDontSee('class="sidebar"', false)->assertDontSee('data-job-filters', false);
        $queries = collect($db->getQueryLog())->pluck('query')->implode("\n");
        $db->disableQueryLog();
        $this->assertStringNotContainsString('select distinct', strtolower($queries));
        $this->assertSame($full->viewData('total'), $partial->viewData('total'));
        $this->assertSame($full->viewData('jobs')->pluck('id')->all(), $partial->viewData('jobs')->pluck('id')->all());
        $this->assertStringContainsString('X-Current-Refresh', $partial->headers->get('Vary'));
        $this->assertStringContainsString('no-store', $partial->headers->get('Cache-Control'));
        $this->assertStringContainsString('X-Current-Refresh', $full->headers->get('Vary'));
        $this->assertLessThan(strlen($full->getContent()), strlen($partial->getContent()));
        $job = $partial->viewData('jobs')->first();
        $this->get('/current/jobs/'.$job->id, ['X-Current-Refresh' => '1'])->assertOk()
            ->assertHeader('X-Current-Refresh', '1')->assertSee('data-live-region="investigation"', false)
            ->assertSee('Validate order')->assertDontSee('<html', false);
    }

    public function test_all_refreshable_listing_and_dashboard_views_render_partial_html(): void
    {
        config()->set('current.broadcasts.visible', true);
        foreach (['dashboard', 'jobs/pending', 'jobs/failed', 'metrics', 'metrics/queues', 'batches', 'schedule',
            'webhooks/dashboard', 'webhooks', 'webhooks/outgoing', 'broadcasts/dashboard', 'broadcasts', 'broadcasts/authorization'] as $path) {
            $this->get('/current/'.$path, ['X-Current-Refresh' => '1'])->assertOk()
                ->assertHeader('X-Current-Refresh', '1')->assertSee('data-live-region=', false)
                ->assertDontSee('data-job-filters', false)->assertDontSee('<html', false);
        }
    }

    public function test_partial_requests_keep_authorization_and_health_region_can_appear_and_clear(): void
    {
        $headers = ['X-Current-Refresh' => '1'];
        $this->get('/current/monitoring', $headers)->assertSee('data-live-region="coverage"', false)
            ->assertDontSee('Recorder failure observed');
        file_put_contents(config('current.diagnostic_path'), json_encode(['last_failure_at' => time(), 'error_class' => 'RuntimeException']));
        $this->get('/current/monitoring', $headers)->assertSee('Recorder failure observed');
        unlink(config('current.diagnostic_path'));
        $this->get('/current/monitoring', $headers)->assertSee('data-live-region="coverage"', false)
            ->assertDontSee('Recorder failure observed');
        $this->actingAs(new GenericUser(['id' => 2, 'is_admin' => false]));
        $this->get('/current/monitoring', $headers)->assertForbidden()->assertHeaderMissing('X-Current-Refresh');
    }
}
