const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const template = fs.readFileSync(
    path.resolve(__dirname, '../../resources/views/custody/index.blade.php'),
    'utf8',
);

test('Return renders the shared Release browser with the approved page sizes', () => {
    const activeReturn = template.split("@elseif($mode === 'return')")[1].split(/\r?\n@else\r?\n/)[0];
    assert.match(activeReturn, /data-sao-release-browser/);
    assert.match(activeReturn, /data-release-pagination/);
    assert.match(activeReturn, /data-release-page-size/);
    assert.match(activeReturn, /data-release-previous/);
    assert.match(activeReturn, /data-release-next/);
    assert.match(activeReturn, /\[7, 15, 30, 50\]/);
    assert.match(activeReturn, /@include\('custody.partials.sao-release-list-interactions'\)/);
});

test('Return physical status keys belong to the current table rows', () => {
    const activeReturn = template.split("@elseif($mode === 'return')")[1].split(/\r?\n@else\r?\n/)[0];
    assert.match(activeReturn, /data-status="\{\{ \$physicalReturnStatus\['key'\] \}\}"/);
    assert.match(activeReturn, /data-id="\{\{ \$custody->id \}\}"/);
    assert.match(activeReturn, /data-search=/);
    assert.match(activeReturn, /id="operational-status"/);
});
