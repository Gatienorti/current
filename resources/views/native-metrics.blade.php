@extends('current::layout')
@section('title', 'Metrics')
@section('content')
@if(request()->header('X-Current-Refresh') !== '1')
<div class="page-heading"><h1>{{ $metricKind === 'jobs' ? class_basename($label) : $label }}</h1><nav class="filter-actions" aria-label="Metric period">@foreach(['1h' => 'Last hour', '24h' => 'Last 24 hours'] as $value => $text)<a class="button {{ $range === $value ? 'primary' : 'secondary' }} small" href="{{ route('current.metrics.'.$metricKind.'.history', $selection + ['range' => $value]) }}" @if($range === $value) aria-current="page" @endif>{{ $text }}</a>@endforeach</nav></div>
@endif
<section class="panel" data-live-region="native-metrics">
<div class="panel-heading"><h2>Captured attempts</h2><a class="text-link" href="{{ route('current.monitoring', $selection) }}">Inspect jobs @include('current::icon', ['iconName' => 'arrow-right'])</a></div>
@if(!$available)
<div class="empty-state"><h3>Metric history unavailable</h3><p>Check Current migrations and history storage.</p></div>
@elseif(!array_filter($series, fn ($point) => $point['available']))
<div class="empty-state"><h3>No snapshots yet</h3><p>Keep Laravel's scheduler running or run <code>php artisan current:snapshot</code>.</p></div>
@else
@php
$max = max(1, max(array_map(fn ($point) => ($point['completed'] ?? 0) + ($point['failed'] ?? 0), $series)));
$maxDuration = max(1, max(array_column($series, 'duration')));
$width = 600 / count($series);
@endphp
<div class="activity-trend">
<div class="activity-legend"><span>Completed attempts</span><span class="failure-legend">Terminal failures</span></div>
<svg viewBox="0 0 600 130" role="img" aria-label="Captured completed and failed attempts in five-minute buckets; gaps are unsnapshotted intervals">
@foreach($series as $i => $point)
<g><title>{{ gmdate('M j H:i', $point['at']) }}{{ $point['partial'] ? ' (partial after reset)' : '' }} UTC: {{ $point['available'] ? $point['completed'].' completed, '.$point['failed'].' failed' : 'Snapshot unavailable' }}</title>
@if($point['available'])
<rect class="activity-bar" x="{{ $i*$width+1 }}" y="{{ 115-($point['completed']+$point['failed'])/$max*100 }}" width="{{ max(.5,$width-2) }}" height="{{ ($point['completed']+$point['failed'])/$max*100 }}" rx="1"/>
<rect class="activity-failed-bar" x="{{ $i*$width+1 }}" y="{{ 115-$point['failed']/$max*100 }}" width="{{ max(.5,$width-2) }}" height="{{ $point['failed']/$max*100 }}" rx="1"/>
@else<line x1="{{ $i*$width+1 }}" x2="{{ ($i+1)*$width-1 }}" y1="115" y2="115" stroke="currentColor" opacity=".3"/>@endif
</g>
@endforeach
</svg>
<div class="activity-axis"><span>{{ gmdate('M j H:i', $series[0]['at']) }}</span><span>5-minute windows · UTC</span><span>{{ gmdate('M j H:i', $series[count($series)-1]['at']+300) }}</span></div>
<div class="activity-legend" style="margin-top:16px"><span>Average runtime</span></div>
<svg viewBox="0 0 600 65" role="img" aria-label="Mean captured runtime, excluding missing or invalid timings">
@foreach($series as $i => $point)
@if($point['available'] && $point['duration'] !== null)<g><title>{{ gmdate('M j H:i', $point['at']) }} UTC: {{ number_format($point['duration'],1) }} ms</title><rect class="activity-bar" x="{{ $i*$width+1 }}" y="{{ 55-$point['duration']/$maxDuration*45 }}" width="{{ max(.5,$width-2) }}" height="{{ max(1,$point['duration']/$maxDuration*45) }}" rx="1"/></g>@endif
@endforeach
</svg>
<details><summary>Trend data</summary><div class="table-scroll"><table><thead><tr><th>Window · UTC</th><th>Completed</th><th>Failed</th><th>Mean runtime</th></tr></thead><tbody>
@foreach($series as $point)<tr><td>{{ gmdate('M j H:i', $point['at']) }}{{ $point['partial'] ? ' · partial after reset' : '' }}</td><td>{{ $point['completed'] ?? 'Unavailable' }}</td><td>{{ $point['failed'] ?? 'Unavailable' }}</td><td>{{ $point['duration'] !== null ? number_format($point['duration'],1).' ms' : '—' }}</td></tr>@endforeach
</tbody></table></div></details>
</div>
@endif
<div class="panel-footnote">Captured attempts only. Missing snapshots are gaps; zero means no captured finishes. The latest three closed windows are corrected for late telemetry.</div>
</section>
@if($metricKind === 'queues')
<section class="panel" data-live-region="native-queue-waits">
<div class="panel-heading"><h2>Estimated queue wait</h2></div>
@if(!$waitAvailable)
<div class="empty-state"><h3>Queue wait history unavailable</h3><p>Check Current migrations and history storage.</p></div>
@elseif(!$waits)
<div class="empty-state"><h3>No queue wait snapshots yet</h3></div>
@else
@php $maxWait = max(1, max(array_column($waits, 'wait'))); $start = $waitStart; $span = ($range === '24h' ? 24 : 1) * 3600 + 300; @endphp
<div class="activity-trend">
<svg viewBox="0 0 600 100" role="img" aria-label="Queue wait estimates at observation time; missing points are unknown">
@foreach($waits as $point)
@if($point['wait'] !== null)
<g><title>{{ gmdate('M j H:i', $point['observed_at']) }} UTC: {{ number_format($point['wait'], 1) }} seconds · {{ $point['ready'] }} ready · {{ $point['workers'] }} consuming workers</title><rect class="activity-bar" x="{{ ($point['at']-$start)/$span*600+1 }}" y="{{ 85-$point['wait']/$maxWait*75 }}" width="{{ max(1, 300/$span*600-2) }}" height="{{ max(1, $point['wait']/$maxWait*75) }}" rx="1"/></g>
@endif
@endforeach
</svg>
<details><summary>Queue wait data</summary><div class="table-scroll"><table><thead><tr><th>Observed · UTC</th><th>Ready</th><th>Consuming workers</th><th>Estimated wait</th></tr></thead><tbody>
@foreach($waits as $point)<tr><td>{{ gmdate('M j H:i:s', $point['observed_at']) }}</td><td>{{ $point['ready'] ?? 'Unknown' }}</td><td>{{ $point['workers'] ?? 'Unknown' }}</td><td>{{ $point['wait'] !== null ? number_format($point['wait'], 1).' s' : 'Unknown' }}</td></tr>@endforeach
</tbody></table></div></details>
</div>
@endif
<div class="panel-footnote">Point-in-time estimates use ready jobs, recent captured runtime and consuming workers. Priority queues can share the same capacity. Missing evidence remains unknown.</div>
</section>
@endif
@endsection
