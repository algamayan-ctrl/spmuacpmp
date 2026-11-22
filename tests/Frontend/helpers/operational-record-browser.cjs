const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.resolve(__dirname, '../../../resources/views/custody/partials/sao-release-list-interactions.blade.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];

function element(value = '') {
    const handlers = new Map();
    return {
        value, textContent: '', hidden: false, disabled: false, dataset: {}, handlers, children: [], attributes: {},
        addEventListener(event, fn) { handlers.set(event, [...(handlers.get(event) || []), fn]); },
        dispatch(event) { for (const fn of handlers.get(event) || []) fn(); },
        appendChild(child) { this.children.push(child); },
        replaceChildren(...children) { this.children = children; },
        setAttribute(name, value) { this.attributes[name] = value; },
        classList: { add() {} },
    };
}

function mountOperationalRecords({kind = 'release', count = 23} = {}) {
    const statuses = kind === 'return' ? ['ON_CUSTODY', 'RETURN_INSPECTION', 'OVERDUE'] : ['FOR_PREPARATION', 'READY_FOR_RELEASE'];
    const rows = Array.from({length: count}, (_, index) => ({
        dataset: {
            id: String(index + 1), created: String(index + 1),
            search: 'Borrower ' + (index + 1) + (kind === 'return' ? ' RETURN-' : ' BR-') + (index + 1) + ' Event ' + (index + 1),
            status: statuses[index % statuses.length],
        }, hidden: false,
    }));
    const search = element(), status = element('all'), sort = element('newest'), size = element('7');
    const table = element(), empty = element(), footer = element(), previous = element(), next = element(), range = element(), pages = element();
    const list = {children: [...rows], appendChild(row) { this.children = this.children.filter(child => child !== row); this.children.push(row); }};
    const controls = {
        '#operational-filter-list': list, '#operational-search': search, '#operational-status': status, '#operational-sort': sort,
        '[data-release-table-wrap]': table, '#operational-filter-empty': empty, '[data-release-pagination]': footer,
        '[data-release-page-size]': size, '[data-release-previous]': previous, '[data-release-next]': next,
        '[data-release-range]': range, '[data-release-pages]': pages,
    };
    const browser = {dataset: {}, querySelectorAll: () => rows, querySelector: selector => controls[selector]};
    const context = {document: {querySelector: selector => selector === '[data-sao-release-browser]' ? browser : null, createElement: () => element()}};
    vm.runInNewContext(script, context);
    return {
        rows, search, status, sort, size, table, empty, footer, previous, next, range, pages, list, context,
        visible: () => list.children.filter(row => !row.hidden).map(row => Number(row.dataset.id)),
        page(number) {
            const button = pages.children.find(child => child.textContent === String(number) && child.handlers.has('click'));
            assert.ok(button, 'Page ' + number + ' must be reachable');
            button.dispatch('click');
        },
    };
}

function assertEveryPage(ui, expected, pageSize) {
    ui.size.value = String(pageSize); ui.size.dispatch('change');
    if (!expected.length) {
        assert.deepEqual(ui.visible(), []);
        assert.equal(ui.empty.hidden, false);
        assert.equal(ui.footer.hidden, true);
        assert.equal(ui.table.hidden, true);
        return;
    }
    const seen = [], totalPages = Math.ceil(expected.length / pageSize);
    for (let page = 1; page <= totalPages; page++) {
        const start = (page - 1) * pageSize, end = Math.min(page * pageSize, expected.length);
        assert.deepEqual(ui.visible(), expected.slice(start, end));
        assert.equal(ui.range.textContent, 'Showing ' + (start + 1) + '–' + end + ' of ' + expected.length + (expected.length === 1 ? ' record' : ' records'));
        assert.equal(ui.previous.disabled, page === 1);
        assert.equal(ui.next.disabled, page === totalPages);
        assert.equal(ui.pages.children.find(button => button.attributes['aria-current'] === 'page').textContent, String(page));
        seen.push(...ui.visible());
        if (page < totalPages) ui.next.dispatch('click');
    }
    assert.deepEqual(seen, expected);
    assert.equal(new Set(seen).size, expected.length);
    const finalPage = ui.visible();
    ui.next.dispatch('click');
    assert.deepEqual(ui.visible(), finalPage);
    assert.equal(new Set(ui.list.children).size, ui.rows.length);
}

module.exports = {mountOperationalRecords, assertEveryPage, script};
