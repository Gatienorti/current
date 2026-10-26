<?php

namespace Current\Console;

use Current\Broadcasts\AuthorizationRecorder;
use Current\Broadcasts\BroadcastRecorder;
use Current\Store;
use Current\Webhooks\OutgoingRecorder;
use Current\Webhooks\WebhookRecorder;
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

        foreach (['async_receipts' => 'id', 'async_versions' => 'entity'] as $table => $key) {
            if (! $store->connection()->getSchemaBuilder()->hasTable('lens_'.$table)) {
                continue;
            }
            do {
                $keys = $store->table($table)->where('expires_at', '<=', microtime(true))->limit(500)->pluck($key);
                if ($keys->isNotEmpty()) {
                    $store->table($table)->whereIn($key, $keys)->delete();
                }
            } while ($keys->isNotEmpty());
        }

        $receipts = 0;
        while ($removed = app(WebhookRecorder::class)->prune()) {
            $receipts += $removed;
        }
        $this->info("Removed {$receipts} expired webhook receipts and their links.");

        $deliveries = 0;
        while ($removed = app(OutgoingRecorder::class)->prune()) {
            $deliveries += $removed;
        }
        $this->info("Removed {$deliveries} expired outgoing webhook attempts.");

        $broadcasts = 0;
        while ($removed = app(BroadcastRecorder::class)->prune()) {
            $broadcasts += $removed;
        }
        $this->info("Removed {$broadcasts} expired broadcast submissions.");

        $authorizations = 0;
        while ($removed = app(AuthorizationRecorder::class)->prune()) {
            $authorizations += $removed;
        }
        $this->info("Removed {$authorizations} expired broadcast authorization failures.");

        return self::SUCCESS;
    }
}
