    @if($steps->isNotEmpty())
    <div class="execution-map-canvas" data-execution-map data-follow-default="{{ $watch ? '1' : '0' }}" data-map-key="{{ $job->id }}:{{ $attempt?->id ?? 'none' }}">
    <div class="step-map-scroll" data-map-viewport aria-describedby="map-instructions" role="region" aria-label="Recorded execution map" tabindex="0"><div class="step-map-track" data-map-track>
        @foreach($steps as $step)
            @unless($loop->first)<span class="map-order-connector" aria-hidden="true">@include('horizon-lens::icon', ['iconName' => 'arrow-right'])</span>@endunless
            <a class="step-map-node {{ $step->status }} {{ $step->parent_id ? 'nested' : '' }}" data-step-target="step-{{ $step->id }}" aria-controls="step-{{ $step->id }}" href="#step-{{ $step->id }}"><span class="map-node-state">@include('horizon-lens::icon', ['iconName' => $step->status === 'completed' ? 'circle-check' : ($step->status === 'failed' ? 'circle-x' : 'clock')])</span><span class="map-node-label"><strong>{{ $step->label }}</strong><small>{{ $step->status === 'running' ? 'No finish observed' : ucfirst($step->status) }}{{ $step->parent_id ? ' · Nested block' : '' }}</small></span>@if($step->finished_at)<span class="map-node-duration">{{ number_format(($step->finished_at - $step->started_at) * 1000, 1) }} ms</span>@endif</a>
        @endforeach
    </div></div>
    <div class="map-controls" data-map-controls hidden role="group" aria-label="Execution map view">
        <button type="button" data-map-action="in" aria-label="Zoom in" title="Zoom in">@include('horizon-lens::icon', ['iconName' => 'plus'])</button>
        <button type="button" data-map-action="out" aria-label="Zoom out" title="Zoom out">@include('horizon-lens::icon', ['iconName' => 'minus'])</button>
        <button type="button" data-map-action="fit" aria-label="Fit view" title="Fit view" aria-pressed="true">@include('horizon-lens::icon', ['iconName' => 'fit'])<span>Fit view</span></button>
        <button type="button" data-map-action="actual" aria-label="Actual size" title="Actual size" aria-pressed="false">100%</button>
        @if($watch)<button type="button" data-map-action="follow" aria-label="Follow live execution" aria-pressed="true">Follow live</button>@endif
        <output data-map-scale aria-label="Map zoom">100%</output>
    </div>
    <button type="button" class="map-minimap" data-map-minimap hidden aria-label="Map overview: click or drag to navigate; press Enter to fit"><svg aria-hidden="true"></svg></button>
    </div>
    <div class="panel-footnote"><span id="map-instructions">Drag to pan · Scroll or +/− to zoom · 0 to fit.</span> Arrows show recorded start order, not job dependencies. Only captured steps appear. Up to {{ config('horizon-lens.max_steps') }} steps are retained; work outside named steps is not traced.</div>
    @else
    <div class="empty-state compact"><div class="empty-symbol">@include('horizon-lens::icon', ['iconName' => 'route'])</div><h3>No internal steps captured</h3><p>Queue events show this attempt’s boundary. Named steps need instrumentation; expand Source outline for possible calls.</p></div>
    @endif
