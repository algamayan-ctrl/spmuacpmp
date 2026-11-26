const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const index = fs.readFileSync(path.join(root, 'resources/views/requests/index.blade.php'), 'utf8');
const borrowerRow = fs.readFileSync(path.join(root, 'resources/views/requests/partials/my-requests-row.blade.php'), 'utf8');
const borrowerToolbar = fs.readFileSync(path.join(root, 'resources/views/requests/partials/my-requests-toolbar.blade.php'), 'utf8');
const borrowerScripts = fs.readFileSync(path.join(root, 'resources/views/requests/partials/my-requests-scripts.blade.php'), 'utf8');
const borrowerRequestController = fs.readFileSync(path.join(root, 'app/Http/Controllers/BorrowingRequestController.php'), 'utf8');
const borrowerRequestModel = fs.readFileSync(path.join(root, 'app/Models/BorrowingRequest.php'), 'utf8');
const statusBadge = fs.readFileSync(path.join(root, 'resources/views/components/status-badge.blade.php'), 'utf8');
const presentation = fs.readFileSync(path.join(root, 'app/Support/RequestRecordStatus.php'), 'utf8');
const supportedStart = presentation.indexOf('public const FILTERS = [');
const supportedEnd = presentation.indexOf('];', supportedStart);
const supportedStatuses = presentation.slice(supportedStart, supportedEnd);

test('Request Records uses the standard custody label', () => {
  assert.match(index, /RequestRecordStatus::forRequest\(\$request\)/);
  assert.match(presentation, /'key' => 'ON_CUSTODY', 'label' => 'On Custody'/);
  assert.match(presentation, /'PICKUP_EXPIRED' => \['key' => 'MISSED_PICKUP', 'label' => 'Missed Pickup'/);
  assert.match(presentation, /'PICKUP_SCHEDULING', 'ITEM_PREPARATION', 'PREPARING_RELEASE' => \[\s*'key' => 'FOR_ISSUANCE', 'label' => 'For Issuance'/);
});

test('Request Records uses the Admin status set for both staff roles', () => {
  for (const label of [
    "'FOR_APPROVAL' => 'For Approval'",
    "'FOR_ISSUANCE' => 'For Issuance'",
    "'MISSED_PICKUP' => 'Missed Pickup'",
    "'RETURNED_LATE' => 'Returned Late'",
    "'SLDDP' => 'SLDDP'",
    "'ACCOUNTABILITY_REVIEW' => 'Accountability Review'",
  ]) {
    assert.match(supportedStatuses, new RegExp(label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }

  for (const removed of ['Submitted', 'Return Processing', 'Borrower Cleared', 'Accountability Pending', 'Legacy']) {
    assert.doesNotMatch(supportedStatuses, new RegExp(removed));
  }
  assert.doesNotMatch(index, /ActionOfficerStatusPresentation|NEEDS_ATTENTION|FOR_VERIFICATION|RETURN_INSPECTION|UNDER_REVIEW/);
});

test('Borrower ongoing requests use the shared active statuses plus Draft', () => {
  assert.match(borrowerRequestController, /BORROWER_REQUEST_STATUS_FILTERS = RequestRecordStatus::ONGOING_FILTERS/);
  assert.match(index, /RequestRecordStatus::filters\(\)/);
  assert.doesNotMatch(presentation, /For Review|Preparing for Pickup/);
  assert.match(borrowerToolbar, /\$borrowerRequestStatusFilters/);
  assert.match(borrowerRequestModel, /RequestRecordStatus::forRequest\(\$this\)/);
  assert.match(borrowerRow, /borrowerRecordStatus\(\)/);
  assert.match(borrowerRow, /data-status="\{\{ \$statusKey \}\}"/);
  assert.match(borrowerScripts, /const filterForm = document\.getElementById\('request-filters'\)/);
  assert.doesNotMatch(borrowerToolbar, /Submitted|Return Processing|Borrower Cleared|Legacy/);
});

test('Shared status badges distinguish revision, custody, cancellation, and completion for every role', () => {
  assert.match(statusBadge, /'NEEDS_REVISION'[\s\S]*=> 'warning'/);
  assert.match(statusBadge, /'ON_CUSTODY'[\s\S]*=> 'info'/);
  assert.match(statusBadge, /'CANCELLED'[\s\S]*=> 'neutral'/);
  assert.match(statusBadge, /str_contains\(\$key, 'COMPLETED'\)[\s\S]*=> 'success'/);
});
