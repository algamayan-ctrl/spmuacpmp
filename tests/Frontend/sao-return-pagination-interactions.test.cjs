const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const {mountOperationalRecords, assertEveryPage, script} = require('./helpers/operational-record-browser.cjs');
const mount = (count = 23) => mountOperationalRecords({kind: 'return', count});

test('Return uses seven entries by default and preserves the final page', () => {
    const ui = mount();
    assert.deepEqual(ui.visible(), [23, 22, 21, 20, 19, 18, 17]);
    assert.equal(ui.range.textContent, 'Showing 1–7 of 23 records');
    assert.equal(ui.previous.disabled, true);
    ui.page(4);
    assert.deepEqual(ui.visible(), [2, 1]);
    assert.equal(ui.range.textContent, 'Showing 22–23 of 23 records');
    assert.equal(ui.next.disabled, true);
    ui.next.dispatch('click');
    assert.deepEqual(ui.visible(), [2, 1]);
    ui.previous.dispatch('click');
    assert.deepEqual(ui.visible(), [9, 8, 7, 6, 5, 4, 3]);
});

for (const size of [7, 15, 30, 50]) {
    test('Return: no missing or duplicate rows across all pages at ' + size + ' entries', () => {
        for (const count of [0, 1, 6, 7, 8, 14, 15, 16, 29, 30, 31, 49, 50, 51, 103]) {
            assertEveryPage(mount(count), Array.from({length: count}, (_, index) => count - index), size);
        }
    });
}

test('Return search and physical status combine across every page and retain filters during navigation', () => {
    const ui = mount(123);
    ui.page(18);
    ui.search.value = '  bOrRoWeR  '; ui.search.dispatch('input');
    for (const [status, offset] of [['ON_CUSTODY', 1], ['RETURN_INSPECTION', 2], ['OVERDUE', 3]]) {
        ui.status.value = status; ui.status.dispatch('change');
        const expected = Array.from({length: 41}, (_, index) => 120 + offset - index * 3);
        for (const size of [7, 15, 30, 50]) {
            assertEveryPage(ui, expected, size);
            assert.equal(ui.status.value, status);
            assert.equal(ui.search.value, '  bOrRoWeR  ');
        }
    }
    ui.status.value = 'RETURN_INSPECTION'; ui.status.dispatch('change');
    ui.search.value = 'RETURN-2 Event 2'; ui.search.dispatch('input');
    assert.deepEqual(ui.visible(), [2]);
    assert.equal(ui.range.textContent, 'Showing 1–1 of 1 record');
    assert.equal(ui.previous.disabled, true);
    ui.status.value = 'OVERDUE'; ui.status.dispatch('change');
    assert.deepEqual(ui.visible(), []);
    assert.equal(ui.empty.hidden, false);
    assert.equal(ui.footer.hidden, true);
    assert.equal(ui.table.hidden, true);
    ui.search.value = ''; ui.search.dispatch('input');
    assert.equal(ui.visible().length, 41);
    ui.status.value = 'all'; ui.status.dispatch('change');
    assert.equal(ui.visible().length, 50);
    assert.equal(ui.range.textContent, 'Showing 1–50 of 123 records');
});

test('Return size and sort changes reset to page one, including equal timestamps', () => {
    const ui = mount(123);
    for (const size of [7, 15, 30, 50]) {
        ui.page(Math.ceil(123 / Number(ui.size.value)));
        ui.size.value = String(size); ui.size.dispatch('change');
        assert.equal(ui.visible().length, size);
        assert.equal(ui.visible()[0], 123);
        assert.equal(ui.previous.disabled, true);
    }
    ui.page(3);
    ui.sort.value = 'oldest'; ui.sort.dispatch('change');
    assert.deepEqual(ui.visible(), Array.from({length: 50}, (_, index) => index + 1));
    ui.rows.forEach(row => { row.dataset.created = '10'; });
    ui.sort.value = 'newest'; ui.sort.dispatch('change');
    assert.deepEqual(ui.visible(), Array.from({length: 50}, (_, index) => 123 - index));
});

test('Return handles empty results and repeated initialization without duplicate handlers', () => {
    const ui = mount();
    ui.search.value = 'not found'; ui.search.dispatch('input');
    assert.equal(ui.footer.hidden, true);
    assert.equal(ui.empty.hidden, false);
    ui.search.value = ''; ui.search.dispatch('input');
    assert.equal(ui.range.textContent, 'Showing 1–7 of 23 records');
    vm.runInNewContext(script, ui.context);
    assert.equal(ui.next.handlers.get('click').length, 1);
    assert.equal(ui.search.handlers.get('input').length, 1);
    assert.equal(ui.size.handlers.get('change').length, 1);
    const empty = mount(0);
    assert.equal(empty.footer.hidden, true);
    assert.equal(empty.table.hidden, true);
    assert.equal(empty.empty.hidden, false);
});
