<?php

namespace Current\Tests;

use Current\Native\ObservationRedis;
use Current\Native\RuntimeEstimate;
use Current\Native\RuntimeSampler;
use Current\Native\RuntimeSnapshot;
use Current\Prototype\Jobs\PaymentJob;
use RuntimeException;

final class NativeRuntimeSamplerTest extends TestCase
{
    private function supervisorBinary(): string
    {
        $binary = dirname(__DIR__).'/supervisor/current-supervisor';
        if (! is_executable($binary)) {
            if (getenv('CURRENT_REQUIRE_SUPERVISOR_TESTS') === '1') { $this->fail('Build the native supervisor before running required process checks.'); }
            $this->markTestSkipped('Optional process integration requires a built Linux supervisor.');
        }
        return $binary;
    }

    public function test_sampler_observes_real_history_through_the_private_pipe(): void
    {
        config()->set('current.native.binary', $this->supervisorBinary());
        dispatch(new PaymentJob('success')); $this->work();
        $end = microtime(true) - 1;
        $this->store()->table('attempts')->update(['started_at' => $end - 2, 'finished_at' => $end]);
        $sampler = new RuntimeSampler;
        $pools = [['connection' => 'redis', 'queues' => ['lens-demo']]];
        $snapshot = app(RuntimeSnapshot::class);
        $redis = ObservationRedis::connect(config('current.native.registry.redis_connection', 'default'));
        $key = $snapshot->key('redis', 'lens-demo');
        try {
            $deadline = microtime(true) + 7;
            do {
                $sampler->tick($pools);
                if ($redis->get($key)) { break; }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertIsString($redis->get($key), 'Sampler did not publish a fresh observation.');
            $this->store()->connection()->beforeExecuting(fn () => throw new RuntimeException('Runtime read attempted SQL.'));
            $this->assertEqualsWithDelta(6, (new RuntimeEstimate)->workSeconds('redis', ['lens-demo' => 3]), .01);
        } finally { $sampler->stop(); }
    }

    public function test_launcher_death_stops_the_sampling_process_group(): void
    {
        if (! function_exists('posix_kill')) { $this->markTestSkipped('Requires the Linux process integration environment.'); }
        $binary = $this->supervisorBinary();
        $marker = tempnam(sys_get_temp_dir(), 'runtime-child-'); unlink($marker);
        $artisan = tempnam(sys_get_temp_dir(), 'runtime-artisan-');
        $parent = tempnam(sys_get_temp_dir(), 'runtime-parent-');
        file_put_contents($artisan, '<?php file_put_contents('.var_export($marker, true).', (string) getmypid()); sleep(30);');
        $command = [$binary, '-runtime-sample', PHP_BINARY, $artisan];
        file_put_contents($parent, '<?php $p=proc_open('.var_export($command, true).', [0=>["pipe","r"],1=>["file","/dev/null","w"],2=>["file","/dev/null","w"]], $pipes); fwrite($pipes[0], "{}"); fclose($pipes[0]); sleep(30);');
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, $parent]);
        $process->setTimeout(null); $process->start();
        try {
            $deadline = microtime(true) + 3;
            do { clearstatcache(); if (is_file($marker)) { break; } usleep(10000); } while (microtime(true) < $deadline);
            $this->assertFileExists($marker);
            $pid = (int) file_get_contents($marker);
            $this->assertGreaterThan(0, $pid);
            $this->assertTrue(posix_kill($pid, 0));
            $process->signal(SIGKILL); $process->wait();
            $deadline = microtime(true) + 2;
            do { if (! posix_kill($pid, 0)) { break; } usleep(10000); } while (microtime(true) < $deadline);
            $this->assertFalse(posix_kill($pid, 0), 'Sampling PHP survived launcher death.');
        } finally {
            if ($process->isRunning()) { $process->stop(0, SIGKILL); }
            foreach ([$marker, $artisan, $parent] as $file) { if (is_file($file)) { unlink($file); } }
        }
    }

    public function test_unknown_queue_does_not_hide_other_valid_runtime_observations(): void
    {
        $snapshot = app(RuntimeSnapshot::class); $routes = ['redis' => ['new-queue', 'known-queue']];
        $snapshot->publish(['observed_at' => microtime(true), 'rows' => [
            ['connection' => 'redis', 'queue' => 'new-queue', 'runtime' => null],
            ['connection' => 'redis', 'queue' => 'known-queue', 'runtime' => 2],
        ]], $routes);
        $estimate = new RuntimeEstimate;
        try { $estimate->workSeconds('redis', ['new-queue' => 1]); $this->fail('Unknown queue accepted.'); }
        catch (RuntimeException) { $this->assertEqualsWithDelta(6, $estimate->workSeconds('redis', ['known-queue' => 3]), .001); }
    }

    public function test_bad_stale_and_incomplete_snapshots_never_replace_valid_values(): void
    {
        $snapshot = app(RuntimeSnapshot::class); $routes = ['redis' => ['lens-demo']];
        $valid = ['observed_at' => microtime(true), 'rows' => [['connection' => 'redis', 'queue' => 'lens-demo', 'runtime' => 2.0]]];
        $snapshot->publish($valid, $routes);
        foreach (['stale', 'future', 'negative', 'missing', 'duplicate', 'wrong-queue'] as $case) {
            $bad = $valid;
            match ($case) {
                'stale' => $bad['observed_at'] = microtime(true) - 60,
                'future' => $bad['observed_at'] = microtime(true) + 60,
                'negative' => $bad['rows'][0]['runtime'] = -1,
                'missing' => $bad['rows'] = [],
                'duplicate' => $bad['rows'][] = $bad['rows'][0],
                'wrong-queue' => $bad['rows'][0]['queue'] = 'other',
            };
            try { $snapshot->publish($bad, $routes); $this->fail('Accepted '.$case); }
            catch (RuntimeException) { $this->assertEqualsWithDelta(4, (new RuntimeEstimate)->workSeconds('redis', ['lens-demo' => 2]), .001); }
        }
        $redis = ObservationRedis::connect(config('current.native.registry.redis_connection', 'default'));
        foreach ([['observed_at' => time() - 31, 'runtime' => 2], ['observed_at' => time() + 60, 'runtime' => 2], ['observed_at' => time(), 'runtime' => null]] as $row) {
            $redis->set($snapshot->key('redis', 'lens-demo'), json_encode($row));
            try { (new RuntimeEstimate)->workSeconds('redis', ['lens-demo' => 1]); $this->fail('Accepted stale or unknown observation.'); }
            catch (RuntimeException) { $this->assertTrue(true); }
        }
    }
}
