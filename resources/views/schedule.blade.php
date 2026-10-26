@extends('current::layout')
@section('title', 'Schedule')
@section('content')
@if(request()->header('X-Current-Refresh') !== '1')
<div class="page-heading"><div><h1>Schedule</h1></div></div>
@endif
<section class="panel schedule-panel" data-live-region="schedule">
    <div class="panel-heading"><h2>Scheduled tasks @if($available)<span class="count">{{ $tasks->total() }}</span>@endif</h2></div>
    @if(!$available)
        <div class="empty-state"><h3>Schedule unavailable</h3><p>The application's schedule could not be loaded.</p></div>
    @elseif($tasks->isEmpty())
        <div class="empty-state"><div class="empty-symbol">@include('current::icon', ['iconName' => 'calendar'])</div><h3>No scheduled tasks</h3></div>
    @else
        <div class="table-scroll"><table>
            <thead><tr><th>Task</th><th>Frequency</th><th>Timezone</th><th title="Based on the expression; runtime conditions may skip a task.">Next scheduled</th><th>Options</th></tr></thead>
            <tbody>@foreach($tasks as $task)
                <tr>
                    <td><strong>{{ $task['name'] }}</strong>@if($task['command'] && $task['command'] !== $task['name'])<div class="subtle"><code>{{ $task['command'] }}</code></div>@endif</td>
                    <td><span title="Cron: {{ $task['expression'] }}">{{ $task['frequency'] }}</span></td>
                    <td>{{ $task['timezone'] }}</td>
                    <td>{{ $task['next'] ?? 'Unavailable' }}</td>
                    <td>@forelse($task['flags'] as $flag)<div>{{ $flag }}</div>@empty @if(!$task['environments'])<span class="subtle">—</span>@endif @endforelse
                        @if($task['environments'])<div class="subtle">Environments: {{ implode(', ', $task['environments']) }}</div>@endif
                    </td>
                </tr>
            @endforeach</tbody>
        </table></div>
    @endif
    @if($tasks->hasPages())
    <div class="panel-heading"><span class="subtle">Page {{ $tasks->currentPage() }} of {{ $tasks->lastPage() }}</span><div>
        @if($tasks->previousPageUrl())<a class="text-link" href="{{ $tasks->previousPageUrl() }}">Previous</a>@endif
        @if($tasks->nextPageUrl())<a class="text-link" href="{{ $tasks->nextPageUrl() }}">Next →</a>@endif
    </div></div>
    @endif
</section>
@endsection
