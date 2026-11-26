(() => {
    'use strict';

    // This is the browser for complete record lists already authorized by the server.
    const initialize = (table) => {
        if (table.dataset.recordTableReady) return;
        table.dataset.recordTableReady = '1';
        const rows = [...table.tBodies[0]?.rows || []].filter(row => !row.querySelector('td[colspan]'));
        rows.forEach(row => { row.dataset.recordRow = '1'; });
        const wrap = table.parentElement;
        const title = table.dataset.recordTable || 'Records';
        const paginationOnly = table.dataset.recordControls === 'pagination';
        const headers = [...table.tHead?.rows[0]?.cells || []].map(cell => cell.textContent.trim());
        const filterColumn = Number(table.dataset.recordFilterColumn ?? Math.max(0, headers.findIndex(label => /status|condition|type|stage/i.test(label))));
        const filterLabel = table.dataset.recordFilterLabel || headers[filterColumn] || 'Category';
        const text = (row) => (row.dataset.search || row.textContent).toLocaleLowerCase();
        const cellText = (row) => (row.dataset.recordFilter || row.cells[filterColumn]?.textContent || '').trim();
        const options = [...new Set(rows.map(cellText).filter(Boolean))].sort((a, b) => a.localeCompare(b));
        let page = 1;
        const toolbar = paginationOnly ? null : document.createElement('div');
        if (toolbar) {
            toolbar.className = 'record-table-toolbar';
            toolbar.setAttribute('role', 'search');
            toolbar.setAttribute('aria-label', `${title} filters`);
        }
        const control = (labelText, element, container = toolbar) => {
            const label = document.createElement('label');
            label.append(document.createTextNode(labelText), element);
            container.append(label);
            return element;
        };
        let search, filter, from, to, clear;
        if (!paginationOnly) {
            search = control('Search', document.createElement('input'));
            search.type = 'search'; search.maxLength = 200; search.placeholder = `Search ${title.toLocaleLowerCase()}…`;
            search.parentElement.classList.add('record-table-search');
            filter = control(filterLabel, document.createElement(options.length <= 12 && options.every(value => value.length <= 70) ? 'select' : 'input'));
            if (filter.tagName === 'SELECT') {
                filter.add(new Option(`All ${filterLabel.toLocaleLowerCase()}`, ''));
                options.forEach(value => filter.add(new Option(value, value)));
            } else {
                filter.type = 'search'; filter.maxLength = 200; filter.placeholder = `Search ${filterLabel.toLocaleLowerCase()}…`;
            }
            if (table.hasAttribute('data-record-dates')) {
                from = control('Date from', document.createElement('input')); from.type = 'date';
                to = control('Date to', document.createElement('input')); to.type = 'date';
            }
        }
        const size = document.createElement('select');
        [10, 25, 50, 100].forEach(value => size.add(new Option(paginationOnly ? `${value} entries` : String(value), String(value))));
        if (!paginationOnly) {
            control('Show entries', size);
            const actions = document.createElement('div'); actions.className = 'record-table-actions';
            clear = document.createElement('button'); clear.type = 'button'; clear.className = 'button secondary'; clear.textContent = 'Clear';
            actions.append(clear); toolbar.append(actions); wrap.before(toolbar);
        }
        const footer = document.createElement('footer'); footer.className = 'record-table-footer';
        const count = document.createElement('p'); count.className = 'meta'; count.setAttribute('role', 'status');
        const pages = document.createElement('nav'); pages.setAttribute('aria-label', `${title} pages`);
        footer.append(count);
        if (paginationOnly) {
            control('Show entries', size, footer);
            size.parentElement.classList.add('record-table-page-size');
            size.setAttribute('aria-label', `${title} entries per page`);
        }
        footer.append(pages); wrap.after(footer);
        const empty = document.createElement('tr'); empty.className = 'record-table-no-results';
        const emptyCell = document.createElement('td'); emptyCell.colSpan = headers.length || 1;
        const emptyText = paginationOnly ? `No ${title.toLocaleLowerCase()} records.` : 'No records match these filters.';
        emptyCell.textContent = emptyText; empty.append(emptyCell); table.tBodies[0]?.append(empty);
        const originals = [...table.tBodies[0]?.rows || []].filter(row => row !== empty && !rows.includes(row));
        const render = () => {
            const terms = (search?.value || '').trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
            const selected = (filter?.value || '').trim().toLocaleLowerCase();
            const invalidDates = Boolean(from?.value && to?.value && to.value < from.value);
            if (to) to.setCustomValidity(invalidDates ? 'Date to must be on or after Date from.' : '');
            emptyCell.textContent = invalidDates ? 'Date to must be on or after Date from.' : emptyText;
            const matches = rows.filter(row => terms.every(term => text(row).includes(term))
                && (!selected || (filter.tagName === 'SELECT' ? cellText(row).toLocaleLowerCase() === selected : cellText(row).toLocaleLowerCase().includes(selected)))
                && (!(from?.value || to?.value) || Boolean(row.dataset.recordDate))
                && (!from?.value || (row.dataset.recordDate || '') >= from.value)
                && (!to?.value || (row.dataset.recordDate || '') <= to.value));
            const perPage = Number(size.value);
            const last = Math.max(1, Math.ceil(matches.length / perPage)); page = Math.min(page, last);
            const visible = new Set(matches.slice((page - 1) * perPage, page * perPage));
            rows.forEach(row => { row.hidden = !visible.has(row); });
            originals.forEach(row => { row.hidden = true; });
            empty.hidden = matches.length > 0;
            count.textContent = `Showing ${matches.length ? (page - 1) * perPage + 1 : 0}–${Math.min(page * perPage, matches.length)} of ${matches.length} records`;
            pages.replaceChildren();
            const button = (label, target, disabled, current = false) => {
                const node = document.createElement('button'); node.type = 'button'; node.textContent = label;
                node.className = `button ${current ? 'primary' : 'secondary'} small`; node.disabled = disabled;
                if (current) node.setAttribute('aria-current', 'page');
                node.addEventListener('click', () => {
                    page = target; render();
                    const replacement = [...pages.querySelectorAll('button')].find(item => item.textContent === label && !item.disabled)
                        || pages.querySelector('[aria-current="page"]');
                    replacement?.focus();
                });
                pages.append(node);
            };
            button('Previous', page - 1, page === 1);
            const numbers = [...new Set([1, last, page - 1, page, page + 1].filter(number => number >= 1 && number <= last))].sort((a, b) => a - b);
            numbers.forEach((number, index) => {
                if (index && number > numbers[index - 1] + 1) { const gap = document.createElement('span'); gap.textContent = '…'; gap.setAttribute('aria-hidden', 'true'); pages.append(gap); }
                button(String(number), number, false, page === number);
            });
            button('Next', page + 1, page === last);
        };
        [search, filter, size, from, to].filter(Boolean).forEach(input => input.addEventListener(input.type === 'search' ? 'input' : 'change', () => { page = 1; render(); }));
        clear?.addEventListener('click', () => { search.value = ''; filter.value = ''; if (from) from.value = ''; if (to) to.value = ''; page = 1; render(); search.focus(); });
        render();
    };
    const start = () => document.querySelectorAll('table[data-record-table]').forEach(initialize);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true}); else start();
})();
