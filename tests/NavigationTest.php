<?php

namespace HorizonLens\Tests;

use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\TagRepository;
use Mockery;

final class NavigationTest extends TestCase
{
    public function test_product_tabs_and_horizon_sections_render_without_collecting_other_operations(): void
    {
        $this->get('/horizon-lens')->assertOk()->assertSee('Product areas')->assertSee('Pending')->assertSee('Silenced')->assertDontSee('Job explorer');
        $this->get('/horizon-lens/dashboard')->assertOk()->assertSee('Current workload')->assertSee('Jobs Per Minute')->assertSee('Recorded queues');
        $this->get('/horizon-lens/webhooks')->assertOk()->assertSee('No webhooks telemetry is being collected.');
        $this->get('/horizon-lens/broadcasts')->assertOk()->assertSee('No broadcasts telemetry is being collected.');
        $this->assertSame(0, $this->store()->table('jobs')->count());
    }

    public function test_job_state_views_enforce_scope_and_keep_monitoring_unrestricted(): void
    {
        foreach (['failed', 'completed', 'pending', 'processing'] as $status) {
            $id = $this->store()->newJob('scope-'.$status, 'redis', 'test', ['data' => ['commandName' => 'ScopeJob']]);
            $this->store()->table('jobs')->where('id', $id)->update(['status' => $status]);
        }
        foreach (['failed', 'completed', 'pending', 'processing'] as $status) {
            $response = $this->get('/horizon-lens/jobs/'.$status)->assertOk()->assertSee('scope-'.$status);
            $this->assertSame($status, $response->viewData('scopeStatus'));
            $this->assertSame(1, $response->viewData('total'));
            $filtered = $this->get('/horizon-lens/jobs/'.$status.'?status=completed&queue=test')->assertOk();
            $this->assertSame($status, $filtered->viewData('filters')['status']);
            $this->assertSame(1, $filtered->viewData('total'));
        }
        $this->get('/horizon-lens/jobs/recent')->assertOk()->assertSee('scope-failed')->assertSee('scope-completed');
        $this->assertSame(4, $this->get('/horizon-lens/monitoring')->viewData('total'));
    }

    public function test_scoped_lists_have_compact_filters_without_summary_cards_or_deep_filter_link(): void
    {
        foreach (['jobs/recent', 'jobs/pending', 'jobs/processing', 'jobs/completed', 'silenced', 'jobs/failed'] as $path) {
            $response = $this->get('/horizon-lens/'.$path)->assertOk()
                ->assertDontSee('Deep filters')->assertDontSee('stat-card')->assertSee('name="job_id"', false)
                ->assertSee('Last 5 minutes')->assertSee('Last 7 days')->assertSee('data-time-range', false);
            $this->assertStringNotContainsString('name="status"', $response->getContent());
        }
        $this->get('/horizon-lens/jobs/failed')->assertSee('name="exception_class"', false);
        $this->get('/horizon-lens/jobs/completed')->assertDontSee('name="exception_class"', false)->assertSee('More filters');
        $this->get('/horizon-lens/jobs/failed?tags=order%3Aone')->assertSee('class="additional-filters"  open', false);
    }

    public function test_source_failure_is_unavailable_not_an_empty_success(): void
    {
        $repository = Mockery::mock(MetricsRepository::class);
        $repository->shouldReceive('measuredJobs')->once()->andThrow(new \RuntimeException('private-connection-detail'));
        $this->app->instance(MetricsRepository::class, $repository);
        $response = $this->get('/horizon-lens/metrics')->assertOk()->assertSee('Horizon data is unavailable')->assertDontSee('private-connection-detail');
        $this->assertFalse($response->viewData('available'));
        $this->get('/horizon-lens/batches')->assertOk()->assertSee('Batch storage is unavailable');
    }

    public function test_metric_categories_read_separately_and_do_not_share_source_failures(): void
    {
        $repository = Mockery::mock(MetricsRepository::class);
        $repository->shouldReceive('measuredJobs')->once()->andReturn(['OnlyJob']);
        $repository->shouldReceive('throughputForJob')->with('OnlyJob')->once()->andReturn(4);
        $repository->shouldReceive('runtimeForJob')->with('OnlyJob')->once()->andReturn(12.5);
        $repository->shouldReceive('measuredQueues')->twice()->andReturnUsing((function () {
            $calls = 0;

            return function () use (&$calls) {
                if (++$calls > 1) {
                    throw new \RuntimeException('private-queue-error');
                }

                return ['only-queue'];
            };
        })());
        $repository->shouldReceive('throughputForQueue')->with('only-queue')->once()->andReturn(8);
        $repository->shouldReceive('runtimeForQueue')->with('only-queue')->once()->andReturn(20.5);
        $this->app->instance(MetricsRepository::class, $repository);
        $jobs = $this->get('/horizon-lens/metrics/jobs')->assertOk()->assertSee('Job metrics')->assertSee('OnlyJob')->assertDontSee('only-queue')->assertSee('12.5 ms');
        $this->assertSame('jobs', $jobs->viewData('metricKind'));
        $queues = $this->get('/horizon-lens/metrics/queues')->assertOk()->assertSee('Queue metrics')->assertSee('only-queue')->assertDontSee('OnlyJob')->assertSee('20.5 ms');
        $this->assertSame('queues', $queues->viewData('metricKind'));
        $this->get('/horizon-lens/metrics/queues')->assertOk()->assertSee('Horizon data is unavailable')->assertDontSee('private-queue-error');
        $this->get('/horizon-lens/metrics/unknown')->assertNotFound();
    }

    public function test_all_new_pages_require_authorization(): void
    {
        $this->app['auth']->forgetGuards();
        foreach (['dashboard', 'jobs/recent', 'jobs/pending', 'jobs/failed', 'monitoring', 'metrics', 'metrics/jobs', 'metrics/queues', 'batches', 'silenced', 'webhooks', 'broadcasts', 'assets/steps.js', 'assets/filters.js', 'assets/refresh.js', 'assets/map.js'] as $path) {
            $this->get('/horizon-lens/'.$path)->assertForbidden();
        }
    }

    public function test_monitoring_has_expandable_filters_and_legacy_search_links_redirect(): void
    {
        $this->store()->newJob('dedicated-search-job', 'redis', 'needle', ['data' => ['commandName' => 'SearchJob'], 'tags' => ['order:needle']]);
        $this->get('/horizon-lens')->assertOk()->assertDontSee('name="job_id"', false);
        $this->get('/horizon-lens/jobs/pending')->assertOk()->assertSee('name="job_id"', false)->assertDontSee('Deep filters');
        $this->get('/horizon-lens?queue=needle&status=pending')
            ->assertRedirect(route('horizon-lens.monitoring', ['status' => 'pending', 'queue' => 'needle']));
        $response = $this->get('/horizon-lens/monitoring?queue=needle&status=pending')->assertOk()
            ->assertSee('More filters')->assertDontSee('Deep filters')->assertDontSee('Monitored tags')->assertDontSee('Manage in Horizon')->assertSee('dedicated-search-job')->assertSee('name="job_id"', false);
        $this->assertSame(1, $response->viewData('total'));
    }

    public function test_monitoring_filters_retained_tags_without_reading_horizon_monitoring(): void
    {
        $this->store()->newJob('retained-history-job', 'redis', 'payments', ['data' => ['commandName' => 'SearchJob'], 'tags' => ['order:retained']]);
        $repository = Mockery::mock(TagRepository::class);
        $repository->shouldNotReceive('monitoring');
        $this->app->instance(TagRepository::class, $repository);
        $response = $this->get('/horizon-lens/monitoring?tags=order%3Aretained')->assertOk()
            ->assertSee('retained-history-job')->assertDontSee('Monitored tags');
        $this->assertSame(1, $response->viewData('total'));
    }
}
