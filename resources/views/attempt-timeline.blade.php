@php
    $chronological = $attempts->reverse()->values();
    $hasEnqueueOrigin = $job->coverage === 'observed_enqueue' && $chronological->first() && $job->first_observed_at <= $chronological->first()->started_at;
    $timelineStart = $hasEnqueueOrigin ? (float) $job->first_observed_at : (float) ($chronological->first()?->started_at ?? 0);
    $timelineEnd = $chronological->reduce(fn ($end, $row) => max($end, (float) $row->started_at, (float) ($row->finished_at ?? $row->started_at)), $timelineStart);
    $timelineSpan = max(0.001, $timelineEnd - $timelineStart);
    $previousEnd = null;
    $hasUnresolved = false;
    $durationLabel = fn ($seconds) => $seconds < 1 ? number_format($seconds * 1000, 1).' ms' : number_format($seconds, 2).' s';
@endphp
@if($chronological->isNotEmpty())
<section class="panel attempt-timeline" aria-label="Attempt timeline" data-attempt-timeline>
    <div class="panel-heading"><h2>Attempt timeline</h2><div class="timeline-scale" role="group" aria-label="Timeline scale" hidden><button type="button" data-timeline-scale="true" aria-pressed="true">True scale</button><button type="button" data-timeline-scale="compressed" aria-pressed="false">Compressed</button></div></div>
    <div class="attempt-timeline-body" data-time-start="{{ $timelineStart }}" data-time-end="{{ $timelineEnd }}">
        @if($hasEnqueueOrigin)
        <p class="timeline-gap">Enqueue → first start <strong>{{ $durationLabel($chronological->first()->started_at - $timelineStart) }}</strong> <span class="subtle">including dispatch delay</span></p>
        @endif
        @foreach($chronological as $item)
            @php
                $start = (float) $item->started_at;
                $finish = $item->finished_at !== null ? (float) $item->finished_at : null;
                $left = max(0, min(100, ($start - $timelineStart) / $timelineSpan * 100));
                $width = $finish !== null && $finish >= $start ? max(0, min(100 - $left, ($finish - $start) / $timelineSpan * 100)) : 0;
            @endphp
            @if(!$hasUnresolved && $previousEnd !== null && $start > $previousEnd)<p class="timeline-gap">Between attempts <strong>{{ $durationLabel($start - $previousEnd) }}</strong> <span class="subtle">cause not captured</span></p>@endif
            <a class="attempt-timeline-row {{ $attempt?->id === $item->id ? 'selected' : '' }}" href="{{ route('current.show', ['id' => $job->id, 'attempt' => $item->id, 'return' => $returnTo]) }}#execution-map" @if($attempt?->id === $item->id) aria-current="page" @endif>
                <span><strong>Attempt {{ $loop->iteration }}</strong><small>{{ $item->display_label }}</small></span>
                <span class="attempt-time-track" aria-hidden="true"><span class="attempt-time-bar {{ $item->display_status }} {{ $finish === null ? 'unresolved' : '' }}" data-start="{{ $start }}" data-end="{{ $finish ?? $start }}" style="left:{{ $left }}%;width:{{ $width }}%"></span></span>
                <span class="mono timeline-duration">{{ $finish !== null && $finish >= $start ? $durationLabel($finish - $start) : 'Finish unknown' }}<small>+{{ $durationLabel(max(0, $start - $timelineStart)) }}</small></span>
            </a>
            @php
                $hasUnresolved = $hasUnresolved || $finish === null || $finish < $start;
                $previousEnd = $finish === null ? $previousEnd : max($previousEnd ?? $finish, $finish);
            @endphp
        @endforeach
    </div>
    <div class="panel-footnote"><span data-scale-description>True scale.</span> Offsets start at {{ $hasEnqueueOrigin ? 'observed enqueue' : 'the first observed attempt' }}. Gaps do not establish backoff or worker loss. Bars have a minimum visible width; open attempts show only their start.</div>
</section>
@endif
