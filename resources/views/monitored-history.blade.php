@extends('current::layout')
@section('title','Monitored history')
@section('content')
@if(request()->header('X-Current-Refresh') !== '1')<div class="page-heading"><div><h1>Monitored history</h1><p class="mono">{{ $tag }}</p></div><a class="button secondary" href="{{ route('current.monitoring',['manage_tags'=>1]) }}">Manage monitoring</a></div>@endif
<section class="panel" data-live-region="monitored-history">
@if(!$available)<div class="empty-state"><h3>Monitored history unavailable</h3><p>@if($collector_unknown ?? false)A running matching collector with monitored retention support could not be confirmed. Retention extension is unknown. @endif The source could not be read; this is not evidence that no jobs exist.</p></div>
@else
<div class="panel-heading"><h2>{{ $kind === 'failed' ? 'Failed tag entries' : 'Monitored entries' }} · {{ number_format($total) }}</h2><nav><a class="text-link" href="{{ route('current.monitoring.tags.history',['tag'=>$tag]) }}">All monitored</a> · <a class="text-link" href="{{ route('current.monitoring.tags.history',['tag'=>$tag,'kind'=>'failed']) }}">Failed</a></nav></div>
<div class="table-scroll"><table><thead><tr><th>Job / identifier</th><th>Observed status</th><th>Queue</th><th></th></tr></thead><tbody>
@if($native)
@forelse($jobs as $job)<tr><td>{{ class_basename($job->job_class) }}<div class="job-uuid">{{ $job->external_id }}</div></td><td>@include('current::status',['status'=>$job->display_status])</td><td>{{ $job->queue }}</td><td><a class="text-link" href="{{ route('current.show',['id'=>$job->id]) }}">Inspect retained detail</a></td></tr>@empty<tr><td colspan="4">No retained matching observations since monitoring began.</td></tr>@endforelse
@else
@forelse($rows as $row)<tr><td>{{ class_basename($row['job_class']) }}<div class="job-uuid">{{ $row['uuid'] }}</div></td><td>@include('current::status',['status'=>$row['status']])</td><td>{{ $row['queue'] }}</td><td><a class="text-link" href="{{ $row['url'] }}">Inspect retained detail in Horizon ↗</a></td></tr>@empty<tr><td colspan="4">No retained Horizon records in this index page.</td></tr>@endforelse
@endif
</tbody></table></div>
<div class="pagination"><span>25 entries per page</span><div>@if($native)@if($jobs->previousPageUrl())<a class="button secondary small" href="{{ $jobs->previousPageUrl() }}">Previous</a>@endif @if($jobs->nextPageUrl())<a class="button secondary small" href="{{ $jobs->nextPageUrl() }}">Next</a>@endif @else @if($previous)<a class="button secondary small" href="{{ route('current.monitoring.tags.history',['tag'=>$tag,'kind'=>$kind,'offset'=>max(0,$offset-25)]) }}">Previous</a>@endif @if($next)<a class="button secondary small" href="{{ route('current.monitoring.tags.history',['tag'=>$tag,'kind'=>$kind,'offset'=>$offset+25]) }}">Next</a>@endif @endif</div></div>
<div class="panel-footnote">@if($native)Native history uses captured approved tags and observations since monitoring began. Its complete attempts, steps and snapshots follow the job's retained expiry. @else Horizon owns this monitored index and the detail retention. Its index can contain references whose record has expired; failed-tag entries can include retained failures from before monitoring was enabled. @endif Missing telemetry does not prove successful execution.</div>
@endif
</section>
@endsection
