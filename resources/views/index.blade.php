@extends('current::layout')
@section('title', $pageTitle)
@section('content')
<div class="page-heading"><div><h1>{{ $pageTitle }}</h1></div></div>
@include('current::filters')
@include('current::job-results')
@include('current::coverage')
@endsection
