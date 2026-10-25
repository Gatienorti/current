<svg class="icon {{ $iconClass ?? '' }}" data-icon="{{ $iconName }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($iconName)
    @case('plus')<path d="M12 5v14M5 12h14"/>@break
    @case('minus')<path d="M5 12h14"/>@break
    @case('fit')<path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5"/>@break
    @case('copy')<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>@break
    @case('dashboard')<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>@break
    @case('search')<circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 4.5 4.5"/>@break
    @case('metrics')<path d="M4 4v16h16M9 15v-4m5 4V6m5 9V9"/>@break
    @case('layers')<path d="m3 7 9-4 9 4-9 4-9-4Zm0 5 9 4 9-4M3 17l9 4 9-4"/>@break
    @case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
    @case('circle-check')<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>@break
    @case('circle-x')<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>@break
    @case('bell-off')<path d="m3 3 18 18M10 4.3a5 5 0 0 1 7 4.6v3.3l2 3.8m-4.5 1H5l2-4V9m3 11h4"/>@break
    @case('alert')<path d="m10.3 4.1-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-2.9l-8-14a2 2 0 0 0-3.4 0ZM12 9v4m0 4h.01"/>@break
    @case('jobs')<rect x="6" y="7" width="15" height="14" rx="2"/><path d="M17 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h1m4-5h7m-7 4h5"/>@break
    @case('webhook')<circle cx="12" cy="5" r="2.5"/><circle cx="5" cy="18" r="2.5"/><circle cx="19" cy="18" r="2.5"/><path d="m10.8 7.2-4.6 8.6m1.3 2.2h9m-3.3-10.8 4.6 8.6"/>@break
    @case('broadcast')<path d="M5 5a10 10 0 0 0 0 14m14-14a10 10 0 0 1 0 14M8 8a5.7 5.7 0 0 0 0 8m8-8a5.7 5.7 0 0 1 0 8"/><circle cx="12" cy="12" r="1.5"/>@break
    @case('refresh')<path d="M20 8a8 8 0 0 0-13-3L3 9m0-5v5h5m-4 7a8 8 0 0 0 13 3l4-4m0 5v-5h-5"/>@break
    @case('sun')<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.4 1.4m11.2 11.2L19 19M5 19l1.4-1.4M17.6 6.4 19 5"/>@break
    @case('moon')<path d="M20.5 13.5A9 9 0 0 1 10.5 3 9 9 0 1 0 20.5 13.5Z"/>@break
    @case('monitor')<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4"/>@break
    @case('chevron-down')<path d="m6 9 6 6 6-6"/>@break
    @case('chevron-right')<path d="m9 6 6 6-6 6"/>@break
    @case('chevron-left')<path d="m15 6-6 6 6 6"/>@break
    @case('check')<path d="m5 12 4.5 4.5L19 7"/>@break
    @case('x')<path d="m6 6 12 12M18 6 6 18"/>@break
    @case('arrow-right')<path d="M4 12h16m-6-6 6 6-6 6"/>@break
    @case('arrow-left')<path d="M20 12H4m6-6-6 6 6 6"/>@break
    @case('arrow-down')<path d="M12 4v16m-6-6 6 6 6-6"/>@break
    @case('external')<path d="M14 3h7v7m0-7L11 13m-1-9H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h13a2 2 0 0 0 2-2v-5"/>@break
    @case('calendar')<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18m-14 4h2m4 0h2m-8 3h2"/>@break
    @case('info')<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/>@break
    @case('filter')<path d="M3 6h8m4 0h6M3 12h2m4 0h12M3 18h12m4 0h2M11 3v6M5 9v6m10 0v6"/>@break
    @case('enter')<path d="M20 5v7a3 3 0 0 1-3 3H4m5-5-5 5 5 5"/>@break
    @case('branch')<path d="M5 3v10a3 3 0 0 0 3 3h12m-5-5 5 5-5 5"/>@break
    @case('route')<circle cx="5" cy="5" r="2"/><circle cx="19" cy="19" r="2"/><path d="M7 5h9a4 4 0 0 1 0 8H8a3 3 0 0 0 0 6h9"/>@break
@endswitch
</svg>
