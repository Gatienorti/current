<?php

namespace Current\Native;

use Symfony\Component\Process\Process;
use Throwable;

/** Nonblocking launcher task. Go bounds execution and reaps the PHP process group. */
final class RuntimeSampler
{
    private ?Process $process = null;
    private float $next = 0;
    private string $output = '';
    private array $routes = [];

    public function tick(array $pools): void
    {
        try {
            if ($this->process) {
                $running = $this->process->isRunning();
                $this->output .= $this->process->getIncrementalOutput();
                $this->process->clearOutput();
                $errors = $this->process->getIncrementalErrorOutput();
                $this->process->clearErrorOutput();
                if (strlen($this->output) > 262144 || strlen($errors) > 16384) { $this->stop(); return; }
                $this->process->checkTimeout();
                if ($running) { return; }
                $success = $this->process->isSuccessful();
                $this->process = null;
                if ($success) { app(RuntimeSnapshot::class)->publish(json_decode($this->output, true, 32, JSON_THROW_ON_ERROR), $this->routes); }
                return;
            }
            if (microtime(true) < $this->next || ! $pools) { return; }
            $this->next = microtime(true) + 10;
            $snapshot = app(RuntimeSnapshot::class);
            $this->routes = $snapshot->routes($pools);
            $snapshot->validateRoutes($this->routes);
            $input = json_encode(['source' => $snapshot->source(), 'routes' => $this->routes], JSON_THROW_ON_ERROR);
            if (strlen($input) > 262144) { return; }
            $this->output = '';
            $this->process = new Process([config('current.native.binary'), '-runtime-sample', PHP_BINARY, base_path('artisan')], base_path());
            $this->process->setInput($input)->setTimeout(5)->start();
        } catch (Throwable) {
            $this->stop(); // Keep existing values until their short TTL; never invent zero runtimes.
        }
    }

    public function stop(): void
    {
        if ($this->process?->isRunning()) { $this->process->stop(0.2, SIGTERM); }
        $this->process = null;
        $this->output = '';
    }
}
