<?php

namespace Current\Tests;

use Current\Current;
use Illuminate\Auth\GenericUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;
use PHPUnit\Framework\Attributes\DataProvider;

final class PageAcceptanceTest extends TestCase
{
    public static function backends(): array { return ['Horizon' => ['horizon'], 'native' => ['native']]; }

    #[DataProvider('backends')]
    public function test_job_pages_match_observed_queue_lifecycles_and_their_rendered_retry_form(string $backend): void
    {
        $this->backend($backend);
        foreach (['completed', 'failed', 'silenced'] as $scenario) {
            config()->set($backend === 'native' ? 'current.native.silenced' : 'horizon.silenced', $scenario === 'silenced' ? [PageAcceptanceJob::class] : []);
            dispatch(new PageAcceptanceJob($scenario)); $this->work();
        }
        config()->set($backend === 'native' ? 'current.native.silenced' : 'horizon.silenced', []);
        dispatch(new PageAcceptanceJob('pending'));
        dispatch((new PageAcceptanceJob('processing'))->onQueue('page-running'));
        $reserved = Queue::connection()->pop('page-running');
        event(new JobProcessing('redis', $reserved));
        $jobs = $this->store()->table('jobs')->get()->keyBy(fn ($job) => json_decode($job->arguments_json, true)['scenario']);
        $this->assertSame(1, Queue::size('lens-demo'));
        $this->assertSame(1, (int) Redis::zcard('queues:page-running:reserved'));
        foreach (['pending', 'processing', 'completed', 'failed', 'silenced'] as $section) {
            $path = $section === 'silenced' ? '/current/silenced' : '/current/jobs/'.$section;
            $page = $this->get($path)->assertOk()->assertViewHas('total', 1)->assertSee($jobs[$section]->external_id);
            $this->assertSame([$jobs[$section]->id], $page->viewData('jobs')->pluck('id')->all());
        }
        $this->get('/current/jobs/recent')->assertOk()->assertViewHas('total', 5);
        $query = ['status' => 'failed', 'connection' => 'redis', 'queue' => ['lens-demo', 'page-running'],
            'job_class' => PageAcceptanceJob::class, 'tags' => 'scenario:failed', 'job_id' => $jobs['failed']->external_id];
        $filtered = $this->get('/current/monitoring?'.http_build_query($query))->assertOk()->assertViewHas('total', 1);
        $this->assertSame([$jobs['failed']->id], $filtered->viewData('jobs')->pluck('id')->all());
        $this->get('/current/monitoring?'.http_build_query($query + ['exception_class' => 'AbsentException']))->assertViewHas('total', 0);
        $this->getJson('/current/monitoring?queue[]=lens-demo&queue[]=page-running&status=invalid')->assertUnprocessable();
        $detail = $this->get('/current/jobs/'.$jobs['failed']->id)->assertOk()
            ->assertSee('Accept payload')->assertSee('Finalize record')->assertSee('[REDACTED]')->assertDontSee('page-private-token');
        $this->assertSame('failed', $detail->viewData('job')->display_status);
        $this->assertSame(['completed', 'failed'], $detail->viewData('steps')->pluck('status')->all());
        $this->assertSame('failed', $detail->viewData('argumentsSnapshot')['value']->scenario);
        $form = $this->form($detail->getContent(), route('current.retry', ['id' => $jobs['failed']->id]));
        $this->assertSame('POST', strtoupper($form['method']));
        $this->withHeader('X-CSRF-TOKEN', 'invalid')->post($form['action'])->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', 'page-acceptance');
        $this->post($form['action'], $form['fields'])->assertRedirect();
        $this->post($form['action'], $form['fields'])->assertRedirect();
        $this->assertSame(6, $this->store()->table('jobs')->count());
        $this->assertSame(2, Queue::size('lens-demo'), 'Only the pending job and one retry are ready.');
        $this->store()->table('jobs')->where('id', $jobs['completed']->id)->update(['expires_at' => time() - 1]);
        $this->get('/current/jobs/'.$jobs['completed']->id)->assertNotFound();
        $this->get('/current/jobs/completed')->assertViewHas('total', 0);
    }

    public function test_native_dashboard_forms_submit_valid_group_and_single_targets_and_stale_state_is_honest(): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('Requires a Unix socket fixture and pcntl.'); }
        $this->backend('native');
        $directory = sys_get_temp_dir().'/current-page-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
        $owner = str_repeat('a', 32);
        config()->set('current.native.state_directory', $directory);
        config()->set('current.native.instance', 'acceptance');
        config()->set('current.native.registry.enabled', false);
        config()->set('current.native.controls', true);
        $health = ['version' => 1, 'instance' => 'acceptance', 'owner' => $owner, 'state' => 'running', 'updated_at' => gmdate('c'), 'pools' => []];
        foreach (['shared--abc' => ['shared', 'page-group'], 'single' => ['', 'page-single']] as $name => [$group, $queue]) {
            $health['pools'][] = ['name' => $name, 'connection' => 'redis', 'queues' => [$queue], 'group' => $group,
                'queue' => $group ? $queue : '', 'group_limit' => $group ? 2 : 0, 'workers' => 1, 'min_workers' => 1, 'max_workers' => 2,
                'state' => 'running', 'slots' => [['state' => 'running', 'pid' => getmypid(), 'starts' => 1]]];
        }
        file_put_contents($directory.'/acceptance.json', json_encode($health));
        $server = stream_socket_server('unix://'.$directory.'/acceptance.sock');
        // The socket fixture must not inherit live PDO/TLS sessions.
        foreach (app('db')->getConnections() as $connection) { $connection->disconnect(); }
        $pid = pcntl_fork(); $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $requests = [];
            while (count($requests) < 2 && $connection = @stream_socket_accept($server, 5)) {
                stream_set_timeout($connection, 1); $requests[] = json_decode(fgets($connection, 4097), true);
                fwrite($connection, "{\"accepted\":true}\n"); fclose($connection);
            }
            file_put_contents($directory.'/requests.json', json_encode($requests)); fclose($server); exit(count($requests) === 2 ? 0 : 1);
        }
        try {
            $page = $this->get('/current/dashboard')->assertOk()->assertSee('Supervisor · acceptance');
            $this->assertSame([1, 1], array_column($page->viewData('nativeHealth')['pools'], 'running'));
            $forms = $this->forms($page->getContent(), route('current.native.control'));
            $this->assertSame(['shared', 'single'], array_column(array_column($forms, 'fields'), 'pool'));
            foreach ($forms as $form) { $this->post($form['action'], $form['fields'] + ['action' => 'pause'])->assertRedirect(route('current.dashboard')); }
            pcntl_waitpid($pid, $status); $pid = null;
            $this->assertSame(0, pcntl_wexitstatus($status));
            $requests = json_decode(file_get_contents($directory.'/requests.json'), true);
            $this->assertSame(['shared', 'single'], array_column($requests, 'pool'));
            $this->assertSame([$owner, $owner], array_column($requests, 'owner'));
            $health['updated_at'] = gmdate('c', time() - 30);
            file_put_contents($directory.'/acceptance.json', json_encode($health));
            $stale = $this->get('/current/dashboard')->assertOk()->assertSee('Stale')->assertSee('Unknown');
            $this->assertSame('stale', $stale->viewData('nativeHealth')['state']);
            $this->assertSame([null, null], array_column($stale->viewData('nativeHealth')['pools'], 'running'));
            $this->assertSame([], $this->forms($stale->getContent(), route('current.native.control')));
            $this->assertSame([true, true], array_column($stale->viewData('nativeWorkload')['rows'], 'uncertain'));
            $this->assertSame([null, null], array_column($stale->viewData('nativeWorkload')['rows'], 'wait'));
        } finally {
            if ($pid) { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
            fclose($server);
            foreach (glob($directory.'/*') as $path) { unlink($path); } rmdir($directory);
        }
    }

    public function test_horizon_dashboard_and_metrics_read_real_isolated_repositories_and_ready_queues(): void
    {
        $master = new MasterSupervisor('testing'); $master->name = 'page-host';
        $options = new SupervisorOptions('page-host:page-pool', 'redis'); $options->queue = 'page-ready';
        $supervisor = new Supervisor($options); $master->supervisors = collect([$supervisor]);
        app(MasterSupervisorRepository::class)->update($master);
        app(SupervisorRepository::class)->update($supervisor);
        foreach (range(1, 3) as $i) { dispatch((new PageAcceptanceJob('waiting-'.$i))->onQueue('page-ready')); }
        $metrics = app(MetricsRepository::class);
        $metrics->incrementJob('PageMetricJob', 250); $metrics->incrementQueue('redis:page-ready', 250);
        $page = $this->get('/current/dashboard')->assertOk()->assertSee('Active')->assertSee('page-ready')->assertSee('page-pool');
        $data = $page->viewData('horizon');
        $this->assertSame(3, $data['counts']['data']['recent']);
        $this->assertSame(0, $data['counts']['data']['failed']);
        $this->assertSame(0, $data['supervisors']['data'][0]['processes']);
        $this->assertSame(3, $data['workload']['data'][0]['length']);
        $this->assertSame(3, Queue::size('page-ready'));
        $jobMetrics = $this->get('/current/metrics/jobs')->assertOk()->assertSee('PageMetricJob')->viewData('rows');
        $this->assertSame(['PageMetricJob', 1, '250.0 ms'], $jobMetrics[0]['cells']);
        $queueMetrics = $this->get('/current/metrics/queues')->assertOk()->viewData('rows');
        $this->assertSame(['redis:page-ready', 1, '250.0 ms'], $queueMetrics[0]['cells']);
        $master->working = false; app(MasterSupervisorRepository::class)->update($master);
        $this->get('/current/dashboard')->assertSee('Paused')->assertViewHas('horizon', fn ($data) => $data['masters']['data']['status'] === 'paused');
        app(MasterSupervisorRepository::class)->forget($master->name);
        $this->get('/current/dashboard')->assertSee('Inactive')->assertViewHas('horizon', fn ($data) => $data['supervisors']['data'] === []);
    }

    #[DataProvider('backends')]
    public function test_queue_inventory_links_keep_exact_connection_queue_scope_and_unknown_allocation(string $backend): void
    {
        $this->backend($backend);
        config()->set('current.source_roots', []);
        if ($backend === 'native') {
            config()->set('current.native.grace_seconds', 10);
            config()->set('current.native.pools', ['page-pool' => ['connection' => 'redis', 'queues' => ['page-one', 'page-two'],
                'workers' => 2, 'min_workers' => 1, 'max_workers' => 6, 'timeout' => 2, 'balance' => 'auto']]);
        } else {
            config()->set('horizon.env', 'testing'); config()->set('horizon.defaults', []);
            config()->set('horizon.environments', ['testing' => ['page-pool' => ['connection' => 'redis', 'queue' => ['page-one', 'page-two'],
                'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 6]]]);
        }
        dispatch((new PageAcceptanceJob('queue-one'))->onQueue('page-one'));
        dispatch((new PageAcceptanceJob('queue-two'))->onQueue('page-two'));
        $page = $this->get('/current/queues')->assertOk()->assertSee('page-pool')->assertSee('Min 1 / queue · Max 6 total');
        $pool = $page->viewData('supervisorTables')[0];
        $this->assertNull($pool['allocated'], 'Configuration without inventory must not invent an allocated worker count.');
        $this->assertSame(['page-one', 'page-two'], array_column($pool['queues'], 'queue'));
        foreach (['page-one', 'page-two'] as $queue) {
            $link = route('current.monitoring', ['connection' => 'redis', 'queue' => $queue]);
            $page->assertSee('href="'.htmlspecialchars($link, ENT_QUOTES, 'UTF-8').'"', false);
            $jobs = $this->get($link)->assertOk()->assertViewHas('total', 1)->viewData('jobs');
            $this->assertSame([$queue], $jobs->pluck('queue')->all());
        }
        $directory = sys_get_temp_dir().'/current-queue-page-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
        file_put_contents($directory.'/Unmapped.php', '<?php namespace PageAudit; class Unmapped implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { public $queue = "unmapped"; public function handle() {} }');
        config()->set('current.source_roots', [$directory]);
        try {
            if ($backend === 'native') {
                config()->set('current.native.state_directory', $directory); config()->set('current.native.instance', 'acceptance');
                $health = ['version' => 1, 'instance' => 'acceptance', 'owner' => str_repeat('a', 32), 'state' => 'running', 'updated_at' => gmdate('c'), 'pools' => []];
                foreach (app(\Current\Native\Plan::class)->workerPools() as $pool) {
                    $health['pools'][] = $pool + ['queue' => $pool['queues'][0], 'state' => 'running', 'slots' => [['state' => 'running', 'pid' => getmypid(), 'starts' => 1]]];
                }
                file_put_contents($directory.'/acceptance.json', json_encode($health));
            } else {
                $options = new SupervisorOptions('page-host:page-pool', 'redis'); $options->queue = 'page-one,page-two';
                app(SupervisorRepository::class)->update(new Supervisor($options));
            }
            $allocated = $backend === 'native' ? 2 : 0;
            $allocationLabel = $backend === 'native' ? 'Live workers' : 'Currently allocated';
            $partial = $this->withHeader('X-Current-Refresh', '1')->get('/current/queues')->assertOk()->assertHeader('X-Current-Refresh', '1')
                ->assertSee('data-live-region="queue-architecture"', false)->assertDontSee('<h1>Queues', false)->assertDontSee('<html', false)
                ->assertSee($allocationLabel.': '.$allocated)->assertSee('Other job routing')->assertSee('Unmapped');
            $this->assertSame($allocated, $partial->viewData('supervisorTables')[0]['allocated']);
            if ($backend === 'native') {
                $health['updated_at'] = gmdate('c', time() - 30); file_put_contents($directory.'/acceptance.json', json_encode($health));
            } else { app(SupervisorRepository::class)->forget('page-host:page-pool'); }
            $unknown = $this->get('/current/queues')->assertOk()->assertDontSee($allocationLabel.':')->assertSee('Other job routing');
            $this->assertNull($unknown->viewData('supervisorTables')[0]['allocated']);
            config()->set($backend === 'native' ? 'current.native.pools' : 'horizon.environments', []);
            $notice = $this->get('/current/queues')->assertOk()->assertSee('Other job routing')->assertSee('data-live-region="queue-architecture"', false);
            $notice->assertSee($backend === 'native' ? 'Queue configuration is unavailable.' : 'No supervisors configured for this environment');
            config()->set('current.source_roots', [$directory, $directory.'/absent']);
            $this->get('/current/queues')->assertSee('Some source files could not be inspected.')->assertSee('Other job routing');
        } finally {
            foreach (glob($directory.'/*') as $path) { unlink($path); } rmdir($directory);
        }
    }

    public function test_native_metric_categories_and_history_separate_closed_windows_from_current_completions(): void
    {
        $this->backend('native');
        dispatch(new PageAcceptanceJob('metric-one')); $this->work();
        dispatch(new PageAcceptanceJob('metric-two')); $this->work();
        $now = time(); $end = intdiv($now, 300) * 300;
        // Deterministic closed-window evidence; the other completion stays in the current open window.
        $attempt = $this->store()->table('attempts')->orderBy('id')->first();
        $this->store()->table('attempts')->where('id', $attempt->id)->update(['started_at' => $end - 2.25, 'finished_at' => $end - 2]);
        app(\Current\Native\Metrics::class)->snapshot($now);
        foreach (['jobs', 'queues'] as $kind) {
            $list = $this->get('/current/metrics/'.$kind)->assertOk();
            $this->assertSame(2, $list->viewData('rows')[0]['cells'][1]);
            $history = $this->get($list->viewData('rows')[0]['link'])->assertOk()->assertSee('Trend data');
            $series = $history->viewData('series');
            $this->assertCount(12, $series); $this->assertFalse($series[0]['available']); $this->assertNull($series[0]['completed']);
            $this->assertTrue($series[11]['available']); $this->assertSame(1, $series[11]['completed']);
            $this->assertSame(0, $series[11]['failed']); $this->assertEqualsWithDelta(250, $series[11]['duration'], .01);
            $history24 = $this->get($list->viewData('rows')[0]['link'].'&range=24h')->assertOk();
            $this->assertCount(288, $history24->viewData('series'));
        }
        $this->getJson('/current/metrics/jobs/history')->assertUnprocessable();
        Schema::connection('lens')->drop('lens_native_metrics');
        $unavailable = $this->get('/current/metrics/queues/history?connection=redis&queue=lens-demo')->assertOk()->assertSee('Metric history unavailable');
        $this->assertFalse($unavailable->viewData('available'));
    }

    #[DataProvider('backends')]
    public function test_batches_schedule_and_navigation_expose_known_data_without_running_scheduled_tasks(string $backend): void
    {
        $this->backend($backend);
        $this->batches();
        $batch = Bus::batch([])->name('Page acceptance batch')->dispatch();
        $page = $this->get('/current/batches')->assertOk()->assertSee('Page acceptance batch');
        $this->assertSame(['Page acceptance batch', 0, 0, 0], $page->viewData('rows')[0]['cells']);
        if ($backend === 'native') {
            $this->get($page->viewData('rows')[0]['link'])->assertOk()->assertSee($batch->id)->assertSee('No retained jobs with captured membership in this batch.');
        } else { $this->assertStringContainsString('/horizon/batches/'.$batch->id, $page->viewData('rows')[0]['link']); }
        $ran = false; $schedule = new Schedule('UTC');
        $schedule->call(function () use (&$ran) { $ran = true; })->name('Acceptance schedule')->everyFiveMinutes();
        app()->instance(Schedule::class, $schedule);
        $schedulePage = $this->get('/current/schedule')->assertOk()->assertSee('Acceptance schedule')->assertSee('Every 5 minutes');
        $this->assertCount(1, $schedulePage->viewData('tasks')->items()); $this->assertFalse($ran);
        foreach (['assets/lens.css' => 'text/css', 'assets/theme.js' => 'text/javascript', 'assets/filters.js' => 'text/javascript',
            'assets/steps.js' => 'text/javascript', 'assets/map.js' => 'text/javascript', 'assets/refresh.js' => 'text/javascript', 'assets/batch-retry.js' => 'text/javascript'] as $path => $mime) {
            $asset = $this->get('/current/'.$path)->assertOk();
            $this->assertStringStartsWith($mime, $asset->headers->get('Content-Type'));
            $this->assertGreaterThan(100, strlen($asset->getContent()));
        }
        $this->withHeader('X-Current-Refresh', '1')->get('/current/batches')->assertOk()->assertHeader('X-Current-Refresh', '1')->assertSee('Page acceptance batch')->assertDontSee('<html', false);
    }

    #[DataProvider('backends')]
    public function test_all_activity_pages_preserve_known_values_relationships_filters_visibility_and_expiry(string $backend): void
    {
        $this->backend($backend);
        Gate::define('viewCurrent', fn ($user) => $user->is_admin);
        config()->set('current.broadcasts.visible', true);
        config()->set('current.broadcasts.enabled', true);
        config()->set('current.webhooks.enabled', true);
        config()->set('current.webhooks.outgoing.enabled', true);
        dispatch(new PageAcceptanceJob('activity')); $this->work();
        $job = $this->store()->table('jobs')->sole(); $attempt = $this->store()->table('attempts')->sole();
        $receipt = (string) Str::ulid(); $delivery = (string) Str::ulid();
        $broadcast = (string) Str::ulid(); $second = (string) Str::ulid(); $authorization = (string) Str::ulid();
        $now = time() - 60;
        $this->store()->table('webhook_receipts')->insert(['id' => $receipt, 'provider' => 'page-provider', 'route' => 'hooks/page',
            'method' => 'POST', 'received_at' => $now, 'finished_at' => $now + 1, 'duration_ms' => 12, 'http_status' => 202,
            'signature' => 'valid', 'expires_at' => time() + 3600]);
        $this->store()->table('webhook_jobs')->insert(['receipt_id' => $receipt, 'job_id' => $job->id]);
        $this->store()->table('webhook_deliveries')->insert(['id' => $delivery, 'destination' => 'page-destination', 'endpoint' => 'https://example.test/page',
            'method' => 'POST', 'started_at' => $now, 'finished_at' => $now + 1, 'duration_ms' => 34, 'http_status' => 502,
            'job_id' => $job->id, 'attempt_id' => $attempt->id, 'expires_at' => time() + 3600]);
        foreach ([$broadcast => ['accepted', 1], $second => ['failed', 2]] as $id => [$outcome, $version]) {
            $this->store()->table('broadcasts')->insert(['id' => $id, 'connection' => 'reverb', 'event' => 'PageEvent', 'channels_json' => '["private-page.*"]',
                'channel_types_json' => '["Private"]', 'payload_json' => json_encode(['version' => $version]), 'payload_state' => 'captured',
                'outcome' => $outcome, 'started_at' => $now, 'finished_at' => $now + 1, 'duration_ms' => 56,
                'job_id' => $job->id, 'attempt_id' => $attempt->id, 'expires_at' => time() + 3600]);
        }
        $this->store()->table('broadcast_auth_failures')->insert(['id' => $authorization, 'route_name' => 'page.broadcast.auth', 'method' => 'POST',
            'http_status' => 403, 'channel_type' => 'Private', 'channels_json' => '["private-page.*"]', 'observed_at' => $now, 'expires_at' => time() + 3600]);
        $paths = [
            '/current/webhooks/dashboard' => 'page-provider', '/current/webhooks?provider=page-provider&status=202' => $receipt,
            '/current/webhooks/'.$receipt => $receipt, '/current/webhooks/outgoing?destination=page-destination&outcome=http_error' => $delivery,
            '/current/webhooks/outgoing/'.$delivery => $delivery, '/current/broadcasts/dashboard' => 'PageEvent',
            '/current/broadcasts?event=PageEvent&outcome=accepted&channel=private-page.*' => $broadcast,
            '/current/broadcasts/'.$broadcast => $broadcast, '/current/broadcasts/'.$broadcast.'/compare?with='.$second => '/payload/version',
            '/current/broadcasts/authorization?route=page.broadcast.auth&status=403&channel=private-page.*' => 'Forbidden response',
        ];
        foreach ($paths as $path => $expected) {
            $page = $this->get($path)->assertOk()->assertSee($expected);
            $this->assertStringContainsString('href="'.route('current.webhooks.dashboard').'"', $page->getContent());
            $this->withHeader('X-Current-Refresh', '1')->get($path)->assertOk()->assertHeader('X-Current-Refresh', '1')->assertSee($expected)->assertDontSee('<html', false);
            $this->withHeader('X-Current-Refresh', '0');
        }
        $streams = $this->get('/current/webhooks/dashboard')->viewData('streams');
        $this->assertSame(1, $streams['incoming']['stats']->total); $this->assertSame(0, $streams['incoming']['stats']->failures);
        $this->assertSame(1, $streams['outgoing']['stats']->total); $this->assertSame(1, $streams['outgoing']['stats']->failures);
        $streams = $this->get('/current/broadcasts/dashboard')->viewData('streams');
        $this->assertSame(2, $streams['submissions']['stats']->total); $this->assertSame(1, $streams['submissions']['stats']->failures);
        $this->assertSame(1, $streams['authorization']['stats']->total);
        $this->assertSame([$job->id], $this->get('/current/webhooks/'.$receipt)->viewData('jobs')->pluck('id')->all());
        $this->assertSame($job->id, $this->get('/current/webhooks/outgoing/'.$delivery)->viewData('job')->id);
        $this->assertSame($job->id, $this->get('/current/broadcasts/'.$broadcast)->viewData('job')->id);
        $this->get('/current/jobs/'.$job->id)->assertSee($receipt)->assertSee($delivery)->assertSee('PageEvent');
        foreach (['webhooks/dashboard', 'webhooks', 'webhooks/'.$receipt, 'webhooks/outgoing', 'webhooks/outgoing/'.$delivery,
            'broadcasts/dashboard', 'broadcasts', 'broadcasts/'.$broadcast, 'broadcasts/'.$broadcast.'/compare', 'broadcasts/authorization'] as $path) {
            $this->actingAs(new GenericUser(['id' => 2, 'is_admin' => false])); $this->get('/current/'.$path)->assertForbidden();
        }
        $this->actingAs(new GenericUser(['id' => 1, 'is_admin' => true]));
        config()->set('current.broadcasts.enabled', false); config()->set('current.webhooks.enabled', false); config()->set('current.webhooks.outgoing.enabled', false);
        $this->get('/current/webhooks/dashboard')->assertSee('capture: disabled')->assertSee('page-provider');
        $this->get('/current/broadcasts/dashboard')->assertSee('capture: disabled')->assertSee('PageEvent');
        $this->store()->table('jobs')->where('id', $job->id)->update(['expires_at' => time() - 1]);
        $this->assertCount(0, $this->get('/current/webhooks/'.$receipt)->viewData('jobs'));
        $this->assertNull($this->get('/current/webhooks/outgoing/'.$delivery)->viewData('job'));
        $this->assertNull($this->get('/current/broadcasts/'.$broadcast)->viewData('job'));
        foreach (['webhook_receipts', 'webhook_deliveries', 'broadcasts', 'broadcast_auth_failures'] as $table) { $this->store()->table($table)->update(['expires_at' => time() - 1]); }
        $this->get('/current/webhooks/'.$receipt)->assertNotFound(); $this->get('/current/webhooks/outgoing/'.$delivery)->assertNotFound();
        $this->get('/current/broadcasts/'.$broadcast)->assertNotFound(); $this->get('/current/broadcasts/'.$broadcast.'/compare?with='.$second)->assertNotFound();
        $this->assertSame(0, $this->get('/current/webhooks/dashboard')->viewData('streams')['incoming']['stats']->total);
        $this->assertSame(0, $this->get('/current/broadcasts/dashboard')->viewData('streams')['authorization']['stats']->total);
        config()->set('current.broadcasts.visible', false);
        foreach (['broadcasts', 'broadcasts/dashboard', 'broadcasts/authorization', 'broadcasts/'.$broadcast, 'broadcasts/'.$broadcast.'/compare'] as $path) { $this->get('/current/'.$path)->assertNotFound(); }
        Schema::connection('lens')->drop('lens_webhook_receipts');
        $unavailable = $this->get('/current/webhooks/dashboard')->assertOk()->assertSee('History unavailable');
        $this->assertFalse($unavailable->viewData('streams')['incoming']['available']); $this->assertTrue($unavailable->viewData('streams')['outgoing']['available']);
    }

    private function backend(string $backend): void
    {
        config()->set('current.execution_backend', $backend);
        if ($backend === 'native') { config()->set('queue.connections.redis.driver', 'current-redis'); }
        Gate::define('viewCurrent', fn ($user) => true); Gate::define('operateCurrent', fn ($user) => true);
        $this->withSession(['_token' => 'page-acceptance'])->withHeader('X-CSRF-TOKEN', 'page-acceptance');
    }

    private function batches(): void
    {
        config()->set('queue.batching', ['database' => 'app', 'table' => 'job_batches']);
        Schema::connection('app')->create('job_batches', function ($table) {
            $table->string('id')->primary(); $table->string('name'); $table->integer('total_jobs'); $table->integer('pending_jobs');
            $table->integer('failed_jobs'); $table->longText('failed_job_ids'); $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable(); $table->integer('created_at'); $table->integer('finished_at')->nullable();
        });
    }

    private function form(string $html, string $action): array
    {
        $forms = $this->forms($html, $action); $this->assertCount(1, $forms); return $forms[0];
    }

    private function forms(string $html, string $action): array
    {
        $document = new \DOMDocument; @$document->loadHTML($html); $forms = [];
        foreach ((new \DOMXPath($document))->query('//form') as $form) {
            if ($form->getAttribute('action') !== $action) { continue; }
            $fields = [];
            foreach ($form->getElementsByTagName('input') as $input) { $fields[$input->getAttribute('name')] = $input->getAttribute('value'); }
            $forms[] = ['action' => $action, 'method' => $form->getAttribute('method'), 'fields' => $fields];
        }
        return $forms;
    }
}

final class PageAcceptanceJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;
    public int $tries = 1;
    public function __construct(public string $scenario) { $this->onQueue('lens-demo'); }
    public function tags(): array { return ['scenario:'.$this->scenario]; }
    public function currentArguments(): array { return ['scenario' => $this->scenario, 'api_token' => 'page-private-token']; }
    public function currentMetadata(): array { return ['fixture' => 'page-acceptance']; }
    public function handle(): void
    {
        Current::step('Accept payload', fn () => true);
        Current::step('Finalize record', function () {
            if ($this->scenario === 'failed' && Redis::setnx('page-failed-once', 1)) { throw new \RuntimeException('Page fixture failed'); }
        });
    }
}
