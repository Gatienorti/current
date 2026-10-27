@php
    $firstAttempt = $attempts->last();
    $queueToStart = $job->coverage === 'observed_enqueue' && $firstAttempt && $firstAttempt->started_at >= $job->first_observed_at ? $firstAttempt->started_at - $job->first_observed_at : null;
    $overviewFields = [
        'Queue' => $job->queue, 'Connection' => $job->connection,
        'Observed attempts' => $job->attempt_count,
        'Max attempts' => $job->max_tries === null ? 'Not captured' : ($job->max_tries === 0 ? 'Unlimited' : $job->max_tries),
        'Timeout' => $job->timeout === null ? 'Not captured' : $job->timeout.'s',
        'Backoff · seconds' => $job->backoff ?? 'Not captured',
        'Enqueue delay' => $job->delay_seconds === null ? 'Not captured' : $job->delay_seconds.'s',
        'First observed · UTC' => gmdate('M j, H:i:s', (int) $job->first_observed_at),
        'Expires · UTC' => gmdate('M j, H:i:s', (int) $job->expires_at),
    ];
@endphp
<div class="job-summary-grid" id="job-details">
    <section class="panel job-overview"><div class="panel-heading"><h2>Job details</h2><span class="subtle">{{ $job->coverage === 'observed_enqueue' ? 'Enqueue observed' : 'Partial enqueue history' }}</span></div><div class="job-overview-body"><code class="job-full-class">{{ $job->job_class }}</code><dl class="job-field-grid">@foreach($overviewFields as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl><div class="job-extra-fields"><span>Tags</span><div>@forelse($jobTags as $tag)<span class="tag">{{ $tag }}</span>@empty<span class="subtle">None captured</span>@endforelse</div></div>@if($job->retry_until)<div class="job-extra-fields"><span>Retry until · UTC</span><span>{{ gmdate('M j, H:i:s', (int) $job->retry_until) }}</span></div>@endif @if($job->retry_of)<div class="job-extra-fields"><span>Retry of</span><code>{{ $job->retry_of }}</code></div>@endif @if($job->batch_id)<div class="job-extra-fields"><span>Batch</span>@if(\Current\ExecutionBackend::native())<a class="text-link mono" href="{{ route('current.batches.show', ['id' => $job->batch_id]) }}">{{ $job->batch_id }}</a>@else<code>{{ $job->batch_id }}</code>@endif</div>@endif</div></section>
    <section class="panel job-lifecycle-panel"><div class="panel-heading"><h2>Lifecycle</h2><span class="subtle">{{ $attempt ? 'Selected attempt' : 'No start observed' }}</span></div><ol class="job-lifecycle">
        @if($job->payload_created_at)<li><span class="lifecycle-dot"></span><div><strong>Payload created</strong><small>{{ gmdate('M j, H:i:s', (int) $job->payload_created_at) }} UTC</small></div></li>@endif
        <li><span class="lifecycle-dot {{ $job->coverage === 'observed_enqueue' ? 'completed' : '' }}"></span><div><strong>{{ $job->coverage === 'observed_enqueue' ? 'Enqueue observed' : 'Enqueue not observed' }}</strong>@if($job->coverage === 'observed_enqueue')<small>{{ gmdate('M j, H:i:s', (int) $job->first_observed_at) }} UTC</small>@endif</div></li>
        @if($attempt)<li><span class="lifecycle-dot processing"></span><div><strong>Worker attempt {{ $attempt->reported_attempt }} started</strong><small>{{ gmdate('M j, H:i:s', (int) $attempt->started_at) }} UTC</small></div></li>
        <li><span class="lifecycle-dot {{ $attempt->status }}"></span><div><strong>{{ $attempt->finished_at ? ucfirst(str_replace('_', ' ', $attempt->status)).' observed' : 'Finish not observed' }}</strong>@if($attempt->finished_at)<small>{{ gmdate('M j, H:i:s', (int) $attempt->finished_at) }} UTC · {{ number_format(($attempt->finished_at - $attempt->started_at) * 1000, 1) }} ms</small>@else<small>This attempt may still be running.</small>@endif</div></li>@endif
    </ol>@if($queueToStart !== null)<div class="lifecycle-footnote">Enqueue → first start: {{ number_format($queueToStart * 1000, 1) }} ms <span class="subtle">· includes delay</span></div>@endif</section>
</div>
@include('current::retry-context')
