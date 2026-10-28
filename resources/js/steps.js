(() => {
    let cleanup = () => {};
    let payloadOpen = true;
    let timelineScale = 'compressed';
    let sourceOpen = true;
    const timeline = () => {
        document.querySelectorAll('[data-attempt-timeline]').forEach(panel => {
            const body = panel.querySelector('[data-time-start]');
            const bars = [...panel.querySelectorAll('[data-start]')];
            const start = Number(body.dataset.timeStart), end = Number(body.dataset.timeEnd);
            const points = [...new Set([start, end, ...bars.flatMap(bar => [Number(bar.dataset.start), Number(bar.dataset.end)])])].filter(Number.isFinite).sort((a, b) => a - b);
            const durations = bars.map(bar => Number(bar.dataset.end) - Number(bar.dataset.start)).filter(value => value > 0).sort((a, b) => a - b);
            const gapCap = (durations[Math.floor(durations.length / 2)] || 1) * 0.15;
            const render = () => {
                panel.dataset.timelineScale = timelineScale;
                const cumulative = [0];
                for (let i = 1; i < points.length; i++) {
                    const span = points[i] - points[i - 1];
                    const active = bars.some(bar => Number(bar.dataset.start) < points[i] && Number(bar.dataset.end) > points[i - 1]);
                    cumulative[i] = cumulative[i - 1] + (timelineScale === 'compressed' && !active ? Math.min(span, gapCap) : span);
                }
                const total = cumulative.at(-1) || 1;
                const position = time => 100 * (cumulative[points.indexOf(time)] || 0) / total;
                bars.forEach(bar => {
                    const left = position(Number(bar.dataset.start));
                    const right = position(Number(bar.dataset.end));
                    bar.style.left = `${left}%`;
                    bar.style.width = `${Math.max(0, right - left)}%`;
                });
                panel.querySelectorAll('[data-timeline-scale]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.timelineScale === timelineScale)));
                panel.querySelector('[data-scale-description]').textContent = timelineScale === 'compressed' ? 'Compressed spacing; bar widths are not proportional to elapsed time.' : 'True scale.';
            };
            panel.querySelector('.timeline-scale').hidden = false;
            panel.querySelectorAll('[data-timeline-scale]').forEach(button => button.addEventListener('click', () => { timelineScale = button.dataset.timelineScale; render(); }));
            render();
        });
    };
    const enhance = () => {
        cleanup();
        timeline();
        const sourceOutline = document.querySelector('.source-disclosure');
        if (sourceOutline) {
            sourceOutline.open = sourceOpen;
            sourceOutline.querySelector(':scope > summary').addEventListener('click', event => {
                event.preventDefault();
                sourceOutline.open = sourceOpen = !sourceOutline.open;
            });
        }
        const root = document.querySelector('[data-investigation]');
        const payloadPanels = [...document.querySelectorAll('[data-payload-panel]')];
        payloadPanels.forEach(panel => {
            panel.open = payloadOpen;
            panel.querySelector(':scope > summary').addEventListener('click', event => {
                event.preventDefault();
                payloadOpen = !panel.open;
                payloadPanels.forEach(item => { item.open = payloadOpen; });
            });
        });
        document.querySelectorAll('[data-copy-json]').forEach(button => {
            button.hidden = false;
            button.addEventListener('click', async () => {
                const source = document.getElementById(button.dataset.copyJson);
                const label = button.querySelector('[data-copy-label]');
                if (!source || !label) return;
                try {
                    await navigator.clipboard.writeText(source.textContent);
                    label.textContent = 'Copied';
                } catch {
                    label.textContent = 'Copy unavailable';
                }
                setTimeout(() => { label.textContent = 'Copy JSON'; }, 1800);
            });
        });
        document.querySelectorAll('[data-retry-form]').forEach(form => {
            form.addEventListener('submit', () => {
                const button = form.querySelector('button');
                button.disabled = true;
                button.textContent = 'Requesting retry…';
            });
        });
        const debugDialog = document.querySelector('[data-debug-dialog]');
        const debugOpen = document.querySelector('[data-debug-open]');
        if (debugDialog && debugOpen && typeof debugDialog.showModal === 'function') {
            debugOpen.hidden = false;
            const prompt = debugDialog.querySelector('[data-debug-text]');
            const status = debugDialog.querySelector('[data-debug-status]');
            debugOpen.addEventListener('click', () => { status.textContent = ''; debugDialog.showModal(); });
            debugDialog.querySelector('[data-debug-close]').addEventListener('click', () => debugDialog.close());
            debugDialog.addEventListener('close', () => debugOpen.focus({ preventScroll: true }));
            debugDialog.querySelector('[data-debug-copy]').addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(prompt.value);
                    status.textContent = 'Copied to clipboard';
                } catch {
                    prompt.focus(); prompt.select();
                    status.textContent = 'Copy unavailable. The prompt is selected; copy it manually.';
                }
            });
        }
        if (!root) return;
        const panels = [...root.querySelectorAll('[data-step-inspector]')];
        const links = [...root.querySelectorAll('[data-step-target]')];
        const valid = id => panels.some(panel => panel.id === id);
        const select = id => {
            if (!valid(id)) id = root.dataset.defaultInspector;
            panels.forEach(panel => { panel.hidden = panel.id !== id && panel.id !== 'attempt-context'; });
            links.forEach(link => {
                if (link.dataset.stepTarget === id) link.setAttribute('aria-current', 'step');
                else link.removeAttribute('aria-current');
                if (link.hasAttribute('aria-expanded')) link.setAttribute('aria-expanded', String(link.dataset.stepTarget === id));
            });
            document.dispatchEvent(new CustomEvent('lens:step-selected', { detail: id }));
        };
        const fromLocation = () => select(window.location.hash.slice(1));
        links.forEach(link => link.addEventListener('click', event => {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            let id = link.dataset.stepTarget;
            if (link.classList.contains('execution-step-summary') && link.getAttribute('aria-expanded') === 'true') id = 'attempt-context';
            if (!valid(id)) return;
            event.preventDefault();
            if (!link.hasAttribute('data-step-context-link')) {
                if (window.location.hash !== `#${id}`) history.pushState(null, '', `#${id}`);
                select(id);
            }
            const panel = panels.find(panel => panel.id === id);
            const bounds = panel.getBoundingClientRect();
            if (link.hasAttribute('data-step-context-link')) {
                panel.setAttribute('tabindex', '-1');
                panel.focus({ preventScroll: true });
            }
            const jumpToStep = link.classList.contains('step-map-node') || link.classList.contains('error-button');
            const target = jumpToStep ? (panel.closest('.execution-step') || panel) : panel;
            if (jumpToStep || bounds.top < 0 || bounds.bottom > window.innerHeight) {
                target.scrollIntoView({ block: jumpToStep ? 'center' : 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            }
        }));
        window.addEventListener('hashchange', fromLocation);
        window.addEventListener('popstate', fromLocation);
        cleanup = () => {
            window.removeEventListener('hashchange', fromLocation);
            window.removeEventListener('popstate', fromLocation);
        };
        fromLocation();
    };
    document.addEventListener('DOMContentLoaded', enhance);
    document.addEventListener('lens:refreshed', enhance);
})();
