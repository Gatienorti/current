@extends('horizon-lens::layout')
@section('title', 'Dashboard')
@section('content')
@php
    $number = fn ($group, $key) => $horizon[$group]['available'] ? number_format($horizon[$group]['data'][$key]) : 'Unavailable';
    $status = $horizon['masters']['available'] ? $horizon['masters']['data']['status'] : 'unknown';
    $processes = $horizon['supervisors']['available'] ? number_format(array_sum(array_column($horizon['supervisors']['data'], 'processes'))) : 'Unavailable';
    $wait = $horizon['wait']['available'] && $horizon['wait']['data'] ? reset($horizon['wait']['data']) : null;
    $waitQueue = $horizon['wait']['available'] ? array_key_first($horizon['wait']['data']) : null;
    $cards = [
        ['Jobs Per Minute', $number('metrics', 'rate'), 'Horizon reported processing rate', ''],
        [$horizon['recent_label'], $number('counts', 'recent'), 'Horizon retained recent-job count', ''],
        [$horizon['failed_label'], $number('counts', 'failed'), 'Horizon retained recent failures', ''],
        ['Status', ['running' => 'Active', 'paused' => 'Paused', 'inactive' => 'Inactive', 'unknown' => 'Unavailable'][$status], $status === 'inactive' ? 'No Horizon master reporting' : ($status === 'unknown' ? 'Master status could not be read' : $horizon['masters']['data']['paused'].' paused master(s)'), 'status-'.$status],
        ['Total Processes', $processes, 'Workers reported by Horizon supervisors', ''],
        ['Max Wait Time', !$horizon['wait']['available'] ? 'Unavailable' : ($wait === null ? '—' : number_format($wait, 0).'s'), $waitQueue ?? 'Estimated queue clearing time', ''],
        ['Max Runtime', !$horizon['metrics']['available'] ? 'Unavailable' : ($horizon['metrics']['data']['max_runtime'] ?: '—'), 'Queue with highest reported runtime', 'queue-metric'],
        ['Max Throughput', !$horizon['metrics']['available'] ? 'Unavailable' : ($horizon['metrics']['data']['max_throughput'] ?: '—'), 'Queue with highest reported throughput', 'queue-metric'],
    ];
@endphp
<div class="page-heading"><div><h1>Dashboard<span class="heading-dot">.</span></h1></div><a class="button secondary" href="{{ request()->fullUrl() }}">@include('horizon-lens::icon', ['iconName' => 'refresh']) Refresh</a></div>
<div data-live-region="dashboard">
<div class="horizon-stats-grid">
    @foreach($cards as [$label, $value, $caption, $style])
    <div class="horizon-stat {{ $style }}"><div class="stat-label">{{ $label }}</div><div class="horizon-stat-value">@if(str_starts_with($style, 'status-'))<span class="horizon-status-dot" aria-hidden="true"></span>@endif{{ $value }}</div><div class="stat-caption">{{ $caption }}</div></div>
    @endforeach
</div>
<section class="panel">
    <div class="panel-heading"><h2>Current workload</h2><span class="soft-badge">HORIZON DATA</span></div>
    @if(!$horizon['workload']['available'])
        <div class="empty-state compact"><h3>Queue workload is unavailable</h3><p>Horizon’s workload source could not be read. Queue lengths and worker allocations are unknown.</p></div>
    @else
    <div class="table-scroll"><table><thead><tr><th>QUEUE / GROUP</th><th>READY JOBS</th><th>WORKER PROCESSES</th><th>ESTIMATED WAIT</th><th></th></tr></thead><tbody>
    @forelse($horizon['workload']['data'] as $queue)
        <tr><td><span class="queue-name">{{ $queue['name'] }}</span></td><td class="mono">{{ number_format($queue['length']) }}</td><td class="mono">{{ number_format($queue['processes']) }}</td><td class="mono">{{ number_format($queue['wait']) }}s</td><td>@if(!$queue['split'])<a class="text-link" href="{{ route('horizon-lens.monitoring', ['queue' => $queue['name']]) }}">Inspect jobs @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a>@endif</td></tr>
        @foreach($queue['split'] as $part)<tr class="split-queue"><td>@include('horizon-lens::icon', ['iconName' => 'branch']) {{ $part['name'] }}</td><td class="mono">{{ number_format($part['length']) }}</td><td class="subtle">Shared above</td><td class="mono">{{ number_format($part['wait']) }}s</td><td><a class="text-link" href="{{ route('horizon-lens.monitoring', ['queue' => $part['name']]) }}">Inspect jobs @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a></td></tr>@endforeach
    @empty
        <tr><td colspan="5"><div class="empty-state compact"><h3>No queues reported by Horizon supervisors</h3><p>Queues and allocated workers appear here when Horizon supervisors report them. This does not prove that every application queue is empty.</p></div></td></tr>
    @endforelse
    </tbody></table></div>
    @endif
    <div class="panel-footnote">Horizon workload reports ready jobs and allocated worker processes. Wait is Horizon’s estimate of time to clear; shared queue groups have one process allocation.</div>
</section>
<section class="panel">
    <div class="panel-heading"><h2>Supervisors</h2><span class="subtle">Reported worker allocation</span></div>
    @if(!$horizon['supervisors']['available'])<div class="empty-state compact"><h3>Supervisor data is unavailable</h3><p>Worker process counts are unknown.</p></div>
    @else
    <div class="table-scroll"><table><thead><tr><th>SUPERVISOR / MASTER</th><th>STATUS</th><th>CONNECTION</th><th>QUEUES</th><th>PROCESSES</th><th>BALANCING</th></tr></thead><tbody>
    @forelse($horizon['supervisors']['data'] as $supervisor)
    <tr><td><strong>{{ $supervisor['name'] }}</strong><div class="job-uuid">{{ $supervisor['master'] }}</div></td><td>{{ ucfirst($supervisor['status']) }}</td><td>{{ $supervisor['connection'] }}</td><td>{{ $supervisor['queues'] }}</td><td class="mono">{{ number_format($supervisor['processes']) }}</td><td>{{ ucfirst($supervisor['balance']) }}</td></tr>
    @empty<tr><td colspan="6"><div class="empty-state compact"><h3>No supervisors reporting</h3><p>One-off queue workers can execute jobs without a Horizon master or supervisor appearing here.</p></div></td></tr>@endforelse
    </tbody></table></div>
    @endif
</section>
<div class="dashboard-source-heading"><span class="soft-badge">CURRENT HISTORY</span><span>Captured observations · {{ config('horizon-lens.retention_days', 7) }}-day retention · no backfill</span></div>
<div class="dashboard-grid">
    <section class="panel"><div class="panel-heading"><h2>Recorded queues</h2><a class="text-link" href="{{ route('horizon-lens.monitoring') }}">Monitoring @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a></div><div class="table-scroll"><table><thead><tr><th>QUEUE</th><th>RETAINED JOBS</th><th></th></tr></thead><tbody>@forelse($queueSummary as $queue)<tr><td><span class="queue-name">{{ $queue->queue }}</span></td><td class="mono">{{ $queue->jobs }}</td><td><a class="text-link" href="{{ route('horizon-lens.monitoring', ['queue' => $queue->queue]) }}">Inspect @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a></td></tr>@empty<tr><td colspan="3"><div class="empty-state compact"><p>No queues captured yet.</p></div></td></tr>@endforelse</tbody></table></div><div class="panel-footnote">Retained Current records, separate from the current queue backlog or worker allocation.</div></section>
    <section class="panel"><div class="panel-heading"><h2>Recent observations</h2><a class="text-link" href="{{ route('horizon-lens.recent') }}">Recent jobs @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a></div><div class="table-scroll"><table><thead><tr><th>JOB</th><th>STATUS</th><th>QUEUE</th></tr></thead><tbody>@forelse($jobs->take(5) as $job)<tr><td><a class="job-name" href="{{ route('horizon-lens.show', ['id' => $job->id, 'return' => $detailContext]) }}">{{ class_basename($job->job_class) }}</a><div class="job-uuid">{{ gmdate('M j, H:i:s', (int) $job->observed_at) }} UTC</div></td><td>@include('horizon-lens::status', ['status' => $job->display_status])@include('horizon-lens::history-indicator')</td><td><span class="queue-name">{{ $job->queue }}</span></td></tr>@empty<tr><td colspan="3"><div class="empty-state compact"><p>No observations captured yet.</p></div></td></tr>@endforelse</tbody></table></div></section>
</div>
<div class="coverage-note"><span>@include('horizon-lens::icon', ['iconName' => 'info'])</span><div><strong>Coverage matters.</strong> Horizon metrics use Horizon’s configured retention and snapshot semantics. Current history starts at collection; unobserved work remains unknown. @if(($counts['incomplete'] ?? 0) + ($counts['conflicting'] ?? 0))<a class="text-link" href="{{ route('horizon-lens.monitoring', ['status' => 'incomplete']) }}">Review incomplete observations @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a>@endif @if($health)<strong>A recorder failure was reported; some observations may be missing.</strong>@endif</div></div>
</div>
@endsection
