(() => {
    document.addEventListener('DOMContentLoaded', () => {
        const toggle = document.querySelector('[data-auto-refresh]');
        const regions = () => [...document.querySelectorAll('[data-live-region]')];
        if (!toggle || !regions().length) return;
        const interval = () => document.querySelector('[data-refresh-ms="1000"]') ? 1000 : 5000;
        const snapshots = new Map();
        let enabled = true, timer, inFlight = false, failures = 0, lastUpdated;
        const interacting = () => Boolean(
            document.querySelector('[data-job-filters] :focus, .filter-dropdown.is-open, .map-panning, .range-picker[open], [data-debug-dialog][open]')
            || window.getSelection()?.toString()
            || [...document.querySelectorAll('[data-live-region] details[open]')].some(details =>
                !details.parentElement.closest('details:not([open])') && details.getClientRects().length > 0)
        );
        const updateControl = () => {
            toggle.querySelector('[data-refresh-label]').textContent = enabled ? (failures ? 'Retrying…' : `Auto · ${interval() / 1000}s`) : 'Paused';
            toggle.setAttribute('aria-pressed', String(enabled));
            toggle.setAttribute('aria-label', enabled ? 'Pause automatic refresh' : 'Resume automatic refresh');
            toggle.title = enabled
                ? (failures ? 'Refresh failed; retaining the current data and retrying with backoff.' : `Updates every ${interval() / 1000} second${interval() === 1000 ? '' : 's'}${lastUpdated ? `. Last refreshed ${lastUpdated}` : ''}. Pauses while the tab is hidden or you inspect expanded details.`)
                : 'Automatic refresh is paused. Click to resume.';
        };
        const schedule = (delay = interval()) => {
            clearTimeout(timer);
            if (enabled && !document.hidden) timer = setTimeout(refresh, delay);
        };
        async function refresh() {
            if (!enabled || document.hidden || inFlight) return;
            if (interacting()) { schedule(); return; }
            inFlight = true;
            const url = new URL(window.location.href);
            url.hash = '';
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 15000);
            try {
                const response = await fetch(url.href, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal, headers: { Accept: 'text/html' } });
                if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) throw new Error('Refresh unavailable');
                const next = new DOMParser().parseFromString(await response.text(), 'text/html');
                const replacements = new Map([...next.querySelectorAll('[data-live-region]')].map(region => [region.dataset.liveRegion, region]));
                const current = regions();
                if (!current.every(region => replacements.has(region.dataset.liveRegion))) throw new Error('Page unavailable');
                if (!enabled || document.hidden || interacting() || window.location.pathname + window.location.search !== url.pathname + url.search) return;
                const focused = document.activeElement;
                const focusRegion = focused?.closest('[data-live-region]');
                const focusId = focused?.id;
                const focusHref = focused?.closest('a')?.getAttribute('href');
                const scroll = [window.scrollX, window.scrollY];
                let changed = false;
                current.forEach(region => {
                    const key = region.dataset.liveRegion;
                    const replacement = replacements.get(key);
                    const snapshot = replacement.outerHTML;
                    if (snapshots.get(key) !== snapshot) {
                        region.replaceWith(replacement);
                        changed = true;
                    }
                    snapshots.set(key, snapshot);
                });
                if (changed) document.dispatchEvent(new Event('lens:refreshed'));
                if (focusRegion) {
                    const replacement = focusId ? document.getElementById(focusId) : [...document.querySelectorAll('[data-live-region] a')].find(link => link.getAttribute('href') === focusHref);
                    replacement?.focus({ preventScroll: true });
                }
                window.scrollTo(...scroll);
                failures = 0;
                lastUpdated = new Date().toLocaleTimeString();
                toggle.dataset.refreshedAt = new Date().toISOString();
            } catch {
                failures = Math.min(failures + 1, 4);
            } finally {
                clearTimeout(timeout);
                inFlight = false;
                updateControl();
                schedule(failures ? Math.min(60000, interval() * 2 ** failures) : interval());
            }
        }
        toggle.hidden = false;
        updateControl();
        toggle.addEventListener('click', () => {
            enabled = !enabled;
            updateControl();
            clearTimeout(timer);
            if (enabled && !inFlight) refresh();
        });
        document.addEventListener('visibilitychange', () => {
            clearTimeout(timer);
            if (enabled && !document.hidden && !inFlight) refresh();
        });
        schedule();
    });
})();
