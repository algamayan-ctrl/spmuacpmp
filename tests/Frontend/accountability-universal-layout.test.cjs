const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const appCss = fs.readFileSync(path.join(root, 'public/css/app.css'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'resources/views/layouts/app.blade.php'), 'utf8');
const index = fs.readFileSync(path.join(root, 'resources/views/accountability/index.blade.php'), 'utf8');
const obligations = fs.readFileSync(path.join(root, 'resources/views/accountability/partials/obligations-workspace.blade.php'), 'utf8');
const obligationStyles = fs.readFileSync(path.join(root, 'resources/views/accountability/partials/obligations-styles.blade.php'), 'utf8');
const oversightStyles = fs.readFileSync(path.join(root, 'resources/views/accountability/partials/oversight-styles.blade.php'), 'utf8');
const resolvedHistory = fs.readFileSync(path.join(root, 'resources/views/accountability/partials/resolved-history.blade.php'), 'utf8');
const notifications = fs.readFileSync(path.join(root, 'resources/views/reports/notifications.blade.php'), 'utf8');
const audit = fs.readFileSync(path.join(root, 'resources/views/reports/audit.blade.php'), 'utf8');
const reportingWorkspaceStyles = fs.readFileSync(path.join(root, 'resources/views/reports/partials/workspace-styles.blade.php'), 'utf8');

// The current compact summary has two native links; it does not restore the old four-tile row.
test('My Obligations keeps compact balance and borrowing-status metrics clickable, including zero states', () => {
  assert.match(obligations, /\['focus' => 'payment'\]/);
  // A blocking unresolved incident without a formal restriction drills into active cases.
  assert.match(obligations, /\['focus' => \$activeRestrictionCount > 0 \|\| ! \$borrowingBlocked \? 'status' : 'active'\]/);
  assert.equal((obligations.match(/<a class="ob-metric-card /g) || []).length, 2);
  assert.equal((obligations.match(/class="ob-metric-arrow"/g) || []).length, 2);
  assert.match(obligations, /borrowingStatus\(\(int\) auth\(\)->id\(\)\)/);
  assert.match(obligations, /@if\(\$needsActionCount > 0\)/);
  assert.match(obligations, /@if\(\$hasCurrentAccountability \|\| \$focus !== 'active'\)/);
  assert.doesNotMatch(obligations, /class="ob-summary-card/);
  assert.match(obligations, /\$focusMeta = match \(\$focus\)/);
  assert.match(obligations, /id="current-obligations" class="ob-focused-results"/);
  assert.match(obligationStyles, /\.ob-workspace \.ob-current-status\s*\{/);
});

// Resolved history is a dedicated staff workspace tab, not permanent default-page content.
test('staff resolved history appears only in the History workspace', () => {
  assert.match(index, /\$resolvedHistoryRequested = ! \$isBorrower && request\('view'\) === 'resolved'/);
  assert.match(index, /\$requestedStaffTab = 'history'/);
  assert.match(index, /@if\(\$activeStaffTab === 'history' \|\| \$resolvedHistoryRequested\)[\s\S]*?@include\('accountability\.partials\.resolved-history'/);
  assert.match(index, /greaterThanOrEqualTo\(\$resolvedPeriodStart\)/);
});

test('Action Officer accountability navigation uses task-oriented labels', () => {
  assert.match(layout, /'Needs My Decision' : 'Needs Your Action'/);
  assert.match(layout, /'All Active Cases' : 'Open Cases'/);
  assert.match(layout, /'History' : 'Resolved Cases'/);
  assert.match(index, /'label' => 'Needs Your Action'/);
  assert.match(index, /'label' => 'Open Cases'/);
  assert.match(index, /Use Open Cases for all ongoing borrower cases, or Resolved Cases for completed records\./);
});

test('Head accountability uses one focused case-review path without Action Officer-only return links', () => {
  const workQueue = fs.readFileSync(path.join(root, 'app/Services/AccountabilityWorkQueueService.php'), 'utf8');

  assert.doesNotMatch(index, /Review full Return Inspection and transaction history/);
  assert.doesNotMatch(index, /Confirm Late-Return Offense/);
  assert.doesNotMatch(index, /<h2>Administrative Review<\/h2>/);
  assert.doesNotMatch(index, /\$pendingViolations/);
  assert.match(workQueue, /'stage' => 'Late Return Review'/);
  assert.match(workQueue, /'action_label' => \$isHead \? 'Review Case' : 'View Case'/);
});

// A disclosure must use the same compact, pressable blue-outline contract as
// the approved table actions. A page-local visual override would bring back
// the pale border or dark text that this regression protects against.
test('every inline View Details or Case Details disclosure uses the approved blue-outline contract', () => {
  assert.match(oversightStyles, /\.accountability-cases-table table\s*\{[\s\S]*?width:\s*100%/);
  assert.match(oversightStyles, /\.accountability-cases-table td\s*\{\s*font-size:\s*12\.5px/);
  assert.match(appCss, /\.button\.secondary\s*,\s*\.table-action\s*\{[\s\S]*?color:\s*var\(--interactive\)[\s\S]*?border-color:\s*var\(--interactive\)/);
  const triggerRule = appCss.match(/\.disclosure-trigger\s*\{([^}]*)\}/)?.[1] || '';
  assert.match(triggerRule, /display:\s*inline-flex/);
  assert.match(triggerRule, /align-items:\s*center/);
  assert.match(triggerRule, /gap:\s*7px/);
  assert.match(triggerRule, /min-width:\s*118px/);
  assert.match(appCss, /\.button\.secondary\.disclosure-trigger,\s*\.table-action\.disclosure-trigger\s*\{[\s\S]*?color:\s*var\(--interactive\)[\s\S]*?background:\s*var\(--surface-elevated\)[\s\S]*?border-color:\s*var\(--interactive\)/);
  assert.match(appCss, /\.button\.secondary\.disclosure-trigger:hover,[\s\S]*?color:\s*#fff[\s\S]*?background:\s*var\(--interactive\)/);
  assert.match(appCss, /\.disclosure-trigger\[aria-expanded="true"\][\s\S]*?\.disclosure-trigger__chevron[\s\S]*?rotate\(180deg\)/);
  assert.match(appCss, /details\[open\]\s*>\s*\.disclosure-trigger[\s\S]*?\.disclosure-trigger__chevron[\s\S]*?rotate\(180deg\)/);

  assert.match(resolvedHistory, /class="resolved-history-detail-toggle button secondary small ui-pressable disclosure-trigger"/);
  assert.match(resolvedHistory, /class="button secondary small ui-pressable disclosure-trigger" data-case-toggle/);
  assert.match(obligations, /<summary class="button secondary small ui-pressable disclosure-trigger">\s*<span>View Details<\/span>/);
  assert.equal(
    (index.match(/data-case-toggle/g) || []).length,
    (index.match(/class="button secondary small ui-pressable disclosure-trigger" data-case-toggle/g) || []).length,
    'every active accountability inline expander should use the approved blue-outline stack'
  );
  assert.match(index, /<summary class="button secondary small ui-pressable disclosure-trigger"><span>Case details<\/span>/);
  assert.match(notifications, /class="notification-delivery-view-button button secondary small ui-pressable disclosure-trigger"/);
  assert.match(audit, /<button class="ictu-audit-view-button button secondary small ui-pressable disclosure-trigger"/);
  assert.match(audit, /data-audit-details-toggle[\s\S]*?<span>View Details<\/span>/);

  assert.doesNotMatch(oversightStyles, /\.accountability-cases-card \[data-case-toggle\]/);
  assert.doesNotMatch(oversightStyles, /\.accountability-view-detail\s*\{/);
  assert.doesNotMatch(obligationStyles, /\.ob-details > summary\s*\{/);
  assert.match(reportingWorkspaceStyles, /\.reporting-workspace \.button:not\(\.disclosure-trigger\)\s*\{/);
  assert.match(reportingWorkspaceStyles, /\.reporting-workspace \.button\.secondary:not\(\.disclosure-trigger\)\s*\{/);
});
