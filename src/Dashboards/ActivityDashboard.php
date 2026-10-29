<?php

namespace Current\Dashboards;

use Current\Store;

final class ActivityDashboard
{
    public function __construct(private Store $store) {}

    public function stream(string $table, string $time, string $dimension, string $failure, float $from, float $to, int $buckets, ?string $groupKey = null, bool $authorization = false): array
    {
        $query = $this->store->table($table)->where('expires_at', '>', microtime(true))->where($time, '>=', $from)->where($time, '<', $to);
        $unfinished = $authorization ? '0' : 'CASE WHEN finished_at IS NULL THEN 1 ELSE 0 END';
        $duration = $authorization ? 'NULL' : 'AVG(duration_ms)';
        $aggregate = "COUNT(*) AS total, SUM(CASE WHEN {$failure} THEN 1 ELSE 0 END) AS failures, SUM({$unfinished}) AS unfinished, {$duration} AS duration";
        $stats = (clone $query)->selectRaw($aggregate)->first();
        $width = ($to - $from) / $buckets;
        $bucket = $this->store->connection()->getDriverName() === 'sqlite' ? "CAST(({$time} - ?) / ? AS INTEGER)" : "FLOOR(({$time} - ?) / ?)";
        $observed = (clone $query)->selectRaw($bucket.' AS bucket', [$from, $width])->selectRaw($aggregate)->groupBy('bucket')->orderBy('bucket')->get()->keyBy('bucket');
        $series = [];
        for ($i = 0; $i < $buckets; $i++) {
            $row = $observed->get($i);
            $series[] = ['at' => $from + $i * $width, 'total' => (int) ($row->total ?? 0), 'failures' => (int) ($row->failures ?? 0), 'duration' => $row?->duration];
        }
        $leaders = (clone $query)->select($dimension.' as label')->selectRaw($aggregate)->selectRaw("MAX({$time}) AS latest")->groupBy($dimension)->orderByDesc('total')->orderBy($dimension)->limit(10)->get();
        $recent = (clone $query)->whereRaw('('.$failure.')')->orderByDesc($time)->orderByDesc('id')->limit(5)->get();
        $repeated = $groupKey ? (clone $query)->whereNotNull($groupKey)->select($groupKey)->selectRaw('COUNT(*) AS total, MAX(id) AS id')->groupBy($groupKey)->havingRaw('COUNT(*) > 1')->orderByDesc('total')->orderByDesc('id')->limit(5)->get() : collect();

        return compact('stats', 'series', 'leaders', 'recent', 'repeated') + ['available' => true];
    }

    public function patterns(float $from, float $to): array
    {
        // Bound JSON decoding. SQL totals and event rankings use all retained rows in the window.
        $rows = $this->store->table('broadcasts')->where('expires_at', '>', microtime(true))->where('started_at', '>=', $from)->where('started_at', '<', $to)->orderByDesc('started_at')->orderByDesc('id')->limit(1000)->pluck('channels_json');
        $counts = [];
        foreach ($rows as $json) {
            foreach (array_unique(json_decode($json, true, 64, JSON_THROW_ON_ERROR)) as $pattern) {
                $counts[$pattern] = ($counts[$pattern] ?? 0) + 1;
            }
        }
        arsort($counts);

        return ['counts' => array_slice($counts, 0, 10, true), 'sample' => $rows->count()];
    }
}
