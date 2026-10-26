@extends('horizon-lens::layout')
@section('title', 'Outgoing webhooks')
@section('content')
<div class="page-heading"><div><h1>Outgoing webhooks</h1></div><span class="soft-badge">PROOF OF CONCEPT</span></div>
@if(!config('horizon-lens.webhooks.outgoing.enabled'))<p class="observation-notice">Collection is disabled. Enable CURRENT_OUTGOING_WEBHOOKS_ENABLED and configure destination endpoints.</p>@endif
<section class="panel filters-panel compact-filters webhook-filters"><form class="filters" method="get" data-job-filters>
<label>Destination<input name="destination" placeholder="Any destination" value="{{ $filters['destination'] ?? '' }}"></label>
@include('horizon-lens::webhook-outcome-filter', ['incoming' => false])
@include('horizon-lens::time-range', ['timeCaption' => 'Attempt time'])
<div class="filter-actions"><button class="button primary">Apply filters</button><a class="text-link" href="{{ route('horizon-lens.webhooks.outgoing') }}">Reset</a></div>
<details class="additional-filters" @if(!empty($filters['event_id']) || !empty($filters['event_type']) || !empty($filters['status'])) open @endif>
<summary>@include('horizon-lens::icon', ['iconName' => 'chevron-right', 'iconClass' => 'more-filters-chevron'])More filters <span class="subtle">Event ID, event type, HTTP status</span></summary>
<div class="additional-filter-fields"><label>Event ID<input name="event_id" value="{{ $filters['event_id'] ?? '' }}" placeholder="Exact event ID"></label><label>Event type<input name="event_type" value="{{ $filters['event_type'] ?? '' }}" placeholder="Exact event type"></label><label>HTTP status<input name="status" type="number" min="100" max="599" value="{{ $filters['status'] ?? '' }}" placeholder="Any status"></label></div>
</details></form>@if($errors->any())<p class="validation-error">{{ $errors->first() }}</p>@endif</section>
<div data-live-region="outgoing-webhooks">
<section class="panel"><div class="panel-heading"><h2>Deliveries</h2><span class="subtle">Latest started first · UTC</span></div>
<div class="table-wrap"><table><thead><tr><th>Destination / endpoint</th><th>Latest response</th><th>Related job</th><th>Attempts / elapsed</th><th>Started · UTC</th></tr></thead><tbody>
@forelse($deliveries as $delivery)
<tr><td><a class="text-link" href="{{ route('horizon-lens.webhooks.outgoing.show', ['id' => $delivery->id]) }}">{{ $delivery->destination }}</a>@if($delivery->event_type)<span class="subtle"> · {{ $delivery->event_type }}</span>@endif<span class="subtle mono"> · {{ substr($delivery->id, -8) }}</span><div class="subtle mono">{{ $delivery->method }} {{ $delivery->endpoint }}</div></td><td><span class="status status-{{ $delivery->http_status >= 400 ? 'failed' : ($delivery->exception_class ? 'failed' : ($delivery->http_status ? 'completed' : 'pending')) }}">{{ $delivery->http_status ?? ($delivery->exception_class ? 'Transport error' : 'Not observed') }}</span></td><td>@if($delivery->job_id)<a class="text-link" href="{{ route('horizon-lens.show', ['id' => $delivery->job_id]) }}">View job ↗</a>@else<span class="subtle">Not linked</span>@endif</td><td>{{ $delivery->attempt_count }} {{ $delivery->attempt_count == 1 ? 'attempt' : 'attempts' }}<div class="subtle">{{ $delivery->unfinished_count ? 'Completion not observed for '.$delivery->unfinished_count : number_format(max(0, ($delivery->last_finished - $delivery->first_started) * 1000), 1).' ms elapsed' }}</div></td><td>{{ gmdate('M j, H:i:s', (int)$delivery->started_at) }}</td></tr>
@empty<tr><td colspan="5"><div class="empty-state compact"><h3>No outgoing attempts observed</h3><p>Only Laravel HTTP client requests matching configured destinations are captured.</p></div></td></tr>@endforelse
</tbody></table></div>
<div class="panel-footnote">Filters match any retained attempt; each row shows the latest started attempt of its delivery. Elapsed time spans retained observations, including retry delays. Missing completion remains unknown.</div></section>
<div class="pagination">@if($deliveries->previousPageUrl())<a class="button secondary" href="{{ $deliveries->previousPageUrl() }}">Previous</a>@endif @if($deliveries->nextPageUrl())<a class="button secondary" href="{{ $deliveries->nextPageUrl() }}">Next</a>@endif</div>
</div>
@endsection
