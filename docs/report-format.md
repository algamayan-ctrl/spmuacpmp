# Standard report format

All ten catalogue reports use the same formal table presentation in PDF, Word,
Excel and Print: institutional context, report title and period, provenance,
grouped grey headers, bordered records and optional summary after the records.
Each report retains its own operational columns and scope. The compact browser
preview uses the same columns and groups, with contained horizontal scrolling,
search, sorting, pagination and permission-checked drilldowns.

## Borrowing activity

The approved Excel reference defines twelve columns:

1. Control No.
2. Name
3. Office / Unit / Colleges / Division
4. Requested Equipment
5. Dates Needed — From (DD/MM/YYYY)
6. Dates Needed — To (DD/MM/YYYY)
7. Received — Date
8. Received — Time
9. Released — Date
10. Released — Time
11. Total Time Allocated
12. Remarks

Equipment uses the current request version's description/unit snapshots and
**requested** quantities. Affiliation uses the saved request snapshot. Receipt
is that version's `submitted_at`; release is the custody's actual `released_at`.
Approval, scheduled pickup, request creation and equipment return are not receipt
or release substitutes. The underlying purpose/event field remains unchanged.

Elapsed time includes nights and weekends, shows days/hours/minutes (seconds for
durations under one minute), and is never calculated up to the current time.
Missing timestamps and missing/invalid elapsed time display `-`. Release before
receipt does not become a negative or zero duration. Local times use the existing
configured application timezone, normally Asia/Manila; report notes identify it.
This change does not backfill historical timestamps or write operational records.

## Other reports and formats

Approval, release/custody, returns, accountability, billing, inventory,
utilization, laundry and gate-pass reports retain their relevant quantities,
amounts, findings and workflow states. Their column metadata declares the groups;
shared layout code supplies all HTML, PDF, Word and Excel header geometry.
Record dates use DD/MM/YYYY. Period filters and population rules are unchanged.

Legal landscape with 12 mm margins is the default to fit the wide tables. A4,
Letter, orientation, font size, margins and the optional summary remain available.
PDF and Word repeat the complete grouped header when the table spans pages.
The PDF reserves space for its numbered institutional footer. Print uses a
flowing footer to avoid covering records.

Excel includes the formal sheet and a flat **Data** sheet with unique labels,
filtering and a frozen header. Dates/times remain real Excel values; counts and
amounts remain numeric, with two decimal places for financial amounts. Multiline
equipment and affiliation cells are retained. CSV is plain tabular data with
unique flat headers, rather than merged cells or document styling. Its reader
must support quoted line breaks. Borrowing CSV columns intentionally follow the
new twelve-column schema; downstream consumers of the old schema need updating.

## Validation

- 184 report, date/filter, analytics reconciliation, export, authorization and
  shared UI tests passed (2,257 assertions) against disposable SQLite.
- 206 frontend tests passed; one optional standalone browser fixture test skipped.
- 30 live browser checks covered all ten report types at 375, 768 and 1440 px,
  with no document horizontal overflow or error page. Empty report states remain
  empty; populated schemas for every type are covered by exporter tests.
- All ten populated schemas were rendered to PDF/Word/Excel/CSV/Print; Word XML
  and Excel files were reopened, including escaped text and numeric/date values.
- A 65-row borrowing PDF retained every row across six pages, repeated both header
  rows and stayed inside the page and clear of the footer.
- The five-row approved sample PDF and the generated Excel sheet were rendered
  and visually inspected. Native Word/Excel/printer pagination can vary by client.

No production deployment, migrations, database, uploaded documents, RBAC,
notification delivery or operational configuration changes are included.
