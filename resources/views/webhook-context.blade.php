<section class="panel"><div class="panel-heading"><h2>Event identity</h2></div><div class="context-body"><dl class="context-list">
<dt>Event ID</dt><dd class="mono">{{ $record->event_id ?? 'Not captured' }}</dd>
<dt>Event type</dt><dd>{{ $record->event_type ?? 'Not captured' }}</dd></dl><p class="small-note">Supplied by the application. Repeated event IDs remain separate observations, not proof of duplicate processing.</p></div></section>
@foreach(['payload' => 'Payload', 'metadata' => 'Metadata', 'response' => 'Response data'] as $field => $title)
@php($state = $record->{$field.'_state'})
<details class="panel json-panel"><summary class="panel-heading"><h2>{{ $title }}</h2><span class="soft-badge">{{ match($state) { 'captured' => 'Captured', 'limited' => 'Captured with limits', 'size_limit' => 'Not captured · size limit', 'failed' => 'Capture unavailable', default => 'Not captured' } }}</span>@include('current::icon', ['iconName' => 'chevron-down', 'iconClass' => 'json-panel-chevron'])</summary>
<div class="json-panel-body">
@if($record->{$field.'_json'} !== null)
<p class="small-note">Application-provided snapshot · redacted before storage</p>
<div class="json-tree">@include('current::json-node', ['jsonValue' => json_decode($record->{$field.'_json'}, true), 'jsonKey' => null])</div>
@else
<p class="subtle">{{ match($state) { 'failed' => 'Capture failed. No raw data was retained.', 'size_limit' => 'This snapshot exceeded the configured byte limit and was omitted.', default => 'No explicit snapshot was captured. Existing records are not backfilled.' } }}</p>
@endif
</div></details>
@endforeach
