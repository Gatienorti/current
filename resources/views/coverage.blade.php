@if($health)
<div class="coverage-note" data-live-region="coverage"><span>@include('horizon-lens::icon', ['iconName' => 'info'])</span><div><strong>Recorder failure observed {{ gmdate('M j, H:i:s', (int) $health['last_failure_at']) }} UTC; some telemetry may be missing.</strong></div></div>
@endif
