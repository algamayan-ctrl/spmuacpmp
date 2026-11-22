const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const preparation = read('resources/views/custody/partials/item-preparation-form.blade.php');
const process = read('resources/views/custody/partials/release-process.blade.php');
const interactions = read('resources/views/custody/partials/release-process-interactions.blade.php');
const show = read('resources/views/custody/show.blade.php');
const controller = read('app/Http/Controllers/CustodyController.php');
const workflow = read('app/Services/RequestWorkflowService.php');


/*
 * The numbered "Release Process" container (with its own "Item Preparation"
 * step, badge, and toggle) was replaced by a redesign that merges
 * preparation into the Approved Items card - see release-process.blade.php
 * and CustodyReleasePageRedesignTest.php. item-preparation-form.blade.php
 * itself (read here as `preparation`) is left unchanged on disk and is no
 * longer @include-d from the release page; its own contract is still worth
 * asserting in case it is ever re-linked.
 */
test('Step 2 keeps the normal path simple and the release page no longer repeats a discrepancy instruction', () => {
    assert.doesNotMatch(process, /<h3>Item Preparation<\/h3>/);
    assert.doesNotMatch(process, /Physically check each approved item before release/);
    assert.match(process, /<h2>Approved Items<\/h2>/);
    assert.match(process, /Preparation/);
    assert.match(preparation, /Pending Preparation/);
    assert.match(preparation, /Confirm Items Prepared/);
    assert.match(preparation, /Report Inventory Discrepancy/);
    assert.doesNotMatch(preparation, /All approved items must be physically ready before release/);
    assert.doesNotMatch(preparation, /Record only what you physically observed/);
});


test('AO discrepancy form shows only fields applicable to the selected discrepancy', () => {
    assert.match(preparation, /data-preparation-issue-type/);
    assert.match(preparation, /data-preparation-quantity-field hidden/);
    assert.match(preparation, /data-preparation-condition-field hidden/);
    assert.match(preparation, /data-preparation-details-field hidden/);
    assert.match(preparation, /Physically ready quantity/);
    assert.match(preparation, /Condition observed/);
    assert.match(preparation, /Remarks \(Optional\)/);
    assert.match(interactions, /value === 'QUANTITY_AVAILABILITY'/);
    assert.match(interactions, /value === 'PHYSICAL_CONDITION'/);
    assert.match(interactions, /value === 'OTHER'/);
    assert.match(interactions, /setFieldState(detailsField, detailsInput, hasOptionalRemarks || otherIssue, otherIssue)/);
});


test('reported discrepancy blocks preparation and preserves the AO recheck path', () => {
    assert.match(preparation, /Inventory Review Required/);
    assert.match(preparation, /Inventory Reviewed — Recheck Item/);
    assert.match(preparation, /@disabled\(\$openPreparationIssues->isNotEmpty\(\)\)/);
    assert.match(process, /\$canConfirmPreparation = \$hasPickupSchedule && ! \$hasOpenPreparationIssue;/);
    assert.match(process, /@disabled\(!\s*\$canConfirmPreparation\)/);
    assert.match(process, /Complete the item review before release\./);
});


test('admin uses one resolution control instead of duplicate resolution forms before pickup expiry', () => {
    assert.match(show, /Inventory Discrepancy Review/);
    assert.match(show, /data-preparation-resolution-form/);
    assert.match(show, /Resolved — AO Recheck Required/);
    assert.match(show, /Unable to Fulfill Approved Request/);
    assert.match(show, /Remarks \(Optional\)/);
    assert.match(show, /data-preparation-unfulfillable-confirmation hidden/);
    assert.match(show, /Confirm Inventory Reviewed/);
    assert.match(controller, /cancelApprovedForPreparationDiscrepancy/);
    assert.match(workflow, /PREPARATION_ISSUE_CLOSED_UNFULFILLED/);
});


test('unable-to-fulfill remains an SPMU-side cancellation with no partial release or missed pickup', () => {
    assert.match(show, /complete approved quantity cannot be provided and partial release is not allowed/i);
    assert.match(show, /No borrower missed-pickup record was created/);
    assert.match(workflow, /borrower_missed_pickup' => false/);
    assert.match(workflow, /partial_release_allowed' => false/);
    assert.match(workflow, /reserved quantity has been returned to inventory/);
});


/*
 * item-preparation-form.blade.php's own action-row/panel markup is
 * unchanged (kept intact in case it is ever re-linked), so its own contract
 * still holds. Its styling is no longer asserted here: the release page
 * that used to render it (and needed release-process-styles.blade.php to
 * back it) no longer includes this partial - see release-process.blade.php
 * and CustodyReleasePageRedesignTest.php.
 */
test('Step 2 actions keep the universal action row and chevron panel pattern', () => {
    const rowStart = preparation.indexOf('release-preparation-action-row');
    const panelStart = preparation.indexOf('<div\n                class="release-preparation-report-panel"', rowStart);
    const actionRow = preparation.slice(rowStart, panelStart);

    assert.ok(rowStart >= 0 && panelStart > rowStart);
    assert.ok(actionRow.indexOf('Report Inventory Discrepancy') < actionRow.indexOf('Confirm Items Prepared'));
    assert.match(actionRow, /data-release-panel-toggle/);
    assert.match(actionRow, /aria-controls="release-preparation-report-panel"/);
    assert.match(actionRow, /<x-icon name="chevron-down" size="16" \/>/);
});
