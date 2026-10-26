@extends('current::layout')
@section('title', 'Queues')
@section('content')
<div class="page-heading"><h1>Queues</h1><span class="soft-badge">{{ $environment }}</span></div>
@if(!$available)<p class="observation-notice">Horizon configuration is unavailable.</p>@endif
@if($partial)<p class="observation-notice">Some source files could not be inspected. Job assignments may be incomplete.</p>@endif
@foreach($supervisorTables as $supervisor)
<section class="panel supervisor-queues">
    <div class="panel-heading">
        <div><h2>{{ $supervisor['name'] }}</h2><span class="subtle">{{ $supervisor['connection'] }} · {{ ucfirst($supervisor['balance']) }} balancing</span></div>
        <div class="supervisor-allocation"><strong>{{ $supervisor['workers'] }}</strong>@if($supervisor['allocated'] !== null)<span class="subtle">Currently allocated: {{ $supervisor['allocated'] }}</span>@endif</div>
    </div>
    <div class="table-scroll"><table><thead><tr><th>Queue</th><th title="Static declarations and default routes. Dispatch-time overrides may change the destination.">Assigned job classes</th><th></th></tr></thead><tbody>
    @foreach($supervisor['queues'] as $queue)
    <tr><td><strong>{{ $queue['queue'] }}</strong></td><td>
        <div class="queue-class-list">@forelse($queue['classes'] as $job)
        <span class="queue-declared-class" title="{{ $job['class'] }} · {{ $job['source'] }}"><span>{{ class_basename($job['class']) }}</span>@if($job['default'])<span class="subtle">Default route</span>@endif</span>
        @empty<span class="subtle">No static assignment found</span>@endforelse</div>
    </td><td><a class="text-link" href="{{ route('current.monitoring', ['connection' => $queue['connection'], 'queue' => $queue['queue']]) }}">Inspect jobs →</a></td></tr>
    @endforeach
    </tbody></table></div>
</section>
@endforeach
@if(!$supervisorTables && $available)<section class="panel"><div class="empty-state"><h3>No supervisors configured for this environment</h3></div></section>@endif
@if($unmapped)
<section class="panel supervisor-queues"><div class="panel-heading"><h2>Other job routing</h2></div>
<div class="table-scroll"><table><thead><tr><th>Job class</th><th>Routing</th></tr></thead><tbody>
@foreach($unmapped as $job)<tr><td title="{{ $job['class'] }}">{{ class_basename($job['class']) }}</td><td>{{ $job['dynamic'] ? 'Dynamic / inherited — unresolved' : $job['connection'].' · '.implode(', ', $job['queues']) }}</td></tr>@endforeach
</tbody></table></div></section>
@endif
@endsection
