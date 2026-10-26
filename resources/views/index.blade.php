@extends('current::layout')
@section('title', $pageTitle)
@section('content')
@if(request()->header('X-Current-Refresh') !== '1')
<div class="page-heading"><div><h1>{{ $pageTitle }}</h1></div></div>
@include('current::filters')
@endif
@include('current::job-results')
@include('current::coverage')
@endsection
