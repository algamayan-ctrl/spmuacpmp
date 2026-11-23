# UI Admin revision tracker

## Current progress

Updated: **23 November 2026, Asia/Manila**.

**Current step:** the Action Officer Inventory UI now shares the Admin's read-only layout and is applied locally. Cards/icons, latest movement, tabs, Overview, Stock Card, history and description are aligned. Desktop/narrow test-data previews and 55 backend tests pass; refresh the live Inventory page for acceptance. Existing role permissions remain intact.

**Next priorities:** close live authenticated acceptance where sessions expired, then run the broader regression and classify the 32 skipped frontend checks. The former Action Officer overview failure is resolved by the approved shared layout and updated role/parity checks. Gmail OTP remains paused by the user.

| Status | Work item | Result or next action |
|---|---|---|
| Done | Shared staff Inventory layout | Both staff roles share cards/icons, latest movement, tabs, Overview breakdown/source records, Stock Card styling, movement details, Borrowing History and Item Description. Admin-only edit/stock adjustment/report actions and Officer-only stock-card print access remain guarded. |
| Passed | Staff inventory parity and access checks | 55 backend tests / 467 assertions passed, including the former Officer overview failure, equality of eight shared read-only regions and rejected Officer edit/update/delete/adjust requests. Full frontend: 283 passed, 0 failures, 32 skipped. The shared history dropdown now offers and selects the backend-supported 7/15/30/50 entries; obsolete 10-entry fixtures were updated. |
| Done | Staff Inventory runtime and visual verification | Three runtime views match source and compile/lint successfully. Health and login returned HTTP 200. Chrome test-data previews confirm desktop cards/icons, latest movement, all four tabs, read-only Item Description, Stock Card and narrow history controls. The isolated test container was removed after evidence export. No controller, stock calculation, live inventory or migration changed. |
| Done | Approved dashboard link correction | Three destination lines changed in dashboard.blade.php. Both case cards open queue=all; Active Restrictions adds queue=all while preserving view=restrictions and targeting the existing workspace anchor. Layout/styles are unchanged. |
| Passed | Dashboard link regression | Three new regression tests fail against the original dashboard URLs and pass with the correction. Focused suite: 19 tests / 146 assertions passed. Route matrix: 1 test / 105 assertions, 39 panels checked in empty and populated scenarios, no missing URL/fragment and all 78 targets returned HTTP 200. Only the approved three targets changed. |
| Done | Dashboard link runtime verification | Running dashboard view hash matches source. Compiled Blade syntax and Apache config checks pass; health and login returned HTTP 200. Only the dashboard view was copied. Existing controllers and Accountability view remain unchanged; no migration or live data edits. Isolated test container removed after evidence export. |
| Pending | Post-change live dashboard clicks | Chrome displays Session ended. User was asked to sign in again; automated role/queue/filter verification is complete. |
| Done | Colored panel route inventory | 39 summary/navigation panels across Admin, Action Officer and Borrower, checked with empty and populated fixtures (78 destination requests). All have URLs and return HTTP 200 for their authorized role. |
| Done | Panel audit evidence and runtime check | Full 39-panel table, 3 defect descriptions, destination matrix, 80-test results and live Admin screenshots are saved. Eleven relevant runtime files match source. The isolated audit container was removed; production containers remain healthy. |
| Done | Needs My Decision live check | Its link targets queue=work and the existing queue below the cards. The live queue contains zero cases, so clicking the same selected queue produces the empty state rather than a new page/modal. Open Cases and Unsettled Charges also change to their correct lists. |
| Corrected after approval | Dashboard panel landing targets | The original audit found two open-case cards defaulting to the action queue, plus Active Restrictions using that queue and an absent fragment. The approved correction now opens queue=all and the existing workspace anchor, retaining the restriction filter where needed. |
| Passed | Panel audit backend checks | 80 isolated tests / 428 assertions passed, covering link resolution, dashboard/accountability reconciliation and analytics drilldown content. The audit matrix separately identifies semantic destination defects; HTTP success alone is not treated as correct navigation. |
| Done | Review existing inventory edit and audit behavior | Item details already generate before/after audit snapshots. Quantity is read-only in the edit form; stock changes use Inventory Overview. Edit and adjustment endpoints already require SPMU Head/Admin. |
| Done | Inventory quantity and audit previews | Current Quantity 6, proposed New Total Quantity 8, required reason and a sample audit comparison are visualized. The audit sample also demonstrates a specification edit. |
| Done | Inventory quantity editor | Admin-only editor, required reason, committed-stock minimum, stale-quantity protection, Stock Card movement and atomic audit write are implemented. Existing audit records layout is unchanged. |
| Passed | Inventory checks | 12 new backend tests / 116 assertions passed. Includes stock corrections, future reservations/custody/laundry/incidents/maintenance, stale forms, authorization, rollback and metadata-only recovery. 15 focused frontend checks passed; full frontend run: 283 passed, 0 failed, 32 skipped. |
| Historical; Officer failure resolved | Inventory backend regression | Earlier run: 75 tests / 848 assertions, 74 passed and one pre-existing Officer overview expectation failed. The approved shared staff layout and current parity tests resolve that failure; latest Inventory gate is 55 tests / 467 assertions, all passed. |
| Done | Apply inventory changes to local runtime | Six runtime files match source. PHP syntax, three compiled Blade views and Apache config checks passed. Local health and login endpoints returned HTTP 200. No migration or live inventory edits were performed. |
| Done | Inventory visual inspection with test records | Chrome screenshots confirm balanced quantity columns on desktop, stacked controls within the panel at narrow width, and the existing audit record plus expanded Field / Previous Value / New Value table showing Total Quantity 6 → 8. |
| Pending | Live authenticated inventory browser check | The existing Admin page has an expired-session dialog. No live inventory was saved or edited for testing. Runtime hashes, compiled views and HTTP health were verified separately. |
| Done | Admin Request Records pagination implementation | Shares the existing staff pager, with 7, 15, 30 and 50 entries. Search, status, sorting and record visibility are retained. |
| Done | Request Records, Release and Return pagination recheck | 33 focused frontend checks passed; all pages and final pages covered without lost or duplicated records. |
| Done | Resolve the eight remaining frontend failures | Updated obsolete expectations for the retained cards, tracker, remarks, release guard and list styles. Latest run: 281 passed, 0 failed, 32 skipped. |
| Done | Focused backend verification | 39 tests passed with 690 assertions, using isolated SQLite and no external mail delivery. Includes Admin pagination, borrower grouping, revision remarks and release safeguards. |
| Pending | Earlier Admin Request Records images | Original list images captured. The saved after preview remains available; pager, final page and entry-option screenshots are still outstanding. |
| Done | Verify the running local application | Running Request Records, shared pager, sidebar, Gate Pass styles and mail service match saved source. The Request Records view was compiled and passed PHP lint; health and login both return HTTP 200. |
| Done and maintained | Persistent progress tracker | Update this section at task start, after material results, and before each handoff or final response. |
| Paused by user | Gmail OTP delivery | Sender authentication still needs a valid Google App Password. Resume only when the user returns to this task. |
| Pending | Broader acceptance | The 32 skipped frontend checks and a complete current backend regression are outside the completed focused gate. |

### Resume instructions

1. Read **Current progress** before continuing this revision.
2. Keep one current step, mark completed work immediately, and record a concrete next action for unfinished work.
3. Retain the approved appearance and workflows. Show before-and-after images for visible revisions.
4. Distinguish source implementation, automated checks, browser verification and running-application verification.
5. Update this tracker after each work session. Keep historical results below; they do not replace the current status.

### Current evidence

- Shared staff Inventory UI: C:/Users/Admin/.codex/tmp/ictu-officer-inventory-parity-20261123/verification.json, backend-junit.xml, frontend-all.txt, protected-file hashes, runtime hashes and desktop/narrow screenshots. Preview images use isolated test data; the provided Officer screenshot is retained as officer-before.png.
- Approved three-link revision: C:/Users/Admin/.codex/tmp/ictu-dashboard-link-fix-20261123/verification.json, before/after PHPUnit results, repeated route matrix and runtime hashes. Application diff: exactly three destination lines in the dashboard view.
- Colored panels audit: docs/COLORED-PANEL-NAVIGATION-AUDIT.md. Machine-readable route matrix, verification.json, PHPUnit results, runtime parity and screenshots: C:/Users/Admin/.codex/tmp/ictu-colored-panel-navigation-20261123/. No application files changed for this audit.
- Inventory implementation, baseline reproduction, backend/frontend results, runtime hashes and real rendered screenshots: C:/Users/Admin/.codex/tmp/ictu-inventory-quantity-implementation-20261123/verification.json. The disposable SQLite test container was removed after evidence export; production containers remain healthy.
- Inventory proposal: C:/Users/Admin/.codex/tmp/ictu-inventory-quantity-preview-20261123/proposal.json. Illustrative images are linked there; they are not deployed UI screenshots.
- Pagination checks: C:/Users/Admin/.codex/tmp/ictu-record-pagination-20261122/verification.json.
- Current frontend results: C:/Users/Admin/.codex/tmp/ictu-admin-pagination-regression-20261122/after-frontend.tap.
- Current backend results: C:/Users/Admin/.codex/tmp/ictu-admin-pagination-regression-20261122/backend-results.txt and backend-junit.xml.
- Test-data HTML previews and original images: C:/Users/Admin/.codex/tmp/ictu-admin-pagination-regression-20261122/.
- Inventory automated checks and UI images were completed on 23 November 2026; earlier pagination checks are dated 22 November. New preview images use synthetic test records rather than live inventory or borrower records.

## Original November 13 revision

Approved scope: the ICTU UI Revision Plan confirmed on 13 November 2026. Existing application structure, restored corner styles, authorization and operational rules are retained.

Panel follow-up: [Pagination, Search and Filters tracker](TABLE-CONTROLS-REVISION-TRACKER.md). Its functional implementation is complete; actual browser visual sign-off remains pending.

| Status | Milestone |
|---|---|
| Complete | Review markdown, source and approved visual concepts |
| Complete | Confirm report semantics, sidebar groups, closures and resubmissions |
| Complete | Preserve current working copy (833 files) outside the repository |
| Complete | Record isolated regression baseline |
| Complete | Shared dark styling, compact layouts, sidebar and document viewing |
| Complete | Borrower settings, Premises, inventory drilldowns and Stock Card |
| Complete | Fixed-highlight time picker and closure-aware date picker |
| Complete | Summary Report, report filters and monthly future request numbers |
| Complete | Analytics drilldown reconciliation |
| Complete | Isolated regression, export, numbering and permission checks |
| Pending | Actual desktop/mobile screenshots and visual verification |

## Protection and validation

- Working-copy backup: `C:/Users/Admin/.codex/tmp/ictu-ui-admin-20261113/baseline` with SHA-256 manifest and original Git status.
- No live deployment or live migration is part of this revision.
- Existing database records, official signatures, numbering and source changes are preserved.
- Frontend baseline: 246 tests, 243 passed, 2 existing failures, 1 skipped. Failures: preparation discrepancy AO recheck assertion; Borrower Request Records static Draft option assertion.
- Original source and original tests: 144/144 targeted PHP cases passed. Revised suite: 201/201 cases, 1507 assertions. An additional ledger grouping check passed in its 4-case suite.
- Final frontend suite: 249 cases, 246 passed, the same 2 baseline failures, 1 skipped. New picker unit checks all pass.
- Four concurrent workers created 40 unique request numbers in the isolated fixture database.
- All 24 selected role pages rendered; 235 rendered JavaScript blocks, 329 rendered CSS blocks and 173 source style blocks parsed without errors.
- DOM interaction checks passed for closed dates, reason controls, lookup failure, Escape, time typing/Apply/Cancel and inventory dialogs. These are not browser visual checks.
- Summary Report Print, CSV, PDF, Word and Excel output checks passed; its one-page PDF was rendered and visually inspected.
- Final 25-case export/sorting suite passed (110 assertions), including multiple equipment lines in Word, chronological date sorting, Excel date types and preserved name text.
- All 210 Blade templates compile to valid PHP.
- Changes are documented in `docs/UI-ADMIN-REVISION-RESULTS.md`; the final file inventory is listed there.
- Calendar retains its original grid, toolbar, filters and Month/List views. Request form retains its steps and field order.
- Summary Report uses the latest submitted version; historical request IDs remain unchanged.

## Remaining visual acceptance

- Actual desktop/mobile browser screenshots and light/dark visual sign-off remain pending. The in-app browser reports a missing network policy; the supported Chrome connection is unavailable. Standalone browser sessions could not create a page. Automatic approval review rejected the separate Chrome screenshot helper with “blocked by policy” and no further reason.
- Preserve the original calendar, request-form layout and corner styles during visual sign-off.
- Implementation is in source only. Rebuild the deployed Docker image and apply the additive migration during a separate deployment; neither was performed on the live system.
