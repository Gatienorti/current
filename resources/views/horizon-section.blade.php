@extends('current::layout')
@section('title', $title)
@section('content')
@if(request()->header('X-Current-Refresh') !== '1')
<div class="page-heading"><div><h1>{{ $title }}</h1></div></div>
@endif
<section class="panel">
@if(isset($batchQuery))
<form method="get" action="{{ route('current.batches') }}" class="panel-heading batch-search" role="search" aria-label="Find a batch">
    @if($batchSearchSupported)
    <div class="batch-search-field">
    <label for="batch-query">Name or batch ID</label>
    <input id="batch-query" name="query" type="search" value="{{ $batchQuery }}" maxlength="100" placeholder="Find a batch">
    </div>
    <button class="button primary" type="submit">Search</button>
    @endif
    @if($batchBefore || $batchQuery !== '')<a class="text-link" href="{{ route('current.batches') }}">Reset</a>@endif
</form>
@endif
<div data-live-region="horizon-section">
@if($section === 'metrics')
<nav class="metric-tabs" aria-label="Metric categories">
    @foreach(['jobs' => 'Jobs', 'queues' => 'Queues'] as $kind => $label)
    <a class="metric-tab {{ $metricKind === $kind ? 'active' : '' }}" href="{{ route('current.metrics.'.$kind) }}" @if($metricKind === $kind) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
@endif
<div class="panel-heading"><h2>{{ $section === 'metrics' ? ($metricKind === 'jobs' ? 'Job metrics' : 'Queue metrics') : $title }}</h2><span class="subtle">{{ \Current\ExecutionBackend::native() && $section === 'metrics' ? 'Captured attempts · past hour' : 'Read only · existing repository' }}</span></div>
@if(!$available)
    <div class="empty-state"><div class="empty-symbol">@include('current::icon', ['iconName' => 'info'])</div><h3>{{ $title === 'Batches' ? 'Batch storage is unavailable' : (\Current\ExecutionBackend::native() ? 'Metrics are unavailable' : 'Horizon data is unavailable') }}</h3><p>{{ $title === 'Batches' ? 'The configured Laravel batch repository could not be read. This lab may not have a batch table yet.' : 'The source could not be read. This is a coverage gap, not evidence that no jobs exist.' }}</p></div>
@else
    <div class="table-scroll"><table><thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>@forelse($rows as $row)<tr>@foreach($row['cells'] as $cell)<td>{{ $cell }}</td>@endforeach @if(isset($row['link']))<td><a class="text-link" href="{{ $row['link'] }}">{{ $row['link_label'] }}</a></td>@endif</tr>@empty<tr><td colspan="{{ count($headers) }}"><div class="empty-state"><div class="empty-symbol">@include('current::icon', ['iconName' => 'search'])</div><h3>{{ $empty }}</h3><p>Only retained information available from the configured source appears here.</p></div></td></tr>@endforelse</tbody></table></div>
@endif
@if(!empty($batchNext) || !empty($batchBefore))
<nav class="panel-heading" aria-label="Batch pages">
    @if(!empty($batchBefore))<a class="text-link" href="{{ route('current.batches', array_filter(['query' => $batchQuery], fn ($value) => $value !== '')) }}">Latest matches</a>@endif
    @if(!empty($batchNext))<a class="text-link" href="{{ route('current.batches', array_filter(['query' => $batchQuery, 'before_id' => $batchNext], fn ($value) => $value !== '')) }}">Older batches →</a>@endif
</nav>
@endif
<div class="panel-footnote">{{ $note }}</div></div></section>
@endsection
