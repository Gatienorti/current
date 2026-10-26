<div class="time-range" data-time-range>
    <span class="field-label" id="observed-time-label">Observed time <span class="subtle">· UTC</span></span>
    <details class="range-picker">
        <summary aria-labelledby="observed-time-label range-value">@include('current::icon', ['iconName' => 'calendar'])<span id="range-value" data-range-label>{{ empty($filters['from']) && empty($filters['to']) ? 'Any time' : str_replace('T', ' ', $filters['from'] ?? 'Any start').' → '.str_replace('T', ' ', $filters['to'] ?? 'Now') }}</span>@include('current::icon', ['iconName' => 'chevron-down', 'iconClass' => 'range-chevron'])</summary>
        <div class="range-popover">
            <div class="range-presets" aria-label="Quick time ranges">
                <span class="range-section-label">QUICK RANGES</span>
                @foreach([5 => 'Last 5 minutes', 15 => 'Last 15 minutes', 30 => 'Last 30 minutes', 60 => 'Last hour', 720 => 'Last 12 hours', 1440 => 'Last day', 10080 => 'Last 7 days'] as $minutes => $label)
                <button type="button" data-range-minutes="{{ $minutes }}">{{ $label }}</button>
                @endforeach
                <button type="button" data-range-clear>Any time</button>
            </div>
            <div class="range-custom">
                <span class="range-section-label">CUSTOM RANGE · UTC</span>
                <div data-calendar hidden>
                    <div class="calendar-toolbar"><button type="button" data-month-back aria-label="Previous month">@include('current::icon', ['iconName' => 'chevron-left'])</button><span data-month-label aria-live="polite"></span><button type="button" data-month-next aria-label="Next month">@include('current::icon', ['iconName' => 'chevron-right'])</button></div>
                    <div class="calendar-weekdays" aria-hidden="true">@foreach(['M','T','W','T','F','S','S'] as $day)<span>{{ $day }}</span>@endforeach</div>
                    <div class="calendar-days" data-calendar-days aria-label="Choose date range"></div>
                    <p class="calendar-hint" data-calendar-hint aria-live="polite">Choose a start date, then an end date.</p>
                    <div class="range-endpoints"><label>From <span data-start-date></span><input type="time" step="1" data-start-time aria-label="Start time in UTC" value="00:00:00"></label><label>Before <span data-end-date></span><input type="time" step="1" data-end-time aria-label="End time in UTC" value="23:59:59"></label></div>
                </div>
                <div data-range-fallback><label>From · UTC<input type="datetime-local" step="1" name="from" value="{{ $filters['from'] ?? '' }}"></label><label>Before · UTC<input type="datetime-local" step="1" name="to" value="{{ $filters['to'] ?? '' }}"></label></div>
                <p class="range-error" data-range-error role="alert" hidden></p>
                <div class="range-footer"><span>{{ $timeCaption ?? 'Latest job observation' }}</span><button type="button" class="button primary small" data-range-apply>Set range</button></div>
            </div>
        </div>
    </details>
</div>
