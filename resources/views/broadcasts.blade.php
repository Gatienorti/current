@extends('horizon-lens::layout')
@section('title', 'Broadcasts')
@section('content')
<div class="page-heading"><div><h1>Broadcasts</h1></div><span class="soft-badge">PROOF OF CONCEPT</span></div>
@if(!config('horizon-lens.broadcasts.enabled'))<p class="observation-notice">Broadcast observation is available. Enable CURRENT_BROADCASTS_ENABLED and configure approved event names and channel patterns to collect submissions. Server health has not been checked.</p>
@elseif(!config('horizon-lens.broadcasts.events') || !config('horizon-lens.broadcasts.channels'))<p class="observation-notice">Configure approved event names and channel patterns to start collection.</p>@endif
<nav class="filter-actions" aria-label="Broadcast issue views">
@foreach(['failed' => 'Failed submissions', 'slow' => 'Slow submissions', 'unfinished' => 'Missing completion'] as $value => $label)<a class="button secondary small" @if(($filters['issue'] ?? '') === $value) aria-current="page" @endif href="{{ route('horizon-lens.broadcasts', array_replace($filters, ['issue' => $value])) }}">{{ $label }}</a>@endforeach
@if(!empty($filters['issue']))<a class="text-link" href="{{ route('horizon-lens.broadcasts', array_diff_key($filters, ['issue' => true])) }}">Clear issue view</a>@endif
</nav><p class="small-note">Slow means at least {{ max(1, (int) config('horizon-lens.broadcasts.slow_ms', 1000)) }} ms observed submission time.</p>
<section class="panel filters-panel compact-filters webhook-filters"><form class="filters" method="get" data-job-filters>
@if(!empty($filters['issue']))<input type="hidden" name="issue" value="{{ $filters['issue'] }}">@endif
<label>Event<input name="event" placeholder="Exact event name" value="{{ $filters['event'] ?? '' }}"></label>
<div class="filter-field"><label for="filter-outcome">Outcome</label><select name="outcome" id="filter-outcome" data-filter-dropdown><option value="">Any outcome</option>@foreach(['accepted' => 'Accepted by broadcaster', 'failed' => 'Submission failed', 'unknown' => 'Completion not observed'] as $value => $label)<option value="{{ $value }}" @selected(($filters['outcome'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
@include('horizon-lens::time-range', ['timeCaption' => 'Submission time'])
<div class="filter-actions"><button class="button primary">Apply filters</button><a class="text-link" href="{{ route('horizon-lens.broadcasts') }}">Reset</a></div>
<details class="additional-filters" @if(!empty($filters['channel'])) open @endif><summary>@include('horizon-lens::icon', ['iconName' => 'chevron-right', 'iconClass' => 'more-filters-chevron'])More filters <span class="subtle">Approved channel pattern</span></summary><div class="additional-filter-fields"><label>Channel pattern<input name="channel" placeholder="Exact configured pattern" value="{{ $filters['channel'] ?? '' }}"></label></div></details>
</form>@if($errors->any())<p class="validation-error">{{ $errors->first() }}</p>@endif</section>
<div data-live-region="broadcasts"><section class="panel"><div class="panel-heading"><h2>Submissions</h2><span class="subtle">Latest first · UTC</span></div>
<div class="table-wrap"><table><thead><tr><th>Event</th><th>Approved channel patterns</th><th>Outcome</th><th>Duration</th><th>Started · UTC</th></tr></thead><tbody>
@forelse($broadcasts as $broadcast)
<tr><td><a class="text-link" href="{{ route('horizon-lens.broadcasts.show', ['id' => $broadcast->id]) }}">{{ $broadcast->event }}</a><div class="subtle">{{ $broadcast->connection }}</div></td><td class="mono">{{ implode(', ', json_decode($broadcast->channels_json, true)) }}</td><td>@include('horizon-lens::broadcast-status')</td><td>{{ $broadcast->duration_ms !== null ? number_format($broadcast->duration_ms, 1).' ms' : 'Not observed' }}</td><td>{{ gmdate('M j, H:i:s', (int)$broadcast->started_at) }}</td></tr>
@empty<tr><td colspan="5"><div class="empty-state compact"><h3>No broadcast submissions observed</h3><p>Existing history is not imported. Missing observations do not establish that no events were sent.</p></div></td></tr>@endforelse
</tbody></table></div><div class="panel-footnote">Acceptance is not browser receipt. Payloads and exact channel names require explicit approved capture; socket IDs are not captured automatically. Server connections and client acknowledgements are not observed.</div></section>
<div class="pagination">@if($broadcasts->previousPageUrl())<a class="button secondary" href="{{ $broadcasts->previousPageUrl() }}">Previous</a>@endif @if($broadcasts->nextPageUrl())<a class="button secondary" href="{{ $broadcasts->nextPageUrl() }}">Next</a>@endif</div></div>
@endsection
