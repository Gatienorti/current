@php
    $compactFilters = $compactFilters ?? false;
    $showException = $section === 'failed' || ($filters['status'] ?? '') === 'failed';
    $activeFilters = array_filter(array_diff_key($filters, array_merge($scopeStatus ? ['status' => true] : [], ['from' => true, 'to' => true])));
    $hasTimeFilter = !empty($filters['from']) || !empty($filters['to']);
@endphp
<section class="panel filters-panel {{ $compactFilters ? 'compact-filters' : '' }}">
    @unless($compactFilters)<div class="panel-heading"><h2><span class="filter-icon">@include('current::icon', ['iconName' => 'filter'])</span> Deep filters</h2><span class="subtle">All filters combine</span></div>@endunless
    <form method="get" action="{{ route($pageRoute) }}" class="filters" data-job-filters>
        @if(!empty($filters['connection']))<input type="hidden" name="connection" value="{{ $filters['connection'] }}">@endif
        @if($compactFilters)
            @include('current::filter-field', ['field' => 'queue'])
            @include('current::filter-field', ['field' => 'job_class'])
            @include('current::time-range')
            <div class="filter-actions"><button class="button primary" type="submit">Apply filters @include('current::icon', ['iconName' => 'enter'])</button><a class="text-link" href="{{ route($pageRoute) }}">Reset</a></div>
            <details class="additional-filters" @if(!empty($filters['history']) || !empty($filters['job_id']) || !empty($filters['tags']) || ($showException && !empty($filters['exception_class'])) || ($section === 'monitoring' && !empty($filters['status']))) open @endif>
                <summary>@include('current::icon', ['iconName' => 'chevron-right', 'iconClass' => 'more-filters-chevron'])More filters <span class="subtle">{{ $section === 'monitoring' ? 'Status, job ID, tags, history' : 'Job ID, tags, history' }}{{ $showException ? ', exception' : '' }}</span></summary>
                <div class="additional-filter-fields">
                    @if($section === 'monitoring')@include('current::filter-field', ['field' => 'status'])@endif
                    @include('current::filter-field', ['field' => 'job_id'])
                    @include('current::filter-field', ['field' => 'tags'])
                    @include('current::filter-field', ['field' => 'history'])
                    @if($showException || $section === 'monitoring')@include('current::filter-field', ['field' => 'exception_class'])@endif
                </div>
            </details>
        @else
            <label>Status<select name="status"><option value="">Any status</option>@foreach(['pending','processing','completed','failed','unknown'] as $value)<option @selected(($filters['status'] ?? '') === $value) value="{{ $value }}">{{ ucfirst($value) }}</option>@endforeach</select></label>
            @foreach(['queue','job_class','job_id','tags','exception_class'] as $field)@include('current::filter-field')@endforeach
            @include('current::time-range')
            <div class="filter-actions"><button class="button primary" type="submit">Apply filters @include('current::icon', ['iconName' => 'enter'])</button><a class="text-link" href="{{ route($pageRoute) }}">Reset</a></div>
        @endif
    </form>
    @if($errors->any())<div class="validation-error">{{ $errors->first() }}</div>@endif
    @if($activeFilters || $hasTimeFilter)<div class="active-filters"><span>ACTIVE</span>@foreach($activeFilters as $key => $value)
        @foreach(is_array($value) ? $value : [$value] as $selectedValue)
            @php
                $remaining = is_array($value) ? array_values(array_filter($value, fn ($item) => $item !== $selectedValue)) : [];
                $chipFilters = $remaining ? array_replace($filters, [$key => $remaining]) : array_diff_key($filters, [$key => true]);
            @endphp
            <a class="filter-chip" aria-label="Remove {{ str_replace('_', ' ', $key) }}: {{ $selectedValue }}" href="{{ route($pageRoute, $chipFilters) }}">{{ str_replace('_', ' ', $key) }}: {{ $selectedValue }} @include('current::icon', ['iconName' => 'x'])</a>
        @endforeach
    @endforeach @if($hasTimeFilter)<a class="filter-chip" href="{{ route($pageRoute, array_diff_key($filters, ['from' => true, 'to' => true])) }}">time: {{ str_replace('T', ' ', $filters['from'] ?? 'Any start') }} → {{ str_replace('T', ' ', $filters['to'] ?? 'Now') }} UTC @include('current::icon', ['iconName' => 'x'])</a>@endif</div>@endif
</section>
