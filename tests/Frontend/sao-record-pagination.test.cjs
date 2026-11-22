const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../../resources/views/requests/partials/sao-record-pagination.blade.php'), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];

function mount(count = 25) {
    const element = (dataset = {}) => ({dataset, value: '', hidden: false, textContent: '', handlers: {}, children: [],
        addEventListener(name, handler) { this.handlers[name] = handler; },
        dispatch(name) { this.handlers[name]?.(); },
        appendChild(child) { this.children.push(child); },
        replaceChildren(...children) { this.children = children; },
        attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        classList: { add() {} },
    });
    const body = {children: [], appendChild(row) { this.children = this.children.filter(child => child !== row); this.children.push(row); }};
    const rows = Array.from({length: count}, (_, i) => ({...element({search: `request ${i} borrower ${i % 2 ? 'ana' : 'ben'}`, created: String(i), status: i % 2 ? 'COMPLETED' : 'UNDER_REVIEW', attention: i < 3 ? '1' : '0'}), parentElement: body}));
    body.children = [...rows];
    const search = element();
    const status = element(); status.value = 'all';
    const sort = element(); sort.value = 'newest';
    const size = element(); size.value = '7';
    const empty = element(); const previous = element(); const next = element(); const range = element(); const pages = element();
    const pagination = {...element(), hidden: true, querySelector: selector => ({'[data-sao-record-previous]': previous, '[data-sao-record-next]': next, '[data-sao-record-range]': range, '[data-sao-record-pages]': pages, '[data-sao-record-page-size]': size})[selector]};
    vm.runInNewContext(script, {document: {querySelectorAll: () => rows, querySelector: selector => ({'[data-record-search]': search, '[data-record-status-filter]': status, '[data-record-sort]': sort, '[data-record-empty]': empty, '[data-sao-record-pagination]': pagination})[selector], createElement: () => element()}});
    return {rows, search, status, sort, size, empty, previous, next, range, pages, pagination, visible: () => body.children.filter(row => !row.hidden).map(row => row.dataset.created)};
}

test('SAO records paginate all matching records and preserve the final page', () => {
    const ui = mount();
    assert.deepEqual(ui.visible(), ['24','23','22','21','20','19','18']);
    assert.equal(ui.range.textContent, '1–7 of 25 requests');
    assert.equal(ui.previous.disabled, true);
    ui.next.dispatch('click'); ui.next.dispatch('click'); ui.next.dispatch('click');
    assert.deepEqual(ui.visible(), ['3','2','1','0']);
    assert.equal(ui.range.textContent, '22–25 of 25 requests');
    assert.equal(ui.next.disabled, true);
    ui.next.dispatch('click');
    assert.ok(ui.pages.children.length > 0);
});

test('search, attention and status filter the full set before pagination and reset to page one', () => {
    const ui = mount();
    ui.next.dispatch('click');
    ui.search.value = ' ANA '; ui.search.dispatch('input');
    assert.equal(ui.range.textContent, '1–7 of 12 requests');
    assert.ok(ui.pages.children.length > 0);
    ui.status.value = 'NEEDS_ATTENTION'; ui.status.dispatch('change');
    assert.deepEqual(ui.visible(), ['1']);
    assert.equal(ui.pagination.hidden, false);
    ui.status.value = 'UNDER_REVIEW'; ui.status.dispatch('change');
    assert.deepEqual(ui.visible(), []);
    assert.equal(ui.empty.hidden, false);
});

test('sort resets pagination and clearing filters restores every original record', () => {
    const ui = mount();
    ui.search.value = 'missing'; ui.search.dispatch('input');
    assert.equal(ui.range.textContent, '0–0 of 0 requests');
    ui.search.value = ''; ui.search.dispatch('input');
    ui.next.dispatch('click');
    ui.sort.value = 'oldest'; ui.sort.dispatch('change');
    assert.deepEqual(ui.visible(), ['0','1','2','3','4','5','6']);
    assert.equal(ui.range.textContent, '1–7 of 25 requests');
    assert.equal(ui.rows.length, 25);
});

for (const size of [7, 15, 30, 50]) {
    test('Request Records: no missing or duplicate records across all pages at ' + size + ' entries', () => {
        for (const count of [1, 6, 7, 8, 14, 15, 16, 29, 30, 31, 49, 50, 51, 103]) {
            const ui = mount(count);
            ui.size.value = String(size); ui.size.dispatch('change');
            const expected = Array.from({length: count}, (_, index) => String(count - index - 1));
            const seen = [];
            const totalPages = Math.ceil(count / size);
            for (let page = 1; page <= totalPages; page++) {
                const start = (page - 1) * size, end = Math.min(page * size, count);
                assert.deepEqual(ui.visible(), expected.slice(start, end));
                assert.equal(ui.range.textContent, (start + 1) + '–' + end + ' of ' + count + ' requests');
                assert.equal(ui.previous.disabled, page === 1);
                assert.equal(ui.next.disabled, page === totalPages);
                assert.equal(ui.pages.children.find(button => button.attributes['aria-current'] === 'page').textContent, String(page));
                seen.push(...ui.visible());
                if (page < totalPages) ui.next.dispatch('click');
            }
            assert.deepEqual(seen, expected);
            assert.equal(new Set(seen).size, count);
            const finalPage = ui.visible();
            ui.next.dispatch('click');
            assert.deepEqual(ui.visible(), finalPage);
        }
    });
}

test('Request Records combines search, attention and status and resets filtered page-size changes', () => {
    const ui = mount(123);
    const lastPage = () => ui.pages.children.at(-1).dispatch('click');
    lastPage();
    assert.deepEqual(ui.visible(), ['3', '2', '1', '0']);
    ui.search.value = '  ANA  '; ui.search.dispatch('input');
    assert.equal(ui.range.textContent, '1–7 of 61 requests');
    ui.next.dispatch('click');
    ui.status.value = 'COMPLETED'; ui.status.dispatch('change');
    assert.equal(ui.range.textContent, '1–7 of 61 requests');
    for (const size of [15, 30, 50, 7]) {
        lastPage();
        ui.size.value = String(size); ui.size.dispatch('change');
        assert.equal(ui.visible()[0], '121');
        assert.equal(ui.visible().length, size);
        assert.equal(ui.previous.disabled, true);
    }
    ui.status.value = 'NEEDS_ATTENTION'; ui.status.dispatch('change');
    assert.deepEqual(ui.visible(), ['1']);
    ui.status.value = 'UNDER_REVIEW'; ui.status.dispatch('change');
    assert.deepEqual(ui.visible(), []);
    assert.equal(ui.range.textContent, '0–0 of 0 requests');
    assert.equal(ui.pagination.hidden, true);
    ui.search.value = ''; ui.search.dispatch('input');
    assert.equal(ui.range.textContent, '1–7 of 62 requests');
    ui.status.value = 'all'; ui.status.dispatch('change');
    lastPage();
    ui.sort.value = 'oldest'; ui.sort.dispatch('change');
    assert.deepEqual(ui.visible(), ['0', '1', '2', '3', '4', '5', '6']);
    assert.equal(ui.previous.disabled, true);
    assert.equal(ui.rows.length, 123);
});

test('Request Records numbered pages and Previous preserve filters and final-page contents', () => {
    const ui = mount(103);
    ui.search.value = 'ben'; ui.search.dispatch('input');
    ui.status.value = 'UNDER_REVIEW'; ui.status.dispatch('change');
    ui.size.value = '15'; ui.size.dispatch('change');
    const expected = Array.from({length: 52}, (_, index) => String(102 - index * 2));
    ui.pages.children.find(button => button.textContent === '4').dispatch('click');
    assert.deepEqual(ui.visible(), expected.slice(45));
    assert.equal(ui.range.textContent, '46–52 of 52 requests');
    ui.previous.dispatch('click');
    assert.deepEqual(ui.visible(), expected.slice(30, 45));
    assert.equal(ui.search.value, 'ben');
    assert.equal(ui.status.value, 'UNDER_REVIEW');
    assert.equal(ui.size.value, '15');
});

test('Request Records handles an empty server result without exposing pagination', () => {
    const ui = mount(0);
    assert.deepEqual(ui.visible(), []);
    assert.equal(ui.pagination.hidden, true);
});

test('a small record set shows its page and count with both navigation buttons disabled', () => {
    const ui = mount(2);
    assert.equal(ui.pagination.hidden, false);
    assert.equal(ui.range.textContent, '1–2 of 2 requests');
    assert.ok(ui.pages.children.length > 0);
    assert.equal(ui.previous.disabled, true);
    assert.equal(ui.next.disabled, true);
    assert.deepEqual(ui.visible(), ['1','0']);
    assert.doesNotMatch(script, /fetch\(|\.submit\(|requestSubmit\(|->(?:save|update|delete)\(/);
});
