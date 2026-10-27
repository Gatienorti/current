(() => {
    document.addEventListener('DOMContentLoaded', () => {
        const toggle = document.querySelector('[data-auto-refresh]');
        const regions = () => [...document.querySelectorAll('[data-live-region]')].filter(region => !region.parentElement.closest('[data-live-region]'));
        if (!toggle || !regions().length) return;
        const picker = document.querySelector('[data-refresh-picker]');
        const choices = [...picker.querySelectorAll('[data-refresh-choice]')];
        const allowed = [0, 1, 5, 15, 30, 60];
        let seconds = 5;
        try {
            const saved = localStorage.getItem('current-refresh-seconds');
            if (saved !== null && allowed.includes(Number(saved))) seconds = Number(saved);
        } catch {}
        const interval = () => seconds * 1000;
        const snapshots = new Map();
        let enabled = seconds !== 0, timer, inFlight = false, failures = 0, lastUpdated;
        const interacting = () => Boolean(
            document.body.hasAttribute('data-current-batch-active')
            || document.querySelector('[data-job-filters] :focus, .filter-dropdown.is-open, .map-panning, .range-picker[open], [data-refresh-picker][open], [data-debug-dialog][open]')
            || window.getSelection()?.toString()
            || [...document.querySelectorAll('[data-live-region] details[open]')].some(details =>
                !details.matches('[data-payload-panel], [data-json-root]') && !details.parentElement.closest('details:not([open])') && details.getClientRects().length > 0)
        );
        const updateControl = () => {
            toggle.querySelector('[data-refresh-label]').textContent = enabled ? (failures ? 'Retrying…' : `${seconds}s`) : 'Paused';
            choices.forEach(choice => choice.setAttribute('aria-pressed', String(Number(choice.dataset.refreshChoice) === seconds)));
            toggle.setAttribute('aria-label', enabled ? `Live updates: ${seconds} second${seconds === 1 ? '' : 's'}` : 'Live updates: paused');
            toggle.title = enabled
                ? (failures ? 'Refresh failed; retaining the current data and retrying with backoff.' : `Updates every ${interval() / 1000} second${interval() === 1000 ? '' : 's'}${lastUpdated ? `. Last refreshed ${lastUpdated}` : ''}. Pauses while the tab is hidden or you inspect expanded details.`)
                : 'Automatic refresh is paused. Choose an interval to resume.';
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
                const response = await fetch(url.href, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal, headers: { Accept: 'text/html', 'X-Current-Refresh': '1' } });
                if (!response.ok || response.headers.get('X-Current-Refresh') !== '1' || !response.headers.get('content-type')?.includes('text/html')) throw new Error('Refresh unavailable');
                const next = new DOMParser().parseFromString(await response.text(), 'text/html');
                const replacements = new Map([...next.querySelectorAll('[data-live-region]')].filter(region => !region.parentElement.closest('[data-live-region]')).map(region => [region.dataset.liveRegion, region]));
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
        picker.hidden = false;
        updateControl();
        choices.forEach(choice => choice.addEventListener('click', () => {
            seconds = Number(choice.dataset.refreshChoice);
            enabled = seconds !== 0;
            try { localStorage.setItem('current-refresh-seconds', String(seconds)); } catch {}
            picker.open = false;
            toggle.focus();
            updateControl();
            clearTimeout(timer);
            if (enabled && !inFlight) refresh();
        }));
        document.addEventListener('pointerdown', event => { if (!picker.contains(event.target)) picker.open = false; });
        picker.addEventListener('keydown', event => {
            if (event.key === 'Escape') { picker.open = false; toggle.focus(); }
        });
        document.addEventListener('visibilitychange', () => {
            clearTimeout(timer);
            if (enabled && !document.hidden && !inFlight) refresh();
        });
        schedule();
    });
})();
