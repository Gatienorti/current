@extends('horizon-lens::layout')
@section('title', class_basename($job->job_class))
@section('content')
<div data-live-region="investigation" data-refresh-ms="5000">
<div class="job-header">
<div class="page-heading detail-heading"><div><h1>{{ class_basename($job->job_class) }}</h1><div class="detail-subtitle">@include('horizon-lens::status', ['status' => $job->display_status])<span class="mono">{{ $job->external_id }}</span></div></div><div class="job-actions">@if($job->display_status === 'failed' && config('horizon-lens.allow_retries', true))
@if($retryJob)<a class="button primary" href="{{ route('horizon-lens.show', ['id' => $retryJob->id, 'watch' => 1]) }}#execution-map">View retry</a>
@elseif(!$retryRequested)<form method="POST" action="{{ route('horizon-lens.retry', ['id' => $job->id]) }}" data-retry-form>@csrf<button class="button primary" type="submit">Retry job</button></form>
@else<span class="subtle">Retry requested · check Horizon</span>
@endif
@endif
@if($debugPrompt)<button class="button secondary" type="button" data-debug-open hidden>Debug with AI</button>
@endif
</div></div>
    <div class="execution-toolbar">
    <nav class="attempt-tabs" aria-label="Execution attempts">
        @forelse($attempts as $item)
        <a aria-label="Attempt {{ $attempts->count() - $loop->index }}: {{ $item->display_label }}, {{ gmdate('M j, H:i:s', (int) $item->started_at) }} UTC" title="{{ $item->display_label }}" class="attempt-tab {{ $attempt?->id === $item->id ? 'active' : '' }}" @if($attempt?->id === $item->id) aria-current="page" @endif href="{{ route('horizon-lens.show', ['id' => $job->id, 'attempt' => $item->id, 'return' => $returnTo]) }}"><span class="mini-dot {{ $item->display_status }}"></span>Attempt {{ $attempts->count() - $loop->index }} · {{ $item->display_label }} <small>{{ gmdate('M j, H:i:s', (int) $item->started_at) }} UTC</small></a>
        @empty<span class="subtle">No execution start captured yet.</span>@endforelse
    </nav>
    @if($attempt)
    <div class="attempt-summary"><div><span class="subtle">Worker attempt {{ $attempt->reported_attempt }}{{ $attempt->id === $attempts->first()->id ? ' · Latest observed' : ' · Earlier execution' }}</span></div><span class="mono subtle">{{ $attempt->finished_at ? number_format(($attempt->finished_at - $attempt->started_at) * 1000, 1).' ms observed' : 'No finish observed' }}</span></div>
    @endif
    </div>
</div>
@if(session('current_retry_notice'))<p role="status" class="observation-notice">{{ session('current_retry_notice') }}</p>
@endif
@if(!$watch && in_array($job->display_status, ['pending', 'processing']))<p><a class="text-link" href="{{ route('horizon-lens.show', ['id' => $job->id, 'watch' => 1]) }}#execution-map">Watch live</a></p>
@endif
@include('horizon-lens::debug-prompt')
@if($job->earlier_unfinished_attempts > 0 || ($job->unfinished_attempts > 0 && $job->oldest_unfinished_at < microtime(true) - config('horizon-lens.stale_after_seconds', 300)) || $job->coverage === 'start_only')
<div class="observation-notice">@include('horizon-lens::history-indicator')<span>The status above follows the latest observed attempt. Missing finishes remain unresolved; a worker may still be running.</span></div>
@endif
<div id="execution-map" class="execution-investigation" data-investigation data-default-inspector="{{ $defaultInspector }}">
    @if($steps->isNotEmpty())
    <section class="panel investigation-panel">
        <div class="panel-heading"><h2>Execution map <span class="count-pill">{{ $steps->count() }}</span></h2><a class="step-summary-link" data-step-target="attempt-context" href="#attempt-context">Attempt summary</a><span class="soft-badge">RECORDED ORDER</span></div>
        @include('horizon-lens::step-map')
    </section>
    @endif
    @include('horizon-lens::job-overview')
    <div class="investigation-details {{ $steps->isEmpty() ? 'without-steps' : '' }}">
        @if($steps->isNotEmpty())
        <section class="panel execution-steps" aria-label="Execution steps">
            <div class="panel-heading"><h2>Execution steps</h2><span class="soft-badge">OBSERVED</span></div>
            <div class="execution-step-list">
            @foreach($steps as $step)
            <div class="execution-step {{ $step->status }} {{ $step->parent_id ? 'nested' : '' }}">
                <a class="execution-step-summary" data-step-target="step-{{ $step->id }}" aria-controls="step-{{ $step->id }}" aria-expanded="true" href="#step-{{ $step->id }}">
                    <span class="map-node-state">@include('horizon-lens::icon', ['iconName' => $step->status === 'completed' ? 'circle-check' : ($step->status === 'failed' ? 'circle-x' : 'clock')])</span>
                    <span class="map-node-label"><strong>{{ $step->label }}</strong><small>{{ $step->status === 'running' ? 'No finish observed' : ucfirst($step->status) }}{{ $step->parent_id ? ' · Nested block' : '' }}</small></span>
                    @if($step->finished_at)<span class="map-node-duration">{{ number_format(($step->finished_at - $step->started_at) * 1000, 1) }} ms</span>
@endif
                    @include('horizon-lens::icon', ['iconName' => 'chevron-down', 'iconClass' => 'step-detail-toggle'])
                </a>
                <section id="step-{{ $step->id }}" class="step-inspector context-body" aria-label="{{ $step->label }} details" data-step-inspector>
                @if($step->exception_class)
                    @if($failedStep?->id === $step->id)
                    <div class="step-error-summary"><strong>{{ class_basename($step->exception_class) }}</strong><a class="text-link" data-step-context-link data-step-target="attempt-context" href="#attempt-context">View failure context @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a></div>
                    @else
                    @include('horizon-lens::exception', ['errorRecord' => $step, 'errorFrames' => []])
                    @endif
                    @if(!$attempt?->exception_class && $attempt?->status === 'completed')<p class="small-note">The step recorded an error; the attempt completed without an unhandled exception.</p>
@endif
                @else
                    <p>{{ $step->finished_at ? 'This instrumented block completed.' : 'No step finish was captured. Its outcome remains unknown.' }}</p>
                @endif
                <dl class="context-list"><dt>Started · UTC</dt><dd>{{ gmdate('M j, H:i:s', (int) $step->started_at) }}</dd><dt>Finished · UTC</dt><dd>{{ $step->finished_at ? gmdate('M j, H:i:s', (int) $step->finished_at) : 'Not observed' }}</dd><dt>Observed duration</dt><dd>{{ $step->finished_at ? number_format(($step->finished_at - $step->started_at) * 1000, 1).' ms' : '—' }}</dd>@if($step->parent_id)<dt>Parent block</dt><dd><a data-step-target="step-{{ $step->parent_id }}" href="#step-{{ $step->parent_id }}">Inspect parent step @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a></dd>
@endif</dl>
                @if($attempt?->exception_class && $failedStep?->id !== $step->id)<a class="text-link attempt-error-shortcut" data-step-target="{{ $failedStep ? 'step-'.$failedStep->id : 'attempt-context' }}" href="#{{ $failedStep ? 'step-'.$failedStep->id : 'attempt-context' }}">Inspect attempt error @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a>
@endif
                </section>
            </div>
            @endforeach
            @if($steps->isEmpty())<div class="empty-state compact"><h3>No internal steps captured</h3><p>Select an observed attempt above. Internal steps require instrumentation.</p></div>
@endif
            </div>
        </section>
        @endif
        <aside class="panel attempt-context-panel" aria-label="Attempt context">
            <div class="panel-heading"><h2>{{ $attempt?->exception_class ? 'Failure location' : 'Attempt context' }}</h2>@include('horizon-lens::icon', ['iconName' => $attempt?->exception_class ? 'alert' : 'info', 'iconClass' => $attempt?->exception_class ? 'context-error-icon' : ''])</div>
            <section id="attempt-context" class="step-inspector context-body" data-step-inspector>
                @if($attempt?->exception_class)
                    @include('horizon-lens::exception', ['errorRecord' => $attempt, 'errorFrames' => $frames, 'errorLabel' => 'Captured exception'])
                    @if($failedStep)<a class="button error-button" data-step-target="step-{{ $failedStep->id }}" href="#step-{{ $failedStep->id }}">Jump to failed step @include('horizon-lens::icon', ['iconName' => 'arrow-left'])</a>
@endif
                @elseif(!$attempt)
                    <p>Only enqueue was observed. No execution start has been captured yet.</p>
                @elseif(!$attempt->finished_at)
                    <p>No finish event has been captured. This worker may still be running; Current does not collect worker heartbeats.</p>
                @elseif($attempt->status === 'completed')
                    <h3>Worker processing completed</h3>
                    <p>{{ $steps->where('status', 'failed')->isNotEmpty() ? 'Some steps recorded errors that the job may have handled.' : 'No unhandled exception was captured for this attempt.' }} Worker completion does not prove external delivery or business success.</p>
                @elseif($attempt->status === 'released')
                    <h3>Release observed</h3><p>This attempt returned to the queue. A later attempt may have started; select it above to inspect its observations.</p>
                @else
                    <h3>{{ ucfirst(str_replace('_', ' ', $attempt->status)) }} observed</h3><p>No structured error details were captured. Queue events do not establish this attempt’s internal effects or the queue’s next state.</p>
                @endif

            </section>
        </aside>
    </div>
</div>
<div class="job-payload-grid">
    @include('horizon-lens::json-panel', ['snapshot' => $argumentsSnapshot, 'snapshotTitle' => 'Args', 'snapshotId' => 'job-args-json', 'snapshotSource' => $argumentsSource])
    @include('horizon-lens::json-panel', ['snapshot' => $metadataSnapshot, 'snapshotTitle' => 'Metadata', 'snapshotId' => 'job-metadata-json', 'snapshotSource' => $metadataSource])
</div>

@if($webhookReceipts->isNotEmpty() || $webhookDeliveries->isNotEmpty())
@include('horizon-lens::webhook-relations', ['showReceipts' => true, 'showDeliveries' => true])
@endif
@if($relatedBroadcasts->isNotEmpty())@include('horizon-lens::related-broadcasts')@endif
@include('horizon-lens::attempt-history')
<details class="panel source-panel source-disclosure">
    <summary class="panel-heading"><h2>Source outline <span class="count-pill">{{ count($operations) }}</span></h2><span class="soft-badge">POSSIBLE OPERATIONS</span>@include('horizon-lens::icon', ['iconName' => 'chevron-down', 'iconClass' => 'source-toggle'])</summary>
    <div class="source-intro">Read from <code>{{ $attempt?->source_file ?? 'unavailable source' }}</code> at attempt start. These possible calls may belong to branches or loops; execution is unknown unless separately captured.</div>
    @if($attempt?->source_hash)<div class="source-revision">Source fingerprint <code>{{ substr($attempt->source_hash, 0, 16) }}</code>@if($attempt->source_revision) · Revision <code>{{ $attempt->source_revision }}</code>
@endif</div>
@endif
    <div class="source-list">@forelse($operations as $operation)@php($isOrigin = $attempt?->exception_file === $attempt?->source_file && $attempt?->exception_line >= $operation['line'] && $attempt?->exception_line <= $operation['end_line'])<div class="source-operation {{ $isOrigin ? 'error-location' : '' }}"><span class="source-line">{{ $operation['line'] }}</span><code>{{ $operation['label'] }}</code><span class="source-evidence">{{ $isOrigin ? 'Exception location' : 'Execution unknown' }}</span></div>@empty<div class="empty-state compact"><p>No supported source outline captured. Dynamic dispatch, inherited handlers, and unavailable source can limit coverage.</p></div>@endforelse</div>
    <div class="panel-footnote">Bounded to {{ config('horizon-lens.max_source_operations') }} calls in handle(). Arguments and source text are not retained.</div>
</details>
</div>
@endsection
