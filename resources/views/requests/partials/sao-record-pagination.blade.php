<script>
(() => {
    const rows = Array.from(document.querySelectorAll('[data-request-record]'));
    const search = document.querySelector('[data-record-search]');
    const status = document.querySelector('[data-record-status-filter]');
    const sort = document.querySelector('[data-record-sort]');
    const empty = document.querySelector('[data-record-empty]');
    const pagination = document.querySelector('[data-sao-record-pagination]');
    if (!rows.length || !search || !status || !sort || !pagination) return;
    const previous = pagination.querySelector('[data-sao-record-previous]');
    const next = pagination.querySelector('[data-sao-record-next]');
    const range = pagination.querySelector('[data-sao-record-range]');
    const pages = pagination.querySelector('[data-sao-record-pages]');
    const body = rows[0].parentElement;
    const pageSizeControl = pagination.querySelector('[data-sao-record-page-size]');
    const getPageSize = () => Math.max(1, Number.parseInt(pageSizeControl?.value || '7', 10) || 7);
    let page = 1;
    const pageNumbers = (totalPages) => {
        const candidates = [1, page - 1, page, page + 1, totalPages]
            .filter(number => number >= 1 && number <= totalPages)
            .sort((left, right) => left - right);
        const result = [];
        candidates.forEach(number => {
            if (result.length && number - result[result.length - 1] > 1) result.push('…');
            if (!result.includes(number)) result.push(number);
        });
        return result;
    };
    const renderPageNumbers = (totalPages) => {
        if (!pages) return;
        pages.replaceChildren();
        pageNumbers(totalPages).forEach(number => {
            if (number === '…') {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'app-pagination-ellipsis';
                ellipsis.setAttribute('aria-hidden', 'true');
                ellipsis.textContent = '…';
                pages.appendChild(ellipsis);
                return;
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'app-pagination-link';
            button.textContent = String(number);
            button.setAttribute('aria-label', `Page ${number}`);
            if (number === page) {
                button.classList.add('is-active');
                button.setAttribute('aria-current', 'page');
            }
            button.addEventListener('click', () => { page = number; render(); });
            pages.appendChild(button);
        });
    };

    const render = () => {
        const term = search.value.trim().toLowerCase();
        const selectedStatus = status.value;
        const matches = rows.filter(row => {
            const matchesStatus = selectedStatus === 'all' || row.dataset.status === selectedStatus;
            return (!term || row.dataset.search.toLowerCase().includes(term)) && matchesStatus;
        }).sort((a, b) => (Number(b.dataset.created) - Number(a.dataset.created)) * (sort.value === 'newest' ? 1 : -1));
        const pageSize = getPageSize();
        const totalPages = Math.max(1, Math.ceil(matches.length / pageSize));
        page = Math.min(Math.max(1, page), totalPages);
        const start = (page - 1) * pageSize;
        rows.forEach(row => { row.hidden = true; });
        matches.forEach((row, index) => { body.appendChild(row); row.hidden = index < start || index >= start + pageSize; });
        if (empty) empty.hidden = matches.length > 0;
        pagination.hidden = matches.length === 0;
        range.textContent = `${matches.length ? start + 1 : 0}–${Math.min(start + pageSize, matches.length)} of ${matches.length} requests`;
        renderPageNumbers(totalPages);
        previous.disabled = page === 1;
        next.disabled = page === totalPages;
    };
    [search, status, sort].forEach(control => control.addEventListener(control === search ? 'input' : 'change', () => { page = 1; render(); }));
    pageSizeControl?.addEventListener('change', () => { page = 1; render(); });
    previous.addEventListener('click', () => { if (page > 1) { page--; render(); } });
    next.addEventListener('click', () => { page++; render(); });
    render();
})();
</script>
