@if($job->unfinished_attempts > 1)
    <span class="history-indicator" title="Multiple starts have no recorded finish. Their execution overlap and outcomes are not confirmed.">Multiple open attempts</span>
@elseif($job->earlier_unfinished_attempts > 0)
    <span class="history-indicator" title="An earlier attempt has no recorded finish. The latest observed outcome is shown separately.">Earlier attempt unfinished</span>
@elseif($job->unfinished_attempts > 0 && $job->oldest_unfinished_at < microtime(true) - config('current.stale_after_seconds', 300))
    <span class="history-indicator" title="This attempt may still be running. Elapsed time alone cannot establish worker loss.">Finish not observed</span>
@endif
@if($job->coverage === 'start_only')<span class="history-indicator">Enqueue not observed</span>@endif
