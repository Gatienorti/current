@if($receipt->receipt_key)
<section class="panel"><div class="panel-heading"><h2>Event receipts <span class="count-pill">{{ $receiptCount }}</span></h2><span class="subtle">Same explicit event identity</span></div>
<div class="table-wrap"><table><thead><tr><th>Received · UTC</th><th>HTTP response</th><th>Signature</th><th>Duration</th><th>Related jobs</th></tr></thead><tbody>
@foreach($groupReceipts as $entry)
<tr><td><a class="text-link" href="{{ route('current.webhooks.show', ['id' => $entry->id]) }}">{{ gmdate('M j, H:i:s', (int)$entry->received_at) }}</a><div class="subtle mono">{{ substr($entry->id, -8) }}{{ $entry->id === $receipt->id ? ' · Selected' : '' }}</div></td>
<td><span class="status status-{{ $entry->http_status >= 400 ? 'failed' : ($entry->http_status ? 'completed' : 'pending') }}">{{ $entry->http_status ?? 'Not observed' }}</span>@if($entry->exception_class)<div class="subtle">{{ class_basename($entry->exception_class) }}</div>@endif</td>
<td>{{ ucfirst($entry->signature) }}</td><td>{{ $entry->duration_ms !== null ? number_format($entry->duration_ms, 1).' ms' : '—' }}</td>
<td>@forelse($groupJobLinks->get($entry->id, collect()) as $link)<div><a class="text-link" href="{{ route('current.show', ['id' => $link->job_id]) }}">{{ class_basename($link->job_class) }}</a></div>@empty<span class="subtle">None retained</span>@endforelse</td></tr>
@endforeach
</tbody></table></div><div class="panel-footnote">Latest {{ $groupReceipts->count() }} of {{ $receiptCount }} retained receipts. Select a receipt to inspect its own context and jobs. Repeated event IDs do not prove why a provider resent an event or whether business actions ran more than once.</div></section>
@else
<p class="observation-notice">No trusted grouping identity was supplied. This receipt remains independent.</p>
@endif
