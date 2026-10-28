<?php

namespace Current\Tests;

use Current\Support\JobRetention;
use Current\Telemetry\Transport;
use Illuminate\Support\Str;

final class NativeTagRetentionTest extends TestCase
{
    public function test_only_approved_exact_tags_extend_native_retention_within_the_global_cap(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('current.retention_days', 2);
        config()->set('current.max_retention_days', 20);
        config()->set('current.tag_prefixes', ['order:']);
        config()->set('current.native.tag_retention_days', ['order:important'=>14, 'order:short'=>1, 'order:capped'=>999, 'secret:value'=>30, 'order:invalid'=>'20']);
        $this->assertSame(14 * 86400, JobRetention::seconds(['tags'=>['order:important']]));
        $this->assertSame(20 * 86400, JobRetention::seconds(['tags'=>['order:capped']]));
        foreach ([[], ['tags'=>['secret:value']], ['tags'=>['order:short']], ['tags'=>['order:important-other']], ['tags'=>['order:invalid']]] as $payload) {
            $this->assertSame(2 * 86400, JobRetention::seconds($payload));
        }
        config()->set('current.execution_backend', 'horizon');
        $this->assertSame(2 * 86400, JobRetention::seconds(['tags'=>['order:important']]));
    }

    public function test_sync_and_async_use_the_same_initial_policy_without_sliding_expiry(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('current.telemetry.driver', 'redis');
        config()->set('current.retention_days', 2);
        config()->set('current.tag_prefixes', ['order:']);
        config()->set('current.native.tag_retention_days', ['order:important'=>14]);
        $payload = ['tags'=>['order:important']];
        $id = $this->store()->newJob((string) Str::uuid(), 'redis', 'lens-demo', $payload);
        $job = $this->store()->table('jobs')->where('id', $id)->sole();
        $this->assertEqualsWithDelta(14 * 86400, $job->expires_at - $job->first_observed_at, 0.01);
        $transport = app(Transport::class); $uuid = (string) Str::uuid(); $dispatch = (string) Str::ulid();
        try {
            $cycle = $transport->cycle($uuid, 'redis', $dispatch, $payload);
            $this->assertEqualsWithDelta(14 * 86400, $cycle['expires_at'] - $cycle['first_observed_at'], 0.01);
            config()->set('current.native.tag_retention_days', ['order:important'=>30]);
            $this->assertSame($cycle, $transport->cycle($uuid, 'redis', $dispatch, $payload));
            $this->assertSame(1, $this->store()->table('jobs')->count(), 'Async cycle selection must not write history SQL.');
        } finally {
            $key = 'cycle:v2:'.hash('sha256', json_encode(['redis', $uuid, $dispatch], JSON_THROW_ON_ERROR));
            $transport->client()->del($key);
        }
    }
}
