<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

final class PurgeCommand extends Command
{
    protected $signature = 'current:purge {--instance= : One local instance; defaults to configured instance} {--all : Inspect all instances in this application state directory} {--signal=TERM : TERM or KILL for proven orphan workers} {--wait=5 : Wait up to this many seconds for recorded workers to exit (0-300)}';
    protected $description = 'Clean up proven orphan native workers using private process identities and Linux pidfds.';

    public function handle(): int
    {
        try {
            if (! ExecutionBackend::native()) { throw new \RuntimeException('Select the native execution backend first.'); }
            $wait = filter_var($this->option('wait'), FILTER_VALIDATE_INT);
            $signal = $this->option('signal');
            if ($wait === false || $wait < 0 || $wait > 300 || ! in_array($signal, ['TERM', 'KILL'], true)
                || ($this->option('all') && $this->option('instance') !== null)) { throw new \RuntimeException('Choose one instance or --all, TERM or KILL, and --wait from 0 to 300 seconds.'); }
            $directory = config('current.native.state_directory');
            if (! is_string($directory) || ! str_starts_with($directory, '/') || str_contains($directory, "\0") || is_link($directory)
                || (file_exists($directory) && ! is_dir($directory)) || (is_dir($directory) && (fileperms($directory) & 0077))) { throw new \RuntimeException('Use the application private native state directory.'); }
            $binary = config('current.native.binary');
            if (! is_string($binary) || ! str_starts_with($binary, '/') || ! is_executable($binary)) { throw new \RuntimeException('Install the matching native supervisor binary before orphan cleanup.'); }
            if ($this->option('all')) {
                $files = is_dir($directory) ? glob(rtrim($directory, '/').'/*.json') : [];
                if ($files === false || count($files) > 256) { throw new \RuntimeException('Local supervisor discovery exceeds its bound.'); }
                $instances = array_map(fn ($file) => basename($file, '.json'), $files);
            } else { $instances = [$this->option('instance') ?? config('current.native.instance')]; }
            $failed = false; $count = 0;
            foreach ($instances as $instance) {
                if (! is_string($instance) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $instance)) {
                    if (! $this->option('all')) { throw new \RuntimeException('Invalid native instance name.'); }
                    continue;
                }
                if (! file_exists(rtrim($directory, '/').'/'.$instance.'.json')) { continue; }
                $count++;
                $process = new Process([$binary, '-purge', $directory, $instance, $signal, (string) $wait], base_path());
                $process->setTimeout($wait + 5)->run();
                $result = json_decode($process->getOutput(), true);
                if (! $process->isSuccessful() || ! is_array($result)) {
                    $sent = is_int($result['signaled'] ?? null) && $result['signaled'] >= 0 && $result['signaled'] <= 256
                        ? $result['signaled'].' proven orphan worker signal(s) sent; ' : '';
                    $this->warn($instance.': '.$sent.'cleanup unconfirmed; no unproven PID is signaled. Inspect private state and the outer process manager.');
                    $failed = true; continue;
                }
                if (($result['active'] ?? null) === true) { $this->line($instance.': active owner retained; no workers signaled.'); }
                elseif (is_int($result['signaled'] ?? null) && is_int($result['exited'] ?? null) && ($result['unconfirmed'] ?? null) === 0) {
                    $this->info($instance.': '.$result['signaled'].' proven orphan worker(s) signaled; recorded worker exits confirmed.');
                } else { $this->warn($instance.': cleanup response unconfirmed.'); $failed = true; }
            }
            if (! $count) { $this->line('No local supervisor records found.'); }
            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e instanceof \RuntimeException ? $e->getMessage() : 'Native orphan cleanup failed.');
            return self::FAILURE;
        }
    }
}
