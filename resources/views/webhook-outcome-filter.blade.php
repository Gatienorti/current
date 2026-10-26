<div class="filter-field"><label for="filter-outcome">Outcome</label><select id="filter-outcome" name="outcome" data-filter-dropdown>
<option value="">Any outcome</option>
@foreach(['http_error' => 'HTTP errors', 'unfinished' => 'Completion not observed'] + ($incoming ? ['invalid_signature' => 'Invalid signature', 'job_failed' => 'Linked job failed'] : ['transport_error' => 'Transport errors']) as $value => $label)
<option value="{{ $value }}" @selected(($filters['outcome'] ?? '') === $value)>{{ $label }}</option>
@endforeach
</select></div>
