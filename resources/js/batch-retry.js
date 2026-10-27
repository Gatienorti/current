(() => {
    const enhance = () => document.querySelectorAll('[data-current-batch-retry]').forEach(form => {
        if (form.dataset.enhanced) return;
        form.dataset.enhanced = '1';
        const submit = form.querySelector('[type="submit"]');
        const panel = form.closest('.panel');
        if (!submit || !panel) return;
        const status = document.createElement('div');
        status.className = 'panel-footnote';
        status.hidden = true;
        status.setAttribute('role', 'status');
        const pause = document.createElement('button');
        pause.type = 'button'; pause.className = 'button small'; pause.textContent = 'Pause'; pause.hidden = true;
        const resume = document.createElement('a');
        resume.className = 'button small'; resume.textContent = 'Review and resume'; resume.hidden = true;
        form.append(pause); panel.append(status, resume);
        let running = false, paused = false, stopped = false, complete = false, total = 0, inspected = 0;
        const safeURL = value => {
            if (typeof value !== 'string' || !value) throw new Error('Invalid continuation');
            const url = new URL(value, window.location.href);
            if (url.origin !== window.location.origin || !['http:', 'https:'].includes(url.protocol)) throw new Error('Invalid continuation');
            return url;
        };
        const recoveryURL = () => {
            const url = new URL(window.location.href), data = new FormData(form);
            url.searchParams.set('retry_pass', data.get('retry_pass'));
            if (data.get('retry_after')) url.searchParams.set('retry_after', data.get('retry_after'));
            else url.searchParams.delete('retry_after');
            return url.href;
        };
        const setField = (name, value) => {
            let field = form.querySelector(`[name="${name}"]`);
            if (value === null || value === undefined) { field?.remove(); return; }
            if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = name; form.append(field); }
            field.value = value;
        };
        pause.addEventListener('click', () => {
            paused = true; pause.disabled = true;
            status.textContent = 'Pausing after the current request finishes…';
        });
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (running || stopped) return;
            running = true; paused = false;
            status.hidden = false; status.textContent = `${total} retries submitted or already submitted. Processing the next failures…`;
            document.body.setAttribute('data-current-batch-active', '');
            submit.disabled = true; pause.disabled = false; pause.hidden = false; resume.hidden = true;
            try {
                do {
                    submit.textContent = 'Retrying batch…';
                    const controller = new AbortController();
                    const timeout = setTimeout(() => controller.abort(), 60000);
                    let result;
                    try {
                        const response = await fetch(safeURL(form.action).href, {method: 'POST', body: new FormData(form), credentials: 'same-origin', redirect: 'error', signal: controller.signal, headers: {Accept: 'application/json'}});
                        if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Unknown request outcome');
                        result = await response.json();
                    } finally { clearTimeout(timeout); }
                    if (!result || !Number.isInteger(result.submitted) || result.submitted < 0 || !Number.isInteger(result.inspected) || result.inspected < 0
                        || result.inspected > 50 || !Number.isInteger(result.unconfirmed) || result.unconfirmed < 0
                        || result.submitted + result.unconfirmed !== result.inspected || typeof result.complete !== 'boolean') throw new Error('Unknown request outcome');
                    const resumeURL = safeURL(result.resume_url);
                    if (!result.complete && (!result.next || typeof result.next.retry_pass !== 'string' || typeof result.next.retry_after !== 'string'
                        || result.next.retry_pass !== new FormData(form).get('retry_pass') || !/^[A-Za-z0-9_-]{1,100}$/.test(result.next.retry_after)
                        || result.next.retry_after <= (new FormData(form).get('retry_after') || '') || result.inspected === 0)) throw new Error('Invalid continuation');
                    total += result.submitted; inspected += result.inspected;
                    status.textContent = `${total} retries submitted or already submitted · ${inspected} failures inspected.`;
                    resume.href = resumeURL.href;
                    if (result.unconfirmed > 0) {
                        // Do not skip uncertain failures by automatically moving to the next page.
                        stopped = true; resume.href = recoveryURL(); resume.hidden = false;
                        status.textContent += ` ${result.unconfirmed} unavailable or unconfirmed. Inspect the results before resuming.`;
                        submit.textContent = 'Review required'; break;
                    }
                    if (result.complete) { complete = true; stopped = true; submit.textContent = 'Retry pass complete'; break; }
                    setField('retry_pass', result.next.retry_pass); setField('retry_after', result.next.retry_after);
                    if (paused) { submit.textContent = 'Continue batch retry'; status.textContent += ' Paused.'; break; }
                    // Yield between chunks so Pause can take effect without cancelling an enqueue request.
                    await new Promise(resolve => setTimeout(resolve, 100));
                } while (!paused);
            } catch {
                stopped = true; resume.href = recoveryURL(); resume.hidden = false;
                submit.textContent = 'Review required';
                status.textContent = `${total} retries confirmed before the interruption. The last request outcome is unknown; it will not be automatically resent.`;
            } finally {
                running = false; pause.hidden = true; submit.disabled = stopped;
                if (paused && !stopped) {
                    submit.textContent = 'Continue batch retry';
                    if (!status.textContent.endsWith('Paused.')) status.textContent += ' Paused.';
                }
                // Preserve the result/recovery link until the operator leaves or explicitly resumes.
                if (complete || (!stopped && !paused)) document.body.removeAttribute('data-current-batch-active');
            }
        });
    });
    document.addEventListener('DOMContentLoaded', enhance);
    document.addEventListener('lens:refreshed', enhance);
})();
