@extends('horizon-lens::layout')
@section('title', $area === 'webhooks' ? 'Webhook dashboard' : 'Broadcast dashboard')
@section('content')
@php
    $webhooks = $area === 'webhooks';
    $labels = $webhooks ? ['incoming' => 'Incoming receipts', 'outgoing' => 'Outgoing attempts'] : ['submissions' => 'Submissions', 'authorization' => 'Authorization failures'];
    $listRoutes = $webhooks ? ['incoming' => 'webhooks', 'outgoing' => 'webhooks.outgoing'] : ['submissions' => 'broadcasts', 'authorization' => 'broadcasts.authorization'];
    $dimensions = ['incoming' => 'provider', 'outgoing' => 'destination', 'submissions' => 'event', 'authorization' => 'route'];
    $number = fn ($key, $field) => $streams[$key]['available'] ? number_format($streams[$key]['stats']->$field ?? 0) : 'Unavailable';
    $duration = !$webhooks && $streams['submissions']['available'] ? $streams['submissions']['stats']->duration : null;
    $cards = $webhooks ? [
        ['Incoming receipts', $number('incoming', 'total')], ['Outgoing attempts', $number('outgoing', 'total')],
        ['Incoming failures', $number('incoming', 'failures')], ['Outgoing failures', $number('outgoing', 'failures')],
    ] : [
        ['Submissions', $number('submissions', 'total')], ['Failed submissions', $number('submissions', 'failures')],
        ['Average submission time', !$streams['submissions']['available'] ? 'Unavailable' : ($duration !== null ? number_format($duration, 1).' ms' : '—')],
        ['Authorization failures', $number('authorization', 'total')],
    ];
@endphp
<div class="page-heading"><h1>{{ $webhooks ? 'Webhook dashboard' : 'Broadcast dashboard' }}<span class="heading-dot">.</span></h1><nav class="filter-actions" aria-label="Dashboard period">@foreach(['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days'] as $value => $label)<a class="button {{ $range === $value ? 'primary' : 'secondary' }} small" @if($range === $value) aria-current="page" @endif href="{{ route('horizon-lens.'.$area.'.dashboard', ['range' => $value]) }}">{{ $label }}</a>@endforeach</nav></div>
<div data-live-region="activity-dashboard">
<div class="horizon-stats-grid">@foreach($cards as [$label, $value])<div class="horizon-stat"><div class="stat-label">{{ $label }}</div><div class="horizon-stat-value">{{ $value }}</div><div class="stat-caption">Retained observations in selected period</div></div>@endforeach</div>
<div class="dashboard-grid">
@foreach($streams as $key => $stream)
<section class="panel"><div class="panel-heading"><h2>{{ $labels[$key] }}</h2><a class="text-link" href="{{ route('horizon-lens.'.$listRoutes[$key], $window) }}">Inspect @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a></div>
@if(!$stream['available'])<div class="empty-state compact"><h3>History unavailable</h3><p>Observations could not be read. Counts are unknown.</p></div>
@else
@include('horizon-lens::activity-trend', ['series' => $stream['series'], 'trendLabel' => $labels[$key], 'authorization' => $key === 'authorization'])
@if($key !== 'authorization')<div class="panel-footnote">{{ number_format($stream['stats']->unfinished ?? 0) }} with no recorded completion. @if($key === 'submissions')<a class="text-link" href="{{ route('horizon-lens.broadcasts', $window + ['issue' => 'unfinished']) }}">Inspect unfinished</a>@else<a class="text-link" href="{{ route('horizon-lens.'.$listRoutes[$key], $window + ['outcome' => 'unfinished']) }}">Inspect unfinished</a>@endif</div>@endif
@endif
</section>
@endforeach
</div>
<div class="dashboard-grid">
@foreach($streams as $key => $stream)
<section class="panel"><div class="panel-heading"><h2>{{ match($key) { 'incoming' => 'Providers', 'outgoing' => 'Destinations', 'submissions' => 'Event names', default => 'Authorization routes' } }}</h2><span class="subtle">Top 10 by observed volume</span></div>
@if(!$stream['available'])<div class="context-body">History unavailable.</div>@else
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Observed</th><th>Failures</th><th>Latest · UTC</th></tr></thead><tbody>@forelse($stream['leaders'] as $leader)<tr><td><a class="text-link" href="{{ route('horizon-lens.'.$listRoutes[$key], $window + [$dimensions[$key] => $leader->label]) }}">{{ $leader->label }}</a></td><td>{{ number_format($leader->total) }}</td><td>{{ number_format($leader->failures) }}</td><td>{{ gmdate('M j H:i:s', (int)$leader->latest) }}</td></tr>@empty<tr><td colspan="4">No retained observations in this period.</td></tr>@endforelse</tbody></table></div>
@endif</section>
@endforeach
</div>
@if(!$webhooks)
<section class="panel"><div class="panel-heading"><h2>Approved channel patterns</h2><span class="subtle">Top 10 · latest {{ $patterns['sample'] ?? '—' }} of at most 1,000 submissions in period</span></div><div class="table-wrap"><table><thead><tr><th>Pattern</th><th>Observed submissions in sample</th></tr></thead><tbody>@if($patterns === null)<tr><td colspan="2">Channel history unavailable.</td></tr>@else @forelse($patterns['counts'] as $pattern => $count)<tr><td><a class="text-link mono" href="{{ route('horizon-lens.broadcasts', $window + ['channel' => $pattern]) }}">{{ $pattern }}</a></td><td>{{ number_format($count) }}</td></tr>@empty<tr><td colspan="2">No approved channel patterns observed in this period.</td></tr>@endforelse @endif</tbody></table></div><div class="panel-footnote">One submission may match several patterns. These counts are not connected clients or delivery acknowledgements.</div></section>
@endif
<div class="dashboard-grid">
@foreach($streams as $key => $stream)
<section class="panel"><div class="panel-heading"><h2>{{ match($key) { 'incoming' => 'Recent incoming failures', 'outgoing' => 'Recent outgoing failures', 'submissions' => 'Recent failed submissions', default => 'Recent authorization failures' } }}</h2></div>
@if(!$stream['available'])<div class="context-body">History unavailable.</div>@else
<div class="table-wrap"><table><thead><tr><th>Observation</th><th>Outcome</th><th>Time · UTC</th></tr></thead><tbody>
@forelse($stream['recent'] as $entry)
@php
    $name = match($key) { 'incoming' => $entry->provider, 'outgoing' => $entry->destination, 'submissions' => $entry->event, default => $entry->route_name };
    $href = $key === 'authorization' ? route('horizon-lens.broadcasts.authorization', $window + ['route' => $name, 'status' => $entry->http_status]) : route('horizon-lens.'.$listRoutes[$key].'.show', ['id' => $entry->id]);
    $at = match($key) { 'incoming' => $entry->received_at, 'authorization' => $entry->observed_at, default => $entry->started_at };
@endphp
<tr><td><a class="text-link" href="{{ $href }}">{{ $name }}</a><div class="subtle mono">{{ substr($entry->id, -8) }}</div></td><td><span class="status status-failed">{{ $key === 'submissions' ? 'Submission failed' : ($entry->http_status ?? 'Transport / handler error') }}</span>@if($key === 'incoming' && $entry->signature === 'invalid')<div class="subtle">Invalid signature</div>@endif @if($key !== 'authorization' && $entry->exception_class)<div class="subtle">{{ class_basename($entry->exception_class) }}</div>@endif</td><td>{{ gmdate('M j H:i:s', (int)$at) }}</td></tr>
@empty<tr><td colspan="3">No failures observed in this period.</td></tr>@endforelse
</tbody></table></div>@endif</section>
@endforeach
</div>
@if($webhooks)
<div class="dashboard-grid">@foreach(['incoming' => 'Repeated incoming events', 'outgoing' => 'Deliveries with multiple attempts'] as $key => $label)<section class="panel"><div class="panel-heading"><h2>{{ $label }}</h2></div>@if(!$streams[$key]['available'])<div class="context-body">History unavailable.</div>@else<div class="table-wrap"><table><thead><tr><th>Inspect history</th><th>{{ $key === 'incoming' ? 'Receipts' : 'Attempts' }} in period</th></tr></thead><tbody>@forelse($streams[$key]['repeated'] as $group)<tr><td><a class="text-link" href="{{ route('horizon-lens.'.$listRoutes[$key].'.show', ['id' => $group->id]) }}">{{ substr($group->id, -8) }}</a></td><td>{{ number_format($group->total) }}</td></tr>@empty<tr><td colspan="2">No repeated explicit groups observed in this period.</td></tr>@endforelse</tbody></table></div>@endif<div class="panel-footnote">Up to 5 explicit groups. Counts cover this period; detail history may include other retained observations. Repeats do not prove duplicate processing or eventual success.</div></section>@endforeach</div>
@endif
<section class="panel"><div class="panel-heading"><h2>Observation coverage</h2></div><div class="context-body">
@if($webhooks)<p>Incoming capture: {{ config('horizon-lens.enabled', true) && config('horizon-lens.webhooks.enabled') ? 'enabled for opted-in routes' : 'disabled' }}. Outgoing capture: {{ config('horizon-lens.enabled', true) && config('horizon-lens.webhooks.outgoing.enabled') ? 'enabled for configured destinations' : 'disabled' }}.</p><p>Counts represent HTTP receipts and delivery attempts, including repeats. Failures include HTTP errors, captured exceptions and invalid reported signatures; downstream job failures are separate. HTTP success is not business success.</p>
@else<p>Submission capture: {{ config('horizon-lens.enabled', true) && config('horizon-lens.broadcasts.enabled') ? 'enabled for configured events/channels' : 'disabled' }}. Authorization capture: {{ config('horizon-lens.enabled', true) && config('horizon-lens.broadcasts.authorization.enabled') ? 'enabled for configured auth routes' : 'disabled' }}.</p><p>Submission time measures the broadcaster call, not browser delivery latency. Authorization failures are separate HTTP observations. Acceptance does not prove client receipt; subscriber counts and server health are not measured.</p>@endif
<p>Only configured integrations and unexpired observations are included. Missing telemetry is unknown, and disabled collection does not remove retained history. Times are UTC; empty buckets mean no retained observations, not proven inactivity.</p></div></section>
</div>
@endsection
