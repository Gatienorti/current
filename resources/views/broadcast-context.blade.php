@foreach(['payload' => 'Payload', 'metadata' => 'Metadata', 'exact_channels' => 'Exact approved channels'] as $field => $title)
@php($state = $broadcast->{$field.'_state'})
<details class="panel json-panel"><summary class="panel-heading"><h2>{{ $title }}</h2><span class="soft-badge">{{ match($state) { 'captured' => 'Captured', 'limited' => 'Captured with limits', 'size_limit' => 'Not captured · size limit', 'failed' => 'Capture unavailable', default => 'Not captured' } }}</span>@include('current::icon', ['iconName' => 'chevron-down', 'iconClass' => 'json-panel-chevron'])</summary><div class="json-panel-body">
@if($broadcast->{$field.'_json'} !== null)
<p class="small-note">Redacted before storage · captured for this submission</p><div class="json-tree">@include('current::json-node', ['jsonValue' => json_decode($broadcast->{$field.'_json'}, true), 'jsonKey' => null])</div>
@else<p class="subtle">{{ match($state) { 'size_limit' => 'Snapshot exceeded the byte limit and was omitted.', 'failed' => 'Capture failed. No raw snapshot was retained.', default => 'No approved snapshot was captured. Existing records are not backfilled.' } }}</p>@endif
</div></details>
@endforeach
