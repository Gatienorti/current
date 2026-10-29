<?php

namespace Current\Tests;

use Current\Current;
use Current\Monitoring\Tags;
use Current\Native\MonitoredTags;
use Current\Store;
use Current\Telemetry\Transport;
use Current\Telemetry\Health;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\TagRepository;
use Symfony\Component\Process\Process;

final class MonitoredTagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('current.retention_days', 1);
        config()->set('current.native.monitoring.retention_days', 7);
        config()->set('current.tag_prefixes', ['order:']);
        Gate::define('viewCurrent', fn ($user) => $user->is_admin);
    }

    public function test_management_is_explicit_operator_authorized_csrf_protected_and_approved_before_persistence(): void
    {
        $this->get('/current/monitoring')->assertOk()->assertSee('Manage monitoring')->assertDontSee('Monitored tags');
        $url = route('current.monitoring.tags.store');
        $this->post($url, ['tag' => 'order:tracked'])->assertStatus(419);
        $this->withSession(['_token' => 'monitor-tags'])->withHeader('X-CSRF-TOKEN', 'monitor-tags');
        $this->post($url, ['tag' => 'order:tracked'])->assertForbidden();
        Gate::define('operateCurrent', fn ($user) => $user->is_admin);
        foreach (['private:customer', 'order:api_key=private-value', 'order:contains whitespace'] as $tag) {
            $this->postJson($url, ['tag' => $tag])->assertUnprocessable();
        }
        $this->assertSame([], app(MonitoredTags::class)->all());
        $this->post($url, ['tag' => 'order:tracked'])->assertRedirect(route('current.monitoring', ['manage_tags' => 1]));
        $policy = app(MonitoredTags::class)->all()['order:tracked'];
        $this->post($url, ['tag' => 'order:tracked'])->assertRedirect();
        $this->assertSame($policy, app(MonitoredTags::class)->all()['order:tracked'], 'Duplicate adds must not reset coverage.');
        $this->get('/current/monitoring?manage_tags=1')->assertOk()->assertSee('order:tracked')->assertSee('Stop monitoring');
        $this->withHeader('X-Current-Refresh', '1')->get('/current/monitoring?manage_tags=1')->assertOk()
            ->assertSee('data-live-region="monitored-tags"', false)->assertDontSee('placeholder="order:example"', false)->assertDontSee('<html', false);
        $this->withHeader('X-Current-Refresh', '0');
        $this->post(route('current.monitoring.tags.destroy'), ['tag' => 'order:tracked'])->assertRedirect();
        $this->assertSame([], app(MonitoredTags::class)->all());
    }

    public static function redisClients(): array { return [['phpredis'], ['predis']]; }

    private function selectClient(string $client): void
    {
        if ($client === 'predis' && ! class_exists(\Predis\Client::class)) { $this->markTestSkipped('Optional Predis is not installed.'); }
        config()->set('database.redis.client', $client);
        app()->instance('redis', new RedisManager(app(), $client, config('database.redis')));
        Redis::clearResolvedInstance('redis');
    }

    #[DataProvider('redisClients')]
    public function test_policy_revision_invalidates_an_empty_or_stale_worker_cache_immediately_and_limits_are_configurable(string $client): void
    {
        $this->selectClient($client);
        $worker = new MonitoredTags; $operator = new MonitoredTags;
        $this->assertSame([], $worker->all());
        $operator->add('order:tracked');
        $this->assertNotNull($worker->expiresAt(['tags' => ['order:tracked']]));
        $operator->remove('order:tracked');
        $this->assertNull($worker->expiresAt(['tags' => ['order:tracked']]));
        config()->set('current.native.monitoring.max_tags', 129);
        for ($i = 0; $i < 26; $i++) { $operator->add(sprintf('order:%03d', $i)); }
        $this->assertSame(129, $operator->settings()['max_tags']);
        $summary = app(Tags::class)->summary();
        $this->assertSame(26, $summary['total']); $this->assertCount(25, $summary['rows']); $this->assertTrue($summary['next']);
        $this->assertSame('order:025', app(Tags::class)->summary(2)['rows'][0]['tag']);
        config()->set('current.native.monitoring.max_tags', 26);
        try { $operator->add('order:overflow'); $this->fail('The configured bound must be enforced atomically.'); }
        catch (\RuntimeException $exception) { $this->assertStringContainsString('limit', $exception->getMessage()); }
        $this->assertCount(26, $operator->all());
        config()->set('current.tag_prefixes', ['public:']);
        $this->assertSame([], $worker->all(), 'A cached policy must not expose tags after capture approval is removed.');
        config()->set('current.tag_prefixes', ['order:']);
        $this->assertCount(26, $worker->all());
    }

    public function test_monitored_jobs_keep_full_detail_beyond_ordinary_retention_and_stop_does_not_shorten_history(): void
    {
        app(MonitoredTags::class)->add('order:tracked');
        dispatch(new MonitoredFixtureJob('order:tracked')); $this->work();
        $job = $this->store()->table('jobs')->sole();
        $this->assertGreaterThan($job->first_observed_at + 6 * 86400, $job->expires_at);
        $page = $this->get(route('current.monitoring.tags.history', ['tag' => 'order:tracked']))->assertOk()->assertSee($job->external_id);
        $this->assertSame([$job->id], $page->viewData('jobs')->pluck('id')->all());
        $this->get('/current/jobs/'.$job->id)->assertOk()->assertSee('Retained operation')->assertSee('[REDACTED]')->assertDontSee('fixture-secret');
        $expiry = $job->expires_at;
        app(MonitoredTags::class)->remove('order:tracked');
        $this->assertSame($expiry, $this->store()->table('jobs')->sole()->expires_at);
        $this->get(route('current.monitoring.tags.history', ['tag' => 'order:tracked']))->assertNotFound();
        $this->get('/current/jobs/'.$job->id)->assertOk();
        dispatch(new MonitoredFixtureJob('order:tracked'));
        $later = $this->store()->table('jobs')->where('id', '!=', $job->id)->sole();
        $this->assertEqualsWithDelta(86400, $later->expires_at - $later->first_observed_at, .01);
    }

    public function test_a_job_already_in_flight_extends_retention_when_monitoring_is_enabled_before_completion(): void
    {
        dispatch(new MonitoredFixtureJob('order:late'));
        $original = $this->store()->table('jobs')->sole();
        $this->assertEqualsWithDelta(86400, $original->expires_at - $original->first_observed_at, .01);
        $job = Queue::connection()->pop('lens-demo'); event(new JobProcessing('redis', $job));
        app(MonitoredTags::class)->add('order:late');
        $job->fire(); event(new JobAttempted('redis', $job));
        $retained = $this->store()->table('jobs')->sole();
        $this->assertSame('completed', $retained->status);
        $this->assertGreaterThan($original->expires_at + 5 * 86400, $retained->expires_at);
        $this->assertSame(1, app(Tags::class)->history('order:late', false)['total']);
        $this->get('/current/jobs/'.$retained->id)->assertSee('Retained operation');
        $this->assertSame(1, $this->store()->table('steps')->count());
    }

    public function test_unavailable_policy_is_not_an_empty_success_and_cannot_break_the_business_job(): void
    {
        config()->set('database.redis.monitor_unavailable', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);
        config()->set('current.native.monitoring.redis_connection', 'monitor_unavailable');
        dispatch(new MonitoredFixtureJob('order:tracked')); $this->work();
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
        $this->get('/current/monitoring?manage_tags=1')->assertOk()->assertSee('Monitored-tag policy or history is unavailable');
        $this->get(route('current.monitoring.tags.history', ['tag' => 'order:tracked']))->assertOk()->assertSee('Monitored history unavailable');
    }

    public function test_late_approval_does_not_backfill_tags_redacted_from_the_queue_payload(): void
    {
        config()->set('current.tag_prefixes', ['public:']);
        dispatch(new MonitoredFixtureJob('order:private'));
        $original = $this->store()->table('jobs')->sole();
        $job = Queue::connection()->pop('lens-demo');
        $this->assertSame([], $job->payload()['tags']);
        event(new JobProcessing('redis', $job));
        config()->set('current.tag_prefixes', ['public:', 'order:']);
        app(MonitoredTags::class)->add('order:private');
        $job->fire(); event(new JobAttempted('redis', $job));
        $this->assertSame($original->expires_at, $this->store()->table('jobs')->sole()->expires_at);
        $this->assertSame(0, app(Tags::class)->history('order:private', false)['total']);
    }

    public function test_async_policy_requires_fresh_owned_capability_and_ordinary_events_still_work_without_it(): void
    {
        app(MonitoredTags::class)->add('order:tracked');
        config()->set('current.telemetry.driver', 'redis');
        $redis = app(Transport::class)->client();
        $redis->set('owner', 'monitor-owner');
        $health = ['heartbeat_at' => microtime(true), 'protocol' => 2, 'maintenance_protocol' => 1, 'owner_token' => 'monitor-owner'];
        $redis->hMSet('health', $health);
        $this->assertFalse(app(Health::class)->supportsMonitoredRetention());
        $this->assertTrue(app(MonitoredTags::class)->hasPolicies());
        $this->assertNull(app(MonitoredTags::class)->expiresAt(['tags' => ['order:tracked']]));
        $this->get('/current/monitoring?manage_tags=1')->assertOk()->assertSee('Monitored-tag policy or history is unavailable');
        try { app(MonitoredTags::class)->add('order:other'); $this->fail('Old collector must not accept monitored retention.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('matching collector', $e->getMessage()); }
        dispatch(new MonitoredFixtureJob('order:tracked')); $this->work();
        $events = $redis->xRange('events:v2', '-', '+');
        $this->assertNotEmpty($events);
        foreach ($events as $event) { $this->assertArrayNotHasKey('monitored_expires_at', json_decode($event['data'], true)); }
        $redis->hMSet('health', $health + ['monitored_retention' => 1]);
        $this->assertNotNull(app(MonitoredTags::class)->expiresAt(['tags' => ['order:tracked']]));
        foreach ([['owner_token' => 'previous-owner'], ['heartbeat_at' => microtime(true) - 16], ['heartbeat_at' => microtime(true) + 60]] as $invalid) {
            $redis->hMSet('health', array_replace($health + ['monitored_retention' => 1], $invalid));
            $this->assertNull(app(MonitoredTags::class)->expiresAt(['tags' => ['order:tracked']]));
        }
        app(MonitoredTags::class)->remove('order:tracked');
        $this->assertSame([], app(MonitoredTags::class)->all());
        $redis->del('owner', 'health');
    }

    public function test_horizon_uses_its_real_monitored_repository_and_retained_details_after_current_history_expires(): void
    {
        config()->set('current.execution_backend', 'horizon'); config()->set('queue.connections.redis.driver', 'redis');
        app(Tags::class)->add('order:horizon');
        dispatch(new MonitoredFixtureJob('order:horizon')); $this->work();
        $job = $this->store()->table('jobs')->sole();
        $this->assertContains('order:horizon', app(TagRepository::class)->monitoring());
        $this->assertSame(1, app(TagRepository::class)->count('order:horizon'));
        $this->store()->table('jobs')->where('id', $job->id)->update(['expires_at' => time() - 1]);
        $this->store()->prune();
        $this->get('/current/jobs/'.$job->id)->assertNotFound();
        $history = $this->get(route('current.monitoring.tags.history', ['tag' => 'order:horizon']))->assertOk()->assertSee($job->external_id)->assertSee('Inspect retained detail in Horizon');
        $this->assertSame($job->external_id, $history->viewData('rows')[0]['uuid']);
        $this->assertNotNull(app(JobRepository::class)->getJobs([$job->external_id])->first());
        app(Tags::class)->remove('order:horizon');
        $this->assertNotContains('order:horizon', app(TagRepository::class)->monitoring());
    }

    public function test_collect_command_refuses_an_older_binary_only_when_native_monitoring_needs_its_capability(): void
    {
        app(MonitoredTags::class)->add('order:tracked');
        $binary = tempnam(sys_get_temp_dir(), 'current-old-collector-');
        file_put_contents($binary, '#!'.PHP_BINARY."\n<?php\necho json_encode(['identity_protocol'=>2,'maintenance_protocol'=>1,'postgres'=>1]);\n");
        chmod($binary, 0700);
        config()->set('current.telemetry.driver', 'redis'); config()->set('current.telemetry.binary', $binary);
        try {
            $this->assertSame(1, Artisan::call('current:collect', ['--once' => true]));
            $this->assertStringContainsString('monitored-tag retention', Artisan::output());
            $this->assertFalse(app(Transport::class)->client()->get('owner'));
            app(MonitoredTags::class)->remove('order:tracked');
            $this->assertSame(0, Artisan::call('current:collect', ['--once' => true]));
        } finally { @unlink($binary); }
    }

    public function test_failed_history_is_a_retained_subset_and_expired_detail_is_not_presented_as_available(): void
    {
        app(MonitoredTags::class)->add('order:outcomes');
        dispatch(new MonitoredFixtureJob('order:outcomes'));
        dispatch(new MonitoredFixtureJob('order:outcomes', true)); $this->work(2);
        $this->assertSame(2, app(Tags::class)->history('order:outcomes', false)['total']);
        $this->assertSame(1, app(Tags::class)->history('order:outcomes', true)['total']);
        $failed = $this->store()->table('jobs')->where('status', 'failed')->sole();
        $this->get(route('current.monitoring.tags.history', ['tag' => 'order:outcomes', 'kind' => 'failed']))
            ->assertOk()->assertSee($failed->external_id);
        $this->store()->table('jobs')->where('id', $failed->id)->update(['expires_at' => microtime(true) - 1]);
        $this->assertSame(0, app(Tags::class)->history('order:outcomes', true)['total']);
        $this->get('/current/jobs/'.$failed->id)->assertNotFound();
    }

    #[DataProvider('redisClients')]
    public function test_async_in_flight_monitoring_keeps_worker_sql_free_and_collector_extends_detail_and_aliases(string $client): void
    {
        $this->selectClient($client);
        $binary = getenv('CURRENT_MONITORING_COLLECTOR_BINARY');
        if (! $binary || ! is_executable($binary)) { $this->markTestSkipped('Requires the matching monitored-retention collector binary.'); }
        if (! $this->store()->connection()->getSchemaBuilder()->hasTable('lens_async_receipts')) {
            (require dirname(__DIR__).'/database/migrations/2026_10_27_000012_add_async_telemetry_receipts.php')->up();
        }
        config()->set('current.telemetry.driver', 'redis');
        $source = config('database.connections.lens.database');
        $local = null;
        if ($this->store()->connection()->getDriverName() === 'sqlite') {
            $local = sys_get_temp_dir().'/current-monitoring-'.bin2hex(random_bytes(6)).'.sqlite';
            $this->store()->connection()->statement('PRAGMA wal_checkpoint(TRUNCATE)'); app('db')->purge('lens'); copy($source, $local);
            config()->set('database.connections.lens.database', $local);
        }
        $transport = app(Transport::class);
        $collector = null;
        try {
            dispatch(new MonitoredFixtureJob('order:async'));
            $this->drain($binary); $original = $this->store()->table('jobs')->sole();
            $collector = new Process([$binary, '-'], dirname(__DIR__), timeout: 20);
            $collector->setInput(json_encode($transport->settings())); $collector->start();
            $this->waitFor(fn () => app(Health::class)->supportsMonitoredRetention(), $collector);
            $database = $this->store()->connection(); $database->enableQueryLog();
            $reserved = Queue::connection()->pop('lens-demo'); event(new JobProcessing('redis', $reserved));
            app(MonitoredTags::class)->add('order:async');
            $reserved->fire(); event(new JobAttempted('redis', $reserved));
            $this->assertSame([], $database->getQueryLog(), 'Async monitoring must not read or write history SQL from the worker.');
            $database->disableQueryLog();
            $this->waitFor(fn () => $transport->client()->xLen('events:v2') === 0, $collector);
            $job = $this->store()->table('jobs')->sole();
            $this->assertSame('completed', $job->status);
            $this->assertGreaterThan($original->expires_at + 5 * 86400, $job->expires_at);
            $this->assertSame($job->expires_at, $this->store()->table('async_aliases')->sole()->job_expires_at);
            $this->assertSame($job->expires_at + 86400, $this->store()->table('async_versions')->min('expires_at'));
            $this->assertSame(1, $this->store()->table('steps')->count());
            $this->get('/current/jobs/'.$job->id)->assertOk()->assertSee('Retained operation')->assertDontSee('fixture-secret');
        } finally {
            if ($collector?->isRunning()) { $collector->signal(SIGTERM); $collector->wait(); }
            app('db')->purge('lens'); config()->set('database.connections.lens.database', $source);
            if ($local !== null) { foreach ([$local, $local.'-wal', $local.'-shm', $local.'.collector.lock'] as $file) { @unlink($file); } }
            $settings = $transport->settings(); $client = $transport->client(); $keys = $client->keys('*');
            foreach ($keys as $key) { $client->del(substr($key, strlen($settings['prefix']))); }
        }
    }

    private function drain(string $binary): void
    {
        $config = app(Transport::class)->settings() + ['once' => true];
        $process = new Process([$binary, '-'], dirname(__DIR__), timeout: 10);
        $process->setInput(json_encode($config)); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    private function waitFor(callable $condition, Process $collector): void
    {
        $deadline = microtime(true) + 10;
        do {
            if ($condition()) { return; }
            $this->assertTrue($collector->isRunning(), $collector->getErrorOutput());
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Timed out waiting for the matching monitored-retention collector.');
    }
}

final class MonitoredFixtureJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;
    public int $tries = 1;
    public function __construct(public string $tag, public bool $failure = false) { $this->onQueue('lens-demo'); }
    public function tags(): array { return [$this->tag]; }
    public function currentArguments(): array { return ['tag' => $this->tag, 'api_token' => 'fixture-secret']; }
    public function handle(): void
    {
        Current::step('Retained operation', fn () => true);
        if ($this->failure) { throw new \RuntimeException('Synthetic monitored failure'); }
    }
}
