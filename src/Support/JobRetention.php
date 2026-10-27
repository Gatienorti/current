<?php

namespace Current\Support;

use Current\ExecutionBackend;

/** Snapshot one bounded retention decision at the first observation. */
final class JobRetention
{
    public static function seconds(array $payload = []): int
    {
        $cap = max(1, min(30, (int) config('current.max_retention_days', 30)));
        $days = max(1, min($cap, (int) config('current.retention_days', 7)));
        if (ExecutionBackend::native()) {
            $policies = config('current.native.tag_retention_days', []);
            $tags = app(Sanitizer::class)->tags(is_array($payload['tags'] ?? null) ? $payload['tags'] : []);
            if (is_array($policies)) {
                foreach (array_slice($policies, 0, 1000, true) as $tag => $retention) {
                    if (is_string($tag) && in_array($tag, $tags, true) && is_int($retention) && $retention >= 1) {
                        $days = max($days, min($retention, $cap));
                    }
                }
            }
        }
        return $days * 86400;
    }
}
