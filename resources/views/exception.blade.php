<div class="exception-block">
    <div class="eyebrow error-eyebrow">{{ $errorLabel ?? 'Failure location' }}</div>
    <h3 class="error-class">{{ class_basename($errorRecord->exception_class) }}</h3>
    <p class="error-message">{{ $errorRecord->exception_message ?? 'Message omitted by capture policy.' }}</p>
    <div class="source-location"><span>Exception location</span><br>{{ $errorRecord->exception_file ?? 'Source location outside configured roots' }}{{ $errorRecord->exception_file ? ':'.$errorRecord->exception_line : '' }}</div>
    @if($errorFrames)
    <details class="stack-trace"><summary>@include('current::icon', ['iconName' => 'chevron-right', 'iconClass' => 'stack-toggle'])Stack context <span>{{ count($errorFrames) }} frames</span></summary>
        @foreach($errorFrames as $frame)<div class="stack-frame"><code>{{ $frame['call'] }}</code><small>{{ $frame['file'] ?? 'External source' }}{{ $frame['file'] ? ':'.$frame['line'] : '' }}</small></div>@endforeach
        <p class="small-note">Up to {{ config('current.max_frames') }} frames retained. Arguments are omitted.</p>
    </details>
    @endif
    <p class="small-note">This is where the error surfaced; it does not establish root cause.</p>
</div>
