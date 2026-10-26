@extends('current::layout')
@section('title', 'Monitoring')
@section('content')
@if(request()->header('X-Current-Refresh') !== '1')
<div class="page-heading"><div><h1>Monitoring</h1></div><a class="button secondary" href="{{ route('current.recent') }}">Recent jobs @include('current::icon', ['iconName' => 'arrow-right'])</a></div>
@include('current::filters')
@endif
@include('current::job-results')
@include('current::coverage')
@endsection
