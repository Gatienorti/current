<?php

namespace Current\Tests;

use Current\Native\Metrics;
use Current\Prototype\Jobs\PaymentJob;
use Illuminate\Support\Facades\Artisan;

final class NativeMetricsTest extends TestCase
{
    private function sample(string $status, float $finished, ?float $started = null): object
    {
        dispatch(new PaymentJob('success')); $this->work();
        $job = $this->store()->table('jobs')->orderByDesc('id')->first();
        $this->store()->table('attempts')->where('job_id', $job->id)->update(['status' => $status, 'started_at' => $started ?? $finished - 0.5, 'finished_at' => $finished]);
        return $job;
    }

    public function test_closed_windows_count_attempts_preserve_gaps_and_correct_late_data_idempotently(): void
    {
        $now = intdiv(time(), 300) * 300 + 20; $end = $now - 20;
        $this->sample('completed', $end - 10);
        $this->sample('failed', $end - 20, $end - 21);
        $this->sample('completed', $end - 30, $end - 29); // Reversed clock: counted, no runtime.
        $this->sample('released', $end - 40);
        $this->sample('completed', $end + 1); // Open bucket is not snapshotted.
        $expired = $this->sample('completed', $end - 50);
        $this->store()->table('jobs')->where('id', $expired->id)->update(['expires_at' => $end - 1]);
        $metrics = app(Metrics::class);
        $this->assertSame(2, $metrics->snapshot($now));
        $series = $metrics->series('queues', ['redis', 'lens-demo'], 1, $now);
        $this->assertNull($series[0]['completed']);
        $this->assertFalse($series[0]['available']);
        $this->assertSame(0, $series[9]['completed']); // Snapshot succeeded with no captured finishes.
        $this->assertSame(2, $series[11]['completed']);
        $this->assertSame(1, $series[11]['failed']);
        $this->assertSame(2, $series[11]['duration_samples']);
        $this->assertEqualsWithDelta(750, $series[11]['duration'], 0.01);
        $this->assertSame(2, $metrics->snapshot($now));
        $this->assertSame(5, $this->store()->table('native_metrics')->count());
        $this->sample('completed', $end - 5);
        $metrics->snapshot($now + 1);
        $this->assertSame(3, $metrics->series('jobs', [PaymentJob::class], 1, $now + 1)[11]['completed']);
        $this->assertSame(5, $this->store()->table('native_metrics')->count());
        $this->assertNull($metrics->series('jobs', [PaymentJob::class], 1, $now + 86401)[0]['completed']);
    }

    public function test_snapshot_command_is_native_only_and_pruning_expires_aggregates(): void
    {
        $this->assertSame(0, Artisan::call('current:snapshot'));
        $this->assertSame(0, $this->store()->table('native_metrics')->count());
        config()->set('current.execution_backend', 'native');
        $this->assertSame(0, Artisan::call('current:snapshot'));
        $this->assertSame(3, $this->store()->table('native_metrics')->count());
        $this->store()->table('native_metrics')->update(['expires_at' => time() - 1]);
        $this->assertSame(0, Artisan::call('current:prune'));
        $this->assertSame(0, $this->store()->table('native_metrics')->count());
        config()->set('current.native.metrics.retention_hours', 0);
        $this->assertSame(1, Artisan::call('current:snapshot'));
        $this->assertSame(0, $this->store()->table('native_metrics')->count());
    }
    public function test_metric_history_routes_are_authorized_and_escape_dimensions(): void
    {
        config()->set('current.execution_backend', 'native');
        \Illuminate\Support\Facades\Gate::define('viewCurrent', fn ($user) => true);
        $url = '/current/metrics/queues/history?'.http_build_query(['connection' => 'redis', 'queue' => '<script>alert(1)</script>']);
        $this->get($url)->assertOk()->assertSee('No snapshots yet')->assertDontSee('<script>alert(1)</script>', false);
        app(Metrics::class)->snapshot();
        $this->get($url)->assertOk()->assertSee('Trend data')->assertDontSee('<script>alert(1)</script>', false);
        $this->getJson('/current/metrics/jobs/history')->assertUnprocessable();
        $this->getJson('/current/metrics/queues/history?connection=redis&queue=one&range=7d')->assertUnprocessable();
        \Illuminate\Support\Facades\Gate::define('viewCurrent', fn ($user) => false);
        $this->get($url)->assertForbidden();
    }

    public function test_excessive_group_count_rolls_back_every_window(): void
    {
        $now = intdiv(time(), 300) * 300 + 20; $finish = $now - 30;
        $job = $this->sample('completed', $finish);
        $metrics = app(Metrics::class); $metrics->snapshot($now);
        $before = $this->store()->table('native_metrics')->orderBy('metric_key')->orderBy('bucket')->get()->toArray();
        $attempt = $this->store()->table('attempts')->where('job_id', $job->id)->first();
        $jobs = []; $attempts = [];
        for ($i = 0; $i < 1000; $i++) {
            $j = (array) $job; $j['id'] = (string) \Illuminate\Support\Str::ulid(); $j['job_class'] = 'GroupLimitFixture'.$i;
            $a = (array) $attempt; $a['id'] = (string) \Illuminate\Support\Str::ulid(); $a['job_id'] = $j['id'];
            $jobs[] = $j; $attempts[] = $a;
        }
        foreach (array_chunk($jobs, 50) as $chunk) { $this->store()->table('jobs')->insert($chunk); }
        foreach (array_chunk($attempts, 50) as $chunk) { $this->store()->table('attempts')->insert($chunk); }
        try { $metrics->snapshot($now + 1); $this->fail('Partial snapshot accepted'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('group limit', $e->getMessage()); }
        $this->assertEquals($before, $this->store()->table('native_metrics')->orderBy('metric_key')->orderBy('bucket')->get()->toArray());
    }

}
