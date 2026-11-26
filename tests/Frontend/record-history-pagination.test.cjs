const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../public/js/record-tables.js'), 'utf8');

function mount({ count = 23, title = 'Transaction history', paginationOnly = true } = {}) {
    const created = [];
    const element = (tag, text = '') => {
        const node = {
            tagName: tag.toUpperCase(), dataset: {}, attributes: {}, children: [], handlers: {},
            value: '', hidden: false, className: '', _text: text,
            setAttribute(name, value) { this.attributes[name] = String(value); },
            hasAttribute(name) { return name in this.attributes; },
            addEventListener(name, handler) { this.handlers[name] = handler; },
            dispatch(name) { this.handlers[name]?.(); },
            append(...children) {
                for (const child of children) {
                    if (child.parentElement) child.parentElement.children = child.parentElement.children.filter(item => item !== child);
                    child.parentElement = this;
                    this.children.push(child);
                }
            },
            add(option) { this.append(option); if (this.children.length === 1) this.value = option.value; },
            before(node) { this.parentElement.children.splice(this.parentElement.children.indexOf(this), 0, node); node.parentElement = this.parentElement; },
            after(node) { this.parentElement.children.splice(this.parentElement.children.indexOf(this) + 1, 0, node); node.parentElement = this.parentElement; },
            replaceChildren(...nodes) { this.children = []; this.append(...nodes); },
            querySelectorAll(selector) {
                const descendants = this.children.flatMap(child => [child, ...child.querySelectorAll('*')]);
                return descendants.filter(child => selector === '*'
                    || (selector === 'button' && child.tagName === 'BUTTON')
                    || (selector === 'td[colspan]' && child.tagName === 'TD' && child.hasAttribute('colspan'))
                    || (selector === '[aria-current="page"]' && child.attributes['aria-current'] === 'page'));
            },
            querySelector(selector) { return this.querySelectorAll(selector)[0] || null; },
            focus() { this.focused = true; },
            setCustomValidity(message) { this.validationMessage = message; },
        };
        Object.defineProperty(node, 'textContent', {
            get() { return this._text + this.children.map(child => child.textContent).join(''); },
            set(value) { this._text = value; this.children = []; },
        });
        node.classList = { add(name) { node.className += ` ${name}`; } };
        created.push(node);
        return node;
    };
    const root = element('section');
    const wrap = element('div'); root.append(wrap);
    const table = element('table'); wrap.append(table);
    table.dataset.recordTable = title;
    if (paginationOnly) table.dataset.recordControls = 'pagination';
    table.setAttribute('data-record-dates', '');
    table.dataset.recordFilterColumn = '1';
    const body = element('tbody'); table.append(body);
    Object.defineProperty(body, 'rows', { get() { return this.children; } });
    table.tBodies = [body];
    table.tHead = { rows: [{ cells: ['Record', 'Status', 'Action'].map(text => element('th', text)) }] };
    const rows = Array.from({ length: count }, (_, index) => {
        const row = element('tr');
        row.dataset.id = String(index);
        row.dataset.recordDate = `2026-11-${String(index % 28 + 1).padStart(2, '0')}`;
        const link = element('a', 'Preview'); link.setAttribute('href', `/files/${index}/preview`);
        row.cells = [element('td', `Record ${index}`), element('td', index % 2 ? 'Closed' : 'Open'), element('td')];
        row.cells[2].append(link); row.append(...row.cells); body.append(row);
        return row;
    });
    vm.runInNewContext(source, {
        document: { readyState: 'complete', querySelectorAll: () => [table],
            createElement: tag => element(tag), createTextNode: text => element('#text', text) },
        Option: function (text, value) { const option = element('option', text); option.value = value; return option; },
    });
    const footer = root.children.find(node => node.tagName === 'FOOTER');
    const pages = footer.children.find(node => node.tagName === 'NAV');
    const size = created.find(node => node.tagName === 'SELECT' && node.children[0]?.value === '10');
    return { created, root, rows, footer, pages, size,
        visible: () => rows.filter(row => !row.hidden).map(row => row.dataset.id),
        button: text => pages.children.find(node => node.tagName === 'BUTTON' && node.textContent === text),
        summary: () => footer.children.find(node => node.tagName === 'P').textContent };
}

for (const title of ['Transaction history', 'Document history']) {
    test(`${title} has pagination without Search, status, date or Clear controls`, () => {
        const ui = mount({ title });
        assert.equal(ui.created.filter(node => node.tagName === 'INPUT').length, 0);
        assert.equal(ui.created.filter(node => node.className.includes('record-table-toolbar')).length, 0);
        assert.equal(ui.created.filter(node => node.textContent === 'Clear').length, 0);
        assert.equal(ui.created.filter(node => node.tagName === 'SELECT').length, 1);
        assert.equal(ui.size.parentElement.parentElement, ui.footer);
        assert.deepEqual(ui.size.children.map(option => option.textContent), ['10 entries', '25 entries', '50 entries', '100 entries']);
        assert.equal(ui.size.attributes['aria-label'], `${title} entries per page`);
        assert.equal(ui.rows[0].cells[2].children[0].attributes.href, '/files/0/preview');
    });
}

for (const size of [10, 25, 50, 100]) {
    test(`history pagination retains every record and its final page at ${size} entries`, () => {
        const ui = mount({ count: 103 });
        ui.size.value = String(size); ui.size.dispatch('change');
        const seen = [];
        do {
            seen.push(...ui.visible());
            if (ui.button('Next').disabled) break;
            ui.button('Next').dispatch('click');
        } while (true);
        assert.deepEqual(seen, Array.from({ length: 103 }, (_, index) => String(index)));
        assert.equal(new Set(seen).size, 103);
        assert.match(ui.summary(), /of 103 records$/);
        assert.ok(ui.visible().length <= size);
        ui.button('Previous').dispatch('click');
        ui.size.value = '25'; ui.size.dispatch('change');
        assert.deepEqual(ui.visible(), Array.from({ length: 25 }, (_, index) => String(index)));
        assert.equal(ui.button('Previous').disabled, true);
    });
}

test('an empty history has a neutral message with no filter controls', () => {
    const ui = mount({ count: 0 });
    assert.equal(ui.summary(), 'Showing 0–0 of 0 records');
    assert.ok(ui.created.some(node => node.tagName === 'TD' && node.textContent === 'No transaction history records.'));
    assert.equal(ui.button('Previous').disabled, true);
    assert.equal(ui.button('Next').disabled, true);
});

test('tables without the history opt-in retain search, status, dates and Clear behavior', () => {
    const ui = mount({ paginationOnly: false, title: 'Analytics records' });
    const search = ui.created.find(node => node.tagName === 'INPUT' && node.type === 'search');
    const filter = ui.created.find(node => node.tagName === 'SELECT' && node !== ui.size);
    const [from, to] = ui.created.filter(node => node.tagName === 'INPUT' && node.type === 'date');
    const clear = ui.created.find(node => node.tagName === 'BUTTON' && node.textContent === 'Clear');
    search.value = 'Record 2'; search.dispatch('input');
    assert.deepEqual(ui.visible(), ['2', '12', '20', '21', '22']);
    filter.value = 'Open'; filter.dispatch('change');
    assert.deepEqual(ui.visible(), ['2', '12', '20', '22']);
    from.value = '2026-11-22'; from.dispatch('change');
    assert.deepEqual(ui.visible(), ['22']);
    to.value = '2026-11-22'; to.dispatch('change');
    assert.deepEqual(ui.visible(), []);
    clear.dispatch('click');
    assert.equal(ui.visible().length, 10);
    assert.equal(search.focused, true);
    assert.equal(filter.value, ''); assert.equal(from.value, ''); assert.equal(to.value, '');
});
