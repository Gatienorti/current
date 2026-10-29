<?php

namespace Current\Tests;

use Current\Native\Retry;
use Current\Prototype\Jobs\PaymentJob;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class NativeRetryTest extends TestCase
{
    private ?string $failedDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        Gate::define('viewCurrent', fn ($user) => true);
        if ($host = getenv('CURRENT_ASYNC_DB_HOST')) {
            $connection = $this->serverConnection();
            config()->set('database.connections.retry_admin', $connection);
            $this->failedDatabase = 'current_retry_test_'.strtolower((string) Str::ulid());
            app('db')->connection('retry_admin')->statement('CREATE DATABASE '.(getenv('CURRENT_ASYNC_DB_DRIVER') === 'pgsql' ? '"'.$this->failedDatabase.'"' : '`'.$this->failedDatabase.'`'));
            app('db')->purge('app');
            config()->set('database.connections.app', array_replace($connection, ['database' => $this->failedDatabase]));
            Schema::connection('app')->create('failed_jobs', function ($table) {
                $table->id(); $table->string('uuid')->unique(); $table->text('connection'); $table->text('queue');
                $table->longText('payload'); $table->longText('exception'); $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    protected function tearDown(): void
    {
        if ($this->failedDatabase) {
            app('db')->purge('app');
            app('db')->connection('retry_admin')->statement('DROP DATABASE '.(getenv('CURRENT_ASYNC_DB_DRIVER') === 'pgsql' ? '"'.$this->failedDatabase.'" WITH (FORCE)' : '`'.$this->failedDatabase.'`'));
            app('db')->purge('retry_admin');
        }
        parent::tearDown();
    }

    public function test_native_retry_uses_retained_payload_and_exact_dispatch_with_duplicate_post_protection(): void
    {
        $job = new PaymentJob('failure'); $job->tries = 1;
        dispatch($job); $this->work();
        $original = $this->store()->table('jobs')->sole();
        $this->assertTrue(app(Retry::class)->available($original));
        $url = '/current/jobs/'.$original->id.'/retry';
        $this->post($url)->assertStatus(419);
        $this->withSession(['_token' => 'retry-csrf'])->withHeader('X-CSRF-TOKEN', 'retry-csrf');
        $this->post($url)->assertForbidden();
        Gate::define('operateCurrent', fn ($user) => true);
        $response = $this->post($url)->assertRedirect();
        $retry = $this->store()->table('jobs')->where('id', '!=', $original->id)->sole();
        $this->assertSame($original->external_id, $retry->external_id);
        $this->assertNotSame($original->dispatch_id, $retry->dispatch_id);
        $response->assertRedirect(route('current.show', ['id' => $retry->id, 'watch' => 1]).'#execution-map');
        $this->post($url)->assertRedirect($response->headers->get('Location'));
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->get('/current/jobs/'.$original->id)->assertSee('View retry');
        $this->work();
        $this->assertFalse(app(Retry::class)->available($original));
        $this->assertTrue(app(Retry::class)->available($this->store()->table('jobs')->where('id', $retry->id)->first()));
    }

    public static function failedReplacementKinds(): array
    {
        return ['new dispatch identity' => [false], 'same identity with case-only command change' => [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failedReplacementKinds')]
    public function test_failed_payload_replacement_between_verification_and_cleanup_is_preserved(bool $sameIdentity): void
    {
        $job = new PaymentJob('failure'); $job->tries = 1;
        dispatch($job); $this->work();
        $original = $this->store()->table('jobs')->sole();
        $replacement = $this->replacementPayload($original, $sameIdentity);
        $replaced = false;
        // A deterministic competing write at the last possible point before SQL deletion.
        $this->replaceAtFailedDelete($original, $replacement, $replaced);
        try {
            app(\Current\RetrySubmission::class)->submit($original);
            $this->fail('A replacement makes failed-payload cleanup unconfirmed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cleanup unconfirmed', $exception->getMessage());
        }
        $this->assertTrue($replaced);
        $this->assertSame($replacement, app('queue.failer')->find($original->external_id)?->payload);
        $this->assertSame(2, $this->store()->table('jobs')->count(), 'The original retained command was enqueued exactly once.');
        $this->assertSame('requested', json_decode($this->store()->table('native_retry_claims')->sole()->value, true)['state']);
        $this->assertSame('requested', app(\Current\RetrySubmission::class)->submit($original)['state']);
        $this->assertSame(2, $this->store()->table('jobs')->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failedReplacementKinds')]
    public function test_forget_preserves_history_and_replacement_at_the_failed_payload_delete_boundary(bool $sameIdentity): void
    {
        $job = new PaymentJob('failure'); $job->tries = 1;
        dispatch($job); $this->work();
        $original = $this->store()->table('jobs')->sole();
        $replacement = $this->replacementPayload($original, $sameIdentity); $replaced = false;
        $this->replaceAtFailedDelete($original, $replacement, $replaced);
        try {
            app(\Current\Native\Maintenance::class)->forget($original->id, false);
            $this->fail('A replaced failed payload cannot be forgotten with the old history.');
        } catch (\Current\Native\MaintenanceException $exception) {
            $this->assertStringContainsString('unconfirmed', $exception->getMessage());
        }
        $this->assertTrue($replaced);
        $this->assertSame($replacement, app('queue.failer')->find($original->external_id)?->payload);
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->assertSame(0, $this->store()->table('job_actions')->count());
        $this->assertFalse($this->store()->table('meta')->where('key', 'retry:'.$original->id)->exists());
    }

    public static function legacyClaimPaths(): array
    {
        return ['migration backfill' => [true], 'runtime backfill' => [false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyClaimPaths')]
    public function test_legacy_unconfirmed_claim_survives_history_expiry_after_upgrade(bool $migrate): void
    {
        $job = new PaymentJob('failure'); $job->tries = 1;
        dispatch($job); $this->work();
        $original = $this->store()->table('jobs')->sole();
        $this->store()->table('meta')->insert(['key' => 'retry:'.$original->id, 'value' => '{"state":"requested"}']);
        if ($migrate) {
            // Simulate the pre-upgrade schema, so untouched legacy claims are migrated too.
            $migration = require dirname(__DIR__).'/database/migrations/2026_10_28_000017_add_native_retry_claims.php';
            $migration->down(); $migration->up();
        } else {
            $this->assertSame('requested', app(\Current\RetrySubmission::class)->submit($original)['state']);
        }
        $this->store()->table('jobs')->where('id', $original->id)->update(['expires_at' => time() - 1]);
        $this->assertSame(1, $this->store()->prune());
        $this->assertFalse($this->store()->table('meta')->where('key', 'retry:'.$original->id)->exists());
        $missingHistory = clone $original; $missingHistory->id = null;
        $this->assertSame('requested', app(\Current\RetrySubmission::class)->submit($missingHistory)['state']);
        $this->assertSame(0, $this->store()->table('jobs')->count(), 'History expiry must not resend an earlier unknown retry.');
        $this->assertNotNull(app('queue.failer')->find($original->external_id));
    }

    public function test_custom_failed_provider_retains_payload_and_unknown_claim_without_uuid_deletion(): void
    {
        $job = new PaymentJob('failure'); $job->tries = 1;
        dispatch($job); $this->work();
        $original = $this->store()->table('jobs')->sole();
        $failer = app('queue.failer');
        $payload = $failer->find($original->external_id)->payload;
        $custom = \Mockery::mock($failer)->makePartial();
        $custom->shouldReceive('forget')->never();
        app()->instance('queue.failer', $custom);
        try {
            app(\Current\RetrySubmission::class)->submit($original);
            $this->fail('An unknown provider cannot confirm exact cleanup.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cleanup unconfirmed', $exception->getMessage());
        }
        $this->assertSame($payload, $failer->find($original->external_id)?->payload);
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->assertSame('requested', app(\Current\RetrySubmission::class)->submit($original)['state']);
        $this->assertSame(2, $this->store()->table('jobs')->count());
        try {
            app(\Current\Native\Maintenance::class)->forget($original->id, true);
            $this->fail('An unconfirmed retry cannot be forgotten.');
        } catch (\Current\Native\MaintenanceException $exception) {
            $this->assertStringContainsString('unconfirmed', $exception->getMessage());
        }
    }

    private function replaceAtFailedDelete(object $original, string $replacement, bool &$replaced): void
    {
        app('db')->connection('app')->beforeExecuting(function ($query, $bindings, $connection) use ($original, $replacement, &$replaced) {
            if (! $replaced && str_starts_with(strtolower(ltrim($query)), 'delete from') && str_contains($query, 'failed_jobs')) {
                $replaced = true;
                $connection->table('failed_jobs')->where('uuid', $original->external_id)->update(['payload' => $replacement]);
            }
        });
    }

    private function replacementPayload(object $original, bool $sameIdentity): string
    {
        $payload = json_decode(app('queue.failer')->find($original->external_id)->payload, true);
        if ($sameIdentity) {
            // Default server text collations can equate these executable byte changes.
            $payload['data']['command'] = str_replace('failure', 'FAILURE', $payload['data']['command']);
        } else {
            $payload['_current_dispatch_id'] = (string) Str::ulid();
        }
        return json_encode($payload);
    }
}
