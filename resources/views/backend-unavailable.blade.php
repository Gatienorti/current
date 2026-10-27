@extends('current::layout')
@section('title', 'Unavailable')
@section('content')
<div class="page-heading"><h1>Unavailable</h1></div>
<section class="panel"><div class="empty-state"><h3>This capability is not available with the native backend yet</h3><a class="button secondary" href="{{ route('current.monitoring') }}">Monitoring</a></div></section>
@endsection
