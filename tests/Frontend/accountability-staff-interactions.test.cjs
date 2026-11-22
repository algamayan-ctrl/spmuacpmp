const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const index = fs.readFileSync(path.join(root, 'resources/views/accountability/index.blade.php'), 'utf8');
const resolvedHistory = fs.readFileSync(path.join(root, 'resources/views/accountability/partials/resolved-history.blade.php'), 'utf8');

const interactionsInclude = "@include('accountability.partials.cases-interactions')";
const resolvedHistoryNeedle = "@include('accountability.partials.resolved-history'";

test('staff accountability interactions load once after the shared resolved-history surface', () => {
  const interactionMatches = index.match(/@include\('accountability\.partials\.cases-interactions'\)/g) || [];
  assert.equal(interactionMatches.length, 1);
  assert.ok(index.indexOf(resolvedHistoryNeedle) >= 0, 'resolved history include should exist');
  assert.ok(index.indexOf(interactionsInclude) > index.indexOf(resolvedHistoryNeedle), 'shared interactions should load after resolved history for every staff view');
});

test('resolved history keeps one universal inline disclosure action per transaction', () => {
  assert.match(resolvedHistory, /<span>View Details<\/span>/);
  assert.match(resolvedHistory, /data-case-toggle/);
  assert.match(resolvedHistory, /accountability-case-detail-row/);
  assert.match(resolvedHistory, /chevron-down/);
});


test('staff accountability KPI cards are whole-card links with universal navigation affordance', () => {
  assert.match(index, /class="accountability-overview-card tone-\{\{ \$card\['tone'\] \}\} has-emphasis"/);
  assert.match(index, /href="\{\{ \$card\['href'\] \}\}"/);
  assert.match(index, /accountability-overview-arrow/);
  assert.match(index, /\['queue' => 'work'\]/);
  // Verification is a supported work-queue filter; it is no longer a separate summary tile.
  assert.match(index, /'verification' => \$staffWorkQueue->where\('stage', 'Physical Compliance Verification'\)/);
  assert.match(index, /'label' => 'Needs Your Action'/);
  assert.match(index, /'label' => 'Open Cases'/);
  // Main consolidates receipt follow-up under Needs action; the receipt filter remains available.
  assert.match(index, /'receipts' => \$staffWorkQueue->filter/);
  assert.match(index, /\['queue' => 'all'\]/);
  assert.match(index, /\$activeStaffTab === 'history'/);
  assert.doesNotMatch(index, /accountability-overview-card[^\n]*is-static/);
});

test('physical verification and open accountability cards use distinct urgency colors', () => {
  const pendingRule = index.match(/\[data-accountability-card-label="Pending Physical Verification"\]\s*\{([^}]*)\}/)?.[1] || '';
  const openRule = index.match(/\[data-accountability-card-label="Open Accountability Cases"\](?:\s*,\s*\[[^\]]+\])*\s*\{([^}]*)\}/)?.[1] || '';

  assert.match(pendingRule, /#d97706/);
  assert.match(pendingRule, /#f59e0b/);
  assert.match(openRule, /#b91c1c/);
  assert.match(openRule, /#ef4444/);
  assert.notEqual(pendingRule, openRule);
});

test('accountability balance focus filters to rows that actually carry an open balance', () => {
  const interactions = fs.readFileSync(path.join(root, 'resources/views/accountability/partials/cases-interactions.blade.php'), 'utf8');
  assert.match(index, /data-has-balance=/);
  assert.match(interactions, /focus !== 'balance' \|\| row\.dataset\.hasBalance === '1'/);
});
