const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.resolve(__dirname, '../../resources/views/requests/form.blade.php'), 'utf8');
const readinessFunction = source.match(/function areRequiredDocumentsReady\(\) \{[\s\S]*?\n    \}/)?.[0];
assert.ok(readinessFunction, 'Use the actual form document-readiness function.');
const ready = new Function('externalBorrower', 'studentToggle', 'requestLetterAlreadyUploaded', 'requestLetterInput', 'ptcAlreadyUploaded', 'ptcInput', `return (${readinessFunction})();`);

for (const [label, external, toggle, letterSaved, letterFiles, ptcSaved, ptcFiles, expected] of [
    ['External with omitted student/PTC controls and saved letter', true, null, true, null, false, null, true],
    ['External with newly uploaded letter', true, null, false, 1, false, null, true],
    ['External still requires the borrowing request letter', true, null, false, null, false, null, false],
    ['External ignores an injected student checkbox for document readiness', true, true, true, null, false, null, true],
    ['CSPC ordinary borrowing needs no PTC', false, false, true, null, false, null, true],
    ['CSPC student activity requires PTC', false, true, true, null, false, null, false],
    ['CSPC student activity accepts a saved PTC', false, true, true, null, true, null, true],
    ['CSPC student activity accepts a newly uploaded PTC', false, true, true, null, false, 1, true],
]) {
    test(label, () => {
        assert.equal(ready(external, toggle === null ? null : {checked:toggle}, letterSaved,
            letterFiles === null ? null : {files:{length:letterFiles}}, ptcSaved,
            ptcFiles === null ? null : {files:{length:ptcFiles}}), expected);
    });
}
