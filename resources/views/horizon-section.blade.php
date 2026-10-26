@extends('horizon-lens::layout')
@section('title', $title)
@section('content')
<div class="page-heading"><div><h1>{{ $title }}<span class="heading-dot">.</span></h1></div></div>
<section class="panel" data-live-region="horizon-section">
@if($section === 'metrics')
<nav class="metric-tabs" aria-label="Metric categories">
    @foreach(['jobs' => 'Jobs', 'queues' => 'Queues'] as $kind => $label)
    <a class="metric-tab {{ $metricKind === $kind ? 'active' : '' }}" href="{{ route('horizon-lens.metrics.'.$kind) }}" @if($metricKind === $kind) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
@endif
<div class="panel-heading"><h2>{{ $section === 'metrics' ? ($metricKind === 'jobs' ? 'Job metrics' : 'Queue metrics') : $title }}</h2><span class="subtle">Read only · existing repository</span></div>
@if(!$available)
    <div class="empty-state"><div class="empty-symbol">@include('horizon-lens::icon', ['iconName' => 'info'])</div><h3>{{ $title === 'Batches' ? 'Batch storage is unavailable' : 'Horizon data is unavailable' }}</h3><p>{{ $title === 'Batches' ? 'The configured Laravel batch repository could not be read. This lab may not have a batch table yet.' : 'The source could not be read. This is a coverage gap, not evidence that no jobs exist.' }}</p></div>
@else
    <div class="table-scroll"><table><thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>@forelse($rows as $row)<tr>@foreach($row['cells'] as $cell)<td>{{ $cell }}</td>@endforeach @if(isset($row['link']))<td><a class="text-link" href="{{ $row['link'] }}">{{ $row['link_label'] }}</a></td>@endif</tr>@empty<tr><td colspan="{{ count($headers) }}"><div class="empty-state"><div class="empty-symbol">@include('horizon-lens::icon', ['iconName' => 'search'])</div><h3>{{ $empty }}</h3><p>Only retained information available from the configured source appears here.</p></div></td></tr>@endforelse</tbody></table></div>
@endif
<div class="panel-footnote">{{ $note }}</div></section>
@endsection
