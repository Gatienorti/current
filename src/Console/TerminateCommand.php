<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Current\Native\Supervisor;
use Illuminate\Console\Command;
use Throwable;

/** Deployment shutdown reads existing ownership; it must not validate new worker plans. */
final class TerminateCommand extends Command
{
    protected $signature = 'current:terminate {--instance= : One local instance; defaults to configured instance} {--all : All instances in this application state directory} {--wait=0 : Wait up to this many seconds for confirmed shutdown (0-86430)}';
    protected $description = 'Request graceful shutdown of existing local Current supervisors.';

    public function handle(Supervisor $supervisor): int
    {
        $original = config('current.native.instance');
        try {
            if (! ExecutionBackend::native()) { throw new \RuntimeException('Select the native execution backend first.'); }
            $wait = filter_var($this->option('wait'), FILTER_VALIDATE_INT);
            if ($wait === false || $wait < 0 || $wait > \Current\Native\Plan::MAX_GRACE_SECONDS || ($this->option('all') && $this->option('instance') !== null)) {
                throw new \RuntimeException('Choose one instance or --all, with --wait between 0 and 86430 seconds.');
            }
            $directory = config('current.native.state_directory');
            if (! is_string($directory) || ! str_starts_with($directory, '/') || str_contains($directory, "\0") || is_link($directory)
                || (file_exists($directory) && ! is_dir($directory)) || (is_dir($directory) && (fileperms($directory) & 0077))) {
                throw new \RuntimeException('Use the application private native state directory.');
            }
            if ($this->option('all')) {
                $files = is_dir($directory) ? glob(rtrim($directory, '/').'/*.json') : [];
                if ($files === false || count($files) > 256) { throw new \RuntimeException('Local supervisor discovery exceeds its bound.'); }
                $instances = array_map(fn ($file) => basename($file, '.json'), $files);
            } else { $instances = [$this->option('instance') ?? $original]; }
            $targets = []; $unconfirmed = 0;
            foreach ($instances as $instance) {
                if (! is_string($instance) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $instance)) {
                    if (! $this->option('all')) { throw new \RuntimeException('Invalid native instance name.'); }
                    continue;
                }
                config()->set('current.native.instance', $instance);
                $health = $supervisor->snapshot();
                if (! $health['available']) {
                    // Missing/corrupt records are never advertised as confirmed stopped.
                    if (is_file(rtrim($directory, '/').'/'.$instance.'.json')) { $unconfirmed++; }
                    continue;
                }
                if ($this->stopped($health)) { continue; }
                try {
                    if (! $supervisor->control($health['owner'], 'stop', null)) { $unconfirmed++; continue; }
                    $targets[$instance] = $health['owner'];
                } catch (Throwable) { $unconfirmed++; }
            }
            if (! $targets) {
                $this->line($unconfirmed ? 'Shutdown could not be confirmed for existing supervisor records.' : 'No active local supervisor records found.');
                return $unconfirmed ? self::FAILURE : self::SUCCESS;
            }
            $this->info('Shutdown accepted for '.count($targets).' local supervisor(s).');
            if ($wait > 0) {
                $deadline = microtime(true) + $wait;
                do {
                    foreach ($targets as $instance => $owner) {
                        config()->set('current.native.instance', $instance);
                        $health = $supervisor->snapshot();
                        if ($health['available'] && $health['owner'] === $owner && $this->stopped($health)) { unset($targets[$instance]); }
                    }
                    if (! $targets) { break; }
                    usleep(100000);
                } while (microtime(true) < $deadline);
                if ($targets) { $unconfirmed += count($targets); }
                else { $this->info('Targeted supervisor shutdown confirmed.'); }
            }
            if ($unconfirmed) { $this->warn('Some shutdown outcomes remain unconfirmed; inspect current supervisor status.'); }
            return $unconfirmed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e instanceof \RuntimeException ? $e->getMessage() : 'Native shutdown could not be requested.');
            return self::FAILURE;
        } finally { config()->set('current.native.instance', $original); }
    }

    private function stopped(array $health): bool
    {
        return in_array($health['state'], ['stopped', 'failed'], true)
            && ! array_filter($health['pools'], fn ($pool) => $pool['running'] === null || $pool['running'] > 0);
    }
}
