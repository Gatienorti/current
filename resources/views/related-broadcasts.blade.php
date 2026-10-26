<section class="panel"><div class="panel-heading"><h2>Related broadcast submissions</h2><span class="subtle">Same recorded job · latest 100</span></div>
<div class="table-wrap"><table><thead><tr><th>Event</th><th>Outcome</th><th>Started · UTC</th><th>Worker attempt</th></tr></thead><tbody>
@foreach($relatedBroadcasts as $related)
<tr><td><a class="text-link" href="{{ route('current.broadcasts.show', ['id' => $related->id]) }}">{{ $related->event }}</a></td><td>@include('current::broadcast-status', ['broadcast' => $related])</td><td>{{ gmdate('M j, H:i:s', (int)$related->started_at) }}</td><td class="mono">{{ $related->attempt_id ? substr($related->attempt_id, -8) : 'Not observed' }}</td></tr>
@endforeach
</tbody></table></div><div class="panel-footnote">These submissions share a job, not necessarily a logical event. Later retry job records and child jobs are not automatically linked.</div></section>
