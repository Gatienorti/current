<?php

namespace Current\Native;

use Symfony\Component\Process\Process;

/** Restarts only the child launcher this listener created, after it has drained. */
final class DeveloperListener
{
    private bool $stopping = false;
    private ?Process $process = null;

    public function stop(): void { $this->stopping = true; }

    public function run(array $command, string $directory, SourceWatcher $watcher, int $interval, float $grace, callable $output, callable $changed): int
    {
        $snapshot = $watcher->snapshot();
        try {
            $this->start($command, $directory);
            while (! $this->stopping) {
                $this->forward($output);
                if (! $this->process->isRunning()) { return $this->process->getExitCode() ?? 1; }
                usleep($interval * 1000);
                if ($this->stopping) { break; }
                $next = $watcher->snapshot();
                if ($next !== $snapshot) {
                    $changed();
                    $this->process->stop($grace, SIGTERM);
                    $this->forward($output);
                    if ($this->stopping) { break; }
                    // Capture edits made while draining, then start the new generation.
                    $snapshot = $watcher->snapshot();
                    $this->start($command, $directory);
                }
            }
            return 0;
        } finally {
            if ($this->process?->isRunning()) { $this->process->stop($grace, SIGTERM); }
            $this->forward($output);
        }
    }

    private function start(array $command, string $directory): void
    {
        $this->process = new Process($command, $directory);
        $this->process->setTimeout(null)->start();
    }

    private function forward(callable $output): void
    {
        if (! $this->process) { return; }
        $output($this->process->getIncrementalOutput()); $this->process->clearOutput();
        $output($this->process->getIncrementalErrorOutput()); $this->process->clearErrorOutput();
    }
}
