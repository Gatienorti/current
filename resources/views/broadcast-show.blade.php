@extends('current::layout')
@section('title', $broadcast->event)
@section('content')
<div data-live-region="broadcast-detail">
<div class="page-heading"><div><h1>{{ $broadcast->event }}</h1><p class="mono">{{ $broadcast->id }}</p></div><div class="filter-actions">@include('current::broadcast-status')<a class="button secondary" href="{{ route('current.broadcasts.compare', ['id' => $broadcast->id]) }}">Compare</a><button class="button secondary" type="button" data-debug-open hidden>Debug with AI</button></div></div>
@include('current::debug-prompt')
<p class="observation-notice">{{ match($broadcast->outcome) { 'accepted' => 'The broadcaster accepted this submission. Client receipt is not known.', 'failed' => 'The submission raised an error. Review the failure details and related job below; some recipients may still have received data.', default => 'A submission started, but no completion was captured. Its result is unknown.' } }}</p>
<section class="panel"><div class="panel-heading"><h2>Observed timeline</h2></div><div class="context-body"><dl class="context-list">
@if($job && $job->coverage === 'observed_enqueue')<dt>Job enqueue observed</dt><dd>{{ gmdate('M j, H:i:s', (int)$job->first_observed_at) }} UTC</dd>@endif
@if($attempt)<dt>Worker attempt started</dt><dd>{{ gmdate('M j, H:i:s', (int)$attempt->started_at) }} UTC</dd>
@if($job->coverage === 'observed_enqueue')<dt>Enqueue → this worker attempt</dt><dd>{{ $attempt->started_at >= $job->first_observed_at ? number_format(($attempt->started_at - $job->first_observed_at) * 1000, 1).' ms · includes configured delay and previous attempts' : 'Clock order inconsistent' }}</dd>@endif
<dt>Worker start → submission</dt><dd>{{ $broadcast->started_at >= $attempt->started_at ? number_format(($broadcast->started_at - $attempt->started_at) * 1000, 1).' ms' : 'Clock order inconsistent' }}</dd>
@endif
<dt>Submission started</dt><dd>{{ gmdate('M j, H:i:s', (int)$broadcast->started_at) }} UTC</dd>
<dt>Submission finished</dt><dd>{{ $broadcast->finished_at ? gmdate('M j, H:i:s', (int)$broadcast->finished_at).' UTC' : 'Not observed' }}</dd>
</dl>@unless($attempt)<p class="small-note">No retained worker attempt is linked; queue timing is unavailable.</p>@endunless</div></section>
<section class="panel"><div class="panel-heading"><h2>Submission details</h2></div><div class="context-body"><dl class="context-list">
<dt>Channel types</dt><dd>{{ $broadcast->channel_types_json ? implode(', ', json_decode($broadcast->channel_types_json, true)) : 'Not captured' }} · approved channels only</dd><dt>Connection</dt><dd>{{ $broadcast->connection }}</dd><dt>Approved channel patterns</dt><dd class="mono">{{ implode(', ', json_decode($broadcast->channels_json, true)) }}</dd><dt>Started · UTC</dt><dd>{{ gmdate('M j, H:i:s', (int)$broadcast->started_at) }}</dd><dt>Duration</dt><dd>{{ $broadcast->duration_ms !== null ? number_format($broadcast->duration_ms, 1).' ms' : 'Not observed' }}</dd><dt>Exception class</dt><dd class="mono">{{ $broadcast->exception_class ?? 'None captured' }}</dd>
</dl></div></section>
<section class="panel"><div class="panel-heading"><h2>Related job</h2></div><div class="context-body">
@if($job)<a class="text-link" href="{{ route('current.show', array_filter(['id' => $job->id, 'attempt' => $broadcast->attempt_id])) }}#execution-map">{{ class_basename($job->job_class) }} ↗</a><p class="mono subtle">{{ $job->external_id }}</p>@include('current::status', ['status' => $job->display_status])
@else<p>No retained job is linked. Immediate broadcasts outside a worker have no job link.</p>@endif
</div></section>
@if($broadcast->exception_class)
<section class="panel"><div class="panel-heading"><h2>Failure context</h2></div><div class="context-body"><p class="error-class">{{ $broadcast->exception_class }}</p><p>{{ $broadcast->exception_message ?? 'Message omitted by capture policy.' }}</p><p class="mono">{{ $broadcast->exception_file ? $broadcast->exception_file.':'.$broadcast->exception_line : 'Source location outside configured roots or unavailable' }}</p>
@if($broadcast->error_state === 'failed')<p class="small-note">Additional error capture was unavailable.</p>@endif
<details><summary>Stack context</summary>@forelse(json_decode($broadcast->exception_trace ?? '[]', true) as $frame)<p class="mono small-note">{{ $frame['file'] ?? 'Outside configured roots' }}{{ isset($frame['line']) ? ':'.$frame['line'] : '' }} · {{ $frame['call'] ?? '' }}</p>@empty<p>No stack frames captured.</p>@endforelse</details>
<p class="small-note">This is where the exception surfaced, not proof of its root cause. Arguments and object instances are excluded.</p></div></section>
@endif
@include('current::broadcast-context')
@if($relatedBroadcasts->isNotEmpty())@include('current::related-broadcasts')@endif
@if($webhookReceipts->isNotEmpty() || $webhookDeliveries->isNotEmpty())@include('current::webhook-relations', ['showReceipts' => true, 'showDeliveries' => true])@endif
<section class="panel"><div class="panel-heading"><h2>Capture coverage</h2></div><div class="context-body"><p>Only approved event names and matching configured channel patterns are retained. Payload and metadata exports and exact approved channel names require explicit capture configuration. Error messages require a sanitizer; stack arguments and socket IDs are not automatically captured.</p><p>Accepted means the broadcaster returned without an exception. It does not prove a connected client received or processed the event. Server health and subscriber counts are not measured.</p></div></section>
</div>
@endsection
