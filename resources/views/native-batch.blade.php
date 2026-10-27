@extends('current::layout')
@section('title', 'Batch')
@section('content')
@if(request()->header('X-Current-Refresh') !== '1')
<div class="page-heading"><h1>{{ $batch->name ?: 'Batch' }}</h1></div>
@endif
<div data-live-region="native-batch">
@if(session('current_batch_notice'))<div role="status" class="panel-footnote">{{ session('current_batch_notice') }}</div>@endif
<div class="horizon-stats-grid">
@foreach([['Total jobs', $batch->totalJobs], ['Pending', $batch->pendingJobs], ['Failed', $batch->failedJobs], ['Progress', $batch->progress().'%']] as [$label, $value])<div class="horizon-stat"><div class="stat-label">{{ $label }}</div><div class="horizon-stat-value">{{ $value }}</div></div>@endforeach
</div>
<section class="panel"><div class="panel-heading"><h2>Batch details</h2><span>{{ $batch->cancelled() ? 'Cancelled' : ($batch->finished() ? 'Finished' : 'Unfinished') }}</span></div><div class="context-body"><dl class="job-field-grid"><div><dt>ID</dt><dd class="mono">{{ $batch->id }}</dd></div><div><dt>Created · UTC</dt><dd>{{ $batch->createdAt->utc()->format('M j, H:i:s') }}</dd></div>@if($batch->finishedAt)<div><dt>Finished · UTC</dt><dd>{{ $batch->finishedAt->utc()->format('M j, H:i:s') }}</dd></div>@endif</dl></div></section>
@if($canRetry && $batch->failedJobIds)
<section class="panel"><div class="panel-heading"><h2>Retry batch failures</h2><form method="POST" action="{{ route('current.batches.retry', ['id' => $batch->id]) }}" data-current-batch-retry>@csrf<input type="hidden" name="scope" value="batch"><input type="hidden" name="retry_pass" value="{{ $retryPass }}">@if($retryAfter)<input type="hidden" name="retry_after" value="{{ $retryAfter }}">@endif<button class="button primary small" type="submit">{{ $retryAfter ? 'Continue batch retry' : 'Retry batch failures' }}</button></form></div><div class="panel-footnote">Pause stops after the current request. Uncertain results require review before resuming.</div></section>
@endif
<section class="panel"><div class="panel-heading"><h2>Captured jobs</h2>
@if($retryable)<form method="POST" action="{{ route('current.batches.retry', ['id' => $batch->id]) }}">@csrf @foreach($retryable as $jobId)<input type="hidden" name="jobs[]" value="{{ $jobId }}">@endforeach<button class="button primary small" type="submit">Retry shown failures ({{ count($retryable) }})</button></form>@endif
</div><div class="table-scroll"><table><thead><tr><th>Job</th><th>Queue</th><th>Status</th><th>Attempts</th></tr></thead><tbody>
@forelse($jobs as $job)<tr><td><a class="job-name" href="{{ route('current.show', ['id' => $job->id]) }}">{{ class_basename($job->job_class) }}</a><div class="job-uuid">{{ $job->external_id }}</div></td><td>{{ $job->queue }}</td><td>@include('current::status', ['status' => $job->display_status])</td><td>{{ $job->attempt_count }}</td></tr>
@empty<tr><td colspan="4">No retained jobs with captured membership in this batch.</td></tr>@endforelse
</tbody></table></div>
<div class="panel-footnote">Only captured batch membership appears here. @if($batch->cancelled())Retries do not uncancel this batch; application cancellation middleware may skip them.@endif</div>
</section>
<div class="pagination">{{ $jobs->links() }}</div>
</div>
@endsection
