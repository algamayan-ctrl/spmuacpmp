const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {mountOperationalRecords, assertEveryPage} = require('./helpers/operational-record-browser.cjs');

const source = fs.readFileSync(path.resolve(__dirname, '../../resources/views/custody/partials/sao-release-list-interactions.blade.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
function element(value = '') {
    const handlers = new Map();
    return {value, textContent: '', hidden: false, disabled: false, dataset: {}, handlers, children: [],
        addEventListener(event, fn) { handlers.set(event, [...(handlers.get(event) || []), fn]); },
        dispatch(event) { for (const fn of handlers.get(event) || []) fn(); },
        appendChild(child) { this.children.push(child); },
        replaceChildren(...children) { this.children = children; },
        setAttribute() {},
        classList: { add() {} },
    };
}
function mount(count = 23) {
    const rows = Array.from({length: count}, (_, index) => ({
        dataset: {id: String(index + 1), created: String(index + 1), search: `Borrower ${index + 1} BR-${index + 1} Event ${index + 1}`, status: index % 2 ? 'READY_FOR_RELEASE' : 'FOR_PREPARATION'}, hidden: false,
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
    return {rows, search, status, sort, size, table, empty, footer, previous, next, range, pages, list, context,
        visible: () => list.children.filter(row => !row.hidden).map(row => Number(row.dataset.id)),
    };
}

test('release defaults to seven records and reaches the final page without losing or repeating rows', () => {
    const ui = mount();
    assert.deepEqual(ui.visible(), [23, 22, 21, 20, 19, 18, 17]);
    assert.equal(ui.range.textContent, 'Showing 1–7 of 23 records');
    assert.ok(ui.pages.children.length > 0);
    assert.equal(ui.previous.disabled, true);
    ui.next.dispatch('click');
    assert.deepEqual(ui.visible(), [16, 15, 14, 13, 12, 11, 10]);
    ui.next.dispatch('click');
    assert.deepEqual(ui.visible(), [9, 8, 7, 6, 5, 4, 3]);
    ui.next.dispatch('click');
    assert.deepEqual(ui.visible(), [2, 1]);
    assert.equal(ui.range.textContent, 'Showing 22–23 of 23 records');
    assert.equal(ui.next.disabled, true);
    ui.previous.dispatch('click');
    assert.ok(ui.pages.children.length > 0);
    assert.equal(new Set(ui.list.children).size, 23);
});

test('search includes records outside the visible page and restores page one when cleared', () => {
    const ui = mount();
    ui.next.dispatch('click');
    ui.search.value = '  bOrRoWeR 1 BR-1  ';
    ui.search.dispatch('input');
    assert.deepEqual(ui.visible(), [1]);
    assert.equal(ui.range.textContent, 'Showing 1–1 of 1 record');
    assert.equal(ui.search.value, '  bOrRoWeR 1 BR-1  ');
    ui.search.value = ''; ui.search.dispatch('input');
    assert.ok(ui.pages.children.length > 0);
    assert.equal(ui.visible().length, 7);
});

test('status and search apply before pagination and page changes preserve the filters', () => {
    const ui = mount(42);
    ui.status.value = 'READY_FOR_RELEASE'; ui.status.dispatch('change');
    assert.equal(ui.range.textContent, 'Showing 1–7 of 21 records');
    ui.next.dispatch('click');
    assert.ok(ui.pages.children.length > 0);
    assert.ok(ui.visible().every(id => id % 2 === 0));
    ui.search.value = 'Borrower 4'; ui.search.dispatch('input');
    assert.deepEqual(ui.visible(), [42, 40, 4]);
    assert.ok(ui.pages.children.length > 0);
});

test('changing page size resets the page and supports 7, 15, 30 and 50', () => {
    const ui = mount(105);
    for (const size of [15, 30, 50, 7]) {
        ui.next.dispatch('click');
        ui.size.value = String(size); ui.size.dispatch('change');
        assert.equal(ui.visible().length, size);
        assert.equal(ui.visible()[0], 105);
        assert.ok(ui.pages.children.length > 0);
    }
});

test('sort changes apply to all matches, reset pagination and keep stable order for equal times', () => {
    const ui = mount();
    ui.next.dispatch('click');
    ui.sort.value = 'oldest'; ui.sort.dispatch('change');
    assert.deepEqual(ui.visible(), [1, 2, 3, 4, 5, 6, 7]);
    ui.rows.forEach(row => { row.dataset.created = '50'; });
    ui.sort.value = 'newest'; ui.sort.dispatch('change');
    assert.deepEqual(ui.visible(), [23, 22, 21, 20, 19, 18, 17]);
});

for (const size of [7, 15, 30, 50]) {
    test('Release: no missing or duplicate rows across all pages at ' + size + ' entries', () => {
        for (const count of [0, 1, 6, 7, 8, 14, 15, 16, 29, 30, 31, 49, 50, 51, 103]) {
            assertEveryPage(mountOperationalRecords({count}), Array.from({length: count}, (_, index) => count - index), size);
        }
    });
}

test('Release numbered pages preserve search and status, and size changes restart filtered results', () => {
    const ui = mountOperationalRecords({count: 123});
    ui.search.value = '  BORROWER  '; ui.search.dispatch('input');
    ui.status.value = 'READY_FOR_RELEASE'; ui.status.dispatch('change');
    ui.size.value = '15'; ui.size.dispatch('change');
    const expected = Array.from({length: 61}, (_, index) => 122 - index * 2);
    ui.page(5);
    assert.deepEqual(ui.visible(), [2]);
    assert.equal(ui.range.textContent, 'Showing 61–61 of 61 records');
    ui.previous.dispatch('click');
    assert.deepEqual(ui.visible(), expected.slice(45, 60));
    assert.equal(ui.status.value, 'READY_FOR_RELEASE');
    assert.equal(ui.search.value, '  BORROWER  ');
    for (const size of [7, 15, 30, 50]) assertEveryPage(ui, expected, size);
    ui.sort.value = 'oldest'; ui.sort.dispatch('change');
    assert.deepEqual(ui.visible(), [...expected].reverse().slice(0, 50));
    assert.equal(ui.previous.disabled, true);
});

test('no matches and empty queues hide pagination and recover after the filter is cleared', () => {
    const ui = mount();
    ui.search.value = 'not found'; ui.search.dispatch('input');
    assert.equal(ui.empty.hidden, false);
    assert.equal(ui.footer.hidden, true);
    assert.equal(ui.table.hidden, true);
    ui.search.value = ''; ui.search.dispatch('input');
    assert.equal(ui.empty.hidden, true);
    assert.equal(ui.footer.hidden, false);
    assert.equal(ui.table.hidden, false);
    const empty = mount(0);
    assert.equal(empty.empty.hidden, false);
    assert.equal(empty.footer.hidden, true);
});

test('one page keeps its range and disabled navigation and initialization adds no duplicate handlers', () => {
    const ui = mount(2);
    assert.equal(ui.range.textContent, 'Showing 1–2 of 2 records');
    assert.equal(ui.previous.disabled, true);
    assert.equal(ui.next.disabled, true);
    assert.equal(ui.footer.hidden, false);
    vm.runInNewContext(script, ui.context);
    assert.equal(ui.next.handlers.get('click').length, 1);
    assert.equal(ui.search.handlers.get('input').length, 1);
});
