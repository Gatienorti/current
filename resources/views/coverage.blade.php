<div data-live-region="coverage">
@if($health)
<div class="coverage-note"><span>@include('current::icon', ['iconName' => 'info'])</span><div><strong>Recorder failure observed {{ gmdate('M j, H:i:s', (int) $health['last_failure_at']) }} UTC; some telemetry may be missing.</strong></div></div>
@endif

@php($collectorHealth = app(\Current\Telemetry\Health::class)->snapshot())
@if($collectorHealth && $collectorHealth['warning'])
<div class="coverage-note"><span>@include('current::icon', ['iconName' => 'info'])</span><div>
@if(!$collectorHealth['available'])
<strong>Telemetry transport unavailable; observations may be missing.</strong>
@else
<strong>{{ $collectorHealth['running'] ? 'Telemetry collector needs attention.' : 'Telemetry collector is not running.' }}</strong>
{{ $collectorHealth['pending'] }} queued · {{ $collectorHealth['lag_seconds'] }}s oldest event.
@if($collectorHealth['storage_error']) Storage is unavailable; accepted events remain queued within the buffer limits. @endif
@if($collectorHealth['dropped'] || $collectorHealth['unaccounted']) Some observations were rejected, expired, or could not be accounted for. @endif
@endif
</div></div>
@endif
</div>
