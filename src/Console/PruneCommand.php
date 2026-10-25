<?php

namespace HorizonLens\Console;

use HorizonLens\Store;
use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'current:prune';

    protected $aliases = ['lens:prune'];

    protected $description = 'Delete expired Current history in bounded batches.';

    public function handle(Store $store): int
    {
        $count = 0;
        while ($removed = $store->prune()) {
            $count += $removed;
        }
        $this->info("Removed {$count} expired jobs and their attempts/steps.");

        return self::SUCCESS;
    }
}
