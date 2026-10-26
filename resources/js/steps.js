(() => {
    let cleanup = () => {};
    let payloadOpen = true;
    const enhance = () => {
        cleanup();
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
