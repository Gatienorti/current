<?php
namespace SupervisorExperiment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;

final class FixtureJob implements ShouldQueue
{
    use Queueable;
    public ?int $timeout = null;
    public function __construct(public string $id, public int $micros = 5000, public string $scenario = 'ok') {}
    public function currentArguments(): array { return ['scenario' => $this->scenario, 'token' => 'fixture-secret']; }
    public function currentMetadata(): array { return ['fixture' => true]; }
    public function handle(): void
    {
        if (getenv('CURRENT_SUPERVISOR_HISTORY') === '1') {
            \Current\Current::step('Execute fixture', fn () => $this->execute());
        } else {
            $this->execute();
        }
    }
    private function execute(): void
    {
        $r = Redis::connection();
        $attempt = $this->attempts();
        $r->hset('started', $this->id, json_encode(['pid' => getmypid(), 'attempt' => $attempt, 'time' => microtime(true)]));
        $r->hincrby('attempts', $this->id, 1);
        if ($this->scenario === 'retry' && $attempt === 1) { throw new \RuntimeException('Synthetic first-attempt failure'); }
        if ($this->scenario === 'release' && $attempt === 1) { $this->release(1); return; }
        if ($this->scenario === 'crash' && $r->setnx('crashed:'.$this->id, 1)) { posix_kill(getmypid(), SIGKILL); }
        if ($this->scenario === 'timeout' && $attempt === 1) { $this->sleepFor(5_000_000); }
        if ($this->scenario === 'fail') { throw new \RuntimeException('Synthetic terminal failure'); }
        $this->sleepFor($this->micros);
        $r->hincrby('completed', $this->id, 1);
        $r->hset('finished', $this->id, microtime(true));
    }
    private function sleepFor(int $micros): void
    {
        $until = hrtime(true) + $micros * 1000;
        while (($remaining = $until - hrtime(true)) > 0) { usleep((int) min(50000, ceil($remaining / 1000))); }
    }
    public function failed(?\Throwable $error): void { Redis::hset('terminal', $this->id, $this->attempts()); }
}

final class BatchFixtureJob implements ShouldQueue
{
    use Queueable, \Illuminate\Bus\Batchable;
    public int $tries = 1;
    public function __construct(public string $label) {}
    public function handle(): void
    {
        if (Redis::setnx('batch-first:'.$this->label, 1)) { throw new \RuntimeException('Synthetic batch failure'); }
        Redis::hincrby('batch-success', $this->label, 1);
    }
}
