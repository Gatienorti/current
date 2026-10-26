@extends('horizon-lens::layout')
@section('title', 'Monitoring')
@section('content')
<div class="page-heading"><div><h1>Monitoring<span class="heading-dot">.</span></h1></div><a class="button secondary" href="{{ route('horizon-lens.recent') }}">Recent jobs @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a></div>
@include('horizon-lens::filters')
@include('horizon-lens::job-results')
@include('horizon-lens::coverage')
@endsection
