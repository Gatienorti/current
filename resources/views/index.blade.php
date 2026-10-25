@extends('horizon-lens::layout')
@section('title', $pageTitle)
@section('content')
<div class="page-heading"><div><h1>{{ $pageTitle }}<span class="heading-dot">.</span></h1><p>{{ $pageDescription }}</p></div></div>
@include('horizon-lens::filters')
@if($section === 'silenced')<p class="panel-footnote silenced-coverage">{{ $silencedCoverage ? 'Silenced classification captured since '.gmdate('M j, Y H:i:s', (int) $silencedCoverage).' UTC.' : 'No silenced classification has been captured yet.' }} Earlier records have unknown classification and are excluded here.</p>@endif
@include('horizon-lens::job-results')
@include('horizon-lens::coverage')
@endsection
