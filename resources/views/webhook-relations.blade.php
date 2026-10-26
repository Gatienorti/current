@php
    $hasReceipts = $showReceipts && $webhookReceipts->isNotEmpty();
    $hasDeliveries = $showDeliveries && $webhookDeliveries->isNotEmpty();
@endphp
@if($hasReceipts || $hasDeliveries)
<section class="panel related-webhooks-panel" id="related-webhooks">
<div class="panel-heading"><h2>Related webhooks</h2><span class="subtle" title="Latest 100 retained observations per direction">{{ $hasReceipts && $hasDeliveries ? 'Incoming & outgoing' : ($hasReceipts ? 'Incoming' : 'Outgoing') }}</span></div>
@if($hasReceipts)
@if($hasDeliveries)<div class="related-webhooks-label">Incoming receipts</div>@endif
<div class="table-wrap"><table><thead><tr><th>Receipt / event</th><th>HTTP response</th><th>Received · UTC</th></tr></thead><tbody>
@forelse($webhookReceipts as $related)
<tr><td><a class="text-link" href="{{ route('horizon-lens.webhooks.show', ['id' => $related->id]) }}">{{ $related->provider }} · {{ substr($related->id, -8) }}</a><div class="subtle">{{ $related->event_type }} {{ $related->event_id }}</div></td><td>{{ $related->http_status ?? 'Not observed' }}</td><td>{{ gmdate('M j, H:i:s', (int)$related->received_at) }}</td></tr>
@empty<tr><td colspan="3">No retained incoming receipt is linked to this job.</td></tr>@endforelse
</tbody></table></div>
@endif
@if($hasDeliveries)
@if($hasReceipts)<div class="related-webhooks-label">Outgoing delivery attempts</div>@endif
<div class="table-wrap"><table><thead><tr><th>Delivery / event</th><th>HTTP outcome</th><th>Sending job attempt</th><th>Started · UTC</th></tr></thead><tbody>
@forelse($webhookDeliveries as $related)
<tr><td><a class="text-link" href="{{ route('horizon-lens.webhooks.outgoing.show', ['id' => $related->id]) }}">{{ $related->destination }} · {{ substr($related->id, -8) }}</a><div class="subtle">{{ $related->event_type }} {{ $related->event_id }}</div></td><td><span class="status status-{{ $related->http_status >= 400 || $related->exception_class ? 'failed' : ($related->http_status ? 'completed' : 'pending') }}">{{ $related->http_status ?? ($related->exception_class ? 'Transport error' : 'Not observed') }}</span></td><td><a class="text-link mono" href="{{ route('horizon-lens.show', array_filter(['id' => $related->job_id, 'attempt' => $related->attempt_id])) }}#execution-map">{{ substr($related->job_id, -8) }} · {{ $related->attempt_id ? substr($related->attempt_id, -8) : 'Not captured' }} ↗</a></td><td>{{ gmdate('M j, H:i:s', (int)$related->started_at) }}</td></tr>
@empty<tr><td colspan="4">No retained outgoing attempts were observed for the linked jobs.</td></tr>@endforelse
</tbody></table></div>
@endif
</section>

@endif
