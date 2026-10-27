<?php

namespace Current\Console;

use Current\Native\RuntimeSnapshot;
use Illuminate\Console\Command;
use Throwable;

/** Internal private-pipe endpoint; contains SQL configuration, never expose over HTTP. */
final class RuntimeSnapshotCommand extends Command
{
    protected $signature = 'current:runtime-snapshot';
    protected $description = 'Internal bounded native runtime observation.';
    protected $hidden = true;

    public function handle(RuntimeSnapshot $snapshot): int
    {
        try {
            $input = stream_get_contents(STDIN, 262145);
            if (strlen($input) > 262144) { return self::FAILURE; }
            $request = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($request['source'] ?? null) || ! is_array($request['routes'] ?? null)) { return self::FAILURE; }
            $snapshot->validateRoutes($request['routes']);
            config()->set('database.connections.current_runtime_read', $request['source']);
            config()->set('current.connection', 'current_runtime_read');
            $output = json_encode($snapshot->sample($request['routes']), JSON_THROW_ON_ERROR);
            if (strlen($output) > 262144) { return self::FAILURE; }
            $this->output->write($output, false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        } catch (Throwable) {
            return self::FAILURE; // Never print SQL errors or private configuration.
        }
    }
}
