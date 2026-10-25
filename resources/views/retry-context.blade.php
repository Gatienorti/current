@if($job->retry_of || $retryContext['children']->isNotEmpty())
<section class="panel" aria-label="Retry history">
    <div class="panel-heading"><h2>Retry history</h2></div>
    <div class="context-body">
        @if($job->retry_of)
        <p>Retried from
            @if($retryContext['parent'])
            <a class="text-link mono" href="{{ route('horizon-lens.show', ['id' => $retryContext['parent']->id]) }}">{{ $job->retry_of }}</a>
            @else
            <span class="mono">{{ $job->retry_of }}</span> <span class="subtle">· Original observation unavailable or ambiguous</span>
            @endif
        </p>
        @endif
        @foreach($retryContext['children'] as $child)
        <p><a class="text-link mono" href="{{ route('horizon-lens.show', ['id' => $child->id, 'watch' => 1]) }}#execution-map">View retry {{ $child->external_id }}</a> @include('horizon-lens::status', ['status' => $child->display_status]) <span class="subtle">{{ gmdate('M j, H:i:s', (int) $child->first_observed_at) }} UTC</span></p>
        @endforeach
        @if($retryContext['limited'])<p class="small-note">Showing up to 20 recent retry observations.</p>@endif
    </div>
</section>
@endif
