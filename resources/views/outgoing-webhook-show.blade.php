@extends('current::layout')
@section('title', $delivery->destination.' delivery')
@section('content')
<div data-live-region="outgoing-webhook-detail">
<div class="page-heading"><div><h1>{{ $delivery->destination }} delivery</h1><p class="mono">{{ $delivery->id }}</p></div><span class="soft-badge">OUTGOING ATTEMPT</span></div>
@include('current::delivery-attempts')
<div class="job-summary-grid">
<section class="panel"><div class="panel-heading"><h2>Selected attempt details</h2></div><div class="context-body"><dl class="context-list">
<dt>Destination</dt><dd class="mono">{{ $delivery->method }} {{ $delivery->endpoint }}</dd>
<dt>HTTP response</dt><dd><span class="status status-{{ $delivery->http_status >= 400 || $delivery->exception_class ? 'failed' : ($delivery->http_status ? 'completed' : 'pending') }}">{{ $delivery->http_status ?? ($delivery->exception_class ? 'Transport error' : 'Not observed') }}</span></dd>
<dt>Observed duration</dt><dd>{{ $delivery->duration_ms !== null ? number_format($delivery->duration_ms, 1).' ms' : 'Not observed' }}</dd>
<dt>Expires · UTC</dt><dd>{{ gmdate('M j, H:i:s', (int)$delivery->expires_at) }}</dd>
</dl></div></section>
<section class="panel"><div class="panel-heading"><h2>Attempt timeline</h2></div><div class="context-body"><p>Started · {{ gmdate('M j, H:i:s', (int)$delivery->started_at) }} UTC</p>
<p>{{ $delivery->finished_at ? 'Finished · '.gmdate('M j, H:i:s', (int)$delivery->finished_at).' UTC' : 'No completion observed yet' }}</p>
@if($delivery->exception_class)<p class="error-class">{{ $delivery->exception_class }}</p>@endif
<p class="small-note">Select an attempt above to inspect its individual context. A successful response does not prove that the recipient processed the event.</p></div></section>
</div>
<section class="panel"><div class="panel-heading"><h2>Related job</h2></div><div class="context-body">
@if($job)
<a class="text-link" href="{{ route('current.show', ['id' => $job->id]) }}">{{ class_basename($job->job_class) }} ↗</a>
<p class="mono subtle">{{ $job->external_id }}</p>
@include('current::status', ['status' => $job->display_status])
<p class="small-note">Sending attempt <span class="mono">{{ $delivery->attempt_id }}</span> · job status reflects its latest observation.</p>
@else<p>No retained job is linked to this attempt.</p>@endif
</div></section>
@include('current::webhook-relations', ['showReceipts' => true, 'showDeliveries' => false])
@include('current::webhook-context', ['record' => $delivery])
<section class="panel"><div class="panel-heading"><h2>Capture coverage</h2></div><div class="context-body"><p>Only configured Laravel HTTP client destinations are observed. Optional context is explicitly exported, redacted and bounded; raw headers and bodies are not automatically captured. Current does not deliver or replay webhooks.</p></div></section>
</div>
@endsection
