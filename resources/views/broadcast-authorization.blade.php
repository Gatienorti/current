@extends('current::layout')
@section('title', 'Authorization failures')
@section('content')
<div class="page-heading"><div><h1>Authorization failures</h1></div></div>
@if(!config('current.broadcasts.authorization.enabled') || (!config('current.broadcasts.authorization.routes') && !config('current.broadcasts.authorization.paths')))<p class="observation-notice">Capture requires an explicit authorization route allowlist and broadcasts.authorization.enabled in Current configuration.</p>@endif
<section class="panel filters-panel compact-filters"><form class="filters" method="get" data-job-filters><label>Route<input name="route" value="{{ $filters['route'] ?? '' }}" placeholder="Exact approved route"></label><label>HTTP status<input name="status" type="number" min="400" max="599" value="{{ $filters['status'] ?? '' }}" placeholder="Any failure"></label>@include('current::time-range', ['timeCaption' => 'Observed time'])<div class="filter-actions"><button class="button primary">Apply filters</button><a class="text-link" href="{{ route('current.broadcasts.authorization') }}">Reset</a></div><details class="additional-filters" @if(!empty($filters['channel'])) open @endif><summary>More filters</summary><label>Approved channel pattern<input name="channel" value="{{ $filters['channel'] ?? '' }}" placeholder="Exact configured pattern"></label></details></form>@if($errors->any())<p class="validation-error">{{ $errors->first() }}</p>@endif</section>
<div data-live-region="broadcast-auth"><section class="panel"><div class="panel-heading"><h2>Failed authorization requests</h2><span class="subtle">Latest first · UTC</span></div><div class="table-wrap"><table><thead><tr><th>Route</th><th>HTTP outcome</th><th>Channel</th><th>Observed · UTC</th></tr></thead><tbody>
@forelse($failures as $failure)<tr><td class="mono">{{ $failure->method }} {{ $failure->route_name }}<div class="subtle">{{ substr($failure->id, -8) }}</div></td><td><span class="status status-failed">{{ $failure->http_status }}</span><div class="subtle">{{ match((int)$failure->http_status) { 401 => 'Unauthenticated response', 403 => 'Forbidden response', 419 => 'Session / CSRF response', 429 => 'Rate limited response', default => $failure->http_status >= 500 ? 'Server error response' : 'Request rejected' } }}</div></td><td>
@php
    $exactChannels = $failure->exact_channels_state === 'captured' ? (json_decode($failure->exact_channels_json ?? '[]', true) ?: []) : [];
    $channelPatterns = json_decode($failure->channels_json, true) ?: [];
@endphp
<div class="mono">{{ implode(', ', $exactChannels ?: $channelPatterns) ?: 'Channel not captured or not approved' }}</div>
<div class="subtle">{{ $failure->channel_type }}@if(!$exactChannels && $channelPatterns) · Pattern only
@endif</div>
</td><td>{{ gmdate('M j, H:i:s', (int)$failure->observed_at) }}</td></tr>
@empty<tr><td colspan="4">No retained authorization failures observed. Missing observations do not establish healthy subscriptions.</td></tr>@endforelse
</tbody></table></div><div class="panel-footnote">HTTP outcomes do not prove the underlying cause or that the channel callback ran. Redirects, successful responses, WebSocket handshakes and client receipt are not tracked. User identities, socket IDs, request headers and response bodies are not retained. No causal link to a submission is inferred.</div></section>
<div class="pagination">@if($failures->previousPageUrl())<a class="button secondary" href="{{ $failures->previousPageUrl() }}">Previous</a>@endif @if($failures->nextPageUrl())<a class="button secondary" href="{{ $failures->nextPageUrl() }}">Next</a>@endif</div></div>
@endsection
