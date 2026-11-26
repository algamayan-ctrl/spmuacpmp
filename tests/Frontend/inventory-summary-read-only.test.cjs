const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
const show = read('resources/views/inventory/show.blade.php');
const js = read('public/js/ui-admin-revisions.js');
const css = read('public/css/ui-admin-revisions.css');

test('all five inventory quantity cards are static and keep their original labels and values', () => {
    const cards = [...show.matchAll(/<div class="inventory-admin-summary-item is-([^" ]+)"([^>]*)>/g)];
    assert.deepEqual(cards.map(card => card[1]), ['total', 'available', 'reserved', 'custody', 'unavailable']);
    for (const card of cards) {
        assert.doesNotMatch(card[2], /\b(?:role|tabindex|aria-haspopup|data-inventory-summary|onclick|onkeydown)\s*=/i);
    }
    for (const [label, value] of [['Total Stock', 'total'], ['Available', 'available'], ['Reserved', 'reserved'], ['On Custody', 'issued'], ['Unavailable', 'unavailable']]) {
        assert.ok(show.includes(`<span>${label}</span>\n                    <strong>{{ $${value} + 0 }}</strong>`));
    }
});

test('retired summary modal has no server-rendered markup, handlers or interaction styles', () => {
    assert.doesNotMatch(show, /inventory\.partials\.summary-dialog|data-inventory-summary/);
    const retired = path.join(root, 'resources/views/inventory/partials/summary-dialog.blade.php');
    if (fs.existsSync(retired)) {
        assert.equal(fs.readFileSync(retired, 'utf8').replace(/\{\{--[\s\S]*?--\}\}/g, '').trim(), '');
    }
    assert.doesNotMatch(js, /data-inventory-summary|data-summary-close|data-summary-stock-card/);
    assert.doesNotMatch(css, /inventory-summary-dialog|inventory-summary-source|inventory-admin-summary-item\[role=button\]/);
});

test('shared inventory data and unrelated interaction initializers remain in use', () => {
    const controller = read('app/Http/Controllers/InventoryController.php');
    const overview = read('resources/views/inventory/partials/sao-overview.blade.php');
    assert.match(controller, /'currentInventorySources' => \$currentInventorySources/);
    assert.match(show, /\$currentSources->isEmpty\(\)/);
    assert.match(overview, /\$currentSources->isNotEmpty\(\)/);
    assert.match(show, /data-inventory-tab/);
    assert.match(show, /data-inventory-adjustment-toggle/);
    assert.match(js, /data-closure-date-picker/);
    assert.match(js, /data-operational-time-picker/);
    assert.match(js, /ui-mobile-records/);
});
