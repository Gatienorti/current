@php
$tagPage = max(1, min(400, (int) request()->query('tag_page', 1)));
try { $monitoredTags = app(\Current\Monitoring\Tags::class)->summary($tagPage); }
catch (\Throwable $exception) { $monitoredTags = ['available' => false, 'collector_unknown' => $exception instanceof \Current\Native\MonitoredRetentionUnavailable]; }
$canManageTags = \Illuminate\Support\Facades\Gate::has('operateCurrent') && \Illuminate\Support\Facades\Gate::allows('operateCurrent');
@endphp
@if($canManageTags && request()->header('X-Current-Refresh') !== '1')<form method="POST" action="{{ route('current.monitoring.tags.store') }}" class="panel panel-heading">@csrf<label>Approved tag <input name="tag" required maxlength="100" placeholder="order:example"></label><button class="button primary" type="submit">Monitor tag</button></form>@endif
<section class="panel" data-live-region="monitored-tags">
<div class="panel-heading"><h2>Monitored tags</h2><a class="text-link" href="{{ route('current.monitoring', request()->except(['manage_tags','tag_page'])) }}">Close management</a></div>
@if(session('current_monitoring_notice'))<p role="status" class="observation-notice">{{ session('current_monitoring_notice') }}</p>@endif
@if(!$monitoredTags['available'])<div class="empty-state compact"><h3>Monitored-tag policy or history is unavailable</h3><p>@if($monitoredTags['collector_unknown'] ?? false)A running matching collector with monitored retention support could not be confirmed. Retention extension is unknown. @endif This is a coverage gap; jobs continue through their normal queue.</p></div>
@else
<div class="table-scroll"><table><thead><tr><th>Tag</th><th>Monitored entries</th><th>Failed entries</th><th></th></tr></thead><tbody>
@forelse($monitoredTags['rows'] as $row)<tr><td class="mono">{{ $row['tag'] }}</td><td><a class="text-link" href="{{ route('current.monitoring.tags.history', ['tag'=>$row['tag']]) }}">{{ $row['total'] }}</a></td><td><a class="text-link" href="{{ route('current.monitoring.tags.history', ['tag'=>$row['tag'],'kind'=>'failed']) }}">{{ $row['failed'] }}</a></td><td>@if($canManageTags)<form method="POST" action="{{ route('current.monitoring.tags.destroy') }}">@csrf<input type="hidden" name="tag" value="{{ $row['tag'] }}"><button class="button secondary small">Stop monitoring</button></form>@endif</td></tr>
@empty<tr><td colspan="4">No approved tags are being monitored.</td></tr>@endforelse
</tbody></table></div>
<div class="pagination"><span>{{ $monitoredTags['total'] }} monitored tags · 25 per page</span><div>@if($monitoredTags['previous'])<a class="button secondary small" href="{{ route('current.monitoring',request()->except('tag_page')+['tag_page'=>$tagPage-1]) }}">Previous</a>@endif @if($monitoredTags['next'])<a class="button secondary small" href="{{ route('current.monitoring',request()->except('tag_page')+['tag_page'=>$tagPage+1]) }}">Next</a>@endif</div></div>
@endif
<div class="panel-footnote">Only tags approved by Current's capture policy can be managed here. Monitoring begins with new observations; it does not import earlier history. Native detail retention is extended at observation and completion, capped by the configured maximum of at most 30 days. Removing a monitor does not erase application jobs or already captured Current detail. Missing observations remain unknown.</div>
</section>
