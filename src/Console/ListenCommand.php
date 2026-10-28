<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Current\Native\DeveloperListener;
use Current\Native\SourceWatcher;
use Illuminate\Console\Command;
use Throwable;

final class ListenCommand extends Command
{
    protected $signature = 'current:listen {--instance= : Override the configured unique instance name} {--environment= : Select native provisioning environment} {--interval=1000 : Polling interval in milliseconds (100-60000)}';
    protected $description = 'Run the native supervisor and restart its workers when application source changes.';

    public function handle(DeveloperListener $listener): int
    {
        try {
            if (! ExecutionBackend::native()) { throw new \RuntimeException('Select the native execution backend first.'); }
            $interval = filter_var($this->option('interval'), FILTER_VALIDATE_INT);
            if ($interval === false || $interval < 100 || $interval > 60000) { throw new \RuntimeException('Choose a watch interval from 100 to 60000 milliseconds.'); }
            $paths = config('current.native.watch', ['app', 'bootstrap', 'config', 'database', 'routes', 'composer.json', 'composer.lock', '.env']);
            if (! is_array($paths)) { throw new \RuntimeException('Configure native.watch as relative files or directories.'); }
            $watcher = new SourceWatcher(base_path(), $paths);
            $command = [PHP_BINARY, base_path('artisan'), 'current:supervise', '--no-interaction'];
            if ($instance = $this->option('instance')) {
                if (! is_string($instance) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $instance)) { throw new \RuntimeException('Invalid native instance name.'); }
                $command[] = '--instance='.$instance;
            }
            if (($selected = $this->option('environment')) !== null) { $command[] = '--environment='.$selected; }
            if (($environment = $this->option('env')) !== null) { $command[] = '--env='.$environment; }
            $grace = config('current.native.grace_seconds', 90);
            if (! is_int($grace) || $grace < 1 || $grace > \Current\Native\Plan::MAX_GRACE_SECONDS) { throw new \RuntimeException('Configure a valid native grace period before watching.'); }
            $this->trap([SIGINT, SIGTERM, SIGQUIT], fn () => $listener->stop());
            $this->info('Watching application source with bounded polling.');

            return $listener->run($command, base_path(), $watcher, $interval, $grace + 2,
                fn ($buffer) => $this->output->write($buffer), fn () => $this->info('Source changed. Draining and restarting native workers...'));
        } catch (Throwable $e) {
            $this->error($e instanceof \RuntimeException ? $e->getMessage() : 'Native development listener failed.');
            return self::FAILURE;
        }
    }
}
