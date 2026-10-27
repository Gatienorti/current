<?php

namespace Current\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Redis;

final class NativeBatchesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native'); config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('queue.batching', ['database' => 'app', 'table' => 'job_batches']);
        Schema::connection('app')->create('job_batches', function ($t) {
            $t->string('id')->primary(); $t->string('name'); $t->integer('total_jobs'); $t->integer('pending_jobs');
            $t->integer('failed_jobs'); $t->longText('failed_job_ids'); $t->mediumText('options')->nullable();
            $t->integer('cancelled_at')->nullable(); $t->integer('created_at'); $t->integer('finished_at')->nullable();
        });
        Gate::define('viewCurrent', fn ($user) => true);
    }

    private function batch(string $label, int $count = 2)
    {
        return Bus::batch(array_map(fn ($i) => new RetryBatchFixture($label.'-'.$i), range(1, $count)))
            ->name($label)->allowFailures()->onConnection('redis')->onQueue('lens-demo')->dispatch();
    }

    public function test_batch_search_escapes_literals_and_paginates_beyond_the_latest_fifty(): void
    {
        $rows = [];
        for ($i = 1; $i <= 55; $i++) {
            $rows[] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $i), 'name' => 'Architecture batch '.$i,
                'total_jobs' => 0, 'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]',
                'options' => serialize([]), 'created_at' => time()];
        }
        app('db')->connection('app')->table('job_batches')->insert($rows);
        $first = $this->get('/current/batches?query=Architecture')->assertOk()->assertSee('Older batches')->assertSee('Architecture batch 55')->assertDontSee('Architecture batch 1</td>', false);
        $first->assertViewHas('rows', fn ($rows) => count($rows) === 50);
        $cursor = sprintf('00000000-0000-4000-8000-%012d', 6);
        $this->get('/current/batches?query=Architecture&before_id='.$cursor)->assertOk()->assertSee('Architecture batch 1')->assertDontSee('Older batches')
            ->assertViewHas('rows', fn ($rows) => count($rows) === 5);
        app('db')->connection('app')->table('job_batches')->where('id', $rows[0]['id'])->update(['name' => 'Literal %_! <script>']);
        $this->get('/current/batches?query='.urlencode('%_!'))->assertOk()->assertSee('Literal %_! &lt;script&gt;', false)
            ->assertDontSee('Literal %_! <script>', false)->assertViewHas('rows', fn ($rows) => count($rows) === 1);
        $this->get('/current/batches?query='.$rows[0]['id'])->assertOk()->assertViewHas('rows', fn ($rows) => count($rows) === 1);
        $this->getJson('/current/batches?query[]=bad')->assertUnprocessable();
        $this->getJson('/current/batches?before_id[]=bad')->assertUnprocessable();
        $this->getJson('/current/batches?query='.str_repeat('a', 101))->assertUnprocessable();
    }

    public function test_batch_inspection_and_retries_share_claims_with_individual_jobs(): void
    {
        $batch = $this->batch('batch-one'); $this->work(2);
        $this->assertSame(2, $batch->fresh()->failedJobs);
        $jobs = $this->store()->table('jobs')->where('batch_id', $batch->id)->get();
        $this->assertCount(2, $jobs);
        $this->get('/current/batches')->assertOk()->assertSee('Inspect batch');
        $url = '/current/batches/'.$batch->id;
        $this->get($url)->assertOk()->assertSee('RetryBatchFixture')->assertDontSee('Retry shown failures');
        $input = ['jobs' => $jobs->pluck('id')->all()];
        $this->post($url.'/retry', $input)->assertStatus(419);
        $this->withSession(['_token' => 'batch-csrf'])->withHeader('X-CSRF-TOKEN', 'batch-csrf');
        $this->post($url.'/retry', $input)->assertForbidden();
        Gate::define('operateCurrent', fn ($user) => true);
        $this->get($url)->assertOk()->assertSee('Retry shown failures (2)');
        $this->post('/current/jobs/'.$jobs[0]->id.'/retry')->assertRedirect();
        $this->post($url.'/retry', $input)->assertRedirect($url);
        $this->assertSame(4, $this->store()->table('jobs')->count());
        $this->post($url.'/retry', $input)->assertRedirect($url);
        $this->post('/current/jobs/'.$jobs[1]->id.'/retry')->assertRedirect();
        $this->assertSame(4, $this->store()->table('jobs')->count());
        $this->work(2);
        $this->assertTrue($batch->fresh()->finished());
        $this->assertSame([], $batch->fresh()->failedJobIds);
        $this->assertSame(2, array_sum(Redis::hgetall('batch-success')));
        $this->get($url)->assertOk()->assertDontSee('Retry shown failures');
    }

    public function test_cross_batch_selection_is_rejected_before_any_enqueue_and_stale_payload_is_not_retried(): void
    {
        $first = $this->batch('first', 1); $second = $this->batch('second', 1); $this->work(2);
        Gate::define('operateCurrent', fn ($user) => true);
        $this->withSession(['_token' => 'batch-csrf'])->withHeader('X-CSRF-TOKEN', 'batch-csrf');
        $jobs = $this->store()->table('jobs')->get();
        $this->post('/current/batches/'.$first->id.'/retry', ['jobs' => $jobs->pluck('id')->all()])->assertConflict();
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $job = $jobs->firstWhere('batch_id', $first->id);
        $failed = app('queue.failer')->find($job->external_id); $payload = json_decode($failed->payload, true);
        $payload['_current_dispatch_id'] = (string) \Illuminate\Support\Str::ulid();
        app('db')->connection('app')->table('failed_jobs')->where('uuid', $job->external_id)->update(['payload' => json_encode($payload)]);
        $this->post('/current/batches/'.$first->id.'/retry', ['jobs' => [$job->id]])->assertRedirect();
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->assertFalse($this->store()->table('meta')->where('key', 'retry:'.$job->id)->exists());
        Gate::define('viewCurrent', fn ($user) => false);
        $this->get('/current/batches/'.$second->id)->assertForbidden();
    }
}

class RetryBatchFixture implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable, \Illuminate\Bus\Batchable;
    public int $tries = 1;
    public function __construct(public string $label) {}
    public function handle(): void
    {
        if (Redis::setnx('batch-first:'.$this->label, 1)) { throw new \RuntimeException('Synthetic first-dispatch failure'); }
        Redis::hincrby('batch-success', $this->label, 1);
    }
}
