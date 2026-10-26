@extends('horizon-lens::layout')
@section('title', $pageTitle)
@section('content')
<div class="page-heading"><div><h1>{{ $pageTitle }}<span class="heading-dot">.</span></h1></div></div>
@include('horizon-lens::filters')
@include('horizon-lens::job-results')
@include('horizon-lens::coverage')
@endsection
