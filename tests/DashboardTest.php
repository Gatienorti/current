<?php

namespace HorizonLens\Tests;

use HorizonLens\HorizonAdapter;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Mockery;

final class DashboardTest extends TestCase
{
    public function test_dashboard_reads_horizon_counters_workload_and_workers_separately_from_lens_history(): void
    {
        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('jobsProcessedPerMinute')->once()->andReturn(3);
        $metrics->shouldReceive('queueWithMaximumRuntime')->once()->andReturn('slow-queue');
        $metrics->shouldReceive('queueWithMaximumThroughput')->once()->andReturn('busy-queue');
        $this->app->instance(MetricsRepository::class, $metrics);
        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('countRecent')->once()->andReturn(149);
        $jobs->shouldReceive('countRecentlyFailed')->once()->andReturn(36);
        $this->app->instance(JobRepository::class, $jobs);
        $masters = Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->once()->andReturn([(object) ['status' => 'running'], (object) ['status' => 'paused']]);
        $this->app->instance(MasterSupervisorRepository::class, $masters);
        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->once()->andReturn([(object) [
            'name' => 'master:payments', 'master' => 'master', 'status' => 'running',
            'processes' => ['redis:payments' => 2, 'redis:notifications' => 3],
            'options' => ['connection' => 'redis', 'queue' => 'payments,notifications', 'balance' => 'auto', 'private' => 'private-supervisor-value'],
        ]]);
        $this->app->instance(SupervisorRepository::class, $supervisors);
        $wait = Mockery::mock(WaitTimeCalculator::class);
        $wait->shouldReceive('calculate')->once()->andReturn(['redis:payments' => 12, 'redis:notifications' => 3]);
        $this->app->instance(WaitTimeCalculator::class, $wait);
        $workload = Mockery::mock(WorkloadRepository::class);
        $workload->shouldReceive('get')->once()->andReturn([
            ['name' => 'payments', 'length' => 8, 'processes' => 2, 'wait' => 12, 'split_queues' => null, 'payload' => 'private-workload-value'],
            ['name' => 'notifications', 'length' => 4, 'processes' => 3, 'wait' => 3, 'split_queues' => null],
            ['name' => 'second', 'length' => 5, 'processes' => 2, 'wait' => 8, 'split_queues' => collect([
                'first' => ['name' => 'first', 'length' => 2, 'wait' => 3], 'second' => ['name' => 'second', 'length' => 3, 'wait' => 8],
            ])],
        ]);
        $this->app->instance(WorkloadRepository::class, $workload);
        config()->set('horizon.trim.recent', 60);
        config()->set('horizon.trim.recent_failed', 10080);
        $this->store()->newJob('history-only', 'redis', 'history-queue', ['data' => ['commandName' => 'HistoryJob']]);
        $response = $this->get('/horizon-lens/dashboard')->assertOk()
            ->assertSee('Jobs Past Hour')->assertSee('Failed Jobs Past 7 Days')->assertSee('Active')
            ->assertSee('149')->assertSee('36')->assertSee('slow-queue')->assertSee('busy-queue')
            ->assertSee('title="master:payments">payments</strong>', false)
            ->assertDontSee('Shared above')->assertSee('queue%5B0%5D=first', false)->assertSee('queue%5B1%5D=second', false)->assertDontSee('HORIZON DATA')->assertDontSee('CURRENT HISTORY')->assertDontSee('Coverage matters.')
            ->assertDontSee('Horizon operations and captured job history in one place.')
            ->assertDontSee('refresh for the latest state')->assertSee('data-live-region="dashboard"', false)
            ->assertDontSee('private-supervisor-value')->assertDontSee('private-workload-value')->assertDontSee('Job explorer');
        $data = $response->viewData('horizon');
        $this->assertSame(3, $data['metrics']['data']['rate']);
        $this->assertSame(1, $data['masters']['data']['paused']);
        $this->assertSame(5, $data['supervisors']['data'][0]['processes']);
        $this->assertSame(1, $response->viewData('total'));
        $this->assertSame(['first, second', 'notifications', 'payments'], array_column($data['workload']['data'], 'name'));
    }

    public function test_monitoring_combines_multiple_queues_with_other_filters(): void
    {
        foreach (['first', 'second', 'other'] as $queue) {
            $this->store()->newJob('multi-'.$queue, 'redis', $queue, ['data' => ['commandName' => 'GroupJob']]);
        }
        $response = $this->get('/horizon-lens/monitoring?'.http_build_query(['queue' => ['first', 'second'], 'job_class' => 'GroupJob']))->assertOk();
        $this->assertSame(2, $response->viewData('total'));
        $this->assertSame(['first', 'second'], $response->viewData('filters')['queue']);
        $response->assertSee('name="queue[]" multiple', false)
            ->assertSee('aria-label="Remove queue: first"', false)
            ->assertSee('aria-label="Remove queue: second"', false);
        foreach (['first' => 'second', 'second' => 'first'] as $removed => $remaining) {
            preg_match('/aria-label="Remove queue: '.$removed.'" href="([^"]+)"/', $response->getContent(), $match);
            parse_str(parse_url(html_entity_decode($match[1]), PHP_URL_QUERY), $parameters);
            $this->assertSame([$remaining], $parameters['queue']);
            $this->assertSame('GroupJob', $parameters['job_class']);
        }
        $this->get('/horizon-lens/monitoring?'.http_build_query(['queue' => ['first', 'second'], 'job_class' => 'AbsentJob']))->assertOk()->assertViewHas('total', 0);
        $this->get('/horizon-lens/monitoring?queue=first')->assertOk()->assertViewHas('total', 1);
        $this->get('/horizon-lens/monitoring?queue%5B0%5D=')->assertOk()->assertViewHas('total', 3);
    }

    public function test_empty_master_and_supervisor_reads_mean_inactive_with_zero_processes(): void
    {
        foreach ([MasterSupervisorRepository::class, SupervisorRepository::class] as $contract) {
            $repository = Mockery::mock($contract);
            $repository->shouldReceive('all')->andReturn([]);
            $this->app->instance($contract, $repository);
        }
        $response = $this->get('/horizon-lens')->assertOk()->assertSee('Inactive')->assertSee('No supervisors reporting')->assertSee('No queues reported by Horizon supervisors');
        $this->assertSame('inactive', $response->viewData('horizon')['masters']['data']['status']);
        $this->assertSame([], $response->viewData('horizon')['supervisors']['data']);
        $this->assertSame('dashboard', $response->viewData('section'));
    }

    public function test_source_failures_show_unknown_values_without_erasing_lens_history(): void
    {
        foreach ([MasterSupervisorRepository::class => 'all', SupervisorRepository::class => 'all', WorkloadRepository::class => 'get', MetricsRepository::class => 'jobsProcessedPerMinute'] as $contract => $method) {
            $repository = Mockery::mock($contract);
            $repository->shouldReceive($method)->andThrow(new \RuntimeException('private-connection-detail'));
            $this->app->instance($contract, $repository);
        }
        $this->store()->newJob('history-survives-outage', 'redis', 'retained', ['data' => ['commandName' => 'RetainedHistoryJob']]);
        $response = $this->get('/horizon-lens/dashboard')->assertOk()->assertSee('Unavailable')
            ->assertSee('Queue workload is unavailable')->assertSee('RetainedHistoryJob')->assertDontSee('private-connection-detail');
        $this->assertFalse($response->viewData('horizon')['masters']['available']);
        $this->assertFalse($response->viewData('horizon')['supervisors']['available']);
        $this->assertNull($response->viewData('horizon')['metrics']['data']);
    }

    public function test_horizon_status_and_period_labels_follow_the_installed_dashboard_semantics(): void
    {
        $masters = Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->andReturn(
            [(object) ['status' => 'paused'], (object) ['status' => 'paused']],
            [(object) ['status' => 'paused'], (object) ['status' => 'running']],
        );
        $this->app->instance(MasterSupervisorRepository::class, $masters);
        config()->set('horizon.trim.recent', 15);
        config()->set('horizon.trim.recent_failed', 1440);
        $first = app(HorizonAdapter::class)->dashboard();
        $second = app(HorizonAdapter::class)->dashboard();
        $this->assertSame('paused', $first['masters']['data']['status']);
        $this->assertSame('running', $second['masters']['data']['status']);
        $this->assertSame('Jobs Past 15 Minutes', $first['recent_label']);
        $this->assertSame('Failed Jobs Past Day', $first['failed_label']);
    }
}
