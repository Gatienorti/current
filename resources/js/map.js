(() => {
    const views = new Map();
    let dispose = () => {};
    const minScale = .02, maxScale = 2;
    const bounded = (value, min, max) => Math.max(min, Math.min(max, value));
    const svgNode = (name, attrs) => {
        const node = document.createElementNS('http://www.w3.org/2000/svg', name);
        Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
        return node;
    };
    function enhance() {
        dispose();
        const cleanup = [];
        document.querySelectorAll('[data-execution-map]').forEach(root => {
            const viewport = root.querySelector('[data-map-viewport]');
            const track = root.querySelector('[data-map-track]');
            const nodes = [...root.querySelectorAll('.step-map-node')];
            if (!viewport || !track || !nodes.length) return;
            const controls = root.querySelector('[data-map-controls]');
            const mini = root.querySelector('[data-map-minimap]');
            const svg = mini.querySelector('svg');
            let initialView = !views.has(root.dataset.mapKey);
            const state = views.get(root.dataset.mapKey) || { scale: 1, x: 0, y: 0, fit: root.dataset.followDefault !== '1', follow: root.dataset.followDefault === '1' };
            views.set(root.dataset.mapKey, state);
            root.classList.add('map-enhanced');
            controls.hidden = false;
            mini.hidden = false;
            let drag = null, miniDrag = null, suppressClick = false, frame;
            let worldWidth = track.offsetWidth, worldHeight = track.offsetHeight;
            const miniNodes = nodes.map(node => {
                const rect = svgNode('rect', { rx: 1.5, class: `mini-step ${node.classList.contains('failed') ? 'failed' : node.classList.contains('completed') ? 'completed' : 'unfinished'}` });
                svg.append(rect);
                return { node, rect };
            });
            const miniView = svgNode('rect', { class: 'mini-viewport', rx: 1 });
            svg.append(miniView);
            let miniScale = 1, miniX = 0, miniY = 0;
            const dimensions = () => ({ width: viewport.clientWidth, height: viewport.clientHeight });
            function draw() {
                const { width, height } = dimensions();
                worldWidth = track.offsetWidth;
                worldHeight = track.offsetHeight;
                if (!width || !worldWidth) return;
                const usableHeight = Math.max(60, height - 80);
                const fitScale = bounded(Math.min(1, (width - 32) / worldWidth, (usableHeight - 16) / worldHeight), minScale, maxScale);
                if (initialView) {
                    // Long maps open with readable cards; Fit View remains available.
                    if (fitScale < .7) {
                        const selected = nodes.find(node => node.getAttribute('aria-current') === 'step') || nodes[0];
                        state.fit = false;
                        state.x = width / 2 - selected.offsetLeft - selected.offsetWidth / 2;
                        state.y = (height - 64) / 2 - selected.offsetTop - selected.offsetHeight / 2;
                    }
                    initialView = false;
                }
                if (state.fit) {
                    state.scale = fitScale;
                    state.x = (width - worldWidth * state.scale) / 2;
                    state.y = 12 + (usableHeight - worldHeight * state.scale) / 2;
                } else {
                    state.x = bounded(state.x, 32 - worldWidth * state.scale, width - 32);
                    state.y = bounded(state.y, 24 - worldHeight * state.scale, height - 24);
                }
                if (state.follow) {
                    const active = nodes.find(node => node.classList.contains('running')) || nodes[nodes.length - 1];
                    state.x = width / 2 - (active.offsetLeft + active.offsetWidth / 2) * state.scale;
                    state.y = (height - 64) / 2 - (active.offsetTop + active.offsetHeight / 2) * state.scale;
                }
                controls.querySelector('[data-map-action="follow"]')?.setAttribute('aria-pressed', String(state.follow));
                track.style.transform = `translate(${state.x}px, ${state.y}px) scale(${state.scale})`;
                controls.querySelector('[data-map-scale]').textContent = `${Math.round(state.scale * 100)}%`;
                controls.querySelector('[data-map-action="fit"]').setAttribute('aria-pressed', String(state.fit));
                controls.querySelector('[data-map-action="actual"]').setAttribute('aria-pressed', String(!state.fit && Math.abs(state.scale - 1) < .001));
                controls.querySelector('[data-map-action="out"]').disabled = state.scale <= minScale;
                controls.querySelector('[data-map-action="in"]').disabled = state.scale >= maxScale;
                const mw = mini.clientWidth, mh = mini.clientHeight;
                svg.setAttribute('viewBox', `0 0 ${mw} ${mh}`);
                miniScale = Math.min((mw - 12) / worldWidth, (mh - 12) / worldHeight);
                miniX = (mw - worldWidth * miniScale) / 2;
                miniY = (mh - worldHeight * miniScale) / 2;
                miniNodes.forEach(({ node, rect }) => {
                    rect.setAttribute('x', miniX + node.offsetLeft * miniScale);
                    rect.setAttribute('y', miniY + node.offsetTop * miniScale);
                    rect.setAttribute('width', Math.max(1, node.offsetWidth * miniScale));
                    rect.setAttribute('height', Math.max(2, node.offsetHeight * miniScale));
                    rect.classList.toggle('selected', node.getAttribute('aria-current') === 'step');
                });
                const left = bounded(-state.x / state.scale, 0, worldWidth);
                const top = bounded(-state.y / state.scale, 0, worldHeight);
                const right = bounded((width - state.x) / state.scale, 0, worldWidth);
                const bottom = bounded((height - state.y) / state.scale, 0, worldHeight);
                miniView.setAttribute('x', miniX + left * miniScale);
                miniView.setAttribute('y', miniY + top * miniScale);
                miniView.setAttribute('width', Math.max(1, (right - left) * miniScale));
                miniView.setAttribute('height', Math.max(2, (bottom - top) * miniScale));
            }
            const scheduleDraw = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(draw); };
            function zoom(scale, x = viewport.clientWidth / 2, y = (viewport.clientHeight - 64) / 2) {
                state.follow = false;
                const next = bounded(scale, minScale, maxScale);
                state.x = x - (x - state.x) * next / state.scale;
                state.y = y - (y - state.y) * next / state.scale;
                state.scale = next;
                state.fit = false;
                draw();
            }
            controls.addEventListener('click', event => {
                const action = event.target.closest('[data-map-action]')?.dataset.mapAction;
                if (action === 'follow') { state.follow = !state.follow; state.fit = false; draw(); return; }
                if (action) state.follow = false;
                if (action === 'fit') { state.fit = true; draw(); }
                if (action === 'in') zoom(state.scale * 1.25);
                if (action === 'out') zoom(state.scale / 1.25);
                if (action === 'actual') {
                    const selected = nodes.find(node => node.getAttribute('aria-current') === 'step') || nodes[0];
                    state.fit = false; state.scale = 1;
                    state.x = viewport.clientWidth / 2 - selected.offsetLeft - selected.offsetWidth / 2;
                    state.y = (viewport.clientHeight - 64) / 2 - selected.offsetTop - selected.offsetHeight / 2;
                    draw();
                }
            });
            viewport.addEventListener('wheel', event => {
                event.preventDefault();
                const bounds = viewport.getBoundingClientRect();
                zoom(state.scale * Math.exp(-bounded(event.deltaY, -300, 300) * .002), event.clientX - bounds.left, event.clientY - bounds.top);
            }, { passive: false });
            viewport.addEventListener('pointerdown', event => {
                if (event.button !== 0) return;
                drag = { id: event.pointerId, x: event.clientX, y: event.clientY, tx: state.x, ty: state.y, moved: false };
            });
            viewport.addEventListener('pointermove', event => {
                if (!drag || drag.id !== event.pointerId) return;
                const dx = event.clientX - drag.x, dy = event.clientY - drag.y;
                if (!drag.moved && Math.hypot(dx, dy) < 4) return;
                if (!drag.moved) viewport.setPointerCapture(event.pointerId);
                drag.moved = true;
                state.follow = false;
                state.fit = false;
                state.x = drag.tx + dx; state.y = drag.ty + dy;
                root.classList.add('map-panning');
                event.preventDefault();
                scheduleDraw();
            });
            const endDrag = event => {
                if (!drag || drag.id !== event.pointerId) return;
                suppressClick = drag.moved;
                if (viewport.hasPointerCapture(event.pointerId)) viewport.releasePointerCapture(event.pointerId);
                drag = null;
                root.classList.remove('map-panning');
                // A drag must not select the node beneath its pointer-up.
                setTimeout(() => { suppressClick = false; }, 0);
            };
            viewport.addEventListener('pointerup', endDrag);
            viewport.addEventListener('pointercancel', endDrag);
            viewport.addEventListener('click', event => { if (suppressClick) { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
            viewport.addEventListener('keydown', event => {
                if (event.target !== viewport) return;
                const keys = { ArrowLeft: [45, 0], ArrowRight: [-45, 0], ArrowUp: [0, 45], ArrowDown: [0, -45] };
                if (keys[event.key]) { state.follow = false; state.fit = false; state.x += keys[event.key][0]; state.y += keys[event.key][1]; draw(); }
                else if (event.key === '+' || event.key === '=') zoom(state.scale * 1.25);
                else if (event.key === '-') zoom(state.scale / 1.25);
                else if (event.key === '0') { state.follow = false; state.fit = true; draw(); }
                else return;
                event.preventDefault();
            });
            function moveMini(event) {
                const bounds = mini.getBoundingClientRect();
                state.fit = false;
                state.x = viewport.clientWidth / 2 - (event.clientX - bounds.left - miniX) / miniScale * state.scale;
                state.y = (viewport.clientHeight - 64) / 2 - (event.clientY - bounds.top - miniY) / miniScale * state.scale;
                draw();
            }
            mini.addEventListener('pointerdown', event => {
                state.follow = false;
                if (event.button !== 0) return;
                miniDrag = event.pointerId;
                mini.setPointerCapture(event.pointerId);
                root.classList.add('map-panning');
                moveMini(event);
            });
            mini.addEventListener('pointermove', event => { if (miniDrag === event.pointerId) moveMini(event); });
            const endMini = event => { if (miniDrag === event.pointerId) { miniDrag = null; root.classList.remove('map-panning'); } };
            mini.addEventListener('pointerup', endMini);
            mini.addEventListener('pointercancel', endMini);
            mini.addEventListener('click', event => { if (event.detail === 0) { state.follow = false; state.fit = true; draw(); } });
            const resize = new ResizeObserver(scheduleDraw);
            resize.observe(viewport);
            resize.observe(track);
            document.addEventListener('lens:step-selected', scheduleDraw);
            cleanup.push(() => { cancelAnimationFrame(frame); resize.disconnect(); document.removeEventListener('lens:step-selected', scheduleDraw); });
            draw();
        });
        dispose = () => cleanup.forEach(fn => fn());
    }
    document.addEventListener('DOMContentLoaded', enhance);
    document.addEventListener('lens:refreshed', enhance);
})();
