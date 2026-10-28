<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Current\Native\LocalInstances;
use Current\Native\Supervisor;
use Illuminate\Console\Command;
use Throwable;

final class ControlCommand extends Command
{
    protected $signature = 'current:control {action : pause, resume, restart or stop} {--instance= : One local instance} {--all : All application-local instances} {--pool= : One worker pool within each selected instance}';
    protected $description = 'Control existing native workers using their recorded ownership.';

    public function handle(LocalInstances $instances, Supervisor $supervisor): int
    {
        $original = config('current.native.instance');
        try {
            if (! ExecutionBackend::native()) { throw new \RuntimeException('Select the native execution backend first.'); }
            $action = $this->argument('action'); $pool = $this->option('pool');
            if (! in_array($action, ['pause', 'resume', 'restart', 'stop'], true) || ($pool !== null && ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $pool))) { throw new \RuntimeException('Invalid native control action or pool.'); }
            if ($action === 'stop' && $pool !== null) { throw new \RuntimeException('Stop targets an instance; use pause for an individual pool.'); }
            $accepted = 0; $failed = 0;
            foreach ($instances->names($this->option('all'), $this->option('instance')) as $name) {
                config()->set('current.native.instance', $name);
                try {
                    $health = $supervisor->snapshot();
                    if (! $health['available'] || in_array($health['state'], ['stale', 'stopped', 'failed'], true)
                        || ! $supervisor->control($health['owner'], $action, $pool)) { $failed++; continue; }
                    $accepted++;
                } catch (Throwable) { $failed++; }
            }
            $this->line('Control accepted for '.$accepted.' supervisor(s); '.$failed.' unconfirmed.');
            return $accepted > 0 && $failed === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) { $this->error($e instanceof \RuntimeException ? $e->getMessage() : 'Native control unavailable.'); return self::FAILURE; }
        finally { config()->set('current.native.instance', $original); }
    }
}
