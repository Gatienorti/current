<?php

namespace Current\Http;

use Current\ExecutionBackend;
use Current\Native\Metrics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

final class NativeMetricsController
{
    public function __invoke(Request $request, Metrics $metrics, string $metricKind)
    {
        abort_unless(ExecutionBackend::native(), 404);
        $filters = $request->validate([
            'job_class' => [Rule::requiredIf($metricKind === 'jobs'), 'nullable', 'string', 'max:240'],
            'connection' => [Rule::requiredIf($metricKind === 'queues'), 'nullable', 'string', 'max:100'],
            'queue' => [Rule::requiredIf($metricKind === 'queues'), 'nullable', 'string', 'max:100'],
            'range' => ['nullable', Rule::in(['1h', '24h'])],
        ]);
        $range = $filters['range'] ?? '1h';
        $dimensions = $metricKind === 'jobs' ? [$filters['job_class']] : [$filters['connection'], $filters['queue']];
        $selection = array_intersect_key($filters, array_flip($metricKind === 'jobs' ? ['job_class'] : ['connection', 'queue']));
        $available = true; $series = []; $now = time(); $hours = $range === '24h' ? 24 : 1;
        try { $series = $metrics->series($metricKind, $dimensions, $hours, $now); }
        catch (Throwable) { $available = false; }
        $waits = []; $waitAvailable = true; $waitStart = intdiv($now, 300) * 300 - $hours * 3600;
        if ($metricKind === 'queues') {
            try { $waits = app(\Current\Native\QueueSamples::class)->series($dimensions, $hours, $now, $metrics->resetAt()); }
            catch (Throwable) { $waitAvailable = false; }
        }
        return view('current::native-metrics', ['waits' => $waits, 'waitAvailable' => $waitAvailable, 'waitStart' => $waitStart, 'section' => 'metrics', 'metricKind' => $metricKind, 'range' => $range,
            'label' => implode(' / ', $dimensions), 'selection' => $selection, 'series' => $series, 'available' => $available]);
    }
}
