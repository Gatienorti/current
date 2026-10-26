@extends('current::layout')
@section('title', 'Compare broadcasts')
@section('content')
<div class="page-heading"><div><h1>Compare broadcasts</h1></div></div>
<section class="panel"><div class="context-body"><p>Baseline: <a class="text-link" href="{{ route('current.broadcasts.show', ['id' => $left->id]) }}">{{ $left->event }} · {{ substr($left->id, -8) }}</a></p>
<form method="get" class="filters" data-job-filters><div class="filter-field"><label for="comparison-recent">Recent submissions</label><select id="comparison-recent" name="with" data-filter-dropdown><option value="">Choose a submission</option>
@if($right && !$candidates->contains('id', $right->id))<option value="{{ $right->id }}" selected>{{ $right->event }} · {{ $right->id }}</option>@endif
@foreach($candidates as $candidate)<option value="{{ $candidate->id }}" @selected($right?->id === $candidate->id)>{{ $candidate->event }} · {{ $candidate->outcome }} · {{ gmdate('M j H:i:s', (int)$candidate->started_at) }} · {{ substr($candidate->id, -8) }}</option>@endforeach
</select></div><div class="filter-actions"><button class="button primary">Compare</button></div></form>
<details><summary>Compare by observation ID</summary><form method="get" class="filters"><label>Observation ID<input name="with" maxlength="26" required placeholder="26-character Current observation ID"></label><button class="button secondary">Compare ID</button></form></details>
@if($errors->any())<p class="validation-error">{{ $errors->first() }}</p>@endif
<p class="small-note">Latest 100 retained submissions are offered. Select any event, or enter an older retained observation ID. Left → right shows changes, not a causal sequence.</p></div></section>
@if($right)
<div class="job-summary-grid">
@foreach(['Baseline' => $left, 'Comparison' => $right] as $label => $entry)<section class="panel"><div class="panel-heading"><h2>{{ $label }}</h2>@include('current::broadcast-status', ['broadcast' => $entry])</div><div class="context-body"><a class="text-link" href="{{ route('current.broadcasts.show', ['id' => $entry->id]) }}">{{ $entry->event }}</a><p class="mono subtle">{{ $entry->id }}</p><p>{{ gmdate('M j, H:i:s', (int)$entry->started_at) }} UTC · {{ $entry->duration_ms !== null ? number_format($entry->duration_ms, 1).' ms' : 'Duration unavailable' }}</p></div></section>@endforeach
</div>
<section class="panel"><div class="panel-heading"><h2>Snapshot coverage</h2></div><div class="table-wrap"><table><thead><tr><th>Snapshot</th><th>Baseline</th><th>Comparison</th><th>Compared</th></tr></thead><tbody>@foreach($comparison['coverage'] as $field => $coverage)<tr><td>{{ ucfirst(str_replace('_', ' ', $field)) }}</td><td>{{ $coverage['left'] }}</td><td>{{ $coverage['right'] }}</td><td>{{ $coverage['compared'] ? 'Yes' : 'Unavailable — not compared' }}</td></tr>@endforeach</tbody></table></div></section>
<section class="panel"><div class="panel-heading"><h2>Differences <span class="count-pill">{{ count($comparison['rows']) }}</span></h2></div><div class="table-wrap"><table class="broadcast-diff"><thead><tr><th>Field / change</th><th>Baseline</th><th>Comparison</th></tr></thead><tbody>
@forelse($comparison['rows'] as $row)<tr><td><span class="soft-badge">{{ $row['kind'] }}</span><div class="mono">{{ $row['path'] }}</div></td><td><pre>{{ $row['left'] }}</pre></td><td><pre>{{ $row['right'] }}</pre></td></tr>@empty<tr><td colspan="3">No differences found in compared fields. Unavailable snapshots and redacted values cannot establish equality of the original data.</td></tr>@endforelse
</tbody></table></div><div class="panel-footnote">@if($comparison['limited'])Comparison limited to 200 differences and 12 levels. @endif Only fully captured snapshots are compared. Missing keys refer to exported snapshots, not the complete event payload. Arrays compare by position; channel lists may differ only in order. Values longer than 2,000 bytes are shortened. This page does not auto-refresh.</div></section>
@endif
@endsection
