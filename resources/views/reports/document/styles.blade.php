<style>
/* Approved formal report layout, shared by every report and the print copy. */
.doc-sheet { box-sizing: border-box; max-width: 1344px; margin: 0 auto; padding: 30px 34px; background: #fff; color: #111; font: 12px/1.4 Arial, Helvetica, sans-serif; }
.doc-header { display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: center; gap: 12px; padding-bottom: 8px; border-bottom: 1px solid #222; }
.doc-header-seal { width: 58px; height: 58px; object-fit: contain; }
.doc-header-republic, .doc-header-address { display: block; font-size: 11px; line-height: 1.2; }
.doc-header-institution { display: block; font-size: 15px; font-weight: 700; line-height: 1.2; }
.doc-title-block { margin: 18px 0 14px; text-align: center; }
.doc-title { margin: 0; font-size: 17px; font-weight: 700; color: #111; }
.doc-period { margin: 3px 0 0; font-size: 12px; }
.doc-meta { margin: 0 0 16px; padding: 0; display: grid; gap: 2px; }
.doc-meta > div { display: grid; grid-template-columns: 130px minmax(0, 1fr); gap: 8px; }
.doc-meta dt::after { content: " :"; }
.doc-meta dd { margin: 0; }
.doc-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 11.2px; }
.doc-table th { padding: 7px 5px; text-align: center; vertical-align: middle; background: #f0f0f0; border: 1px solid #8c8c8c; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.doc-table td { padding: 8px 6px; border: 1px solid #8c8c8c; vertical-align: top; color: #111; white-space: pre-line; overflow-wrap: anywhere; }
.doc-table th { overflow-wrap: anywhere; }
.doc-table td.numeric, .doc-table th.numeric { text-align: right; font-variant-numeric: tabular-nums; }
.doc-table-empty { text-align: center; color: #555; }
.doc-table-action-column { width: 1%; white-space: nowrap; }
.doc-table-notes { margin: 10px 0; font: 10.5px/1.4 Arial, Helvetica, sans-serif; color: #333; }
.doc-table-notes p { margin: 3px 0; }
.doc-summary { margin-top: 16px; }
.doc-summary-heading { margin: 0 0 6px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
.doc-summary dl { margin: 0; display: grid; gap: 2px; }
.doc-summary dl > div { display: grid; grid-template-columns: minmax(0, 220px) auto; gap: 8px; }
.doc-summary dt::after { content: " :"; }
.doc-summary dd { margin: 0; font-weight: 700; font-variant-numeric: tabular-nums; }
.doc-footer { margin-top: 22px; padding-top: 8px; border-top: 1px solid #999; }
.doc-footer p { margin: 0; font-size: 10px; color: #333; }
.doc-table-scroll { max-width: 100%; overflow-x: auto; }
.report-cell-multiline { white-space: pre-line; }
.report-system-records-card > .doc-table-notes { padding: 0 18px; }
.report-system-table thead th { vertical-align: middle; border: 1px solid var(--border, #ddd); }
@media screen and (max-width: 900px) {
    .doc-sheet { padding: 18px 14px; }
    .doc-table { min-width: 1150px; }
}
@media print {
    .doc-sheet { max-width: none; margin: 0; padding: 0; }
    .doc-table-scroll { overflow: visible; }
    .doc-table { min-width: 0; }
    .doc-table-action-column { display: none; }
    .doc-table thead { display: table-header-group; break-after: avoid-page; }
    .doc-table tr { break-inside: avoid; page-break-inside: avoid; }
    .doc-summary, .doc-table-notes, .doc-footer { break-inside: avoid; }
    .doc-table th, .doc-header-seal { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
