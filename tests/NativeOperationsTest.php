<?php

namespace Current\Tests;

use Current\Native\Plan;
use Current\Prototype\Jobs\PaymentJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;

final class NativeOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('current.native', ['state_directory' => '/tmp/current-test-plan', 'instance' => 'test', 'grace_seconds' => 10,
            'pools' => ['payments' => ['connection' => 'redis', 'queues' => ['lens-demo'], 'workers' => 2, 'min_workers' => 1, 'max_workers' => 70, 'timeout' => 2]]]);
        Gate::define('viewCurrent', fn ($user) => true);
    }

    public function test_native_plan_and_queue_architecture_use_explicit_ranges_without_starting_workers(): void
    {
        $runtime = app(Plan::class)->runtime();
        $this->assertSame(2, $runtime['pools'][0]['workers']);
        $this->assertSame(70, $runtime['pools'][0]['max_workers']);
        $this->assertContains('--queue=lens-demo', $runtime['pools'][0]['command']);
        $this->assertSame(0, Artisan::call('current:supervise', ['--check' => true]));
        $this->get('/current/queues')->assertOk()->assertSee('payments')->assertSee('Limits 1–70')->assertSee('lens-demo');
        config()->set('current.native.pools.payments.timeout', 5);
        $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
        $this->assertStringContainsString('timeout must be below', Artisan::output());
    }

    public function test_native_tags_silencing_and_observed_metrics_use_recorded_execution(): void
    {
        config()->set('current.native.silenced', [PaymentJob::class]);
        dispatch(new PaymentJob('success')); $this->work();
        $job = $this->store()->table('jobs')->sole();
        $this->assertSame(1, (int) $job->silenced);
        $tags = $this->store()->table('tags')->pluck('tag')->all();
        $this->assertContains('order:demo-1042', $tags);
        $this->assertNotContains('private:must-not-be-recorded', $tags);
        $this->get('/current/silenced')->assertOk()->assertSee('PaymentJob');
        $this->get('/current/metrics/jobs')->assertOk()->assertSee('PaymentJob')->assertSee('Captured attempts');
        $this->get('/current/metrics/queues')->assertOk()->assertSee('redis / lens-demo');
        $this->get('/current/batches')->assertOk()->assertSee('Batch storage is unavailable');
    }

    public function test_a_previous_redis_error_does_not_hide_a_successful_dispatch(): void
    {
        $connection = \Illuminate\Support\Facades\Redis::connection();
        $connection->set('native-wrong-type', 'value');
        try { $connection->lpush('native-wrong-type', 'item'); } catch (\RedisException) {}
        $this->assertNotNull($connection->client()->getLastError());
        dispatch(new PaymentJob('success'));
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->work();
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
    }
    public function test_native_workers_keep_laravel_batch_and_chain_semantics(): void
    {
        config()->set('queue.batching', ['database' => 'app', 'table' => 'job_batches']);
        \Illuminate\Support\Facades\Schema::connection('app')->create('job_batches', function ($t) {
            $t->string('id')->primary(); $t->string('name'); $t->integer('total_jobs'); $t->integer('pending_jobs');
            $t->integer('failed_jobs'); $t->longText('failed_job_ids'); $t->mediumText('options')->nullable();
            $t->integer('cancelled_at')->nullable(); $t->integer('created_at'); $t->integer('finished_at')->nullable();
        });
        $batch = \Illuminate\Support\Facades\Bus::batch([new NativeMarkerJob('batch-a'), new NativeMarkerJob('batch-b')])
            ->name('Native batch fixture')->onConnection('redis')->onQueue('lens-demo')->dispatch();
        $this->work(2);
        $this->assertTrue($batch->fresh()->finished());
        $this->assertSame(0, $batch->fresh()->failedJobs);
        $this->get('/current/batches')->assertOk()->assertSee('Native batch fixture')->assertDontSee('Batch storage is unavailable');
        \Illuminate\Support\Facades\Bus::chain([new NativeMarkerJob('chain-a'), new NativeMarkerJob('chain-b')])
            ->onConnection('redis')->onQueue('lens-demo')->dispatch();
        $this->work(2);
        $counts = \Illuminate\Support\Facades\Redis::hgetall('native-markers');
        $this->assertCount(4, $counts);
        $this->assertSame(4, array_sum($counts));
        $this->assertSame(4, $this->store()->table('jobs')->where('status', 'completed')->count());
    }

}


class NativeMarkerJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable, \Illuminate\Bus\Batchable;
    public function __construct(public string $label) {}
    public function handle(): void
    {
        \Current\Current::step($this->label, fn () => \Illuminate\Support\Facades\Redis::hincrby('native-markers', $this->label, 1));
    }
}
