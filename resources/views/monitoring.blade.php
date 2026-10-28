@extends('current::layout')
@section('title', 'Monitoring')
@section('content')
@if(request()->header('X-Current-Refresh') !== '1')
<div class="page-heading"><div><h1>Monitoring</h1></div><div class="filter-actions"><a class="button secondary" href="{{ route('current.monitoring',array_merge(request()->query(),['manage_tags'=>1])) }}">Manage monitoring</a><a class="button secondary" href="{{ route('current.recent') }}">Recent jobs @include('current::icon', ['iconName' => 'arrow-right'])</a></div></div>
@include('current::filters')
@endif
@if(request()->boolean('manage_tags'))@include('current::monitored-tags')@endif
@include('current::job-results')
@include('current::coverage')
@endsection
