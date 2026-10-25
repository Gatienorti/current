(() => {
    const iso = date => date.toISOString().slice(0, 19);
    const dateOnly = date => date.toISOString().slice(0, 10);
    const parse = value => value ? new Date(`${value}Z`) : null;
    const displayDate = value => new Date(`${value}T00:00:00Z`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });

    function enhanceDropdown(control) {
        const field = control.closest('.filter-field');
        const label = field.querySelector('label').textContent;
        const editable = control.tagName === 'INPUT';
        const values = editable
            ? [{ value: '', label: control.placeholder }, ...Array.from(control.list.options, option => ({ value: option.value, label: option.value.split('\\').pop() }))]
            : Array.from(control.options, option => ({ value: option.value, label: option.text }));
        const wrapper = document.createElement('div');
        wrapper.className = 'filter-dropdown';
        control.before(wrapper);
        const trigger = editable ? control : document.createElement('button');
        if (editable) {
            control.removeAttribute('list');
            control.setAttribute('autocomplete', 'off');
            control.setAttribute('spellcheck', 'false');
        } else {
            control.hidden = true;
            trigger.type = 'button';
            trigger.id = `${control.id}-trigger`;
            field.querySelector('label').htmlFor = trigger.id;
        }
        trigger.classList.add('dropdown-trigger');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-controls', `${control.id}-options`);
        trigger.setAttribute('aria-haspopup', 'listbox');
        if (editable) { trigger.setAttribute('role', 'combobox'); trigger.setAttribute('aria-autocomplete', 'list'); }
        wrapper.append(trigger);
        const arrow = document.createElement('button');
        arrow.type = 'button'; arrow.className = 'dropdown-arrow';
        arrow.setAttribute('aria-label', `Show ${label.toLowerCase()} choices`);
        arrow.tabIndex = -1;
        arrow.append(document.getElementById('lens-icon-chevron-down').content.firstElementChild.cloneNode(true));
        if (editable) wrapper.append(arrow);
        else { const text = document.createElement('span'); text.className = 'dropdown-value'; trigger.append(text, arrow.firstElementChild); }
        const popup = document.createElement('div');
        popup.className = 'filter-dropdown-menu'; popup.hidden = true;
        wrapper.append(popup);
        const heading = document.createElement('div');
        heading.className = 'dropdown-menu-heading';
        heading.textContent = label;
        popup.append(heading);
        const search = editable ? trigger : document.createElement('input');
        if (!editable) {
            search.type = 'search'; search.placeholder = `Search ${label.toLowerCase()}…`;
            search.setAttribute('aria-label', `Search ${label.toLowerCase()} choices`);
            search.setAttribute('role', 'combobox'); search.setAttribute('aria-autocomplete', 'list');
            search.setAttribute('aria-controls', `${control.id}-options`);
            search.setAttribute('autocomplete', 'off');
            popup.append(search);
        }
        const list = document.createElement('div');
        list.id = `${control.id}-options`; list.className = 'dropdown-options';
        list.setAttribute('role', 'listbox'); list.setAttribute('aria-label', label);
        popup.append(list);
        const empty = document.createElement('p'); empty.className = 'dropdown-no-results';
        popup.append(empty);
        let matches = [], active = -1;
        const syncValue = () => {
            if (!editable) {
                const selectedLabel = values.find(option => option.value === control.value)?.label || values[0].label;
                trigger.querySelector('.dropdown-value').textContent = selectedLabel;
                trigger.setAttribute('aria-label', `${label}: ${selectedLabel}`);
                trigger.classList.toggle('has-value', Boolean(control.value));
            }
        };
        function close(focus = false) {
            popup.hidden = true;
            wrapper.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
            search.setAttribute('aria-expanded', 'false');
            search.removeAttribute('aria-activedescendant');
            if (focus) trigger.focus();
        }
        function position() {
            const rect = wrapper.getBoundingClientRect();
            popup.style.width = `${Math.min(Math.max(rect.width, editable ? 340 : 260), window.innerWidth - 32)}px`;
            popup.style.left = `${Math.max(16, Math.min(rect.left, window.innerWidth - popup.offsetWidth - 16))}px`;
            const below = window.innerHeight - rect.bottom - 16;
            popup.style.top = `${below >= popup.offsetHeight || below >= rect.top ? rect.bottom + 7 : Math.max(16, rect.top - popup.offsetHeight - 7)}px`;
            popup.style.maxHeight = `${Math.max(120, Math.min(350, Math.max(below, rect.top - 16)))}px`;
        }
        function highlight(index) {
            active = index;
            [...list.children].forEach((option, i) => option.classList.toggle('highlighted', i === active));
            if (active >= 0) {
                search.setAttribute('aria-activedescendant', list.children[active].id);
                list.children[active].scrollIntoView({ block: 'nearest' });
            } else search.removeAttribute('aria-activedescendant');
        }
        function choose(option) {
            control.value = option.value;
            control.dispatchEvent(new Event('change', { bubbles: true }));
            syncValue(); close(true);
        }
        function render(query = '') {
            matches = values.filter(option => !option.value || `${option.label} ${option.value}`.toLowerCase().includes(query.toLowerCase().trim()));
            list.replaceChildren();
            matches.forEach((option, index) => {
                const row = document.createElement('div');
                row.id = `${list.id}-${index}`; row.className = 'dropdown-option';
                row.setAttribute('role', 'option'); row.setAttribute('aria-selected', String(control.value === option.value));
                const text = document.createElement('span'); text.className = 'dropdown-option-text';
                const title = document.createElement('span'); title.textContent = option.label;
                text.append(title);
                if (editable && option.value) { const detail = document.createElement('small'); detail.textContent = option.value; text.append(detail); }
                row.append(text);
                const check = document.createElement('span'); check.className = 'dropdown-option-check';
                if (control.value === option.value) check.append(document.getElementById('lens-icon-check').content.firstElementChild.cloneNode(true));
                check.setAttribute('aria-hidden', 'true'); row.append(check);
                row.addEventListener('mousedown', event => event.preventDefault());
                row.addEventListener('click', () => choose(option));
                row.addEventListener('pointermove', () => highlight(index));
                list.append(row);
            });
            const found = matches.some(option => option.value);
            empty.hidden = found || !query;
            empty.textContent = editable ? 'No recorded matches. You can still filter by the exact class you entered.' : 'No matching choices.';
            highlight(-1);
            if (!popup.hidden) position();
        }
        function open(filter = '') {
            if (control.disabled) return;
            document.dispatchEvent(new CustomEvent('lens:filter-popup-open', { detail: wrapper }));
            popup.hidden = false; wrapper.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true'); search.setAttribute('aria-expanded', 'true');
            if (!editable) search.value = '';
            render(filter); position();
            if (!editable) search.focus();
        }
        trigger.addEventListener('click', () => { if (popup.hidden) open(); else if (!editable) close(); });
        if (editable) arrow.addEventListener('click', () => { if (popup.hidden) { open(); trigger.focus(); } else close(true); });
        search.addEventListener('input', () => { if (popup.hidden) open(search.value); else render(search.value); });
        wrapper.addEventListener('keydown', event => {
            if (event.key === 'Escape') { if (!popup.hidden) { event.preventDefault(); close(true); } }
            else if (event.key === 'Tab') close();
            else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (popup.hidden) open();
                highlight((active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length);
            } else if (event.key === 'Enter' && !popup.hidden) {
                event.preventDefault();
                if (active >= 0) choose(matches[active]); else close(true);
            }
        });
        document.addEventListener('pointerdown', event => { if (!event.composedPath().includes(wrapper)) close(); });
        document.addEventListener('lens:filter-popup-open', event => { if (event.detail !== wrapper) close(); });
        window.addEventListener('resize', () => { if (!popup.hidden) position(); });
        document.addEventListener('scroll', event => { if (!event.composedPath().includes(wrapper)) close(); }, true);
        field.closest('details')?.addEventListener('toggle', event => { if (!event.target.open) close(); });
        syncValue(); render();
    }

    document.querySelectorAll('[data-job-filters]').forEach(form => {
        form.querySelectorAll('[data-filter-dropdown]').forEach(enhanceDropdown);
        const status = form.querySelector('[name="status"]');
        const exception = form.querySelector('[data-exception-filter]');
        status?.addEventListener('change', () => {
            exception.hidden = status.value !== 'failed';
            exception.querySelector('input').disabled = exception.hidden;
        });
        const root = form.querySelector('[data-time-range]');
        if (!root) return;
        const picker = root.querySelector('details');
        const summary = root.querySelector('summary');
        const from = root.querySelector('[name="from"]');
        const to = root.querySelector('[name="to"]');
        const calendar = root.querySelector('[data-calendar]');
        const days = root.querySelector('[data-calendar-days]');
        const startTime = root.querySelector('[data-start-time]');
        const endTime = root.querySelector('[data-end-time]');
        const error = root.querySelector('[data-range-error]');
        let start, end, selectingEnd, month;
        from.type = to.type = 'hidden';
        root.querySelector('[data-range-fallback]').hidden = true;
        calendar.hidden = false;
        root.classList.add('enhanced');

        function loadRange() {
            const now = new Date();
            start = from.value?.slice(0, 10) || dateOnly(now);
            end = to.value?.slice(0, 10) || start;
            startTime.value = from.value?.slice(11) || '00:00:00';
            endTime.value = to.value?.slice(11) || '23:59:59';
            month = new Date(`${start.slice(0, 7)}-01T00:00:00Z`);
            selectingEnd = false;
            error.hidden = true;
            render();
        }
        function updateLabel() {
            const a = parse(from.value), b = parse(to.value);
            let label = 'Any time';
            if (a || b) {
                const options = { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' };
                label = `${a ? a.toLocaleString(undefined, options) : 'Any start'} → ${b ? b.toLocaleString(undefined, options) : 'Now'}`;
            }
            root.querySelector('[data-range-label]').textContent = label;
            summary.title = `${label} · UTC`;
        }
        function render() {
            root.querySelector('[data-month-label]').textContent = month.toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
            root.querySelector('[data-start-date]').textContent = displayDate(start);
            root.querySelector('[data-end-date]').textContent = displayDate(end);
            root.querySelector('[data-calendar-hint]').textContent = selectingEnd ? 'Now choose the end date.' : 'Choose a start date, then an end date.';
            days.replaceChildren();
            const offset = (month.getUTCDay() + 6) % 7;
            const length = new Date(Date.UTC(month.getUTCFullYear(), month.getUTCMonth() + 1, 0)).getUTCDate();
            for (let i = 0; i < offset; i++) days.append(document.createElement('span'));
            for (let day = 1; day <= length; day++) {
                const value = dateOnly(new Date(Date.UTC(month.getUTCFullYear(), month.getUTCMonth(), day)));
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = day;
                button.setAttribute('aria-label', displayDate(value));
                button.setAttribute('aria-pressed', String(value === start || value === end));
                if (value === start || value === end) button.classList.add('endpoint');
                if (value > start && value < end) button.classList.add('in-range');
                if (value === dateOnly(new Date())) button.classList.add('today');
                button.addEventListener('click', () => {
                    if (!selectingEnd) { start = end = value; selectingEnd = true; }
                    else { end = value; if (end < start) [start, end] = [end, start]; selectingEnd = false; }
                    error.hidden = true;
                    render();
                    [...days.querySelectorAll('button')].find(item => item.getAttribute('aria-label') === displayDate(value))?.focus();
                });
                days.append(button);
            }
        }
        function close() { picker.open = false; summary.focus(); }
        function positionPicker() {
            const popover = root.querySelector('.range-popover');
            if (window.innerWidth <= 700) {
                popover.style.removeProperty('left');
                popover.style.removeProperty('top');
                return;
            }
            const bounds = summary.getBoundingClientRect();
            popover.style.left = `${Math.max(16, Math.min(bounds.left, window.innerWidth - popover.offsetWidth - 16))}px`;
            popover.style.top = `${Math.max(16, Math.min(bounds.bottom + 9, window.innerHeight - popover.offsetHeight - 16))}px`;
        }
        picker.addEventListener('toggle', () => {
            if (picker.open) { document.dispatchEvent(new CustomEvent('lens:filter-popup-open', { detail: root })); loadRange(); positionPicker(); }
        });
        document.addEventListener('lens:filter-popup-open', event => { if (event.detail !== root) picker.open = false; });
        window.addEventListener('resize', () => { if (picker.open) positionPicker(); });
        root.querySelector('[data-month-back]').addEventListener('click', () => { month.setUTCMonth(month.getUTCMonth() - 1); render(); });
        root.querySelector('[data-month-next]').addEventListener('click', () => { month.setUTCMonth(month.getUTCMonth() + 1); render(); });
        root.querySelectorAll('[data-range-minutes]').forEach(button => button.addEventListener('click', () => {
            const now = new Date();
            from.value = iso(new Date(now.getTime() - Number(button.dataset.rangeMinutes) * 60000));
            to.value = iso(now);
            updateLabel(); close();
            form.requestSubmit();
        }));
        root.querySelector('[data-range-clear]').addEventListener('click', () => {
            from.value = to.value = '';
            updateLabel(); close();
            form.requestSubmit();
        });
        root.querySelector('[data-range-apply]').addEventListener('click', () => {
            const a = `${start}T${startTime.value || '00:00:00'}`;
            const b = `${end}T${endTime.value || '23:59:59'}`;
            if (!(parse(b) > parse(a))) {
                error.textContent = 'End time must be after start time.';
                error.hidden = false;
                return;
            }
            from.value = a; to.value = b;
            updateLabel(); close();
            form.requestSubmit();
        });
        document.addEventListener('click', event => { if (picker.open && !event.composedPath().includes(root)) picker.open = false; });
        root.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); close(); } });
        loadRange(); updateLabel();
    });
})();
