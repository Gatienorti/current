@php
    $compactFilters = $compactFilters ?? false;
    $showException = $section === 'failed' || ($filters['status'] ?? '') === 'failed';
    $activeFilters = array_filter(array_diff_key($filters, array_merge($scopeStatus ? ['status' => true] : [], ['from' => true, 'to' => true])));
    $hasTimeFilter = !empty($filters['from']) || !empty($filters['to']);
@endphp
<section class="panel filters-panel {{ $compactFilters ? 'compact-filters' : '' }}">
    @unless($compactFilters)<div class="panel-heading"><h2><span class="filter-icon">@include('horizon-lens::icon', ['iconName' => 'filter'])</span> Deep filters</h2><span class="subtle">All filters combine</span></div>@endunless
    <form method="get" action="{{ route($pageRoute) }}" class="filters" data-job-filters>
        @if($compactFilters)
            @include('horizon-lens::filter-field', ['field' => 'queue'])
            @include('horizon-lens::filter-field', ['field' => 'job_class'])
            @include('horizon-lens::time-range')
            <div class="filter-actions"><button class="button primary" type="submit">Apply filters @include('horizon-lens::icon', ['iconName' => 'enter'])</button><a class="text-link" href="{{ route($pageRoute) }}">Reset</a></div>
            <details class="additional-filters" @if(!empty($filters['history']) || !empty($filters['job_id']) || !empty($filters['tags']) || ($showException && !empty($filters['exception_class'])) || ($section === 'monitoring' && !empty($filters['status']))) open @endif>
                <summary>@include('horizon-lens::icon', ['iconName' => 'chevron-right', 'iconClass' => 'more-filters-chevron'])More filters <span class="subtle">{{ $section === 'monitoring' ? 'Status, job ID, tags, history' : 'Job ID, tags, history' }}{{ $showException ? ', exception' : '' }}</span></summary>
                <div class="additional-filter-fields">
                    @if($section === 'monitoring')@include('horizon-lens::filter-field', ['field' => 'status'])@endif
                    @include('horizon-lens::filter-field', ['field' => 'job_id'])
                    @include('horizon-lens::filter-field', ['field' => 'tags'])
                    @include('horizon-lens::filter-field', ['field' => 'history'])
                    @if($showException || $section === 'monitoring')@include('horizon-lens::filter-field', ['field' => 'exception_class'])@endif
                </div>
            </details>
        @else
            <label>Status<select name="status"><option value="">Any status</option>@foreach(['pending','processing','completed','failed','unknown'] as $value)<option @selected(($filters['status'] ?? '') === $value) value="{{ $value }}">{{ ucfirst($value) }}</option>@endforeach</select></label>
            @foreach(['queue','job_class','job_id','tags','exception_class'] as $field)@include('horizon-lens::filter-field')@endforeach
            @include('horizon-lens::time-range')
            <div class="filter-actions"><button class="button primary" type="submit">Apply filters @include('horizon-lens::icon', ['iconName' => 'enter'])</button><a class="text-link" href="{{ route($pageRoute) }}">Reset</a></div>
        @endif
    </form>
    @if($errors->any())<div class="validation-error">{{ $errors->first() }}</div>@endif
    @if($activeFilters || $hasTimeFilter)<div class="active-filters"><span>ACTIVE</span>@foreach($activeFilters as $key => $value)<a class="filter-chip" href="{{ route($pageRoute, array_diff_key($filters, [$key => true])) }}">{{ str_replace('_', ' ', $key) }}: {{ $value }} @include('horizon-lens::icon', ['iconName' => 'x'])</a>@endforeach @if($hasTimeFilter)<a class="filter-chip" href="{{ route($pageRoute, array_diff_key($filters, ['from' => true, 'to' => true])) }}">time: {{ str_replace('T', ' ', $filters['from'] ?? 'Any start') }} → {{ str_replace('T', ' ', $filters['to'] ?? 'Now') }} UTC @include('horizon-lens::icon', ['iconName' => 'x'])</a>@endif</div>@endif
</section>
