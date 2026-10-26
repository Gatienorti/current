@extends('current::layout')
@section('title', $receipt->provider.' webhook')
@section('content')
<div data-live-region="webhook-detail">
<div class="page-heading"><div><h1>{{ $receipt->provider }} webhook</h1><p class="mono">{{ $receipt->id }}</p></div><span class="soft-badge">INCOMING RECEIPT</span></div>
@include('current::receipt-history')
<div class="job-summary-grid">
<section class="panel"><div class="panel-heading"><h2>Receipt details</h2></div><div class="context-body"><dl class="context-list">
<dt>Endpoint</dt><dd class="mono">{{ $receipt->method }} {{ $receipt->route }}</dd><dt>HTTP response</dt><dd>{{ $receipt->http_status ?? 'Not observed' }}</dd><dt>Signature</dt><dd>{{ ucfirst($receipt->signature) }} · reported by handler</dd><dt>Observed duration</dt><dd>{{ $receipt->duration_ms !== null ? number_format($receipt->duration_ms, 1).' ms' : 'Not observed' }}</dd><dt>Expires · UTC</dt><dd>{{ gmdate('M j, H:i:s', (int)$receipt->expires_at) }}</dd></dl></div></section>
<section class="panel"><div class="panel-heading"><h2>Receipt timeline</h2></div><div class="context-body"><p>Received · {{ gmdate('M j, H:i:s', (int)$receipt->received_at) }} UTC</p><p>{{ $receipt->finished_at ? 'HTTP response observed · '.gmdate('M j, H:i:s', (int)$receipt->finished_at).' UTC' : 'No HTTP response observed yet' }}</p>@if($receipt->exception_class)<p class="error-class">{{ $receipt->exception_class }}</p>@endif<p class="small-note">This records the application's HTTP response, not provider acknowledgement or downstream business success.</p></div></section>
</div>
<section class="panel"><div class="panel-heading"><h2>Selected receipt’s jobs <span class="count-pill">{{ $jobs->count() }}</span></h2><span class="subtle">Observed dispatches</span></div><div class="table-wrap"><table><thead><tr><th>Job</th><th>Queue</th><th>Latest outcome</th></tr></thead><tbody>@forelse($jobs as $job)<tr><td><a class="text-link" href="{{ route('current.show', ['id' => $job->id]) }}">{{ class_basename($job->job_class) }}</a><div class="mono subtle">{{ $job->external_id }}</div></td><td>{{ $job->queue }}</td><td>@include('current::status', ['status' => $job->display_status])</td></tr>@empty<tr><td colspan="3">No retained job dispatches were linked during this handler.</td></tr>@endforelse</tbody></table></div><div class="panel-footnote">Up to 100 dispatches observed while this route handler is active are linked. Deferred after-response work, external queues and expired records may be absent.</div></section>
@include('current::webhook-relations', ['showReceipts' => false, 'showDeliveries' => true])
@include('current::webhook-context', ['record' => $receipt])
<section class="panel"><div class="panel-heading"><h2>Capture coverage</h2></div><div class="context-body"><p>Context is captured only from explicit application exports; sensitive keys are redacted and snapshots are bounded. Signature status is supplied by the existing handler; Current does not validate or replay webhooks.</p></div></section>
</div>
@endsection
