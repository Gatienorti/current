<?php

namespace Current\Tests;

use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\Process;

final class NativeHistoryFixtureTest extends TestCase
{
    public function test_cleanup_ignores_signal_race_only_after_confirmed_exit(): void
    {
        $this->loadHelpers();
        $child = new Process([PHP_BINARY, '-r', 'sleep(10);']); $child->start();
        $race = new class($child) {
            public bool $stopped = false;
            public function __construct(private Process $child) {}
            public function getPid(): ?int { return $this->child->getPid(); }
            public function isRunning(): bool { return $this->child->isRunning(); }
            public function signal(int $signal): void { $this->child->stop(0); throw new LogicException('Cannot send signal on a non running process.'); }
            public function stop(int $seconds, int $signal): void { $this->stopped = true; $this->child->stop($seconds, $signal); }
        };
        try {
            \stopMode([$race]);
            $this->assertTrue($race->stopped);
            $this->assertFalse($child->isRunning());
        } finally { $child->stop(0); }
    }

    public function test_cleanup_rethrows_signal_error_for_a_live_child_and_preserves_original_failure(): void
    {
        $this->loadHelpers();
        $child = new Process([PHP_BINARY, '-r', 'sleep(10);']); $child->start();
        $race = new class($child) {
            public function __construct(private Process $child) {}
            public function getPid(): ?int { return $this->child->getPid(); }
            public function isRunning(): bool { return $this->child->isRunning(); }
            public function getStatus(): string { return $this->child->getStatus(); }
            public function getExitCode(): ?int { return $this->child->getExitCode(); }
            public function signal(int $signal): void { throw new LogicException('Injected signal failure while still running.'); }
        };
        try {
            try { \stopMode([$race]); $this->fail('Ignored a live child signal failure.'); }
            catch (LogicException $e) { $this->assertSame('Injected signal failure while still running.', $e->getMessage()); }
            $this->assertTrue($child->isRunning());
            $original = new \RuntimeException('original fixture failure');
            try {
                try { throw $original; }
                finally { \stopModePreserving([$race], $original); }
            } catch (\Throwable $e) { $this->assertSame($original, $e); }
        } finally { $child->stop(0); }
    }

    private function loadHelpers(): void
    {
        if (! defined('CURRENT_HISTORY_FIXTURE_HELPERS_ONLY')) { define('CURRENT_HISTORY_FIXTURE_HELPERS_ONLY', true); }
        require_once __DIR__.'/native/fixtures/history.php';
    }
}
