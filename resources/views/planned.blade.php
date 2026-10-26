@extends('horizon-lens::layout')
@section('title', ucfirst($area))
@section('content')
<div class="page-heading"><div><h1>{{ ucfirst($area) }}<span class="heading-dot">.</span></h1></div><span class="soft-badge">PLANNED</span></div>
<section class="panel planned-panel"><div class="empty-state"><div class="planned-symbol">@include('horizon-lens::icon', ['iconName' => $area === 'webhooks' ? 'webhook' : 'broadcast'])</div><h3>{{ $area === 'webhooks' ? 'Follow the work beyond the queue' : 'See where application messages go' }}</h3><p>{{ $area === 'webhooks' ? 'Incoming receipts and outgoing delivery attempts will be linked to the jobs that process them.' : 'Queued broadcasts will connect to their jobs and observed publishing outcomes. Reverb transport and client receipt remain separate observations.' }}</p><span class="planned-notice">This section is planned. No {{ $area }} telemetry is being collected.</span><a class="button primary" href="{{ route('horizon-lens.monitoring') }}">Explore jobs @include('horizon-lens::icon', ['iconName' => 'arrow-right'])</a></div></section>
@endsection
