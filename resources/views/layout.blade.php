<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Jobs') · Current</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,{{ rawurlencode($currentMark) }}">
    <script src="{{ route('horizon-lens.theme', ['v' => $lensThemeVersion]) }}"></script>
    <script defer src="{{ route('horizon-lens.steps', ['v' => $lensStepsVersion]) }}"></script>
    <script defer src="{{ route('horizon-lens.filters', ['v' => $lensFiltersVersion]) }}"></script>
    <script defer src="{{ route('horizon-lens.refresh', ['v' => $lensRefreshVersion]) }}"></script>
    <script defer src="{{ route('horizon-lens.map', ['v' => $lensMapVersion]) }}"></script>
    <link rel="stylesheet" href="{{ route('horizon-lens.css', ['v' => $lensCssVersion]) }}">
</head>
<body>
@php
    $activeArea = request()->routeIs('horizon-lens.webhooks') ? 'webhooks' : (request()->routeIs('horizon-lens.broadcasts') ? 'broadcasts' : 'jobs');
    $activeSection = $section ?? 'monitoring';
    $jobPages = ['dashboard' => ['dashboard', 'Dashboard'], 'monitoring' => ['search', 'Monitoring'], 'metrics' => ['metrics', 'Metrics'], 'batches' => ['layers', 'Batches'], 'pending' => ['clock', 'Pending'], 'completed' => ['circle-check', 'Completed'], 'silenced' => ['bell-off', 'Silenced'], 'failed' => ['alert', 'Failed']];
@endphp
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('horizon-lens.index') }}" aria-label="Current dashboard"><span class="brand-mark" aria-hidden="true">{!! $currentMark !!}</span><span>current</span></a>
        <nav class="section-navigation" aria-label="{{ ucfirst($activeArea) }} navigation">
        @if($activeArea === 'jobs')
            @foreach($jobPages as $key => [$icon, $label])
                <a class="nav-item {{ $activeSection === $key ? 'selected' : '' }}" @if($activeSection === $key) aria-current="page" @endif href="{{ route('horizon-lens.'.$key) }}">@include('horizon-lens::icon', ['iconName' => $icon]){{ $label }}</a>
            @endforeach
        @else
            <div class="nav-label">{{ strtoupper($activeArea) }}</div>
            <a class="nav-item selected" aria-current="page" href="{{ route('horizon-lens.'.$activeArea) }}">@include('horizon-lens::icon', ['iconName' => 'dashboard'])Overview</a>
            @foreach($activeArea === 'webhooks' ? ['Incoming receipts', 'Outgoing deliveries'] : ['Events', 'Reverb inspection'] as $label)
                <span class="nav-item muted" aria-disabled="true">{{ $label }}<small>Planned</small></span>
            @endforeach
        @endif
        </nav>
        <div class="sidebar-bottom"><p>Execution by Horizon.<br>Investigation with Current.</p><a href="{{ route('horizon.index') }}">Open Horizon @include('horizon-lens::icon', ['iconName' => 'external'])</a></div>
    </aside>
    <div class="main-shell">
        <header class="topbar">
            <nav class="product-tabs" aria-label="Product areas">
                <a class="product-tab {{ $activeArea === 'jobs' ? 'active' : '' }}" @if($activeArea === 'jobs') aria-current="page" @endif href="{{ route('horizon-lens.index') }}">@include('horizon-lens::icon', ['iconName' => 'jobs'])Jobs</a>
                <a class="product-tab {{ $activeArea === 'webhooks' ? 'active' : '' }}" @if($activeArea === 'webhooks') aria-current="page" @endif href="{{ route('horizon-lens.webhooks') }}">@include('horizon-lens::icon', ['iconName' => 'webhook'])Webhooks</a>
                <a class="product-tab {{ $activeArea === 'broadcasts' ? 'active' : '' }}" @if($activeArea === 'broadcasts') aria-current="page" @endif href="{{ route('horizon-lens.broadcasts') }}">@include('horizon-lens::icon', ['iconName' => 'broadcast'])Broadcasts</a>
            </nav>
            <div class="topbar-right">
                <button type="button" class="refresh-toggle" data-auto-refresh hidden aria-pressed="true" aria-label="Pause automatic refresh">@include('horizon-lens::icon', ['iconName' => 'refresh'])<span data-refresh-label>Auto · 5s</span></button>
                <details class="accent-picker" data-accent-picker hidden>
                    <summary aria-label="Choose accent color" title="Accent color"><span class="accent-preview" aria-hidden="true"></span><span data-accent-label>Violet</span>@include('horizon-lens::icon', ['iconName' => 'chevron-down'])</summary>
                    <div class="accent-menu" role="group" aria-label="Accent color">
                        <div class="accent-menu-label">ACCENT COLOR</div>
                        @foreach(['violet' => 'Violet', 'emerald' => 'Emerald', 'blue' => 'Blue', 'rose' => 'Rose', 'orange' => 'Orange'] as $accentValue => $accentLabel)
                        <button type="button" data-accent-choice="{{ $accentValue }}" aria-label="{{ $accentLabel }} accent" aria-pressed="{{ $accentValue === 'violet' ? 'true' : 'false' }}"><span class="accent-swatch" aria-hidden="true"></span>{{ $accentLabel }}<span class="accent-check">@include('horizon-lens::icon', ['iconName' => 'check'])</span></button>
                        @endforeach
                    </div>
                </details>
                <div class="theme-switcher" role="group" aria-label="Color theme">
                    <button type="button" data-theme-choice="light" aria-label="Light theme" aria-pressed="false" title="Light theme">@include('horizon-lens::icon', ['iconName' => 'sun'])</button>
                    <button type="button" data-theme-choice="dark" aria-label="Dark theme" aria-pressed="false" title="Dark theme">@include('horizon-lens::icon', ['iconName' => 'moon'])</button>
                    <button type="button" data-theme-choice="system" aria-label="System theme" aria-pressed="true" title="Follow system theme">@include('horizon-lens::icon', ['iconName' => 'monitor'])</button>
                </div>
            </div>
        </header>
        @if($activeArea === 'jobs')
        <nav class="mobile-job-nav" aria-label="Job pages">
            @foreach($jobPages as $key => [$icon, $label])
                <a href="{{ route('horizon-lens.'.$key) }}">{{ $label }}</a>
            @endforeach
        </nav>
        @endif
        <main>@yield('content')</main>
        <footer><span>Current</span><span>All timestamps in UTC <span class="footer-dot">·</span> Observations, with coverage shown</span></footer>
    </div>
</div>
<template id="lens-icon-chevron-down">@include('horizon-lens::icon', ['iconName' => 'chevron-down'])</template>
<template id="lens-icon-check">@include('horizon-lens::icon', ['iconName' => 'check'])</template>
</body>
</html>
