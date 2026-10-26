<?php

namespace Current\Console;

use Current\Telemetry\Health;
use Illuminate\Console\Command;

final class TelemetryStatusCommand extends Command
{
    protected $signature = 'current:telemetry-status';

    protected $description = 'Report async collector availability, backlog, lag and observation loss.';

    public function handle(Health $health): int
    {
        $status = $health->snapshot();
        $this->line(json_encode($status ?? ['driver' => 'sync'], JSON_THROW_ON_ERROR));

        return ($status['warning'] ?? false) ? self::FAILURE : self::SUCCESS;
    }
}
