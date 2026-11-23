# Colored summary panel navigation audit

Verified on 23 November 2026, Asia/Manila. The three dashboard link corrections were subsequently approved and implemented on the same date.

Scope: the 39 summary/navigation panels rendered for the three active portal roles: SPMU Admin/Head, SPMU Action Officer and Borrower. Includes dashboard panels, Accountability panels and the four Admin Analytics sections. Retired ICTU dashboard branches and static report/transaction information panels are not active navigation cards.

## Result

All 39 panels have a destination URL. The original audit found three dashboard links that returned HTTP 200 but opened the wrong subset of records. The approved revision corrects those three URLs in the existing dashboard view. The repeated 78-destination check returns HTTP 200 throughout, with no missing section targets; all other panel URLs are retained.

## Approved correction

Both staff dashboard case cards now open All Active Cases. The Admin Active Restrictions card opens that list with its existing restriction filter and the existing workspace anchor. The implementation changes exactly three destination lines; card labels, counts, colors, icons, layout and case-processing rules are retained.

Three regression tests follow the actual rendered links and check waiting cases, restricted versus unrestricted borrowers, and empty lists. They fail against the original URLs and pass with the correction. Post-change checks: 19 focused tests / 146 assertions, plus the 39-panel route matrix / 105 assertions, all passing. Revision evidence: `C:/Users/Admin/.codex/tmp/ictu-dashboard-link-fix-20261123/`.

The corrected dashboard view is applied to the local runtime and its hash matches source. Compiled Blade and Apache config checks passed; health and login returned HTTP 200. Post-change live clicking awaits sign-in because the Admin browser session expired. The earlier live screenshots below document the original audit, not post-change acceptance.

## Original findings

Needs My Decision works: it opens `/accountability?queue=work#accountability-workspace`, which renders the role-specific action queue below the summary cards. The live Admin page contains zero pending cases. Clicking the already-selected queue shows the same empty state; it does not open a separate document or modal. The live Open Accountability Cases and Unsettled Accountability Charges panels correctly open their respective lists.

| Defect | Actual behavior | Recommended destination |
|---|---|---|
| Admin dashboard: Open Accountability Cases | Uses `/accountability`, whose default queue is `work`. In the populated fixture, a card counting 2 open cases opens a queue containing 1 case requiring the Admin's action. | `/accountability?queue=all#accountability-workspace` |
| Action Officer dashboard: Accountability Cases | Also uses `/accountability`. In the populated fixture, a card counting 2 open cases opens the Officer action queue containing 1 case. Waiting/monitoring cases are omitted from that landing queue. | `/accountability?queue=all#accountability-workspace` |
| Admin dashboard: Active Restrictions | Uses `view=restrictions` but omits `queue=all`, so the default action queue is rendered. Its `#accountability-cases-section` fragment is absent from that page. Reproduced by clicking the live card. | `/accountability?queue=all&view=restrictions#accountability-workspace`, retaining the existing restriction filter. |

The initial audit changed no navigation or business application code. The subsequently approved correction changes only the three dashboard links. The prior approved inventory revision remains separate.

## Verification

- Runtime parity: 11 relevant controller/view files match the saved source.
- Route matrix: 39 distinct panels, each checked with empty and populated fixtures; 78 authorized destination responses were HTTP 200.
- Isolated backend gate: 80 tests, 428 assertions, no failures or errors. Covers route resolution, dashboard/accountability reconciliation and Analytics detail content.
- Live Chrome checks cover the Admin Accountability summary links and dashboard destinations, with screenshots retained in the evidence folder. Officer and Borrower routing was checked in isolated role sessions rather than by changing the user's live login.
- Existing reconciliation tests include constructed borrower-scoped restriction URLs. Those checks pass but do not exercise the exact unscoped URL currently emitted by the Admin dashboard. The route matrix follows the actual rendered URLs and exposes the missing landing queue/fragment.
- No live business records, credentials, mail configuration or migrations were changed.

Evidence: `C:/Users/Admin/.codex/tmp/ictu-colored-panel-navigation-20261123/` contains `panel-navigation-matrix.json`, `panels-junit.xml`, `panel-results.txt`, `runtime-source-parity.json` and live screenshots.

## Complete panel inventory

The following table lists the current destinations after the approved correction. FIXED identifies the three revised dashboard links.

| Role | Area | Panel | Rendered destination | Result |
|---|---|---|---|---|
| SPMU_HEAD | dashboard | For Approval | /approvals | OK |
| SPMU_HEAD | dashboard | Active Custodies | /requests?kpi=active_custodies | OK |
| SPMU_HEAD | dashboard | Open Accountability Cases | /accountability?queue=all#accountability-workspace | FIXED |
| SPMU_HEAD | dashboard | Active Restrictions | /accountability?queue=all&view=restrictions#accountability-workspace | FIXED |
| SPMU_HEAD | accountability | Needs My Decision | /accountability?queue=work#accountability-workspace | OK |
| SPMU_HEAD | accountability | Open Accountability Cases | /accountability?queue=all#accountability-workspace | OK |
| SPMU_HEAD | accountability | Unsettled Accountability Charges | /accountability?queue=all&metric=balance#accountability-workspace | OK |
| SPMU_HEAD | analytics-overview | Total Requests | /reports/analytics-records?section=overview&academic_period=month&detail=requests | OK |
| SPMU_HEAD | analytics-overview | On Custody | /reports/analytics-records?section=overview&academic_period=month&detail=currently-out | OK |
| SPMU_HEAD | analytics-overview | Overdue | /reports/analytics-records?section=overview&academic_period=month&detail=follow-up | OK |
| SPMU_HEAD | analytics-overview | Available Stock | /reports/analytics-records?section=overview&academic_period=month&detail=card&for=inventory.available | OK |
| SPMU_HEAD | analytics-overview | Demand & Utilization | /analytics?academic_period=month&section=demand | OK |
| SPMU_HEAD | analytics-overview | Inventory Health | /analytics?academic_period=month&section=inventory | OK |
| SPMU_HEAD | analytics-overview | Borrowing & Return Performance | /analytics?academic_period=month&section=returns | OK |
| SPMU_HEAD | analytics-demand | Requests Filed | /reports/analytics-records?section=demand&academic_period=month&detail=requests | OK |
| SPMU_HEAD | analytics-demand | Requested Quantity | /reports/analytics-records?section=demand&academic_period=month&detail=card&for=demand.requested-quantity | OK |
| SPMU_HEAD | analytics-demand | Released Quantity | /reports/analytics-records?section=demand&academic_period=month&detail=card&for=demand.released-quantity | OK |
| SPMU_HEAD | analytics-demand | Active Borrowing Units | /reports/analytics-records?section=demand&academic_period=month&detail=card&for=demand.active-units | OK |
| SPMU_HEAD | analytics-inventory | Available Units | /reports/analytics-records?section=inventory&academic_period=month&detail=card&for=inventory.available | OK |
| SPMU_HEAD | analytics-inventory | Reserved / Allocated | /reports/analytics-records?section=inventory&academic_period=month&detail=card&for=inventory.reserved | OK |
| SPMU_HEAD | analytics-inventory | On Custody | /reports/analytics-records?section=inventory&academic_period=month&detail=card&for=inventory.custody | OK |
| SPMU_HEAD | analytics-inventory | Attention Needed | /reports/analytics-records?section=inventory&academic_period=month&detail=card&for=inventory.attention | OK |
| SPMU_HEAD | analytics-returns | Returned On Time | /reports/analytics-records?section=returns&academic_period=month&detail=returns&state=on-time | OK |
| SPMU_HEAD | analytics-returns | Returned Late | /reports/analytics-records?section=returns&academic_period=month&detail=returns&state=late | OK |
| SPMU_HEAD | analytics-returns | Currently Overdue | /reports/analytics-records?section=returns&academic_period=month&detail=follow-up | OK |
| SPMU_HEAD | analytics-returns | Open Accountability | /reports/analytics-records?section=returns&academic_period=month&detail=card&for=returns.issues | OK |
| SPMU_OFFICER | dashboard | For Verification | /verifications | OK |
| SPMU_OFFICER | dashboard | For Release | /release | OK |
| SPMU_OFFICER | dashboard | For Return | /return | OK |
| SPMU_OFFICER | dashboard | Accountability Cases | /accountability?queue=all#accountability-workspace | FIXED |
| SPMU_OFFICER | accountability | Needs Your Action | /accountability?queue=work#accountability-workspace | OK |
| SPMU_OFFICER | accountability | Open Cases | /accountability?queue=all#accountability-workspace | OK |
| SPMU_OFFICER | accountability | Resolved Cases | /accountability?queue=history#accountability-workspace | OK |
| BORROWER_ONLY | dashboard | Active Borrowings | /custody?kpi=active_borrowings | OK |
| BORROWER_ONLY | dashboard | Upcoming Pickup | /custody?kpi=upcoming_pickup | OK |
| BORROWER_ONLY | dashboard | Returns Due | /custody?kpi=returns_due | OK |
| BORROWER_ONLY | dashboard | Active Obligations | /accountability | OK |
| BORROWER_ONLY | accountability | My Unsettled Charges | /accountability?focus=payment#current-obligations | OK |
| BORROWER_ONLY | accountability | Borrowing Status | /accountability?focus=status#current-obligations | OK |
