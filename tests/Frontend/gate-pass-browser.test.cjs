const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../resources/views/gate-passes/partials/index-interactions.blade.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];

function element(dataset = {}) {
    const handlers = new Map();
    return {
        dataset, children: [], attributes: {}, value: '', hidden: false, disabled: false,
        setAttribute(key, value) { this.attributes[key] = value; },
        addEventListener(name, callback) { handlers.set(name, [...(handlers.get(name) || []), callback]); },
        dispatch(name, event) { (handlers.get(name) || []).forEach(callback => callback(event)); },
        appendChild(child) { this.children = this.children.filter(item => item !== child); this.children.push(child); },
        replaceChildren() { this.children = []; },
        querySelector() { return this.children.find(child => child.attributes['aria-current'] === 'page'); },
        querySelectorAll() { return []; },
        closest() { return this.dataset.gatePassPage ? this : null; },
        focus() { this.focused = true; },
        setCustomValidity(message) { this.validationMessage = message; },
        reportValidity() { this.validityReported = true; return !this.validationMessage; },
    };
}

function mount(count = 23, { emptyArchive = false } = {}) {
    const records = Array.from({ length: count }, (_, i) => element({
        search: `Borrower ${i + 1} ${i === 0 ? 'Maria Science Hall' : 'Campus'}`,
        status: i % 2 ? 'VERIFIED' : 'PENDING', date: String(i + 1),
        recordedDate: new Date(Date.UTC(2026, 0, i + 1)).toISOString().slice(0, 10),
    }));
    const names = ['list', 'search', 'status', 'sort', 'empty', 'count', 'pagination', 'page-numbers', 'reset', 'clear', 'size', 'from', 'to'];
    const controls = Object.fromEntries(names.map(name => [name, element()]));
    controls.sort.value = 'newest';
    controls.size.value = '7';
    controls.list.children = [...records];
    const previous = element({ gatePassPage: 'previous' });
    const next = element({ gatePassPage: 'next' });
    const root = element({ pageSize: '7' });
    root.querySelector = selector => selector.includes('="previous"') ? previous
        : selector.includes('="next"') ? next
        : emptyArchive && selector === '[data-gate-pass-list]' ? null : controls[selector.slice(16, -1)];
    root.querySelectorAll = selector => selector === '[data-gate-pass-record]' ? records : [];
    const context = {
        document: { querySelector: () => root, createElement: () => element(), addEventListener() {} },
        window: { addEventListener() {}, requestAnimationFrame() {}, innerWidth: 1024, innerHeight: 768 },
    };
    vm.runInNewContext(script, context);
    return { ...controls, previous, next, context, records,
        visible: () => controls.list.children.filter(row => !row.hidden).map(row => Number(row.dataset.date)),
        click: target => controls.pagination.dispatch('click', { target }),
    };
}

test('pagination exposes all records with correct ranges and boundaries', () => {
    const ui = mount();
    assert.deepEqual(ui.visible(), [23, 22, 21, 20, 19, 18, 17]);
    assert.equal(ui.count.textContent, 'Showing 1 to 7 of 23 records');
    assert.equal(ui.previous.disabled, true);
    ui.click(ui.next);
    assert.equal(ui.count.textContent, 'Showing 8 to 14 of 23 records');
    ui.click(ui.next);
    assert.deepEqual(ui.visible(), [9, 8, 7, 6, 5, 4, 3]);
    ui.click(ui.next);
    assert.deepEqual(ui.visible(), [2, 1]);
    assert.equal(ui.next.disabled, true);
    ui.click(ui.next);
    assert.equal(ui.count.textContent, 'Showing 22 to 23 of 23 records');
    ui.click(ui.previous);
    assert.equal(ui.count.textContent, 'Showing 15 to 21 of 23 records');
    assert.equal(ui['page-numbers'].querySelector().focused, true);
});

test('search finds records outside the current page and combines with status', () => {
    const ui = mount();
    ui.search.value = '  SCIENCE   maria ';
    ui.search.dispatch('input');
    assert.deepEqual(ui.visible(), [1]);
    assert.equal(ui.count.textContent, 'Showing 1 to 1 of 1 records');
    ui.status.value = 'VERIFIED';
    ui.status.dispatch('change');
    assert.deepEqual(ui.visible(), []);
    assert.equal(ui.empty.hidden, false);
    assert.equal(ui.pagination.hidden, true);
    assert.equal(ui.count.textContent, 'Showing 0 records');
    ui.reset.dispatch('click');
    assert.equal(ui.empty.hidden, true);
    assert.equal(ui.pagination.hidden, false);
    assert.equal(ui.search.focused, true);
    assert.equal(ui.visible()[0], 23);
});

test('sort and filter changes reset pagination, while numbered navigation works', () => {
    const ui = mount();
    ui.click(ui['page-numbers'].children.find(button => button.dataset.gatePassPage === '4'));
    assert.equal(ui.count.textContent, 'Showing 22 to 23 of 23 records');
    ui.sort.value = 'oldest';
    ui.sort.dispatch('change');
    assert.deepEqual(ui.visible(), [1, 2, 3, 4, 5, 6, 7]);
    ui.status.value = 'VERIFIED';
    ui.status.dispatch('change');
    assert.equal(ui.count.textContent, 'Showing 1 to 7 of 11 records');
    ui.click(ui.next);
    assert.deepEqual(ui.visible(), [16, 18, 20, 22]);
});

test('reinitializing is harmless and the true empty state needs no controls', () => {
    const ui = mount();
    vm.runInNewContext(script, ui.context);
    ui.click(ui.next);
    assert.equal(ui.count.textContent, 'Showing 8 to 14 of 23 records');
    assert.doesNotThrow(() => vm.runInNewContext(script, {
        document: { querySelector: () => ({ dataset: {}, querySelector: () => null }) },
    }));
});

test('date filters cover all recorded dates and page-size changes reset navigation', () => {
    const ui = mount();
    ui.click(ui.next);
    ui.from.value = '2026-01-01';
    ui.to.value = '2026-01-03';
    ui.from.dispatch('change');
    assert.deepEqual(ui.visible(), [3, 2, 1]);
    assert.equal(ui.count.textContent, 'Showing 1 to 3 of 3 records');
    ui.clear.dispatch('click');
    ui.size.value = '30';
    ui.size.dispatch('change');
    assert.equal(ui.visible().length, 23);
    assert.equal(ui.next.disabled, true);
    assert.equal(ui.search.focused, true);
});

test('date endpoints are inclusive and combine with search, status and sorting before pagination', () => {
    const ui = mount();
    ui.from.value = '2026-01-04';
    ui.to.value = '2026-01-08';
    ui.to.dispatch('change');
    assert.deepEqual(ui.visible(), [8, 7, 6, 5, 4]);
    assert.equal(ui.from.max, '2026-01-08');
    assert.equal(ui.to.min, '2026-01-04');
    ui.status.value = 'VERIFIED'; ui.status.dispatch('change');
    assert.deepEqual(ui.visible(), [8, 6, 4]);
    ui.search.value = 'Borrower 6'; ui.search.dispatch('input');
    assert.deepEqual(ui.visible(), [6]);
    ui.search.value = ''; ui.search.dispatch('input');
    ui.sort.value = 'oldest'; ui.sort.dispatch('change');
    assert.deepEqual(ui.visible(), [4, 6, 8]);
});

test('one-sided date filters reach historical records and exclude missing recorded dates only while filtering', () => {
    const ui = mount();
    ui.records[22].dataset.recordedDate = '';
    ui.from.value = '2026-01-20'; ui.from.dispatch('change');
    assert.deepEqual(ui.visible(), [22, 21, 20]);
    ui.from.value = ''; ui.to.value = '2026-01-03'; ui.to.dispatch('change');
    assert.deepEqual(ui.visible(), [3, 2, 1]);
    ui.clear.dispatch('click');
    assert.equal(ui.visible()[0], 23);
    assert.equal(ui.count.textContent, 'Showing 1 to 7 of 23 records');
});

test('reversed dates show native validation and clear recovers the full register', () => {
    const ui = mount();
    ui.from.value = '2026-01-20'; ui.to.value = '2026-01-01'; ui.from.dispatch('change');
    assert.deepEqual(ui.visible(), []);
    assert.equal(ui.to.validationMessage, 'Date to must be on or after Date from.');
    assert.equal(ui.to.validityReported, true);
    ui.clear.dispatch('click');
    assert.equal(ui.to.validationMessage, '');
    assert.equal(ui.from.max, '');
    assert.equal(ui.to.min, '');
    assert.equal(ui.visible().length, 7);
});

test('each approved page size resets navigation and exposes every record without duplicates', () => {
    const ui = mount(105);
    for (const size of [7, 15, 30, 50]) {
        ui.click(ui.next);
        ui.size.value = String(size); ui.size.dispatch('change');
        assert.equal(ui.visible().length, size);
        assert.equal(ui.visible()[0], 105);
        assert.equal(ui.previous.disabled, true);
        const seen = [...ui.visible()];
        while (!ui.next.disabled) { ui.click(ui.next); seen.push(...ui.visible()); }
        assert.equal(seen.length, 105);
        assert.equal(new Set(seen).size, 105);
        assert.equal(seen.at(-1), 1);
    }
});

test('both Clear controls restore dates, search, sort, page size and the first page', () => {
    const ui = mount();
    for (const clearButton of [ui.clear, ui.reset]) {
        ui.size.value = '15'; ui.size.dispatch('change'); ui.click(ui.next);
        ui.from.value = '2026-01-02'; ui.to.value = '2026-01-22';
        ui.search.value = 'Borrower'; ui.status.value = 'VERIFIED'; ui.sort.value = 'oldest';
        clearButton.dispatch('click');
        assert.equal(ui.from.value, ''); assert.equal(ui.to.value, '');
        assert.equal(ui.search.value, ''); assert.equal(ui.status.value, '');
        assert.equal(ui.sort.value, 'newest'); assert.equal(ui.size.value, '7');
        assert.equal(ui.previous.disabled, true); assert.equal(ui.visible()[0], 23);
    }
});

test('unsupported page sizes fall back to seven entries and controls still reset an empty archive', () => {
    const ui = mount();
    for (const size of ['0', '100', 'invalid']) {
        ui.size.value = size; ui.size.dispatch('change');
        assert.equal(ui.size.value, '7'); assert.equal(ui.visible().length, 7);
    }
    const empty = mount(0, { emptyArchive: true });
    empty.search.value = 'No match'; empty.from.value = '2026-01-01'; empty.to.value = '2026-01-02';
    empty.size.value = '50'; empty.sort.value = 'oldest';
    empty.clear.dispatch('click');
    assert.equal(empty.search.value, ''); assert.equal(empty.from.value, ''); assert.equal(empty.to.value, '');
    assert.equal(empty.size.value, '7'); assert.equal(empty.sort.value, 'newest');
});
