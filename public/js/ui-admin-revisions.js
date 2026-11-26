(() => {
    'use strict';

    const isoDate = (year, month, day) => new Date(Date.UTC(year, month, day)).toISOString().slice(0, 10);
    const monthDays = (year, month) => {
        const offset = new Date(Date.UTC(year, month, 1)).getUTCDay();
        return Array.from({length: Math.ceil((offset + new Date(Date.UTC(year, month + 1, 0)).getUTCDate()) / 7) * 7},
            (_, index) => isoDate(year, month, index - offset + 1));
    };
    const timeParts = (value) => {
        const match = /^(\d{2}):(\d{2})$/.exec(value || '');
        if (!match || +match[1] > 23 || +match[2] > 59) return null;
        return {hour: (+match[1] % 12) || 12, minute: +match[2], period: +match[1] >= 12 ? 'PM' : 'AM'};
    };
    const timeValue = ({hour, minute, period}) => `${String((hour % 12) + (period === 'PM' ? 12 : 0)).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
    const canSelectDate = (date, profile, min = '', max = '') => profile?.open === true && (!min || date >= min) && (!max || date <= max);

    if (typeof module !== 'undefined' && module.exports) module.exports = {monthDays, timeParts, timeValue, canSelectDate};
    if (typeof document === 'undefined') return;

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const button = (text, label, action) => {
        const node = element('button', 'button secondary small', text);
        node.type = 'button';
        if (label) node.setAttribute('aria-label', label);
        if (action) node.addEventListener('click', action);
        return node;
    };
    const notify = (field) => {
        field.dispatchEvent(new Event('input', {bubbles: true}));
        field.dispatchEvent(new Event('change', {bubbles: true}));
    };

    // This is the popup function for the existing date and operational time fields.
    let activePopup = null;
    function popupFor(field, label) {
        activePopup?.close(false);
        const popup = element('div', 'spmu-field-popup');
        popup.setAttribute('role', 'dialog');
        popup.setAttribute('aria-label', label);
        popup.id = `${field.id || 'spmu-field'}-popup`;
        document.body.append(popup);
        field.setAttribute('aria-controls', popup.id);
        field.setAttribute('aria-expanded', 'true');
        const position = () => {
            const rect = field.getBoundingClientRect();
            popup.style.left = `${Math.max(8, Math.min(rect.left, innerWidth - popup.offsetWidth - 8))}px`;
            popup.style.top = `${Math.max(8, rect.bottom + popup.offsetHeight + 8 <= innerHeight ? rect.bottom + 5 : rect.top - popup.offsetHeight - 5)}px`;
        };
        const outside = (event) => {
            if (!popup.contains(event.target) && event.target !== field && !event.target.closest('[data-field-popup-trigger]')) close(false);
        };
        const keys = (event) => {
            if (event.key === 'Escape') { event.preventDefault(); close(); }
        };
        const close = (restore = true) => {
            popup.remove();
            field.setAttribute('aria-expanded', 'false');
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('focusin', outside);
            document.removeEventListener('keydown', keys);
            window.removeEventListener('resize', position);
            window.removeEventListener('scroll', position, true);
            if (activePopup?.node === popup) activePopup = null;
            if (restore) field.focus();
        };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('focusin', outside);
        document.addEventListener('keydown', keys);
        window.addEventListener('resize', position);
        window.addEventListener('scroll', position, true);
        activePopup = {node: popup, close, position};
        return activePopup;
    }

    function enhanceField(field, open, symbol) {
        if (field.dataset.popupReady) return;
        field.dataset.popupReady = '1';
        field.classList.add('spmu-enhanced-field');
        const shell = element('span', 'spmu-field-shell');
        field.before(shell);
        shell.append(field);
        const trigger = button(symbol, `Choose ${field.labels?.[0]?.textContent.trim() || field.id}`, () => {
            if (!field.disabled) open(field);
        });
        trigger.dataset.fieldPopupTrigger = '1';
        trigger.className = 'spmu-field-trigger';
        const clockIcon = field.hasAttribute('data-operational-time-picker')
            ? field.closest('.txn-time-field')?.querySelector(':scope > .ui-icon')
            : null;
        if (clockIcon) {
            trigger.classList.add('icon-button');
            trigger.replaceChildren(clockIcon.cloneNode(true));
        }
        shell.append(trigger);
        trigger.disabled = field.disabled;
        new MutationObserver(() => { trigger.disabled = field.disabled; }).observe(field, {attributes: true, attributeFilter: ['disabled']});
        field.addEventListener('click', (event) => { event.preventDefault(); if (!field.disabled) open(field); });
        field.addEventListener('keydown', (event) => {
            if (event.key === 'F4' || (event.altKey && event.key === 'ArrowDown')) { event.preventDefault(); open(field); }
        });
    }

    // This is the calendar popup for closed-date information in the Borrowing Request.
    function openDatePicker(field) {
        const handle = popupFor(field, 'Choose borrowing date');
        const popup = handle.node;
        popup.classList.add('spmu-date-popup');
        const selected = /^\d{4}-\d{2}-\d{2}$/.test(field.value) ? field.value : (field.min || new Date().toLocaleDateString('en-CA'));
        let year = +selected.slice(0, 4), month = +selected.slice(5, 7) - 1, revision = 0;
        const heading = element('div', 'spmu-popup-heading');
        const title = element('strong');
        heading.append(button('‹', 'Previous month', () => move(-1)), title, button('›', 'Next month', () => move(1)));
        const weekdays = element('div', 'spmu-date-weekdays');
        ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(day => weekdays.append(element('span', '', day)));
        const grid = element('div', 'spmu-date-grid');
        const status = element('p', 'spmu-popup-status');
        status.setAttribute('role', 'status');
        const retry = button('Retry', 'Retry calendar lookup', () => render());
        const close = button('Close', 'Close date picker', () => handle.close());
        const footer = element('div', 'spmu-popup-footer');
        footer.append(retry, close);
        popup.append(heading, weekdays, grid, status, footer);
        const move = (amount) => {
            const next = new Date(Date.UTC(year, month + amount, 1));
            year = next.getUTCFullYear(); month = next.getUTCMonth();
            render();
        };
        async function render() {
            const requestRevision = ++revision;
            title.textContent = new Date(Date.UTC(year, month, 1)).toLocaleDateString('en-US', {month: 'long', year: 'numeric', timeZone: 'UTC'});
            grid.replaceChildren();
            status.textContent = 'Checking operating days…';
            retry.hidden = true;
            handle.position();
            try {
                const url = new URL(field.dataset.operationalDateUrl, location.href);
                const prefix = `${year}-${String(month + 1).padStart(2, '0')}`;
                url.searchParams.set('month', prefix);
                const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
                if (!response.ok) throw new Error('lookup');
                const data = await response.json();
                if (requestRevision !== revision || !popup.isConnected) return;
                if (!data.dates || typeof data.dates !== 'object') throw new Error('lookup');
                if (!monthDays(year, month).filter(date => date.startsWith(prefix))
                    .every(date => typeof data.dates[date]?.open === 'boolean')) throw new Error('lookup');
                monthDays(year, month).forEach(date => {
                    const cell = element('span', 'spmu-date-cell');
                    const inMonth = date.startsWith(prefix);
                    const profile = data.dates[date];
                    const day = button(String(+date.slice(-2)), date);
                    day.className = 'spmu-date-day';
                    day.disabled = !inMonth || !canSelectDate(date, profile, field.min, field.max);
                    if (!inMonth) cell.classList.add('outside-month');
                    if (date === field.value) { day.classList.add('is-selected'); day.setAttribute('aria-current', 'date'); }
                    cell.append(day);
                    if (inMonth && profile?.open === false) {
                        cell.classList.add('is-closed');
                        const reason = String(profile.reason || 'SPMU Closed');
                        const tooltip = element('span', 'spmu-date-reason', reason);
                        tooltip.id = `${popup.id}-${date}-reason`;
                        tooltip.setAttribute('role', 'tooltip');
                        day.setAttribute('aria-describedby', tooltip.id);
                        const info = button('i', `Why ${date} is closed: ${reason}`, () => {
                            const wasOpen = cell.classList.contains('show-reason');
                            grid.querySelectorAll('.show-reason').forEach(other => {
                                other.classList.remove('show-reason');
                                other.querySelector('.spmu-date-info')?.setAttribute('aria-expanded', 'false');
                            });
                            cell.classList.toggle('show-reason', !wasOpen);
                            info.setAttribute('aria-expanded', String(!wasOpen));
                        });
                        info.className = 'spmu-date-info';
                        info.setAttribute('aria-describedby', tooltip.id);
                        info.setAttribute('aria-expanded', 'false');
                        cell.append(info, tooltip);
                    }
                    day.addEventListener('click', () => {
                        if (day.disabled) return;
                        field.value = date;
                        notify(field);
                        handle.close();
                    });
                    grid.append(cell);
                });
                status.textContent = 'Closed dates cannot be selected.';
            } catch {
                if (requestRevision !== revision || !popup.isConnected) return;
                grid.replaceChildren();
                status.textContent = 'Operating days could not be checked. Please retry.';
                retry.hidden = false;
            }
            handle.position();
            if (popup.isConnected) popup.querySelector('.is-selected:not(:disabled), .spmu-date-day:not(:disabled), button')?.focus();
        }
        render();
    }

    // This is the time scroller with a stationary selection highlight for SPMU Admin.
    function openTimePicker(field) {
        const handle = popupFor(field, 'Choose operational time');
        const popup = handle.node;
        popup.classList.add('spmu-time-popup');
        let parts = timeParts(field.value) || {hour: 8, minute: 0, period: 'AM'};
        const manual = element('input', 'spmu-time-manual');
        manual.type = 'time'; manual.step = '60'; manual.value = timeValue(parts);
        manual.setAttribute('aria-label', 'Type operational time');
        const labels = element('div', 'spmu-time-labels');
        ['Hour', 'Minute', 'AM/PM'].forEach(label => labels.append(element('strong', '', label)));
        const wheels = element('div', 'spmu-time-wheels');
        const overlay = element('span', 'spmu-time-highlight');
        overlay.setAttribute('aria-hidden', 'true');
        const controls = [];
        const definitions = [
            ['hour', Array.from({length: 12}, (_, i) => i + 1), 'Hour'],
            ['minute', Array.from({length: 60}, (_, i) => i), 'Minute'],
            ['period', ['AM', 'PM'], 'AM or PM'],
        ];
        const sync = () => {
            controls.forEach(({wheel, values, key}) => { wheel.scrollTop = values.indexOf(parts[key]) * 36; });
        };
        definitions.forEach(([key, values, label]) => {
            const wheel = element('div', 'spmu-time-wheel');
            wheel.tabIndex = 0; wheel.setAttribute('role', 'listbox'); wheel.setAttribute('aria-label', label);
            let timer;
            const choose = (index) => {
                parts[key] = values[Math.max(0, Math.min(values.length - 1, index))];
                manual.value = timeValue(parts);
                Array.from(wheel.children).forEach((option, i) => option.setAttribute('aria-selected', String(values[i] === parts[key])));
                wheel.setAttribute('aria-activedescendant', `${popup.id}-${key}-${values.indexOf(parts[key])}`);
            };
            values.forEach((value, i) => {
                const option = element('div', 'spmu-time-option', typeof value === 'number' ? String(value).padStart(2, '0') : value);
                option.id = `${popup.id}-${key}-${i}`;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', String(value === parts[key]));
                option.addEventListener('click', () => { choose(i); wheel.scrollTo({top: i * 36, behavior: 'smooth'}); });
                wheel.append(option);
            });
            wheel.addEventListener('scroll', () => { clearTimeout(timer); timer = setTimeout(() => choose(Math.round(wheel.scrollTop / 36)), 80); });
            wheel.addEventListener('keydown', (event) => {
                const current = values.indexOf(parts[key]);
                const target = {ArrowUp: current - 1, ArrowDown: current + 1, Home: 0, End: values.length - 1}[event.key];
                if (target === undefined) return;
                event.preventDefault();
                choose(target); wheel.scrollTop = values.indexOf(parts[key]) * 36;
            });
            controls.push({wheel, values, key});
            wheels.append(wheel);
        });
        wheels.append(overlay);
        const footer = element('div', 'spmu-popup-footer');
        footer.append(button('Cancel', 'Cancel time changes', () => handle.close()), button('Apply', 'Apply time', () => {
            if (field.disabled) { handle.close(); return; }
            const valid = timeParts(manual.value);
            if (!valid) { manual.setCustomValidity('Enter a valid time.'); manual.reportValidity(); return; }
            controls.forEach(({wheel, values, key}) => {
                parts[key] = values[Math.max(0, Math.min(values.length - 1, Math.round(wheel.scrollTop / 36)))];
            });
            field.value = timeValue(parts); notify(field); handle.close();
        }));
        manual.addEventListener('input', () => {
            manual.setCustomValidity('');
            const valid = timeParts(manual.value);
            if (valid) { parts = valid; sync(); }
        });
        popup.append(manual, labels, wheels, footer);
        sync(); handle.position();
        controls[0].wheel.focus();
    }

    function initialize() {
        document.querySelectorAll('[data-closure-date-picker]').forEach(field => enhanceField(field, openDatePicker, '▦'));
        document.querySelectorAll('[data-operational-time-picker]').forEach(field => enhanceField(field, openTimePicker, '◷'));
        document.querySelectorAll('table').forEach(table => {
            if (table.closest('.reporting-workspace, .sao-stock-card, .borrowing-calendar') || table.classList.contains('sao-stock-card-table')) return;
            const headers = Array.from(table.querySelectorAll('thead th'));
            if (!headers.length || headers.some(th => th.colSpan > 1 || th.rowSpan > 1) || !table.querySelector('td[data-label]')) return;
            table.querySelectorAll('tbody tr').forEach(row => Array.from(row.cells).forEach((cell, index) => {
                if (!cell.hasAttribute('data-label') && cell.colSpan === 1) cell.dataset.label = headers[index]?.textContent.trim() || '';
            }));
            table.classList.add('ui-mobile-records');
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once: true});
    else initialize();
})();
