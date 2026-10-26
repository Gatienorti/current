<?php

namespace Current\Tests\Benchmarks;

use Current\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Explicit opt-in only: this filename deliberately does not end in Test.php. */
final class QueueOverhead extends TestCase
{
    public static function profiles(): array
    {
        return [
            ['disabled', 1], ['enabled', 1], ['five_steps', 1],
            ['enabled', 2], ['five_steps', 2], ['disabled', 2],
            ['five_steps', 3], ['disabled', 3], ['enabled', 3],
        ];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[DataProvider('profiles')]
    public function compare(string $profile, int $round): void
    {
        if (getenv('CURRENT_RUN_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set CURRENT_RUN_BENCHMARK=1 for this explicitly scoped experiment.');
        }
        config()->set('current.enabled', $profile !== 'disabled');
        config()->set('current.webhooks.enabled', false);
        config()->set('current.webhooks.outgoing.enabled', false);
        config()->set('current.broadcasts.enabled', false);
        config()->set('current.tag_prefixes', ['benchmark:']);
        $queue = 'current-benchmark-'.bin2hex(random_bytes(8));
        $worker = app('queue.worker');
        $options = new WorkerOptions(sleep: 0, maxTries: 1);
        SyntheticJob::$completed = 0;
        $queries = 0;
        $measuring = false;
        DB::listen(static function (QueryExecuted $event) use (&$queries, &$measuring): void {
            if ($measuring && $event->connectionName === 'lens') {
                $queries++;
            }
        });
        $dispatch = $execution = $total = [];
        // Same tiny workload for each profile; warm caches before measurement.
        for ($i = 0; $i < 110; $i++) {
            if ($i === 10) {
                gc_collect_cycles();
                memory_reset_peak_usage();
                $memoryStart = memory_get_usage(true);
                $measuring = true;
            }
            $start = hrtime(true);
            app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch((new SyntheticJob($profile === 'five_steps'))->onConnection('redis')->onQueue($queue));
            $queued = hrtime(true);
            $worker->runNextJob('redis', $queue, $options);
            $finished = hrtime(true);
            if ($measuring) {
                $dispatch[] = ($queued - $start) / 1e6;
                $execution[] = ($finished - $queued) / 1e6;
                $total[] = ($finished - $start) / 1e6;
            }
        }
        $measuring = false;
        $peak = memory_get_peak_usage(true);
        $memoryEnd = memory_get_usage(true);
        $this->assertSame(110, SyntheticJob::$completed);
        $this->assertSame(0, app('queue')->connection('redis')->size($queue));
        $this->assertSame($profile === 'disabled' ? 0 : 110, $this->store()->table('jobs')->where('status', 'completed')->count());
        $this->assertSame($profile === 'five_steps' ? 550 : 0, $this->store()->table('steps')->where('status', 'completed')->count());
        $this->assertFileDoesNotExist(config('current.diagnostic_path'));
        $summary = static function (array $values): array {
            sort($values);
            return ['mean_ms' => array_sum($values) / count($values), 'p50_ms' => ($values[49] + $values[50]) / 2, 'p95_ms' => $values[94]];
        };
        fwrite(STDOUT, "\nCURRENT_BENCHMARK ".json_encode([
            'profile' => $profile, 'round' => $round, 'samples' => 100,
            'dispatch' => $summary($dispatch), 'worker' => $summary($execution), 'combined' => $summary($total),
            'current_sql_per_job' => $queries / 100,
            'php_allocated_start_bytes' => $memoryStart, 'php_allocated_end_bytes' => $memoryEnd,
            'php_allocated_peak_bytes' => $peak, 'php_allocated_peak_delta_bytes' => $peak - $memoryStart,
        ], JSON_THROW_ON_ERROR)."\n");
    }

    protected function tearDown(): void
    {
        // Only this test's randomized namespaces; never flush a Redis database.
        if ($this->app) {
            foreach ([config('database.redis.options.prefix'), config('horizon.prefix')] as $prefix) {
                if (is_string($prefix) && str_starts_with($prefix, 'lens_test_')) {
                    $client = app('redis')->connection()->client();
                    $cursor = '0';
                    do {
                        [$cursor, $keys] = $client->rawCommand('SCAN', $cursor, 'MATCH', $prefix.'*', 'COUNT', '1000');
                        foreach ($keys as $key) {
                            if (str_starts_with($key, $prefix)) {
                                $client->rawCommand('DEL', $key);
                            }
                        }
                    } while ((string) $cursor !== '0');
                }
            }
        }
        parent::tearDown();
    }
}
