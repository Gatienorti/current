@extends('current::layout')
@section('title', 'Dashboard')
@section('content')
@if(request()->header('X-Current-Refresh') !== '1')
<div class="page-heading"><h1>Dashboard</h1></div>
@endif
@if(session('current_control_notice'))<div role="status">{{ session('current_control_notice') }}</div>@endif
@php
$canControl = config('current.native.controls') === true && \Illuminate\Support\Facades\Gate::has('operateCurrent') && \Illuminate\Support\Facades\Gate::allows('operateCurrent') && $nativeHealth['available'] && !in_array($nativeHealth['state'], ['stale', 'stopping', 'stopped', 'failed'], true);
@endphp
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
@if($canControl)<td><form method="POST" action="{{ route('current.native.control') }}">@csrf<input type="hidden" name="owner" value="{{ $nativeHealth['owner'] }}"><input type="hidden" name="pool" value="{{ ($pool['group'] ?? '') ?: $pool['name'] }}@if($pool['queue'] ?? '')<div class="subtle">{{ $pool['queue'] }}</div>@endif"><button class="button secondary small" name="action" value="{{ in_array($pool['state'], ['paused', 'pausing'], true) ? 'resume' : 'pause' }}">{{ in_array($pool['state'], ['paused', 'pausing'], true) ? 'Resume' : 'Pause' }}</button>@if(!in_array($pool['state'], ['paused', 'pausing'], true)) <button class="button secondary small" name="action" value="restart">Restart</button>@endif</form></td>@endif</tr>
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
@forelse($nativeWorkload['rows'] as $row)<tr><td><div class="subtle">{{ $row['connection'] }}</div>{{ implode(', ', $row['queues']) }}</td><td>{{ $row['ready'] === null ? 'Unavailable' : number_format($row['ready']) }}</td><td>{{ $row['uncertain'] ? 'Unknown' : number_format($row['running']) }}</td><td title="{{ $row['reason'] }}">{{ $row['wait'] === null ? '—' : number_format($row['wait'], 1).'s' }}</td><td><a class="text-link" href="{{ route('current.monitoring', ['connection' => $row['connection'], 'queue' => $row['queues']]) }}">Inspect jobs @include('current::icon', ['iconName' => 'arrow-right'])</a></td></tr>
@empty<tr><td colspan="5">No recent queue allocations reported.</td></tr>@endforelse
</tbody></table></div>@endif
<div class="panel-footnote">Wait estimates use ready jobs and recent captured runtimes. @if($nativeWorkload['partial'])Worker inventory is incomplete.@endif</div>
</section>
@include('current::job-results')
@include('current::coverage')
@endsection
