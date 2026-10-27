@extends('current::layout')
@section('title', 'Dashboard')
@section('content')
@if(request()->header('X-Current-Refresh') !== '1')
<div class="page-heading"><h1>Dashboard</h1></div>
@endif
@if(session('current_control_notice'))<div role="status">{{ session('current_control_notice') }}</div>@endif
@php
$canControl = config('current.native.controls') === true && \Illuminate\Support\Facades\Gate::has('operateCurrent') && \Illuminate\Support\Facades\Gate::allows('operateCurrent') && $nativeHealth['available'] && !in_array($nativeHealth['state'], ['stale', 'stopping', 'stopped', 'failed'], true);
$summaryMetrics = $nativeSummary['metrics']; $summaryCounts = $nativeSummary['counts'];
$summaryInventory = $nativeSummary['inventory']; $summaryWait = $nativeSummary['wait'];
$summaryRuntime = $summaryMetrics['data']['max_runtime'] ?? null;
$summaryThroughput = $summaryMetrics['data']['max_throughput'] ?? null;
$metricPeriod = ($summaryMetrics['data']['reset'] ?? false) ? 'since metric reset' : 'past hour';
$queueLabel = fn ($row) => $row ? $row['connection'].' / '.$row['queue'] : '—';
$summaryCards = [
    ['Jobs Per Minute', $summaryMetrics['available'] ? number_format($summaryMetrics['data']['rate'], 1) : 'Unavailable', 'Captured completed attempts / minute'.(($summaryMetrics['data']['rate_seconds'] ?? 60) < 60 ? ' · since metric reset' : ''), ''],
    ['Jobs Past Hour', $summaryCounts['available'] ? number_format($summaryCounts['data']['recent']) : 'Unavailable', 'First captured observations · retained history', ''],
    ['Failed Jobs Past 7 Days', $summaryCounts['available'] ? number_format($summaryCounts['data']['failed']) : 'Unavailable', 'Retained terminal failures · observed in past 7 days', ''],
    ['Status', ['running' => 'Active', 'unknown' => 'Unknown', 'inactive' => 'Inactive', 'failed' => 'Failed', 'paused' => 'Paused', 'degraded' => 'Degraded', 'stopping' => 'Stopping', 'starting' => 'Starting', 'pausing' => 'Pausing'][$summaryInventory['state']], $summaryInventory['available'] ? $summaryInventory['paused'].' paused / '.$summaryInventory['instances'].' '.$summaryInventory['scope'] : 'Worker inventory is incomplete or stale', 'status-'.$summaryInventory['state']],
    ['Total Processes', $summaryInventory['processes'] === null ? 'Unknown' : number_format($summaryInventory['processes']), $summaryInventory['available'] ? $summaryInventory['allocated'].' allocated across '.$summaryInventory['scope'] : 'A complete live process count is unavailable', ''],
    ['Max Wait Time', $summaryWait['available'] ? ($summaryWait['value'] === null ? '—' : number_format($summaryWait['value'], 1).'s') : 'Unavailable', $summaryWait['queue'] ?? $summaryWait['reason'], ''],
    ['Max Runtime', $summaryMetrics['available'] ? $queueLabel($summaryRuntime) : 'Unavailable', $summaryRuntime ? number_format($summaryRuntime['runtime_ms'], 1).' ms mean captured runtime · '.$metricPeriod : 'Queue with highest captured mean runtime · '.$metricPeriod, 'queue-metric'],
    ['Max Throughput', $summaryMetrics['available'] ? $queueLabel($summaryThroughput) : 'Unavailable', $summaryThroughput ? number_format($summaryThroughput['completed']).' captured completed attempts · '.$metricPeriod : 'Queue with most captured completed attempts · '.$metricPeriod, 'queue-metric'],
];
@endphp
<div class="horizon-stats-grid" data-live-region="native-summary">
@foreach($summaryCards as [$label, $value, $caption, $style])
<div class="horizon-stat {{ $style }}"><div class="stat-label">{{ $label }}</div><div class="horizon-stat-value">@if(str_starts_with($style, 'status-'))<span class="horizon-status-dot" aria-hidden="true"></span>@endif{{ $value }}</div><div class="stat-caption" title="{{ $caption }}">{{ $caption }}</div></div>
@endforeach
</div>
<div data-live-region="native-status">
@if($nativeHealth['available'] || !$nativeRegistry['instances'])
<section class="panel">
@if(!$nativeHealth['available'])
<div class="empty-state"><h3>Worker status unavailable</h3><p>No valid native supervisor snapshot is available.</p></div>
@else
<div class="panel-heading"><h2>Supervisor · {{ $nativeHealth['instance'] }}</h2><span>{{ ucfirst($nativeHealth['state']) }}</span></div>
<div class="table-scroll"><table><thead><tr><th>Pool</th><th>State</th><th>Live processes</th><th>Allocation</th><th>Limits</th>@if($canControl)<th>Control</th>@endif</tr></thead><tbody>
@foreach($nativeHealth['pools'] as $pool)
<tr><td>{{ ($pool['group'] ?? '') ?: $pool['name'] }}@if($pool['queue'] ?? '')<div class="subtle">{{ $pool['queue'] }}</div>@endif</td><td>{{ ucfirst($pool['state']) }}</td><td>{{ $pool['running'] ?? 'Unknown' }}</td><td>{{ $pool['workers'] }}</td><td>{{ $pool['min'] }}–{{ $pool['max'] }}</td>
@if($canControl)<td><form method="POST" action="{{ route('current.native.control') }}">@csrf<input type="hidden" name="owner" value="{{ $nativeHealth['owner'] }}"><input type="hidden" name="pool" value="{{ ($pool['group'] ?? '') ?: $pool['name'] }}"><button class="button secondary small" name="action" value="{{ in_array($pool['state'], ['paused', 'pausing'], true) ? 'resume' : 'pause' }}">{{ in_array($pool['state'], ['paused', 'pausing'], true) ? 'Resume' : 'Pause' }}</button>@if(!in_array($pool['state'], ['paused', 'pausing'], true)) <button class="button secondary small" name="action" value="restart">Restart</button>@endif</form></td>@endif</tr>
@endforeach
</tbody></table></div>

@endif
</section>
@endif
</div>
@include('current::native-inventory')
<section class="panel" data-live-region="native-workload">
<div class="panel-heading"><h2>Current workload</h2></div>
@if(!$nativeWorkload['available'])<div class="empty-state compact"><h3>Queue workload unavailable</h3></div>
@else<div class="table-scroll"><table><thead><tr><th>Connection / queue</th><th>Ready jobs</th><th>Worker processes</th><th>Estimated wait</th><th></th></tr></thead><tbody>
@forelse($nativeWorkload['rows'] as $row)<tr><td><div class="subtle">{{ $row['connection'] }}</div>{{ implode(', ', $row['queues']) }}</td><td>{{ $row['ready'] === null ? 'Unavailable' : number_format($row['ready']) }}</td><td>{{ $row['uncertain'] ? 'Unknown' : number_format($row['running']) }}@if(!$row['uncertain'] && $row['consuming'] !== $row['running'])<div class="subtle">{{ $row['consuming'] }} consuming</div>@endif</td><td title="{{ $row['reason'] }}">{{ $row['wait'] === null ? '—' : number_format($row['wait'], 1).'s' }}</td><td><a class="text-link" href="{{ route('current.monitoring', ['connection' => $row['connection'], 'queue' => $row['queues']]) }}">Inspect jobs @include('current::icon', ['iconName' => 'arrow-right'])</a></td></tr>
@empty<tr><td colspan="5">No recent queue allocations reported.</td></tr>@endforelse
</tbody></table></div>@endif
<div class="panel-footnote">Wait estimates use ready jobs and recent captured runtimes. @if($nativeWorkload['partial'])Worker inventory is incomplete.@endif</div>
</section>
@include('current::job-results')
@include('current::coverage')
@endsection
