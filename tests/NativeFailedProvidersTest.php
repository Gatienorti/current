<?php

namespace Current\Tests;

use Current\Contracts\FailedJobMaintenance;
use Current\Native\BulkForget;
use Current\Native\FailedPayloads;
use Current\Native\MaintenanceException;
use Current\Native\Retry;
use Current\Prototype\Jobs\PaymentJob;
use Current\RetrySubmission;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\DynamoDbFailedJobProvider;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class NativeFailedProvidersTest extends TestCase
{
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            (new Filesystem)->deleteDirectory($directory);
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(): string
    {
        return json_encode(['uuid' => (string) Str::uuid(), '_current_dispatch_id' => (string) Str::ulid(),
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => PaymentJob::class, 'command' => serialize(new PaymentJob('failure'))]], JSON_THROW_ON_ERROR);
    }

    private function fileProvider(): FileFailedJobProvider
    {
        $directory = sys_get_temp_dir().'/current-file-maintenance-'.Str::ulid();
        mkdir($directory, 0700, true);
        $this->directories[] = $directory;
        config()->set('cache.stores.maintenance-fixture', ['driver' => 'file', 'path' => $directory.'/cache', 'lock_path' => $directory.'/locks']);

        return new FileFailedJobProvider($directory.'/failures.json', 1000, fn () => Cache::store('maintenance-fixture'));
    }

    public function test_numeric_failed_provider_retries_exact_payload_and_forgets_by_native_failed_id(): void
    {
        Schema::connection('app')->create('numeric_failures', function ($table) {
            $table->id();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        $provider = new DatabaseFailedJobProvider(app('db'), 'app', 'numeric_failures');
        app()->instance('queue.failer', $provider);
        $job = new PaymentJob('failure');
        $job->tries = 1;
        dispatch($job);
        $this->work();
        $original = $this->store()->table('jobs')->sole();
        $this->assertTrue(app(Retry::class)->available($original));
        $submitted = app(RetrySubmission::class)->submit($original);
        $this->assertSame('submitted', $submitted['state']);
        $this->assertNull($provider->find(1));
        $this->work();
        $this->assertNotNull($provider->find(2));
        $this->assertSame(0, Artisan::call('current:forget', ['id' => '2', '--failed-id' => true]));
        $this->assertNull($provider->find(2));
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->assertSame(0, Artisan::call('current:forget', ['--all' => true]));
        $this->assertSame(0, $this->store()->table('jobs')->count());
    }

    public function test_file_snapshot_includes_existing_same_second_records_and_preserves_new_prepends(): void
    {
        $provider = $this->fileProvider();
        app()->instance('queue.failer', $provider);
        Carbon::setTestNow('2026-10-28 01:00:00');
        for ($i = 0; $i < 125; $i++) {
            $provider->log('redis', 'lens-demo', $this->payload(), new \RuntimeException('Synthetic failure'));
        }
        $op = (string) Str::ulid();
        $first = app(BulkForget::class)->run($op, false, false, 1);
        $this->assertFalse($first['complete']);
        $newId = $provider->log('redis', 'lens-demo', $this->payload(), new \RuntimeException('Later, same-second failure'));
        $done = app(BulkForget::class)->run($op, true);
        $this->assertTrue($done['complete']);
        $this->assertSame(125, $done['payloads']);
        $this->assertSame(0, $done['blocked']);
        $this->assertCount(1, $provider->all());
        $this->assertNotNull($provider->find($newId));
    }

    public function test_file_payload_delete_before_checkpoint_resumes_from_durable_page_without_resending_new_work(): void
    {
        $provider = $this->fileProvider();
        app()->instance('queue.failer', $provider);
        for ($i = 0; $i < 105; $i++) {
            $provider->log('redis', 'lens-demo', $this->payload(), new \RuntimeException('Synthetic failure'));
        }
        $op = (string) Str::ulid();
        app(BulkForget::class)->run($op, false, false, 1);
        $tripped = false;
        $this->store()->connection()->beforeExecuting(function ($sql) use ($provider, &$tripped) {
            if (! $tripped && count($provider->all()) === 104 && str_starts_with(strtolower($sql), 'update "lens_maintenance_operations"')) {
                $tripped = true;
                throw new \RuntimeException('Synthetic checkpoint reply loss');
            }
        });
        try {
            app(BulkForget::class)->run($op, true);
            $this->fail('Checkpoint failure ignored');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Synthetic checkpoint', $error->getMessage());
        }
        $this->assertTrue($tripped);
        $late = $provider->log('redis', 'lens-demo', $this->payload(), new \RuntimeException('New after interruption'));
        $done = app(BulkForget::class)->run($op, true);
        $this->assertTrue($done['complete']);
        $this->assertSame(105, $done['payloads']);
        $this->assertSame(0, $done['blocked']);
        $this->assertCount(1, $provider->all());
        $this->assertNotNull($provider->find($late));
    }

    public function test_file_compare_delete_preserves_same_id_replacement_and_unlocked_provider_refuses_atomic_claim(): void
    {
        $provider = $this->fileProvider();
        $payload = $this->payload();
        $id = $provider->log('redis', 'lens-demo', $payload, new \RuntimeException('Fixture'));
        $old = $provider->find($id);
        $new = json_decode($payload, true);
        $new['_current_dispatch_id'] = (string) Str::ulid();
        $provider->log('redis', 'lens-demo', json_encode($new), new \RuntimeException('Replacement'));
        $this->assertTrue(FailedPayloads::forgetExact($provider, $id, $old));
        $this->assertCount(1, $provider->all());
        $this->assertSame($new['_current_dispatch_id'], json_decode($provider->find($id)->payload, true)['_current_dispatch_id']);
        $directory = sys_get_temp_dir().'/current-unlocked-file-'.Str::ulid();
        mkdir($directory);
        $this->directories[] = $directory;
        $unlocked = new FileFailedJobProvider($directory.'/failures.json');
        $id = $unlocked->log('redis', 'lens-demo', $this->payload(), new \RuntimeException('Fixture'));
        $this->assertFalse(FailedPayloads::forgetExact($unlocked, $id, $unlocked->find($id)));
        $this->assertNotNull($unlocked->find($id));
    }

    public function test_custom_atomic_provider_contract_supports_retry_and_snapshot_bulk_without_calling_plain_forget(): void
    {
        $provider = new AtomicMaintenanceFailedProvider;
        app()->instance('queue.failer', $provider);
        $job = new PaymentJob('failure');
        $job->tries = 1;
        dispatch($job);
        $this->work();
        $original = $this->store()->table('jobs')->sole();
        $this->assertSame('submitted', app(RetrySubmission::class)->submit($original)['state']);
        $this->work();
        $op = (string) Str::ulid();
        $first = app(BulkForget::class)->run($op, false, false, 1);
        $this->assertFalse($first['complete']);
        $late = $provider->log('redis', 'lens-demo', $this->payload(), new \RuntimeException('Later custom failure'));
        $done = app(BulkForget::class)->run($op, true);
        $this->assertTrue($done['complete']);
        $this->assertSame(0, $done['blocked']);
        $this->assertCount(1, $provider->rows);
        $this->assertNotNull($provider->find($late));
        $snapshot = FailedPayloads::snapshot($provider);
        $provider->scope = 'different-store';
        $this->expectException(MaintenanceException::class);
        FailedPayloads::page($provider, $snapshot, null);
    }

    public function test_basic_custom_provider_requires_explicit_nonatomic_opt_in_and_bounds_all_to_captured_failures(): void
    {
        $provider = new BasicMaintenanceFailedProvider;
        app()->instance('queue.failer', $provider);
        $jobs = [];
        for ($i = 0; $i < 105; $i++) {
            $job = new PaymentJob('failure');
            $job->tries = 1;
            $jobs[] = $job;
        }
        Queue::bulk($jobs, '', 'lens-demo');
        $this->work(105);
        $this->assertSame(1, Artisan::call('current:forget', ['--all' => true]));
        $this->assertSame(105, $this->store()->table('jobs')->count());
        $this->assertSame(0, $provider->forgetCalls);
        $op = (string) Str::ulid();
        $first = app(BulkForget::class)->run($op, false, false, 1, true);
        $this->assertFalse($first['complete']);
        $this->assertSame(100, $first['histories']);
        $lateJob = new PaymentJob('failure');
        $lateJob->tries = 1;
        dispatch($lateJob);
        $this->work();
        $late = $this->store()->table('jobs')->orderByDesc('id')->first();
        $unobserved = $provider->log('redis', 'lens-demo', $this->payload(), new \RuntimeException('Unobserved custom failure'));
        $done = app(BulkForget::class)->run($op, true, false, 0, true);
        $this->assertTrue($done['complete']);
        $this->assertSame(105, $done['histories']);
        $this->assertSame(105, $done['payloads']);
        $this->assertSame(0, $done['blocked']);
        $this->assertSame($late->id, $this->store()->table('jobs')->sole()->id);
        $this->assertNotNull($provider->find($unobserved));
        $this->assertCount(2, $provider->rows);
        $this->assertSame(0, Artisan::call('current:forget', ['id' => $late->external_id, '--allow-nonatomic-provider' => true]));
        $this->assertStringContainsString('NON-ATOMIC', Artisan::output());
    }

    public function test_bulk_lease_handover_during_provider_lookup_fences_the_next_mutation(): void
    {
        $provider = new AtomicMaintenanceFailedProvider;
        app()->instance('queue.failer', $provider);
        $id = $provider->log('redis', 'lens-demo', $this->payload(), new \RuntimeException('Synthetic failure'));
        $operation = (string) Str::ulid();
        app(BulkForget::class)->run($operation, false, false, 1);
        $provider->onFind = function () use ($operation) {
            $this->store()->table('maintenance_operations')->where('id', $operation)->update([
                'owner_token' => (string) Str::ulid(), 'lease_until' => microtime(true) + 30,
                'revision' => $this->store()->connection()->raw('revision + 1'),
            ]);
        };
        try {
            app(BulkForget::class)->run($operation, true);
            $this->fail('A stale operation owner was allowed to continue.');
        } catch (MaintenanceException $error) {
            $this->assertStringContainsString('ownership changed', $error->getMessage());
        }
        $provider->onFind = null;
        $this->assertNotNull($provider->find($id));
        $this->assertSame(0, $this->store()->table('job_actions')->count());
    }

    public function test_nonatomic_ambiguous_delete_is_not_automatically_repeated_on_resume(): void
    {
        $provider = new BasicMaintenanceFailedProvider;
        app()->instance('queue.failer', $provider);
        $job = new PaymentJob('failure');
        $job->tries = 1;
        dispatch($job);
        $this->work();
        $provider->failForget = true;
        $op = (string) Str::ulid();
        $first = app(BulkForget::class)->run($op, false, false, 0, true);
        $this->assertTrue($first['complete']);
        $this->assertSame(1, $first['blocked']);
        $this->assertSame(1, $provider->forgetCalls);
        app(BulkForget::class)->run($op, true, false, 0, true);
        $this->assertSame(1, $provider->forgetCalls);
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->assertCount(1, $provider->rows);
    }

    public function test_nonatomic_delete_reply_checkpoint_interruption_reconciles_absence_without_resending(): void
    {
        $provider = new BasicMaintenanceFailedProvider;
        app()->instance('queue.failer', $provider);
        $job = new PaymentJob('failure');
        $job->tries = 1;
        dispatch($job);
        $this->work();
        $op = (string) Str::ulid();
        $tripped = false;
        $this->store()->connection()->beforeExecuting(function ($sql) use ($provider, &$tripped) {
            if (! $tripped && $provider->forgetCalls === 1 && str_starts_with(strtolower($sql), 'update "lens_maintenance_operations"')) {
                $tripped = true;
                throw new \RuntimeException('Synthetic non-atomic checkpoint loss');
            }
        });
        try {
            app(BulkForget::class)->run($op, false, false, 0, true);
            $this->fail('Checkpoint failure ignored');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('checkpoint loss', $error->getMessage());
        }
        $done = app(BulkForget::class)->run($op, true, false, 0, true);
        $this->assertTrue($done['complete']);
        $this->assertSame(1, $done['histories']);
        $this->assertSame(1, $done['payloads']);
        $this->assertSame(1, $provider->forgetCalls);
        $this->assertCount(0, $provider->rows);
    }

    public function test_dynamo_adapter_requests_strong_paged_reads_and_conditional_exact_delete_without_external_calls(): void
    {
        // Service request-contract coverage only. No AWS SDK or emulator is needed,
        // and this deliberately does not establish DynamoDB runtime compatibility.
        $fake = new class
        {
            public array $calls = [];

            public array $row = [];

            public bool $conditional = false;

            public function getEndpoint()
            {
                return 'http://synthetic-dynamo.invalid';
            }

            public function query($request)
            {
                $this->calls['query'][] = $request;
                if (count($this->calls['query']) > 1) {
                    return ['Items' => []];
                }

return ['Items' => [$this->row], 'LastEvaluatedKey' => ['application' => ['S' => 'fixture'], 'uuid' => ['S' => 'next']]];
            }

            public function getItem($request)
            {
                $this->calls['get'][] = $request;

                return ['Item' => $this->row];
            }

            public function deleteItem($request)
            {
                $this->calls['delete'][] = $request;
                if ($this->conditional) {
                    throw new class extends \RuntimeException
                    {
                        public function getAwsErrorCode()
                        {
                            return 'ConditionalCheckFailedException';
                        }
                    };
                }

return [];
            }
        };
        $payload = $this->payload();
        $uuid = json_decode($payload, true)['uuid'];
        $fake->row = ['uuid' => ['S' => $uuid], 'connection' => ['S' => 'redis'], 'queue' => ['S' => 'lens-demo'], 'payload' => ['S' => $payload], 'failed_at' => ['N' => (string) (time() - 5)]];
        $class = new \ReflectionClass(DynamoDbFailedJobProvider::class);
        $provider = $class->newInstanceWithoutConstructor();
        foreach (['dynamo' => $fake, 'table' => 'fixture-failures', 'applicationName' => 'fixture'] as $name => $value) {
            $class->getProperty($name)->setValue($provider, $value);
        }
        $snapshot = FailedPayloads::snapshot($provider);
        $page = FailedPayloads::page($provider, $snapshot, null, 10);
        $this->assertFalse($page['complete']);
        $this->assertTrue($fake->calls['query'][0]['ConsistentRead']);
        $this->assertSame('failed_at < :before', $fake->calls['query'][0]['FilterExpression']);
        $this->assertSame((string) $snapshot['before'], $fake->calls['query'][0]['ExpressionAttributeValues'][':before']['N']);
        FailedPayloads::page($provider, $snapshot, $page['cursor'], 10);
        $this->assertSame($page['cursor'], $fake->calls['query'][1]['ExclusiveStartKey']);
        $failed = FailedPayloads::findRetained($provider, $uuid);
        $this->assertTrue($fake->calls['get'][0]['ConsistentRead']);
        $this->assertTrue(FailedPayloads::forgetExact($provider, $uuid, $failed));
        $request = $fake->calls['delete'][0];
        $timeAlias = array_search('failed_at', $request['ExpressionAttributeNames'], true);
        $this->assertIsString($timeAlias);
        $this->assertStringContainsString($timeAlias.' = ', $request['ConditionExpression']);
        $payloadAlias = array_search('payload', $request['ExpressionAttributeNames'], true);
        preg_match('/'.preg_quote($payloadAlias, '/').' = (:[A-Za-z0-9]+)/', $request['ConditionExpression'], $valueAlias);
        $this->assertSame($payload, $request['ExpressionAttributeValues'][$valueAlias[1]]['S']);
        $fake->conditional = true;
        $this->assertFalse(FailedPayloads::forgetExact($provider, $uuid, $failed));
    }
}

class AtomicMaintenanceFailedProvider extends NullFailedJobProvider implements FailedJobMaintenance
{
    public array $rows = [];

    public string $scope = 'synthetic-isolated-store';

    private int $sequence = 0;

    public ?\Closure $onFind = null;

    public function log($connection, $queue, $payload, $exception)
    {
        $id = json_decode($payload, true)['uuid'];
        $this->rows[$id] = (object) ['id' => $id, 'connection' => $connection, 'queue' => $queue, 'payload' => $payload, '_sequence' => ++$this->sequence];

        return $id;
    }

    public function find($id)
    {
        if ($this->onFind) {
            ($this->onFind)();
        }

return $this->rows[$id] ?? null;
    }

    public function forget($id)
    {
        throw new \LogicException('Current must use the explicit atomic provider capability.');
    }

    public function currentFailedMaintenanceScope(): string
    {
        return $this->scope;
    }

    public function currentFailedSnapshot(): array
    {
        return ['upper' => $this->sequence];
    }

    public function currentFailedPage(array $snapshot, mixed $cursor, int $limit): array
    {
        $rows = array_values(array_filter($this->rows, fn ($row) => $row->_sequence > ($cursor ?? 0) && $row->_sequence <= $snapshot['upper']));
        usort($rows, fn ($a, $b) => $a->_sequence <=> $b->_sequence);
        $page = array_slice($rows, 0, $limit);

        return ['jobs' => $page, 'cursor' => $page ? end($page)->_sequence : $cursor, 'complete' => count($rows) <= $limit];
    }

    public function forgetCurrentFailedExact(string $id, object $expected): bool
    {
        $current = $this->rows[$id] ?? null;
        if (! $current || $current->connection !== $expected->connection || $current->queue !== $expected->queue || ! hash_equals($current->payload, $expected->payload)) {
            return false;
        }
        unset($this->rows[$id]);

        return true;
    }
}

class BasicMaintenanceFailedProvider extends NullFailedJobProvider
{
    public array $rows = [];

    public int $forgetCalls = 0;

    public bool $failForget = false;

    public function log($connection, $queue, $payload, $exception)
    {
        $id = json_decode($payload, true)['uuid'];
        $this->rows[$id] = (object) compact('id', 'connection', 'queue', 'payload');

        return $id;
    }

    public function find($id)
    {
        return $this->rows[$id] ?? null;
    }

    public function all()
    {
        throw new \LogicException('Unbounded custom failed storage must never be read.');
    }

    public function forget($id)
    {
        $this->forgetCalls++;
        if ($this->failForget) {
            throw new \RuntimeException('Synthetic ambiguous provider failure');
        }$exists = isset($this->rows[$id]);
        unset($this->rows[$id]);

        return $exists;
    }
}
