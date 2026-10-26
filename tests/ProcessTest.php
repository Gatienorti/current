<?php

namespace Current\Tests;

use Current\Tests\Fixtures\BlockingJob;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

final class ProcessTest extends TestCase
{
    private function worker(): Process
    {
        $settings = [];
        foreach (['database.connections.app.database', 'database.connections.lens.database', 'database.redis.options.prefix', 'database.redis.horizon.options.prefix', 'horizon.prefix', 'current.diagnostic_path'] as $key) {
            $settings[$key] = config($key);
        }

        return new Process([PHP_BINARY, __DIR__.'/worker.php'], dirname(__DIR__), ['LENS_TEST_SETTINGS' => json_encode($settings)], timeout: 12);
    }

    private function waitForStarts(int $count, array $workers): void
    {
        $deadline = microtime(true) + 5;
        do {
            if ($this->store()->table('attempts')->count() >= $count && $this->store()->table('steps')->count() >= $count) {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $details = array_map(fn (Process $worker) => [
            'running' => $worker->isRunning(), 'exit' => $worker->getExitCode(),
            'output' => $worker->getOutput(), 'error' => $worker->getErrorOutput(),
        ], $workers);
        $health = config('current.diagnostic_path');
        $this->fail('Worker did not capture its start and checkpoint within five seconds. '.json_encode([
            'workers' => $details,
            'attempts' => $this->store()->table('attempts')->count(),
            'steps' => $this->store()->table('steps')->count(),
            'recorder' => is_file($health) ? json_decode(file_get_contents($health), true) : null,
        ]));
    }

    public function test_sigkill_keeps_attempt_and_step_incomplete(): void
    {
        Queue::push(new BlockingJob(20));
        $process = $this->worker();
        try {
            $process->start();
            $this->waitForStarts(1, [$process]);
            $process->signal(SIGKILL);
            $process->wait();
            $attempt = $this->store()->table('attempts')->first();
            $this->assertSame('processing', $attempt->status);
            $this->assertNull($attempt->finished_at);
            $this->assertNull($this->store()->table('steps')->first()->finished_at);
            config()->set('current.stale_after_seconds', 0);
            $response = $this->get('/current/monitoring?status=processing&history=unfinished')->assertOk()->assertSee('BlockingJob')->assertSee('Finish not observed');
            $this->assertSame('processing', $response->viewData('jobs')->first()->display_status);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0, SIGKILL);
            }
        }
    }

    public function test_two_worker_processes_keep_execution_and_step_identity_separate(): void
    {
        Queue::push(new BlockingJob(1));
        Queue::push(new BlockingJob(1));
        $first = $this->worker();
        $second = $this->worker();
        try {
            $first->start();
            $second->start();
            $this->waitForStarts(2, [$first, $second]);
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $attempts = $this->store()->table('attempts')->get();
            $this->assertCount(2, $attempts);
            $this->assertCount(2, $attempts->pluck('worker_id')->unique());
            $this->assertSame(['completed', 'completed'], $attempts->pluck('status')->all());
            $this->assertCount(2, $this->store()->table('steps')->pluck('attempt_id')->unique());
        } finally {
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(0, SIGKILL);
                }
            }
        }
    }

    public function test_real_worker_timeout_is_observed_without_completing_open_step(): void
    {
        Queue::push(new BlockingJob(20));
        $process = $this->worker();
        try {
            $process->run();
        } catch (ProcessSignaledException $error) {
            $this->assertSame(SIGKILL, $process->getTermSignal());
        }
        $attempt = $this->store()->table('attempts')->first();
        $this->assertSame('failed', $attempt->status);
        $this->assertNotNull($attempt->finished_at);
        $this->assertSame(TimeoutExceededException::class, $attempt->exception_class);
        $this->assertNull($this->store()->table('steps')->first()->finished_at);
        $this->assertSame('failed', $this->store()->table('jobs')->first()->status);
    }
}
