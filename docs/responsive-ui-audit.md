# Responsive UI audit — 26 October 2026

Base: `main` at `e02f1ffcb689c73675c2c7687eb7537fcdaf4970`.
Branch: `fix/responsive-ui-all-roles-20261026`.

## Changes

- Shared request tracker: reset column auto-placement to row order. Use the card's available width, with two columns below 860px and a single sequential list below 520px. Remove connectors when wrapped; preserve order, status colors, current-step semantics, and focus/touch tooltips.
- Release summary: reset the shared heading's explicit second-column placement when stacking. This prevents a zero-width title column on phones.
- Shared shell: allow cards, content regions, fieldsets, labels, and controls to shrink; contain wide tables; wrap long detail values. Remove the document's 320px minimum, which also overflowed a 320px window with a scrollbar. Let the topbar title use available space. Use 16px mobile form controls to avoid focus zoom.
- Mobile navigation: make the closed off-canvas sidebar inert and hidden to assistive technology. While open, make the background app stage inert; closing with Escape restores the menu toggle's focus. Desktop sidebar preferences remain intact.
- Borrower inventory: fix the conflict between the desktop 940px table minimum and the shared mobile row layout. Add field labels to every mobile cell and keep multi-line descriptions together.
- Borrower detail cards: clamp the 330px grid minimum to the available width.
- Request wizard: constrain its grid and children so returning from Review to a populated Select Items stage keeps the 720px table inside its own scroll area.
- Verification queue: remove a dangling selector that invalidated its responsive block, and include the more specific verification filter variant in the single-column breakpoint.
- Gate Pass progress: stack milestones inside narrow cards, including reduced desktop content areas.
- Create/Edit Request form and its Review Summary: visible label `Purpose of Borrowing` becomes `Event`. The `purpose_event` name, value, validation, placeholder, database schema, and request detail/document wording remain intact.

No controllers, policies, workflow services, migrations, production configuration, or document templates are changed.

## Coverage

`ResponsiveUiTest` renders 64 real controller/view variants using disposable in-memory SQLite data. These are exported only when `SPMU_UI_SNAPSHOTS` is explicitly set.

| Audience | Screens / states |
| --- | --- |
| Borrower | Dashboard, inventory and item details, calendar, request list/detail, all three Create Request stages, borrowings and custody details, populated obligations, notifications, account settings |
| SPMU Action Officer | Dashboard, inventory/detail, calendar, request records/detail, verification, release queue/detail, return queue/inspection, Gate Pass list/detail, Laundry list/detail/completed, populated accountability and borrower workspace, registration queue, notifications, account settings |
| SPMU Head/Admin | Dashboard, inventory/create/edit, calendar, request records/detail, approvals, custody oversight, populated accountability, users/create/edit, policies, fee configuration, retained technical configuration and delivery records, analytics, reports, audit history, notifications, account settings |
| Public | Home, login, registration, password recovery, privacy, terms |

ICTU is a retired application-login classification in this base revision. Its technical custodian role is preserved. Retained technical screens are exercised through the existing authorized Head/Admin routes; no ICTU portal or additional permissions are introduced.

## Validation

- Full PHPUnit suite: **1,725 passed, 15,198 assertions**, no failures or skips (PHP 8.4.24, SQLite in memory).
- Final focused UI and role checks: **29 passed, 367 assertions** across `ResponsiveUiTest`, `SpmuRoleSeparationTest`, `SinglePortalRoleIsolationTest`, `IctuTechnicalTurnoverTest`, and `CreateRequestOrganizationalUnitTest`.
- Node frontend suite: **206 passed, 1 skipped, 0 failed**. The optional standalone Playwright Report Builder date-range test has no `REPORT_BUILDER_HTML` fixture configured.
- Browser responsive matrix: 64 rendered variants at **320, 375, 768, 1024, 1280, and 1440px**, plus Review → Select Items → Request Details transitions at each width. The checked-in browser helper measures page overflow, uncontained content, narrow milestone labels, and cramped headings.
- Visual inspection: mobile release progress/summary, borrower inventory rows, request selection and review; expanded desktop sidebar near the tablet breakpoint; mobile menu open/Escape/focus behavior.
- Blade compilation, Pint for the new PHP test, JavaScript syntax, and whitespace checks.

The changed runtime assets are loaded directly from `public/css/app.css` and `public/js/app.js`, with existing cache-busting. No Vite source or dependency changes are needed.

## Reproducing the browser checks

1. Install locked Composer dependencies in a local checkout with a local test-only app key. Use `phpunit.xml`'s disposable in-memory database; never point the test suite at an operational database.
2. Set `SPMU_UI_SNAPSHOTS` to a temporary directory and run `php vendor/bin/phpunit tests/Feature/ResponsiveUiTest.php`.
3. Serve the snapshot directory at `/snapshots/` and the checkout's `public/` directory at `/` on a loopback HTTP server. Read `manifest.json` from the snapshot directory.
4. With the Browser skill's connected tab and viewport handles, run `auditResponsivePages` from `tests/Browser/responsive-audit.mjs`. Fail the run if any result has a nonempty `failures` array. Reset the temporary viewport afterward.

## Limits and release status

This is local Chromium verification with synthetic records, including the shared view behind `/release/32`; the production record itself was not accessed. Actual iOS/Safari, Android devices, Firefox, unusual historical data, live AJAX availability, external identity/email providers, and upload/download flows need a staging acceptance pass. Browser fixtures intentionally have no live application backend, so AJAX-dependent availability can show its normal load-error message; this error state and the resulting layout were also exercised. Wide data tables and the tabular month calendar retain contained scrolling.

The full PHP suite includes workflow/authorization coverage, but this UI change does not claim physical-device or production-data certification. No production deployment, merge, database operation, uploaded-document mutation, or operational configuration change was performed. Keep the PR as a draft while additional requested edits are being collected.
