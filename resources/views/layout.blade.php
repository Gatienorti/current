@if(request()->header('X-Current-Refresh') === '1')
@yield('content')
@else
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Jobs') · Current</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,{{ rawurlencode($currentMark) }}">
    <script src="{{ route('current.theme', ['v' => $lensThemeVersion]) }}"></script>
    <script defer src="{{ route('current.steps', ['v' => $lensStepsVersion]) }}"></script>
    <script defer src="{{ route('current.filters', ['v' => $lensFiltersVersion]) }}"></script>
    <script defer src="{{ route('current.refresh', ['v' => $lensRefreshVersion]) }}"></script>
    <script defer src="{{ route('current.map', ['v' => $lensMapVersion]) }}"></script>
    <link rel="stylesheet" href="{{ route('current.css', ['v' => $lensCssVersion]) }}">
</head>
<body>
@php
    $activeArea = request()->routeIs('current.webhooks', 'current.webhooks.*') ? 'webhooks' : (request()->routeIs('current.broadcasts', 'current.broadcasts.*') ? 'broadcasts' : 'jobs');
    $activeSection = $section ?? 'monitoring';
    $jobPages = ['dashboard' => ['dashboard', 'Dashboard'], 'queues' => ['layers', 'Queues'], 'monitoring' => ['search', 'Monitoring'], 'metrics' => ['metrics', 'Metrics'], 'batches' => ['layers', 'Batches'], 'schedule' => ['calendar', 'Schedule'], 'pending' => ['clock', 'Pending'], 'completed' => ['circle-check', 'Completed'], 'silenced' => ['bell-off', 'Silenced'], 'failed' => ['alert', 'Failed']];
@endphp
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('current.index') }}" aria-label="Current dashboard"><span class="brand-mark" aria-hidden="true">{!! $currentMark !!}</span><span>current</span></a>
        <nav class="section-navigation" aria-label="{{ ucfirst($activeArea) }} navigation">
        @if($activeArea === 'jobs')
            @foreach($jobPages as $key => [$icon, $label])
                <a class="nav-item {{ $activeSection === $key ? 'selected' : '' }}" @if($activeSection === $key) aria-current="page" @endif href="{{ route('current.'.$key) }}">@include('current::icon', ['iconName' => $icon]){{ $label }}</a>
            @endforeach
        @elseif($activeArea === 'webhooks')
            <a class="nav-item {{ request()->routeIs('current.webhooks.dashboard') ? 'selected' : '' }}" href="{{ route('current.webhooks.dashboard') }}">@include('current::icon', ['iconName' => 'dashboard'])Dashboard</a>
            <a class="nav-item {{ request()->routeIs('current.webhooks', 'current.webhooks.show') ? 'selected' : '' }}" href="{{ route('current.webhooks') }}" >@include('current::icon', ['iconName' => 'webhook'])Incoming receipts</a>
            <a class="nav-item {{ request()->routeIs('current.webhooks.outgoing*') ? 'selected' : '' }}" href="{{ route('current.webhooks.outgoing') }}">@include('current::icon', ['iconName' => 'webhook'])Outgoing deliveries</a>
        @else
            <a class="nav-item {{ request()->routeIs('current.broadcasts.dashboard') ? 'selected' : '' }}" href="{{ route('current.broadcasts.dashboard') }}">@include('current::icon', ['iconName' => 'dashboard'])Dashboard</a>
            <a class="nav-item {{ request()->routeIs('current.broadcasts', 'current.broadcasts.show', 'current.broadcasts.compare') ? 'selected' : '' }}" href="{{ route('current.broadcasts') }}">@include('current::icon', ['iconName' => 'broadcast'])Submissions</a>
            <a class="nav-item {{ request()->routeIs('current.broadcasts.authorization') ? 'selected' : '' }}" href="{{ route('current.broadcasts.authorization') }}">@include('current::icon', ['iconName' => 'alert'])Authorization failures</a>
        @endif
        </nav>
        <div class="sidebar-bottom"><div class="package-version"><span>Current</span><span>{{ $currentVersion }}</span></div></div>
    </aside>
    <div class="main-shell">
        <header class="topbar">
            <nav class="product-tabs" aria-label="Product areas">
                <a class="product-tab {{ $activeArea === 'jobs' ? 'active' : '' }}" @if($activeArea === 'jobs') aria-current="page" @endif href="{{ route('current.index') }}">@include('current::icon', ['iconName' => 'jobs'])Queues &amp; Jobs</a>
                <a class="product-tab {{ $activeArea === 'webhooks' ? 'active' : '' }}" @if($activeArea === 'webhooks') aria-current="page" @endif href="{{ route('current.webhooks.dashboard') }}">@include('current::icon', ['iconName' => 'webhook'])Webhooks</a>
                @if($broadcastsVisible)<a class="product-tab {{ $activeArea === 'broadcasts' ? 'active' : '' }}" @if($activeArea === 'broadcasts') aria-current="page" @endif href="{{ route('current.broadcasts.dashboard') }}">@include('current::icon', ['iconName' => 'broadcast'])Broadcasts</a>@endif
            </nav>
            <div class="topbar-right">
                <details class="refresh-picker" data-refresh-picker hidden>
                    <summary class="refresh-toggle" data-auto-refresh aria-label="Live updates: 5 seconds">@include('current::icon', ['iconName' => 'refresh'])<span data-refresh-label>5s</span></summary>
                    <div class="refresh-menu" role="group" aria-label="Live updates">
                        @foreach([0 => 'Pause', 1 => '1s', 5 => '5s', 15 => '15s', 30 => '30s', 60 => '60s'] as $seconds => $label)
                        <button type="button" data-refresh-choice="{{ $seconds }}" aria-pressed="{{ $seconds === 5 ? 'true' : 'false' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </details>
                <details class="accent-picker" data-accent-picker hidden>
                    <summary aria-label="Choose accent color" title="Accent color"><span class="accent-preview" aria-hidden="true"></span><span data-accent-label>Violet</span>@include('current::icon', ['iconName' => 'chevron-down'])</summary>
                    <div class="accent-menu" role="group" aria-label="Accent color">
                        <div class="accent-menu-label">ACCENT COLOR</div>
                        @foreach(['violet' => 'Violet', 'emerald' => 'Emerald', 'blue' => 'Blue', 'rose' => 'Rose', 'orange' => 'Orange'] as $accentValue => $accentLabel)
                        <button type="button" data-accent-choice="{{ $accentValue }}" aria-label="{{ $accentLabel }} accent" aria-pressed="{{ $accentValue === 'violet' ? 'true' : 'false' }}"><span class="accent-swatch" aria-hidden="true"></span>{{ $accentLabel }}<span class="accent-check">@include('current::icon', ['iconName' => 'check'])</span></button>
                        @endforeach
                    </div>
                </details>
                <div class="theme-switcher" role="group" aria-label="Color theme">
                    <button type="button" data-theme-choice="light" aria-label="Light theme" aria-pressed="false" title="Light theme">@include('current::icon', ['iconName' => 'sun'])</button>
                    <button type="button" data-theme-choice="dark" aria-label="Dark theme" aria-pressed="false" title="Dark theme">@include('current::icon', ['iconName' => 'moon'])</button>
                    <button type="button" data-theme-choice="system" aria-label="System theme" aria-pressed="true" title="Follow system theme">@include('current::icon', ['iconName' => 'monitor'])</button>
                </div>
            </div>
        </header>
        @if($activeArea === 'jobs')
        <nav class="mobile-job-nav" aria-label="Job pages">
            @foreach($jobPages as $key => [$icon, $label])
                <a href="{{ route('current.'.$key) }}">{{ $label }}</a>
            @endforeach
        </nav>
        @endif
        <main>@yield('content')</main>
    </div>
</div>
<template id="lens-icon-chevron-down">@include('current::icon', ['iconName' => 'chevron-down'])</template>
<template id="lens-icon-check">@include('current::icon', ['iconName' => 'check'])</template>
</body>
</html>

@endif
