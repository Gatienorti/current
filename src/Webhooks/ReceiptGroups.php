<?php

namespace HorizonLens\Webhooks;

use HorizonLens\Store;

final class ReceiptGroups
{
    public function __construct(private Store $store) {}

    public function listing($matches)
    {
        $now = microtime(true);
        $query = $this->store->table('webhook_receipts')
            ->where('expires_at', '>', $now)
            ->whereIn($this->store->connection()->raw('COALESCE(receipt_key, id)'), $matches->selectRaw('COALESCE(receipt_key, id)'))
            ->whereNotExists(function ($later) use ($now) {
                $later->selectRaw('1')->from('lens_webhook_receipts as later')
                    ->where('later.expires_at', '>', $now)
                    ->whereRaw('COALESCE(later.receipt_key, later.id) = COALESCE(lens_webhook_receipts.receipt_key, lens_webhook_receipts.id)')
                    ->whereRaw('(later.received_at > lens_webhook_receipts.received_at OR (later.received_at = lens_webhook_receipts.received_at AND later.id > lens_webhook_receipts.id))');
            })->select('lens_webhook_receipts.*');
        foreach (['receipt_count' => 'COUNT(*)', 'unfinished_count' => 'SUM(CASE WHEN finished_at IS NULL THEN 1 ELSE 0 END)', 'first_received' => 'MIN(received_at)', 'last_finished' => 'MAX(finished_at)', 'error_count' => 'SUM(CASE WHEN http_status >= 400 THEN 1 ELSE 0 END)'] as $name => $aggregate) {
            $query->selectSub(function ($group) use ($now, $aggregate) {
                $group->from('lens_webhook_receipts as grouped')->selectRaw($aggregate)->where('grouped.expires_at', '>', $now)
                    ->whereRaw('COALESCE(grouped.receipt_key, grouped.id) = COALESCE(lens_webhook_receipts.receipt_key, lens_webhook_receipts.id)');
            }, $name);
        }

        return $query;
    }

    public function receipts(object $receipt)
    {
        return $this->store->table('webhook_receipts')->where('expires_at', '>', microtime(true))
            ->when($receipt->receipt_key, fn ($query) => $query->where('receipt_key', $receipt->receipt_key), fn ($query) => $query->where('id', $receipt->id));
    }
}
