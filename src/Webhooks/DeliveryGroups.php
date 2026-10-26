<?php

namespace Current\Webhooks;

use Current\Store;

final class DeliveryGroups
{
    public function __construct(private Store $store) {}

    public function listing($matches)
    {
        $now = microtime(true);
        $query = $this->store->table('webhook_deliveries')
            ->where('expires_at', '>', $now)
            ->whereIn($this->store->connection()->raw('COALESCE(delivery_key, id)'), $matches->selectRaw('COALESCE(delivery_key, id)'))
            ->whereNotExists(function ($later) use ($now) {
                $later->selectRaw('1')->from('lens_webhook_deliveries as later')
                    ->where('later.expires_at', '>', $now)
                    ->whereRaw('COALESCE(later.delivery_key, later.id) = COALESCE(lens_webhook_deliveries.delivery_key, lens_webhook_deliveries.id)')
                    ->whereRaw('(later.started_at > lens_webhook_deliveries.started_at OR (later.started_at = lens_webhook_deliveries.started_at AND later.id > lens_webhook_deliveries.id))');
            })->select('lens_webhook_deliveries.*');
        foreach (['attempt_count' => 'COUNT(*)', 'unfinished_count' => 'SUM(CASE WHEN finished_at IS NULL THEN 1 ELSE 0 END)', 'first_started' => 'MIN(started_at)', 'last_finished' => 'MAX(finished_at)'] as $name => $aggregate) {
            $query->selectSub(function ($group) use ($now, $aggregate) {
                $group->from('lens_webhook_deliveries as grouped')->selectRaw($aggregate)->where('grouped.expires_at', '>', $now)
                    ->whereRaw('COALESCE(grouped.delivery_key, grouped.id) = COALESCE(lens_webhook_deliveries.delivery_key, lens_webhook_deliveries.id)');
            }, $name);
        }

        return $query;
    }

    public function attempts(object $delivery)
    {
        return $this->store->table('webhook_deliveries')->where('expires_at', '>', microtime(true))
            ->when($delivery->delivery_key, fn ($query) => $query->where('delivery_key', $delivery->delivery_key), fn ($query) => $query->where('id', $delivery->id));
    }
}
