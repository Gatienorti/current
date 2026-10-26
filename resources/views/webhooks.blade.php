@extends('horizon-lens::layout')
@section('title', 'Incoming webhooks')
@section('content')
<div class="page-heading"><div><h1>Incoming webhooks</h1></div><span class="soft-badge">PROOF OF CONCEPT</span></div>
@if(!config('horizon-lens.webhooks.enabled'))<p class="observation-notice">Collection is disabled. Enable CURRENT_WEBHOOKS_ENABLED and opt in individual routes with current.webhook middleware.</p>@endif
<section class="panel filters-panel compact-filters webhook-filters"><form class="filters" method="get" data-job-filters>
<label>Provider<input name="provider" placeholder="Any provider" value="{{ $filters['provider'] ?? '' }}"></label>
@include('horizon-lens::webhook-outcome-filter', ['incoming' => true])
@include('horizon-lens::time-range', ['timeCaption' => 'Receipt time'])
<div class="filter-actions"><button class="button primary">Apply filters</button><a class="text-link" href="{{ route('horizon-lens.webhooks') }}">Reset</a></div>
<details class="additional-filters" @if(!empty($filters['event_id']) || !empty($filters['event_type']) || !empty($filters['status'])) open @endif>
<summary>@include('horizon-lens::icon', ['iconName' => 'chevron-right', 'iconClass' => 'more-filters-chevron'])More filters <span class="subtle">Event ID, event type, HTTP status</span></summary>
<div class="additional-filter-fields"><label>Event ID<input name="event_id" value="{{ $filters['event_id'] ?? '' }}" placeholder="Exact event ID"></label><label>Event type<input name="event_type" value="{{ $filters['event_type'] ?? '' }}" placeholder="Exact event type"></label><label>HTTP status<input name="status" type="number" min="100" max="599" value="{{ $filters['status'] ?? '' }}" placeholder="Any status"></label></div>
</details></form>@if($errors->any())<p class="validation-error">{{ $errors->first() }}</p>@endif</section>
<div data-live-region="webhooks">
<section class="panel"><div class="panel-heading"><h2>Receipts</h2><span class="subtle">Latest received first · UTC</span></div>
<div class="table-wrap"><table><thead><tr><th>Provider / route</th><th>Receipts</th><th>Latest HTTP response</th><th>Signature</th><th>Duration</th><th>Received · UTC</th></tr></thead><tbody>
@forelse($receipts as $receipt)
<tr><td><a class="text-link" href="{{ route('horizon-lens.webhooks.show', ['id' => $receipt->id]) }}">{{ $receipt->provider }}</a>@if($receipt->event_type)<span class="subtle"> · {{ $receipt->event_type }}</span>@endif<span class="subtle mono"> · {{ substr($receipt->id, -8) }}</span><div class="subtle mono">{{ $receipt->method }} {{ $receipt->route }}</div></td><td>{{ $receipt->receipt_count }} {{ $receipt->receipt_count == 1 ? 'receipt' : 'receipts' }}@if($receipt->error_count)<div class="subtle">{{ $receipt->error_count }} HTTP errors</div>@endif @if($receipt->unfinished_count)<div class="subtle">Completion not observed for {{ $receipt->unfinished_count }}</div>@endif</td><td><span class="status status-{{ $receipt->http_status >= 400 ? 'failed' : ($receipt->http_status ? 'completed' : 'pending') }}">{{ $receipt->http_status ?? 'Not observed' }}</span></td><td>{{ ucfirst($receipt->signature) }}</td><td>{{ $receipt->duration_ms !== null ? number_format($receipt->duration_ms, 1).' ms' : '—' }}</td><td>{{ gmdate('M j, H:i:s', (int)$receipt->received_at) }}</td></tr>
@empty<tr><td colspan="6"><div class="empty-state compact"><h3>No receipts observed</h3><p>Only explicitly instrumented routes are captured. Existing webhook history is not imported.</p></div></td></tr>@endforelse
</tbody></table></div>
<div class="panel-footnote">Filters match any retained receipt; each row shows the latest received. Explicitly grouped event receipts remain individually inspectable. HTTP success does not establish downstream processing success. Only explicitly exported context is retained; headers and query strings are not automatically captured.</div></section>
<div class="pagination">@if($receipts->previousPageUrl())<a class="button secondary" href="{{ $receipts->previousPageUrl() }}">Previous</a>@endif @if($receipts->nextPageUrl())<a class="button secondary" href="{{ $receipts->nextPageUrl() }}">Next</a>@endif</div>
</div>
@endsection
