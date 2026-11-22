const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const appCss = read('public/css/app.css');
const approvalsStyles = read('resources/views/approvals/partials/queue-styles.blade.php');
const gatePassStyles = read('resources/views/gate-passes/partials/index-styles.blade.php');
const laundryStyles = read('resources/views/laundry/partials/operations-styles.blade.php');
const oversightStyles = read('resources/views/custody/partials/oversight-styles.blade.php');
const borrowingsStyles = read('resources/views/custody/partials/borrowings-styles.blade.php');
const custodyIndex = read('resources/views/custody/index.blade.php');
const myRequestsStyles = read('resources/views/requests/partials/my-requests-styles.blade.php');
const saoReleaseStyles = read('resources/views/custody/partials/sao-release-list-styles.blade.php');

test('.app-main is a flex column that fills the viewport below the topbar', () => {
    const rule = appCss.match(/\.app-main \{[\s\S]*?\n\}/);
    assert.ok(rule, '.app-main rule should exist');
    assert.match(rule[0], /display:\s*flex;/);
    assert.match(rule[0], /flex-direction:\s*column;/);
    assert.match(rule[0], /min-height:\s*calc\(100vh - var\(--topbar-height\)\);/);
});

test('the page heading never grows, and .content-area absorbs the leftover height', () => {
    assert.match(appCss, /\.app-main > \.page-heading \{ flex: 0 0 auto; \}/);
    const rule = appCss.match(/\.app-main > \.content-area \{[\s\S]*?\}/);
    assert.ok(rule, '.app-main > .content-area rule should exist');
    assert.match(rule[0], /flex:\s*1 1 auto;/);
});

test('print layout resets the flex shell so it never adds blank printed pages', () => {
    const printBlock = appCss.match(/@media print \{[\s\S]*?\n\}/);
    assert.ok(printBlock, 'print media block should exist');
    assert.match(printBlock[0], /\.app-main \{[^}]*display:\s*block;[^}]*min-height:\s*0;/);
});

test('Requests for Approval (SPMU Head/AO): the empty queue panel grows to fill the page, populated content does not', () => {
    assert.match(approvalsStyles, /\.approval-queue \{[\s\S]*?display:\s*flex;[\s\S]*?flex-direction:\s*column;[\s\S]*?flex:\s*1 1 auto;/);
    assert.match(approvalsStyles, /\.approval-queue > \.approval-queue-empty \{ flex: 1 1 auto; \}/);
    // No leftover vh-clamped ceiling capping how tall the empty panel can grow.
    assert.doesNotMatch(approvalsStyles, /\.approval-queue-empty \{[^}]*clamp\(/);
    // The "no results after filter" variant (nested inside the populated
    // queue card, not a direct child of .approval-queue) must stay compact.
    assert.match(approvalsStyles, /\.approval-queue-no-results \{ min-height: 0;/);
});

test('Gate Pass records: the empty panel grows, populated toolbar/table does not', () => {
    assert.match(gatePassStyles, /\.gate-pass-browser \{[\s\S]*?display:\s*flex;[\s\S]*?flex-direction:\s*column;[\s\S]*?flex:\s*1 1 auto;/);
    assert.match(gatePassStyles, /\.gate-pass-browser > \.gate-pass-empty-card \{ flex: 1 1 auto; \}/);
});

test('Laundry Operations keeps the selected record panel full height and its pagination at the bottom', () => {
    assert.match(laundryStyles, /\.laundry-operations \{[\s\S]*?display:\s*flex;[\s\S]*?flex-direction:\s*column;[\s\S]*?flex:\s*1 1 auto;/);
    assert.doesNotMatch(laundryStyles, /\.laundry-operations > \.laundry-empty-card \{ flex: 1 1 auto; \}/);
    assert.match(laundryStyles, /\.laundry-operations \.laundry-cases-card\.is-empty \{[^}]*flex:\s*1 1 auto;/);
    assert.match(laundryStyles, /\.laundry-operations \.laundry-cases-card:not\(\.is-empty\) \{[^}]*flex:\s*1 1 auto;/);
    assert.match(laundryStyles, /\.laundry-operations \.laundry-cases-card:not\(\.is-empty\) > \.laundry-pagination \{[^}]*margin-top:\s*auto;/);
    assert.match(laundryStyles, /\.laundry-operations \[hidden\] \{ display: none !important; \}/);
    assert.match(laundryStyles, /\.laundry-inline-empty \{[^}]*min-height:\s*300px;/);
    assert.match(laundryStyles, /\.laundry-completed-block \{[^}]*margin:\s*0;/);
});

test('Release & Return Oversight: the empty panel grows, populated toolbar/list does not', () => {
    assert.match(oversightStyles, /\.custody-oversight \{[\s\S]*?display:\s*flex;[\s\S]*?flex-direction:\s*column;/);
    assert.match(oversightStyles, /\.custody-oversight > \.custody-oversight-empty \{ flex: 1 1 auto; \}/);
    assert.doesNotMatch(oversightStyles, /\.custody-oversight-empty \{[^}]*clamp\(/);
});

test('My Borrowings: the empty panel grows, populated toolbar/list does not', () => {
    assert.match(borrowingsStyles, /\.my-borrowings \{[\s\S]*?display:\s*flex;[\s\S]*?flex-direction:\s*column;/);
    assert.match(borrowingsStyles, /\.my-borrowings > \.my-borrowings-card \{ flex: 1 1 auto; \}/);
    assert.match(borrowingsStyles, /\.my-borrowings-card > \.borrowings-empty \{ flex: 1 1 auto; \}/);
    assert.doesNotMatch(borrowingsStyles, /\.borrowings-empty \{[^}]*clamp\(/);
});

test('AO Release/Return use the active shared table empty state with constrained text', () => {
    assert.match(custodyIndex, /@include\('custody.partials.sao-release-list'\)/);
    assert.match(custodyIndex, /@elseif\(\$mode === 'return'\)[\s\S]*?@include\('custody.partials.sao-release-list-styles'\)/);
    assert.match(saoReleaseStyles, /\.sao-release-browser \{[^}]*display:\s*flex;[^}]*flex-direction:\s*column;/);
    assert.match(saoReleaseStyles, /\.sao-release-empty \{[^}]*flex:\s*1 1 auto;[^}]*min-height:\s*280px/);
    assert.match(saoReleaseStyles, /\.sao-release-empty p \{[^}]*max-width:\s*42ch/);
    assert.match(saoReleaseStyles, /\.sao-release-browser \[hidden\] \{ display: none !important; \}/);
});

test('AO Release queue: the empty state uses the universal operational card with a release-specific icon', () => {
    assert.match(custodyIndex, /@if\(\$mode === 'release'\)[\s\S]*?class="operational-empty-card"/);
    assert.match(custodyIndex, /<x-icon name="box" size="30" \/>/);
    assert.match(custodyIndex, /No transactions waiting for release\./);
    assert.match(custodyIndex, /Approved transactions appear here until physical release is confirmed\./);
});

test('the shared .operational-record-list stack is a flex column (so flex-growing an empty state inside it works)', () => {
    assert.match(appCss, /\.operational-record-list \{ display: flex; flex-direction: column; gap: 9px; \}/);
});

test('My Requests (Borrower): the empty panel grows and centers, populated results do not', () => {
    assert.match(myRequestsStyles, /\.my-requests \{[\s\S]*?display:\s*flex;[\s\S]*?flex-direction:\s*column;/);
    assert.match(myRequestsStyles, /\.my-requests > \.mr-empty \{ flex: 1 1 auto; \}/);
    assert.match(myRequestsStyles, /\.mr-empty \{[\s\S]*?align-content:\s*center;/);
});


test('Return status filter contains physical return stages only', () => {
    for (const status of [
        'ON_CUSTODY',
        'RETURN_INSPECTION',
        'OVERDUE',
    ]) {
        assert.match(custodyIndex, new RegExp(`'key' => '${status}'`));
    }

    for (const unusedStatus of [
        'RSLDDP_DISPOSITION_PENDING',
        'RSLDDP_COMPLIANCE_VERIFICATION',
        'GATE_PASS_PENDING',
        'LINEN_PENDING',
        'BILLING_OPEN',
        'INCIDENT_OPEN',
        'RSLDDP_FOR_ACCOUNTING_PROCESSING',
    ]) {
        assert.doesNotMatch(custodyIndex, new RegExp(`'key' => '${unusedStatus}'`));
    }
});
