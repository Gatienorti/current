<details class="panel json-panel" data-payload-panel open>
    <summary class="panel-heading"><h2>{{ $snapshotTitle }}</h2>@include('current::icon', ['iconName' => 'chevron-down', 'iconClass' => 'json-panel-chevron'])</summary>
    <div class="json-panel-body">
        @if($snapshot['state'] === 'limited')<p class="small-note">Captured with limits.</p>@endif
        @if($snapshotSource->id !== $job->id)
        <p class="small-note">Earlier enqueue: <a class="text-link" href="{{ route('current.show', ['id' => $snapshotSource->id]) }}#job-details">job {{ $snapshotSource->external_id }}</a>. This is historical context, not a fresh capture of this retry.</p>
        @endif
        @if($snapshot['json'] !== null)
            <div class="json-toolbar"><span class="subtle">Enqueue snapshot @if($snapshotSource->context_captured_at)· {{ gmdate('M j, H:i:s', (int) $snapshotSource->context_captured_at) }} UTC @endif</span><button class="button secondary small" type="button" data-copy-json="{{ $snapshotId }}" aria-label="Copy {{ $snapshotTitle }} JSON" hidden>@include('current::icon', ['iconName' => 'copy'])<span data-copy-label>Copy JSON</span></button></div>
            <div class="json-tree">@include('current::json-node', ['jsonValue' => $snapshot['value'], 'jsonKey' => null])</div>
            <pre id="{{ $snapshotId }}" hidden>{{ $snapshot['json'] }}</pre>
        @else
            <p class="subtle">{{ match ($snapshot['state']) { 'unavailable' => 'The payload is encrypted, unsupported or could not be safely read. No raw command was retained.', 'failed' => 'The configured capture could not produce a snapshot. No raw values were retained.', 'size_limit' => 'The snapshot exceeded its configured size bound and was omitted.', default => 'Not captured. No snapshot was recorded for this enqueue. Existing records are not backfilled.' } }}</p>
        @endif
    </div>
</details>
