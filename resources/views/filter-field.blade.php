@switch($field)
    @case('status')
        <div class="filter-field"><label for="filter-status">Status</label><select id="filter-status" name="status" data-filter-dropdown><option value="">Any status</option>@foreach(['pending','processing','completed','failed','unknown'] as $value)<option @selected(($filters['status'] ?? '') === $value) value="{{ $value }}">{{ ucfirst($value) }}</option>@endforeach</select></div>
        @break
    @case('history')
        <div class="filter-field"><label for="filter-history">History</label><select id="filter-history" name="history" data-filter-dropdown><option value="">Any history</option>@foreach(['unfinished' => 'Unfinished attempts', 'overlapping' => 'Multiple open attempts', 'partial' => 'Enqueue not observed'] as $value => $label)<option @selected(($filters['history'] ?? '') === $value) value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
        @break
    @case('queue')
        <div class="filter-field"><label for="filter-queue">Queue</label><select id="filter-queue" name="queue[]" multiple data-filter-dropdown><option value="">All queues</option>@foreach($queues->merge((array) ($filters['queue'] ?? []))->filter()->unique() as $queue)<option @selected(in_array($queue, (array) ($filters['queue'] ?? []), true))>{{ $queue }}</option>@endforeach</select></div>
        @break
    @case('job_class')
        <div class="filter-field"><label for="filter-job-class">Job class</label><input id="filter-job-class" data-filter-dropdown list="job-classes" name="job_class" value="{{ $filters['job_class'] ?? '' }}" placeholder="Any job class"><datalist id="job-classes">@foreach($classes as $class)<option value="{{ $class }}">@endforeach</datalist></div>
        @break
    @case('job_id')
        <label>Job ID<input name="job_id" value="{{ $filters['job_id'] ?? '' }}" placeholder="Exact queue UUID"></label>
        @break
    @case('tags')
        <label>Tags <span class="subtle">(comma separated)</span><input name="tags" value="{{ $filters['tags'] ?? '' }}" placeholder="order:demo-1042"></label>
        @break
    @case('exception_class')
        <div class="filter-field" data-exception-filter @if(!$showException) hidden @endif><label for="filter-exception">Exception class</label><input id="filter-exception" data-filter-dropdown list="exceptions" name="exception_class" value="{{ $filters['exception_class'] ?? '' }}" placeholder="Any exception" @disabled(!$showException)><datalist id="exceptions">@foreach($exceptions as $exception)<option value="{{ $exception }}">@endforeach</datalist></div>
        @break
@endswitch
