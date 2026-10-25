@php
    $snapshotLabel = match ($snapshot['state']) { 'captured' => 'Captured', 'limited' => 'Captured with limits', 'unavailable' => 'Unavailable', 'failed' => 'Capture unavailable', 'size_limit' => 'Not captured · size limit', default => 'Not captured' };
@endphp
<details class="panel json-panel">
    <summary class="panel-heading"><h2>{{ $snapshotTitle }}</h2><span class="soft-badge">{{ $snapshotLabel }}{{ $snapshotSource->id !== $job->id ? ' · Earlier enqueue' : '' }}</span>@include('horizon-lens::icon', ['iconName' => 'chevron-down', 'iconClass' => 'json-panel-chevron'])</summary>
    <div class="json-panel-body">
        @if($snapshotSource->id !== $job->id)
        <p class="small-note">Captured on <a class="text-link" href="{{ route('horizon-lens.show', ['id' => $snapshotSource->id]) }}#job-details">earlier job {{ $snapshotSource->external_id }}</a>. This is historical context, not a fresh capture of this retry.</p>
        @endif
        @if($snapshot['json'] !== null)
            <div class="json-toolbar"><span class="subtle">Enqueue snapshot @if($snapshotSource->context_captured_at)· {{ gmdate('M j, H:i:s', (int) $snapshotSource->context_captured_at) }} UTC @endif</span><button class="button secondary small" type="button" data-copy-json="{{ $snapshotId }}" aria-label="Copy {{ $snapshotTitle }} JSON" hidden>@include('horizon-lens::icon', ['iconName' => 'copy'])<span data-copy-label>Copy JSON</span></button></div>
            <div class="json-tree">@include('horizon-lens::json-node', ['jsonValue' => $snapshot['value'], 'jsonKey' => null])</div>
            <pre id="{{ $snapshotId }}" hidden>{{ $snapshot['json'] }}</pre>
        @else
            <p class="subtle">{{ match ($snapshot['state']) { 'unavailable' => 'The payload is encrypted, unsupported or could not be safely read. No raw command was retained.', 'failed' => 'The configured capture could not produce a snapshot. No raw values were retained.', 'size_limit' => 'The snapshot exceeded its configured size bound and was omitted.', default => 'No snapshot was recorded for this enqueue. Existing records are not backfilled.' } }}</p>
        @endif
    </div>
</details>
