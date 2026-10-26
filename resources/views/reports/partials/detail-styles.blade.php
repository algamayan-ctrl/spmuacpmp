<style>
.reporting-detail .page-heading { margin-bottom: 24px; }
.reporting-detail .reports-navigation-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px 16px; min-width: 0; }
.reporting-detail .reports-navigation-row .reporting-heading-actions { flex-shrink: 0; }
.reporting-detail .reporting-heading-actions .button { min-height: 43px; padding: 10px 18px; }
.reports-detail-page { display: grid; gap: 18px; min-width: 0; }
.reporting-workspace .report-generator-card { padding: 22px 24px; }
.report-generator-grid { display: grid; grid-template-columns: minmax(200px, 1.05fr) minmax(190px, 1.08fr) minmax(185px, 1.1fr) auto; align-items: end; gap: 24px; }
.reporting-workspace .report-generator-grid > label { display: grid; gap: 9px; min-width: 0; margin: 0; color: var(--heading); font-size: 12px; font-weight: 700; }
.reporting-workspace .report-generator-grid select { height: 45px; font-size: 13px; }
.reporting-detail .report-period-control > .ui-icon { left: auto; right: 13px; }
.reporting-detail .report-period-control select { appearance: none; background-image: none; padding-left: 13px; padding-right: 38px; }
.reporting-detail .report-period-context { min-height: 45px; font-size: 12px; }
.reporting-detail .report-period-context > .ui-icon { display: none; }
.reporting-workspace .report-generate-button { min-height: 45px; padding: 11px 20px; font-size: 13px; }
.report-period-helper, .report-description { margin: 16px 0 0; font-size: 11px; color: var(--report-muted); line-height: 1.5; }
.report-description { margin-top: 6px; }
.report-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; }
.report-summary-grid.report-primary-summaries { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.reporting-workspace .report-summary-item { display: flex; flex-direction: row; align-items: flex-start; gap: 22px; padding: 24px 22px; min-height: 126px; }
.report-summary-item .reporting-icon { width: 48px; height: 48px; border-radius: 10px; }
.report-summary-item strong { display: block; color: var(--heading); font-size: 30px; font-weight: 750; line-height: 1.15; }
.reporting-workspace .report-summary-item h2 { margin: 8px 0 6px; color: var(--heading); font-size: 13px; font-weight: 650; }
.report-summary-item p { margin: 0; font-size: 11px; color: var(--report-muted); line-height: 1.5; }
.report-additional-summary { display: flex; flex-wrap: wrap; gap: 8px 18px; font-size: 11px; color: var(--text-secondary); }
.report-additional-summary > span { padding: 6px 10px; border: 1px solid var(--report-line); border-radius: 5px; background: var(--surface-elevated); }
.report-additional-summary strong { margin-left: 6px; }
.reporting-workspace .report-output-card { padding: 24px; }
.report-output-header { display: flex; justify-content: space-between; align-items: center; gap: 18px; flex-wrap: wrap; margin-bottom: 24px; border: 0; background: transparent; padding: 0; }
.reporting-workspace .report-output-header .eyebrow { margin: 0 0 6px; color: var(--text-secondary); font-size: 10px; }
.report-output-header h2 { margin: 0 0 5px; font-size: 17px; font-weight: 650; }
.report-output-header p:not(.eyebrow) { margin: 0; color: var(--report-muted); font-size: 13px; }
.report-output-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.reporting-workspace .report-output-actions .button { min-height: 40px; padding: 9px 14px; }
.report-download-icon { transform: rotate(180deg); }
.report-output-body { padding: 0; min-width: 0; }
.report-table-scroll { overflow-x: auto; border: 1px solid var(--report-line); border-radius: 8px; }
.reporting-workspace .report-table { width: 100%; min-width: 960px; margin: 0; border-collapse: collapse; }
.reporting-workspace .report-table th { padding: 15px 10px; color: var(--heading); background: var(--surface-subtle); font-size: 10px; font-weight: 750; letter-spacing: .05em; text-transform: uppercase; white-space: nowrap; border-bottom: 1px solid var(--report-line); }
.reporting-workspace .report-table td { padding: 13px 10px; color: var(--heading); font-size: 12px; line-height: 1.65; border-bottom: 1px solid var(--report-line); vertical-align: middle; }
.reporting-workspace .report-table tr:last-child td { border-bottom: 0; }
.reporting-workspace .report-table .numeric { text-align: right; }
.report-table .status-badge { font-size: 10px; font-weight: 650; padding: 4px 8px; white-space: nowrap; }
.reporting-workspace .report-request-link, .reporting-workspace .report-table .table-action { color: var(--report-blue); text-decoration: none; font-weight: 700; }
.report-request-link { white-space: nowrap; }
.reporting-workspace .report-table .table-action { font-size: 11px; padding: 5px; }
.reporting-workspace .report-table .empty-state { padding: 30px 16px; text-align: center; color: var(--report-muted); }
.report-note { margin: 22px 0 0; color: var(--report-muted); font-size: 11px; line-height: 1.7; }
.report-section-subheading { margin: 20px 0 10px; font-size: 14px; }
@media (max-width: 1300px) {
 .report-generator-grid { grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) minmax(170px, auto); gap: 16px; }
 .report-generator-grid .report-period-context { grid-column: 1 / 3; grid-row: 2; }
 .report-generator-grid .report-generate-button { grid-column: 3; grid-row: 1; }
 .reporting-workspace .report-summary-item { padding: 20px 16px; gap: 14px; }
}
@media (max-width: 900px) {
 .reporting-detail .page-heading { align-items: flex-start; flex-direction: column; gap: 16px; }
 .reporting-workspace .report-summary-item { gap: 12px; }
 .report-summary-item .reporting-icon { width: 36px; height: 36px; }
 .report-summary-item .reporting-icon svg { width: 21px; }
}
@media (max-width: 700px) {
 .report-generator-grid, .report-summary-grid.report-primary-summaries { grid-template-columns: 1fr; }
 .report-generator-grid .report-period-context, .report-generator-grid .report-generate-button { grid-column: auto; grid-row: auto; }
 .reporting-workspace .report-generator-card, .reporting-workspace .report-output-card { padding: 18px 15px; }
 .report-output-header { margin-bottom: 18px; }
 .reporting-workspace .report-summary-item { min-height: 100px; }
}
@media print {
 .reporting-detail .reports-navigation-row { display: none !important; }
 .reporting-workspace .report-table-scroll { overflow: visible; border-radius: 0; }
 .reporting-workspace .report-table { min-width: 0; width: 100%; table-layout: auto; }
 .reporting-workspace .report-table th, .reporting-workspace .report-table td { font-size: 9px; padding: 7px 5px; overflow-wrap: anywhere; white-space: normal; }
 .reporting-workspace .report-request-link, .reporting-workspace .status-badge { white-space: normal; }
 .reporting-workspace .report-output-card { padding: 14px; }
 .reporting-workspace .report-table thead { display: table-header-group; }
 .reporting-workspace .report-table tr { break-inside: auto; page-break-inside: auto; }
}
</style>

<style>
/*
| Reports chrome.
|
| The builder is interface; everything below it is a document. The two are
| deliberately styled apart so the preview reads as a sheet of paper sitting
| on the application, not as another panel of it.
*/

/* Report builder */
.reporting-workspace .report-builder-card { display: grid; gap: 0; padding: 26px; border-radius: 12px; }
.report-builder-primary { display: grid; grid-template-columns: minmax(0, 30fr) minmax(0, 25fr) minmax(0, 27fr) auto; gap: 18px; align-items: start; margin-top: 14px; }
.report-builder-field { display: grid; gap: 7px; align-content: start; min-width: 0; margin: 0; font-size: 11.5px; font-weight: 750; letter-spacing: .02em; color: var(--text-secondary); }
.report-builder-field > span { min-height: 16px; line-height: 16px; }
.report-builder-field select { min-height: 42px; width: 100%; font-size: 13px; }
.report-resolved-period > p { display: flex; align-items: center; gap: 7px; min-height: 42px; margin: 0; padding: 0 12px; background: var(--surface-subtle); border: 1px solid var(--border); border-radius: var(--radius-sm); }
.report-resolved-period strong { color: var(--heading); font-size: 13px; font-weight: 750; white-space: nowrap; }
.report-resolved-period .ui-icon { color: var(--text-soft); flex: 0 0 auto; }
.report-resolved-period small { color: var(--text-muted); font-size: 11px; font-weight: 500; }

/* The primary action shares the top row, so opening the filters never moves it. */
.reporting-workspace .report-generate-button { min-height: 42px; padding: 10px 18px; font-size: 12.5px; }
.report-no-filters { display: block; margin: 18px 0 0; color: var(--text-muted); font-size: 12px; }

.report-filters-panel { display: grid; gap: 14px; margin-top: 18px; padding: 15px 16px 16px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-subtle); }
.report-filters-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.report-filters-title { display: flex; align-items: center; gap: 7px; margin: 0; color: var(--heading); font-size: 12.5px; font-weight: 750; }
.report-filters-title .ui-icon { color: var(--text-soft); }
.report-filters-help { margin: 4px 0 0; color: var(--text-muted); font-size: 11.5px; line-height: 1.4; }
.report-filter-count { display: inline-flex; align-items: center; min-height: 24px; padding: 2px 8px; border-radius: 999px; background: var(--info-bg); color: var(--info); font-size: 11px; font-weight: 700; white-space: nowrap; }
.report-filter-grid { display: grid; grid-template-columns: repeat(var(--report-filter-columns, 3), minmax(0, 1fr)); gap: 16px; }
.report-builder-card .report-description { margin: 22px 0 0; }

/* Preview */
.report-preview-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin: 0 0 10px; }
.report-preview-label { margin: 0; color: var(--text-muted); font-size: 10.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.report-preview-title { margin: 3px 0 0; color: var(--heading); font-size: 16px; font-weight: 750; }
.report-preview-context { margin: 3px 0 0; color: var(--text-muted); font-size: 11px; line-height: 1.35; }
.report-filter-warning { display: flex; align-items: flex-start; gap: 8px; margin: 0 0 12px; color: var(--warning); font-size: 12px; }

/* Records footer and pagination */
.report-records-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 14px; }
.report-records-footer > p { margin: 0; color: var(--text-secondary); font-size: 12px; }
.report-records-label { display: grid; gap: 2px; }
.report-records-label strong { color: var(--heading); font-size: 11px; letter-spacing: .04em; text-transform: uppercase; }
.report-pagination { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-left: auto; }
.report-page-link { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; padding: 4px 10px; color: var(--text-secondary); background: var(--surface-elevated); border: 1px solid var(--border); border-radius: 6px; text-decoration: none; font-size: 11.5px; font-weight: 700; }
.report-page-link.is-active { color: #fff; background: var(--primary-action); border-color: var(--primary-action); }
.report-pagination a.report-page-link:hover { color: #fff; background: var(--primary-action); border-color: var(--primary-action); }
.report-page-link[aria-disabled="true"] { color: var(--text-soft); background: var(--surface-subtle); cursor: not-allowed; }
.report-page-ellipsis { padding: 0 2px; color: var(--text-muted); }

.report-boundary-note { display: flex; align-items: flex-start; gap: 10px; margin: 0; padding: 14px 16px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-subtle); color: var(--text-muted); font-size: 12px; line-height: 1.55; }
.report-boundary-note .ui-icon { flex: 0 0 auto; color: var(--info); margin-top: 1px; }

/* Report Options dialog */
.report-options-dialog { width: min(560px, calc(100vw - 32px)); padding: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface-elevated); color: var(--text); box-shadow: var(--shadow); }
.report-options-dialog::backdrop { background: rgba(7, 27, 53, .45); }
.report-options-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 13px 16px; border-bottom: 1px solid var(--border); }
.report-options-header h2 { margin: 0; font-size: 15px; font-weight: 750; color: var(--heading); }
.report-options-body { display: grid; gap: 12px; padding: 14px 16px; max-height: min(64vh, 520px); overflow-y: auto; }
.report-options-scope { display: grid; gap: 3px; margin: 0; padding: 11px 13px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-subtle); }
.report-options-scope > span { color: var(--heading); font-size: 13px; font-weight: 750; }
.report-options-scope small { color: var(--text-muted); font-size: 11.5px; }
.report-options-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
.report-options-field { display: grid; gap: 6px; margin: 0; font-size: 11.5px; font-weight: 700; color: var(--text-secondary); }
.report-options-field select, .report-options-field input { width: 100%; min-height: 40px; font-size: 13px; }
.report-options-custom-margins { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-subtle); }
.report-options-content { display: grid; gap: 9px; margin: 0; padding: 13px; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.report-options-content legend { padding: 0 5px; color: var(--text-secondary); font-size: 11.5px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
.report-options-advanced { border: 1px solid var(--border); border-radius: var(--radius-sm); }
.report-options-advanced > summary { padding: 11px 13px; cursor: pointer; font-size: 12.5px; font-weight: 700; color: var(--text-secondary); }
.report-options-advanced > div { display: grid; gap: 8px; padding: 0 13px 13px; }
.report-options-advanced p { margin: 0; color: var(--text-muted); font-size: 11.5px; }
.report-options-error { margin: 0; color: var(--danger); font-size: 12px; }
.report-options-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 12px 16px; border-top: 1px solid var(--border); }

@media (max-width: 1180px) {
 .report-builder-primary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
 .report-filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 620px) {
 .reporting-workspace .report-builder-card { padding: 18px 16px; }
 .report-builder-primary { grid-template-columns: minmax(0, 1fr); gap: 14px; }
 .report-filter-grid { grid-template-columns: minmax(0, 1fr); }
 .report-builder-submit > span { display: none; }
 .reporting-workspace .report-generate-button { width: 100%; justify-content: center; }
}

@media print {
 .app-sidebar, .app-topbar, .report-builder-card, .report-preview-bar,
 .report-filters-panel, .report-generate-button, .report-records-footer,
 .report-pagination, .report-boundary-note,
 .report-options-dialog { display: none !important; }

}
</style>

<style>
/* Universal Reports UX refinements shared by every formal report type. */
.report-filter-actions { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
.report-clear-filters { color: var(--primary-action); font-size: 11px; font-weight: 750; text-decoration: none; }
.report-clear-filters:hover { text-decoration: underline; }
.report-preview-meta { display: flex; flex-wrap: wrap; gap: 5px 12px; margin-top: 4px; color: var(--text-muted); font-size: 11px; }
.report-preview-meta > span { display: inline-flex; align-items: center; gap: 5px; }
.report-preview-meta > span + span::before { content: 'ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢'; margin-right: 7px; color: var(--text-soft); }
.report-preview-filters { margin: 6px 0 0; color: var(--text-secondary); font-size: 11px; line-height: 1.45; }
.report-preview-filters strong { color: var(--heading); }
.report-no-results { display: flex; align-items: flex-start; gap: 14px; padding: 22px; border: 1px dashed var(--border); border-radius: var(--radius); background: var(--surface-subtle); }
.report-no-results-icon { display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; flex: 0 0 42px; border-radius: 10px; color: var(--info); background: var(--info-bg); }
.report-no-results h3 { margin: 4px 0 5px; color: var(--heading); font-size: 15px; }
.report-no-results p:not(.report-preview-label) { margin: 0; color: var(--text-muted); font-size: 12px; line-height: 1.5; }
.report-no-results-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }

.report-options-dialog [hidden] { display: none !important; }
.report-options-eyebrow { margin: 0 0 2px; color: var(--text-muted); font-size: 9.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.report-options-format { padding: 1px 0 2px; }
.report-options-section { display: grid; gap: 10px; padding: 13px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-subtle); }
.report-options-section-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
.report-options-section-heading h3 { margin: 0; color: var(--heading); font-size: 12px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; }
.report-options-section-heading small { color: var(--text-muted); font-size: 10.5px; }
.report-options-auto-note { display: flex; align-items: flex-start; gap: 9px; padding: 11px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-subtle); color: var(--text-secondary); }
.report-options-auto-note .ui-icon { flex: 0 0 auto; color: var(--info); margin-top: 1px; }
.report-options-auto-note div { display: grid; gap: 2px; }
.report-options-auto-note strong { color: var(--heading); font-size: 11.5px; }
.report-options-auto-note span { color: var(--text-muted); font-size: 11px; line-height: 1.4; }
.report-options-advanced-body { display: grid !important; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px !important; }
.report-options-custom-margins { grid-column: 1 / -1; }

@media (max-width: 620px) {
 .report-options-section-heading { display: grid; }
 .report-options-advanced-body { grid-template-columns: minmax(0, 1fr); }
 .report-preview-meta > span + span::before { display: none; }
 .report-no-results { padding: 18px 16px; }
}
</style>



<style>
/* ============================================================
 SYSTEM REPORT PREVIEW
 Normal browser view = application UI.
 Print/export source = formal paper document.
 ============================================================ */

.report-print-view {
 display: none !important;
}

.report-system-preview {
 display: grid;
 gap: 18px;
 min-width: 0;
}

.report-system-summary-grid {
 display: grid;
 grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
 gap: 14px;
}

.report-system-summary-card {
 display: flex;
 align-items: center;
 gap: 14px;
 min-height: 92px;
 padding: 16px 18px;
 border: 1px solid var(--border);
 border-radius: 10px;
 background: var(--surface-elevated);
 box-shadow: 0 1px 2px rgba(15, 35, 58, .03);
}

.report-system-summary-icon {
 display: grid;
 place-items: center;
 flex: 0 0 44px;
 width: 44px;
 height: 44px;
 border-radius: 10px;
 background: var(--info-bg);
 color: var(--info);
 font-size: 15px;
 font-weight: 800;
}

.report-system-summary-card:nth-child(2n) .report-system-summary-icon {
 background: color-mix(in srgb, #12a36d 12%, var(--surface-elevated));
 color: #0b8a59;
}

.report-system-summary-label {
 display: block;
 margin-bottom: 4px;
 color: var(--text-secondary);
 font-size: 11.5px;
 font-weight: 650;
}

.report-system-summary-value {
 display: block;
 color: var(--heading);
 font-size: 24px;
 font-weight: 800;
 line-height: 1.1;
}

.report-system-records-card {
 min-width: 0;
 padding: 18px;
 border: 1px solid var(--border);
 border-radius: 10px;
 background: var(--surface-elevated);
 box-shadow: 0 1px 2px rgba(15, 35, 58, .03);
}

.report-system-records-header {
 display: flex;
 align-items: center;
 justify-content: space-between;
 gap: 16px;
 margin-bottom: 16px;
}

.report-system-records-eyebrow {
 margin: 0 0 3px;
 color: var(--text-muted);
 font-size: 9.5px;
 font-weight: 800;
 letter-spacing: .07em;
 text-transform: uppercase;
}

.report-system-records-header h3 {
 margin: 0;
 color: var(--heading);
 font-size: 15px;
 font-weight: 750;
}

.report-system-search {
 position: relative;
 display: flex;
 align-items: center;
 width: min(260px, 100%);
}

.report-system-search-icon {
 position: absolute;
 left: 11px;
 z-index: 1;
 color: var(--text-muted);
 font-size: 16px;
 pointer-events: none;
}

.report-system-search input {
 width: 100%;
 min-height: 38px;
 padding: 8px 12px 8px 34px;
 border: 1px solid var(--border);
 border-radius: 7px;
 background: var(--surface-elevated);
 color: var(--heading);
 font: inherit;
 font-size: 12px;
 box-shadow: none;
}

.report-system-search input:focus {
 border-color: var(--primary-action);
 outline: 2px solid color-mix(in srgb, var(--primary-action) 20%, transparent);
 outline-offset: 1px;
}

.report-system-table-scroll {
 width: 100%;
 overflow-x: auto;
 border: 1px solid var(--border);
 border-radius: 8px;
}

.report-system-table {
 width: 100% !important;
 min-width: 1000px;
 margin: 0 !important;
 border: 0 !important;
 border-collapse: collapse !important;
 background: var(--surface-elevated) !important;
 color: var(--heading) !important;
 box-shadow: none !important;
 font-family: inherit !important;
}

.report-system-table caption {
 display: none !important;
}

.report-system-table thead {
 display: table-header-group !important;
}

.report-system-table th {
 padding: 11px 10px !important;
 border: 0 !important;
 border-right: 1px solid var(--border) !important;
 border-bottom: 1px solid var(--border) !important;
 background: var(--surface-subtle) !important;
 color: var(--heading) !important;
 font-family: inherit !important;
 font-size: 10px !important;
 font-weight: 750 !important;
 line-height: 1.35 !important;
 letter-spacing: .02em !important;
 text-align: left !important;
 text-transform: none !important;
 vertical-align: middle !important;
 white-space: normal !important;
}

.report-system-table td {
 padding: 11px 10px !important;
 border: 0 !important;
 border-right: 1px solid var(--border) !important;
 border-bottom: 1px solid var(--border) !important;
 background: var(--surface-elevated) !important;
 color: var(--heading) !important;
 font-family: inherit !important;
 font-size: 11.5px !important;
 line-height: 1.45 !important;
 text-align: left !important;
 vertical-align: top !important;
 white-space: normal !important;
}

.report-system-table th:last-child,
.report-system-table td:last-child {
 border-right: 0 !important;
}

.report-system-table tbody tr:last-child td {
 border-bottom: 0 !important;
}

.report-system-table tbody tr:hover td {
 background: color-mix(in srgb, var(--info-bg) 35%, var(--surface-elevated)) !important;
}

.report-system-table a,
.report-system-table .table-action,
.report-system-table button {
 font-family: inherit !important;
}

.report-system-table .table-action,
.report-system-table a.button {
 display: inline-flex !important;
 align-items: center !important;
 justify-content: center !important;
 min-height: 32px !important;
 padding: 6px 10px !important;
 border: 1px solid var(--primary-action) !important;
 border-radius: 6px !important;
 background: transparent !important;
 color: var(--primary-action) !important;
 font-size: 10.5px !important;
 font-weight: 700 !important;
 text-decoration: none !important;
 white-space: nowrap !important;
}

.report-system-record-count {
 margin: 12px 0 0;
 color: var(--text-muted);
 font-size: 11px;
}

/* The preview header is application chrome, not part of the paper. */
.reporting-detail .report-preview-bar {
 margin-bottom: 16px;
 padding: 0;
}

.reporting-detail .report-preview-title {
 font-size: 18px;
}

@media (max-width: 760px) {
 .report-system-summary-grid {
 grid-template-columns: 1fr;
 }

 .report-system-records-header {
 align-items: stretch;
 flex-direction: column;
 }

 .report-system-search {
 width: 100%;
 }
}

</style>

<style>
/* =========================================================
 SYSTEM REPORT PREVIEW — server-rendered records table
 The on-screen preview is application UI only. The formal
 document exists only in Print and the exported files.
 ========================================================= */


.report-preview-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.report-preview-filters { display: flex; flex-wrap: wrap; gap: 4px 14px; }

.report-results { position: relative; }
.report-results-loading { display: flex; align-items: center; gap: 10px; margin: 0 0 12px; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-subtle); color: var(--text-secondary); font-size: 12px; font-weight: 650; }
.report-results-loading[hidden] { display: none; }
.report-results-spinner, .report-system-records-card.is-loading::after { width: 16px; height: 16px; border: 2px solid color-mix(in srgb, var(--primary-action) 25%, transparent); border-top-color: var(--primary-action); border-radius: 50%; animation: report-spin .8s linear infinite; }
.report-results.is-loading .report-system-preview, .report-results.is-loading .report-no-results { opacity: .45; pointer-events: none; transition: opacity .15s ease; }
.report-system-records-card { position: relative; }
.report-system-records-card.is-loading > * { opacity: .5; pointer-events: none; }
.report-system-records-card.is-loading::after { content: ''; position: absolute; top: 22px; right: 22px; }
@keyframes report-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .report-results-spinner, .report-system-records-card.is-loading::after { animation: none; } }

.report-sort-link { display: inline-flex; align-items: center; gap: 5px; color: inherit !important; text-decoration: none !important; font-weight: inherit; }
.report-sort-link:hover span:first-child, .report-sort-link:focus-visible span:first-child { text-decoration: underline; }
.report-sort-indicator { color: var(--text-soft); font-size: 9px; line-height: 1; }
th[aria-sort="ascending"] .report-sort-indicator, th[aria-sort="descending"] .report-sort-indicator { color: var(--primary-action); }
.report-system-table th.numeric, .report-system-table td.numeric { text-align: right !important; font-variant-numeric: tabular-nums; }
.report-system-table th.numeric .report-sort-link { flex-direction: row-reverse; }
.report-system-table .status-badge { font-size: 10px; font-weight: 650; padding: 3px 8px; white-space: nowrap; }
.report-system-table .report-system-action-column { width: 1%; white-space: nowrap !important; text-align: right !important; }
.report-system-table .report-record-restricted { color: var(--text-muted); font-size: 11px; cursor: help; }
.report-system-table .report-system-table-empty { padding: 26px 12px !important; color: var(--text-muted) !important; text-align: center !important; }

.report-system-search-note { margin: -4px 0 12px; color: var(--text-secondary); font-size: 11.5px; line-height: 1.5; }
.report-system-search-note a { margin-left: 4px; color: var(--primary-action); font-weight: 700; }

.report-records-controls { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-left: auto; }
.report-per-page-control select { min-height: 32px; padding: 4px 28px 4px 10px; font-size: 11.5px; }
.report-records-summary { margin: 0; color: var(--text-secondary); font-size: 12px; }

.report-output-note { display: flex; align-items: flex-start; gap: 8px; margin: 0; color: var(--text-muted); font-size: 11.5px; line-height: 1.5; }
.report-output-note .ui-icon { flex: 0 0 auto; margin-top: 1px; color: var(--info); }

.report-preview-error .report-no-results-icon { color: var(--danger); background: var(--danger-bg, color-mix(in srgb, var(--danger) 10%, var(--surface-elevated))); }

@media (max-width: 620px) {
 .report-preview-actions, .report-preview-actions .button { width: 100%; justify-content: center; }
 .report-records-controls { margin-left: 0; }
}

@media print {
 .report-system-search, .report-records-controls, .report-preview-actions,
 .report-system-action-column, .report-output-note { display: none !important; }
}
</style>


<style>
/* =========================================================
 SYSTEM REPORT PREVIEW V2
 ========================================================= */

@media screen {

 .report-print-view {
 display: none !important;
 }

 .report-system-preview {
 display: grid;
 gap: 18px;
 width: 100%;
 min-width: 0;
 }


 /* Summary cards */

 .report-system-summary-grid {
 display: grid;
 grid-template-columns:
 repeat(auto-fit, minmax(210px, 1fr));
 gap: 14px;
 }

 .report-system-summary-card {
 display: flex;
 align-items: center;
 gap: 14px;
 min-height: 90px;
 padding: 16px 18px;
 border: 1px solid var(--border);
 border-radius: 10px;
 background: var(--surface-elevated);
 box-shadow:
 0 1px 2px rgba(15, 35, 58, .03);
 }

 .report-system-summary-icon {
 display: grid;
 place-items: center;
 flex: 0 0 44px;
 width: 44px;
 height: 44px;
 border-radius: 10px;
 background: var(--info-bg);
 color: var(--info);
 font-size: 15px;
 font-weight: 800;
 }

 .report-system-summary-card:nth-child(even)
 .report-system-summary-icon {

 background:
 color-mix(
 in srgb,
 #12a36d 12%,
 var(--surface-elevated)
 );

 color: #0b8a59;
 }

 .report-system-summary-label {
 display: block;
 margin-bottom: 4px;
 color: var(--text-secondary);
 font-size: 11.5px;
 font-weight: 650;
 }

 .report-system-summary-value {
 display: block;
 color: var(--heading);
 font-size: 25px;
 font-weight: 800;
 line-height: 1.1;
 }


 /* Records card */

 .report-system-records-card {
 width: 100%;
 min-width: 0;
 padding: 18px;
 border: 1px solid var(--border);
 border-radius: 10px;
 background: var(--surface-elevated);
 box-shadow:
 0 1px 2px rgba(15, 35, 58, .03);
 }

 .report-system-records-header {
 display: flex;
 align-items: center;
 justify-content: space-between;
 gap: 16px;
 margin-bottom: 16px;
 }

 .report-system-records-eyebrow {
 margin: 0 0 3px;
 color: var(--text-muted);
 font-size: 9.5px;
 font-weight: 800;
 letter-spacing: .07em;
 text-transform: uppercase;
 }

 .report-system-records-header h3 {
 margin: 0;
 color: var(--heading);
 font-size: 15px;
 font-weight: 750;
 }


 /* Search */

 .report-system-search {
 position: relative;
 display: flex;
 align-items: center;
 width: min(260px, 100%);
 }

 .report-system-search-icon {
 position: absolute;
 left: 11px;
 z-index: 1;
 color: var(--text-muted);
 font-size: 16px;
 pointer-events: none;
 }

 .report-system-search input {
 width: 100%;
 height: 38px;
 min-height: 38px;
 padding: 7px 12px 7px 34px;
 border: 1px solid var(--border);
 border-radius: 7px;
 background: var(--surface-elevated);
 color: var(--heading);
 font: inherit;
 font-size: 12px;
 box-shadow: none;
 }

 .report-system-search input:focus {
 border-color: var(--primary-action);
 outline:
 2px solid
 color-mix(
 in srgb,
 var(--primary-action) 20%,
 transparent
 );
 outline-offset: 1px;
 }


 /* System table */

 .report-system-table-scroll {
 width: 100%;
 overflow-x: auto;
 border: 1px solid var(--border);
 border-radius: 8px;
 }

 .report-system-table {
 width: 100% !important;
 min-width: 1000px;
 margin: 0 !important;
 border: 0 !important;
 border-collapse: collapse !important;
 background:
 var(--surface-elevated) !important;
 color: var(--heading) !important;
 box-shadow: none !important;
 font-family: inherit !important;
 }

 .report-system-table thead {
 display: table-header-group !important;
 }

 .report-system-table th {
 padding: 11px 10px !important;
 border: 0 !important;
 border-right:
 1px solid var(--border) !important;
 border-bottom:
 1px solid var(--border) !important;
 background:
 var(--surface-subtle) !important;
 color: var(--heading) !important;
 font-family: inherit !important;
 font-size: 10px !important;
 font-weight: 750 !important;
 line-height: 1.35 !important;
 letter-spacing: .02em !important;
 text-align: left !important;
 text-transform: none !important;
 vertical-align: middle !important;
 white-space: normal !important;
 }

 .report-system-table td {
 padding: 11px 10px !important;
 border: 0 !important;
 border-right:
 1px solid var(--border) !important;
 border-bottom:
 1px solid var(--border) !important;
 background:
 var(--surface-elevated) !important;
 color: var(--heading) !important;
 font-family: inherit !important;
 font-size: 11.5px !important;
 line-height: 1.45 !important;
 text-align: left !important;
 vertical-align: top !important;
 white-space: normal !important;
 }

 .report-system-table th:last-child,
 .report-system-table td:last-child {
 border-right: 0 !important;
 }

 .report-system-table tbody
 tr:last-child td {

 border-bottom: 0 !important;
 }

 .report-system-table tbody
 tr:hover td {

 background:
 color-mix(
 in srgb,
 var(--info-bg) 35%,
 var(--surface-elevated)
 ) !important;
 }

 .report-system-table a,
 .report-system-table button {
 font-family: inherit !important;
 }

 .report-system-table .table-action,
 .report-system-table a.button {
 display: inline-flex !important;
 align-items: center !important;
 justify-content: center !important;
 min-height: 31px !important;
 padding: 5px 9px !important;
 border:
 1px solid
 var(--primary-action) !important;
 border-radius: 6px !important;
 background: transparent !important;
 color:
 var(--primary-action) !important;
 font-size: 10.5px !important;
 font-weight: 700 !important;
 text-decoration: none !important;
 white-space: nowrap !important;
 }

 .report-system-record-count {
 margin: 12px 0 0;
 color: var(--text-muted);
 font-size: 11px;
 }


 /* Preview heading remains application UI */

 .reporting-detail .report-preview-bar {
 margin-bottom: 16px;
 }

 .reporting-detail .report-preview-title {
 font-size: 18px;
 }
}


@media (max-width: 760px) {

 .report-system-summary-grid {
 grid-template-columns: 1fr;
 }

 .report-system-records-header {
 align-items: stretch;
 flex-direction: column;
 }

 .report-system-search {
 width: 100%;
 }
}


</style>

<style>
/* REMOVE SUMMARY LETTER ICONS + CORRUPTED PREVIEW SEPARATOR */

.report-system-summary-icon {
 display: none !important;
}

.report-system-summary-card {
 gap: 0 !important;
}

.report-preview-meta > span + span::before {
 content: none !important;
 display: none !important;
}

</style>
<style>
/* REPORT UNIVERSAL EMPTY STATE FINAL */

.report-no-results {
 display: grid !important;
 place-items: center !important;
 min-height: 275px;
 padding: 38px 24px !important;

 border: 1px solid var(--border) !important;
 border-radius: 10px !important;

 background: var(--surface-elevated) !important;
 text-align: center !important;
}

.report-no-results-inner {
 display: grid;
 justify-items: center;
 width: min(460px, 100%);
 gap: 8px;
}

.report-no-results-illustration {
 display: grid;
 place-items: center;

 width: 70px;
 height: 70px;

 margin-bottom: 5px;

 color: color-mix(
 in srgb,
 var(--primary-action) 48%,
 var(--text-muted)
 );
}

.report-no-results-illustration svg {
 width: 64px;
 height: 64px;
 overflow: visible;
}

.report-no-results-illustration .empty-soft {
 fill: color-mix(
 in srgb,
 var(--primary-action) 7%,
 transparent
 );
 stroke: none;
}

.report-no-results-illustration .empty-line {
 fill: none;
 stroke: currentColor;
 stroke-width: 1.45;
 stroke-linecap: round;
 stroke-linejoin: round;
}

.report-no-results-illustration .empty-spark {
 fill: color-mix(
 in srgb,
 var(--primary-action) 25%,
 transparent
 );
}

.report-no-results h3 {
 margin: 0 !important;

 color: var(--heading) !important;

 font-size: 15px !important;
 font-weight: 750 !important;
 line-height: 1.35 !important;
}

.report-no-results p {
 max-width: 460px;

 margin: 0 !important;

 color: var(--text-muted) !important;

 font-size: 12px !important;
 line-height: 1.55 !important;
}

.report-no-results .report-preview-label,
.report-no-results-actions,
.report-no-results-icon {
 display: none !important;
}

@media (max-width: 620px) {
 .report-no-results {
 min-height: 230px;
 padding: 30px 18px !important;
 }

 .report-no-results-illustration {
 width: 60px;
 height: 60px;
 }

 .report-no-results-illustration svg {
 width: 56px;
 height: 56px;
 }
}

</style>



<style>
/* EXPORT PRINT MODAL CANONICAL FINAL */

/*
 * IMPORTANT:
 * The dialog must shrink to the real form content.
 * Do not let dialog/form inherit a stretched height.
 */
dialog.report-options-dialog[open] {
 display: block !important;

 width: min(520px, calc(100vw - 28px)) !important;
 inline-size: min(520px, calc(100vw - 28px)) !important;

 height: fit-content !important;
 block-size: fit-content !important;

 min-height: 0 !important;
 min-block-size: 0 !important;

 max-height: calc(100dvh - 32px) !important;
 max-block-size: calc(100dvh - 32px) !important;

 padding: 0 !important;

 overflow: hidden !important;

 border-radius: 13px !important;
}

/*
 * This is the important part:
 * force the FORM itself to shrink to its contents.
 */
dialog.report-options-dialog[open] > form {
 display: grid !important;

 grid-template-rows:
 auto
 minmax(0, auto)
 auto !important;

 align-content: start !important;

 width: 100% !important;

 height: fit-content !important;
 block-size: fit-content !important;

 min-height: 0 !important;
 min-block-size: 0 !important;

 max-height: calc(100dvh - 32px) !important;

 margin: 0 !important;
 padding: 0 !important;
}

/* Header */
.report-options-header {
 padding: 11px 15px !important;
}

.report-options-header h2 {
 margin: 0 !important;
 font-size: 15px !important;
}

/* Actual modal content */
.report-options-body {
 display: grid !important;

 gap: 10px !important;

 width: auto !important;

 height: auto !important;
 min-height: 0 !important;

 max-height: calc(100dvh - 150px) !important;

 padding: 11px 15px 10px !important;

 overflow-x: hidden !important;
 overflow-y: auto !important;
}

/* Compact report identity */
.report-options-scope {
 display: grid !important;
 gap: 2px !important;

 margin: 0 !important;
 padding: 0 0 2px !important;

 border: 0 !important;
 background: transparent !important;
}

.report-options-scope > span {
 font-size: 12.5px !important;
 font-weight: 750 !important;
}

.report-options-scope small {
 font-size: 10.5px !important;
 line-height: 1.35 !important;
}

/* Export format */
.report-options-format {
 padding: 0 !important;
}

.report-options-field {
 gap: 5px !important;
 font-size: 11px !important;
}

.report-options-field select,
.report-options-field input {
 min-height: 38px !important;
 height: 38px;
 font-size: 12.5px !important;
}

/* Page layout */
.report-options-section {
 gap: 8px !important;

 padding: 10px 11px !important;

 border-radius: 8px !important;
}

.report-options-section-heading h3 {
 font-size: 10.5px !important;
}

/* Helper sentence not needed */
.report-options-section-heading small {
 display: none !important;
}

.report-options-grid {
 display: grid !important;

 grid-template-columns:
 repeat(2, minmax(0, 1fr)) !important;

 gap: 10px !important;
}

/* Include report summary */
.report-options-content {
 display: flex !important;

 align-items: center !important;

 gap: 8px !important;

 min-height: 42px !important;

 margin: 0 !important;
 padding: 9px 11px !important;

 border-radius: 8px !important;
}

.report-options-content legend {
 display: none !important;
}

.report-options-content label {
 margin: 0 !important;
}

/* Remove large automatic-info box */
.report-options-auto-note {
 display: none !important;
}

/* Advanced settings */
.report-options-advanced {
 margin: 0 !important;

 border-radius: 8px !important;
}

.report-options-advanced > summary {
 padding: 9px 11px !important;

 font-size: 11.5px !important;
}

.report-options-advanced:not([open]) {
 padding-bottom: 0 !important;
}

.report-options-advanced-body {
 display: grid !important;

 grid-template-columns:
 repeat(2, minmax(0, 1fr)) !important;

 gap: 10px !important;

 padding: 0 11px 11px !important;
}

.report-options-custom-margins {
 grid-column: 1 / -1;

 gap: 8px !important;
 padding: 9px !important;
}

/* Footer MUST sit directly after form content */
.report-options-footer {
 position: static !important;

 display: flex !important;

 align-items: center !important;
 justify-content: flex-end !important;

 align-self: auto !important;

 gap: 8px !important;

 width: 100% !important;

 height: auto !important;
 min-height: 0 !important;

 margin: 0 !important;

 padding: 9px 15px 11px !important;

 border-top: 1px solid var(--border) !important;

 background: var(--surface-elevated) !important;
}

.report-options-footer .button {
 height: 36px !important;
 min-height: 36px !important;

 padding: 0 14px !important;
}

/* Nothing should create phantom space after footer */
.report-options-footer:last-child {
 margin-bottom: 0 !important;
}

dialog.report-options-dialog[open] > form::after {
 display: none !important;
 content: none !important;
}

/* Mobile */
@media (max-width: 620px) {

 dialog.report-options-dialog[open] {
 width: min(94vw, 520px) !important;
 inline-size: min(94vw, 520px) !important;

 max-height: calc(100dvh - 20px) !important;
 }

 .report-options-grid,
 .report-options-advanced-body {
 grid-template-columns:
 minmax(0, 1fr) !important;
 }
}

</style>


<style>
/* INVENTORY STYLE REPORT TABLES FINAL */

@media screen {

 /* =====================================================
 TABLE WRAPPER
 ===================================================== */

 .report-system-table-scroll {
 width: 100%;

 overflow-x: auto !important;
 overflow-y: hidden !important;

 border: 1px solid var(--border) !important;
 border-radius: 8px !important;

 background: var(--surface-elevated) !important;
 }


 /* =====================================================
 TABLE BASE
 ===================================================== */

 [data-report-system-table] table {
 width: max-content !important;
 min-width: 100% !important;

 margin: 0 !important;

 border-collapse: separate !important;
 border-spacing: 0 !important;

 table-layout: auto !important;

 background: var(--surface-elevated) !important;
 }


 /* =====================================================
 HEADER
 Similar to Inventory table
 ===================================================== */

 [data-report-system-table] thead th {
 padding: 10px 12px !important;

 background:
 color-mix(
 in srgb,
 var(--surface-subtle) 92%,
 var(--primary-action) 8%
 ) !important;

 color: var(--text-secondary) !important;

 border: 0 !important;
 border-right: 1px solid var(--border) !important;
 border-bottom: 1px solid var(--border) !important;

 font-family: inherit !important;
 font-size: 9.5px !important;
 font-weight: 800 !important;
 line-height: 1.25 !important;

 letter-spacing: .04em !important;
 text-transform: uppercase !important;

 text-align: left !important;
 vertical-align: middle !important;

 white-space: normal !important;

 word-break: normal !important;
 overflow-wrap: normal !important;
 }

 [data-report-system-table] thead th:last-child {
 border-right: 0 !important;
 }


 /* =====================================================
 BODY
 ===================================================== */

 [data-report-system-table] tbody td {
 padding: 11px 12px !important;

 background: var(--surface-elevated) !important;
 color: var(--heading) !important;

 border: 0 !important;
 border-right: 1px solid var(--border) !important;
 border-bottom: 1px solid var(--border) !important;

 font-family: inherit !important;
 font-size: 11.5px !important;
 font-weight: 450 !important;
 line-height: 1.4 !important;

 text-align: left !important;
 vertical-align: middle !important;

 white-space: normal !important;

 word-break: normal !important;
 overflow-wrap: break-word !important;
 }

 [data-report-system-table] tbody td:last-child {
 border-right: 0 !important;
 }

 [data-report-system-table] tbody tr:last-child td {
 border-bottom: 0 !important;
 }

 /*
 * Clean row hover similar to inventory list.
 */
 [data-report-system-table] tbody tr:hover td {
 background:
 color-mix(
 in srgb,
 var(--primary-action) 3%,
 var(--surface-elevated)
 ) !important;
 }


 /* =====================================================
 COLUMN SIZING
 Compact and based on content
 ===================================================== */

 /*
 * Request / transaction IDs
 */
 [data-report-system-table] .report-col-id {
 width: 1% !important;
 min-width: 135px !important;
 max-width: 165px !important;

 white-space: nowrap !important;

 font-family: inherit !important;
 font-variant-numeric: tabular-nums;
 }


 /*
 * Borrower / personnel
 */
 [data-report-system-table] .report-col-person {
 min-width: 110px !important;
 max-width: 150px !important;

 white-space: normal !important;
 }


 /*
 * Division / Office / College / Unit
 */
 [data-report-system-table] .report-col-organization {
 min-width: 120px !important;
 max-width: 190px !important;

 white-space: normal !important;
 }


 /*
 * Dates
 */
 [data-report-system-table] .report-col-date {
 width: 1% !important;

 min-width: 105px !important;
 max-width: 125px !important;

 white-space: normal !important;

 font-variant-numeric: tabular-nums;
 }


 /*
 * Status / decision
 */
 [data-report-system-table] .report-col-status {
 min-width: 105px !important;
 max-width: 145px !important;

 white-space: normal !important;
 }


 /*
 * Purpose / description / remarks / reason
 */
 [data-report-system-table] .report-col-wrap {
 min-width: 125px !important;
 max-width: 210px !important;

 white-space: normal !important;
 }


 /*
 * Version / count / quantity
 */
 [data-report-system-table] .report-col-compact {
 width: 1% !important;

 min-width: 62px !important;
 max-width: 78px !important;

 text-align: center !important;

 white-space: nowrap !important;
 }


 /*
 * Turnaround / duration
 */
 [data-report-system-table] .report-col-duration {
 width: 1% !important;

 min-width: 90px !important;
 max-width: 105px !important;

 text-align: center !important;

 white-space: nowrap !important;

 font-variant-numeric: tabular-nums;
 }


 /*
 * Amounts / numeric values
 */
 [data-report-system-table] .report-col-number {
 width: 1% !important;

 min-width: 75px !important;
 max-width: 95px !important;

 text-align: center !important;

 white-space: nowrap !important;

 font-variant-numeric: tabular-nums;
 }


 /* =====================================================
 NUMERIC HEADER ALIGNMENT
 ===================================================== */

 [data-report-system-table] thead .report-col-compact,
 [data-report-system-table] thead .report-col-duration,
 [data-report-system-table] thead .report-col-number {
 text-align: center !important;
 }


 /* =====================================================
 STATUS / ACTIONS
 ===================================================== */

 [data-report-system-table] .status-badge {
 display: inline-flex !important;

 align-items: center;
 justify-content: center;

 width: fit-content !important;
 max-width: 135px !important;

 margin: 0 !important;

 line-height: 1.2 !important;
 white-space: normal !important;
 }

 [data-report-system-table] .table-action,
 [data-report-system-table] .button {
 min-height: 30px !important;

 padding: 5px 9px !important;

 white-space: nowrap !important;
 }


 /* =====================================================
 EMPTY CELLS
 ===================================================== */

 [data-report-system-table] tbody td:empty::after {
 content: "—";

 color: var(--text-soft);
 }


 /* =====================================================
 SCROLLBAR
 ===================================================== */

 .report-system-table-scroll::-webkit-scrollbar {
 height: 8px;
 }

 .report-system-table-scroll::-webkit-scrollbar-track {
 background: var(--surface-subtle);
 }

 .report-system-table-scroll::-webkit-scrollbar-thumb {
 background:
 color-mix(
 in srgb,
 var(--text-muted) 45%,
 transparent
 );

 border: 2px solid var(--surface-subtle);

 border-radius: 999px;
 }


 /* =====================================================
 MOBILE
 ===================================================== */

 @media (max-width: 760px) {

 [data-report-system-table] thead th {
 padding: 9px 10px !important;

 font-size: 9px !important;
 }

 [data-report-system-table] tbody td {
 padding: 10px !important;

 font-size: 11px !important;
 }
 }
}

</style>
<style>
/* CENTER REPORT TABLE HEADERS FINAL */

@media screen {

 [data-report-system-table] thead th {
 text-align: center !important;
 vertical-align: middle !important;
 }

 /*
 * Keep actual record values readable.
 * Only the header labels are centered.
 */
 [data-report-system-table] tbody td {
 text-align: left !important;
 }

 /*
 * Compact/numeric columns may remain centered in the body.
 */
 [data-report-system-table] tbody .report-col-compact,
 [data-report-system-table] tbody .report-col-duration,
 [data-report-system-table] tbody .report-col-number {
 text-align: center !important;
 }

}

</style>
<style>
/* REPORT RECORDS PAGINATION INVENTORY STYLE */

@media screen {

 .report-records-footer--inventory-style {
 display: flex !important;
 align-items: center !important;
 justify-content: space-between !important;

 gap: 12px 18px !important;

 margin-top: 13px !important;
 padding: 0 !important;

 border: 0 !important;
 background: transparent !important;
 }

 .report-records-summary {
 margin: 0 !important;

 color: var(--text-muted) !important;

 font-size: 11px !important;
 font-weight: 500 !important;
 line-height: 1.4 !important;

 white-space: nowrap;
 }

 .report-records-controls {
 display: flex;
 align-items: center;
 justify-content: flex-end;

 gap: 9px;

 margin-left: auto;
 }

 /* Records per page */

 .report-per-page-control {
 margin: 0;
 }

 .report-per-page-control select {
 width: auto !important;
 min-width: 168px !important;

 height: 38px !important;
 min-height: 38px !important;

 padding: 0 36px 0 13px !important;

 border: 1px solid var(--border) !important;
 border-radius: 8px !important;

 background-color: var(--surface-elevated) !important;

 color: var(--heading) !important;

 font-family: inherit !important;
 font-size: 11.5px !important;
 font-weight: 650 !important;
 }

 /* Previous / pages / Next */

 .report-records-controls .report-pagination {
 display: flex !important;
 align-items: center !important;

 gap: 7px !important;

 margin: 0 !important;
 }

 .report-records-controls .report-page-link {
 display: inline-flex !important;

 align-items: center !important;
 justify-content: center !important;

 min-width: 38px !important;
 height: 38px !important;

 margin: 0 !important;
 padding: 0 11px !important;

 border: 1px solid var(--border) !important;
 border-radius: 8px !important;

 background: var(--surface-elevated) !important;
 color: var(--text-secondary) !important;

 font-size: 11.5px !important;
 font-weight: 650 !important;

 text-decoration: none !important;
 }

 .report-records-controls
 .report-page-link:first-child,

 .report-records-controls
 .report-page-link:last-child {
 min-width: 96px !important;
 }

 .report-records-controls
 a.report-page-link:hover {
 border-color: var(--primary-action) !important;
 color: var(--primary-action) !important;

 background:
 color-mix(
 in srgb,
 var(--primary-action) 4%,
 var(--surface-elevated)
 ) !important;
 }

 .report-records-controls
 .report-page-link.is-active {
 border-color:
 color-mix(
 in srgb,
 var(--primary-action) 35%,
 var(--border)
 ) !important;

 background: var(--surface-elevated) !important;
 color: var(--heading) !important;

 font-weight: 750 !important;
 }

 .report-records-controls
 .report-page-link[aria-disabled="true"] {
 background: var(--surface-subtle) !important;
 color: var(--text-soft) !important;

 cursor: not-allowed !important;
 }

 .report-records-controls
 .report-page-ellipsis {
 display: inline-flex;
 align-items: center;
 justify-content: center;

 min-width: 24px;
 height: 38px;

 color: var(--text-muted);
 }

 @media (max-width: 760px) {

 .report-records-footer--inventory-style {
 align-items: stretch !important;
 flex-direction: column !important;
 }

 .report-records-controls {
 width: 100%;

 margin-left: 0;

 justify-content: flex-start;

 overflow-x: auto;
 }
 }
}

</style>
<style>
/* HIDE REPORT BUILDER DESCRIPTION FINAL */

/*
 * Remove the report-specific explanatory sentence shown
 * underneath the Report Builder controls.
 */
.reporting-workspace
.report-builder-card
.report-description {
 display: none !important;
}

/* Prevent leftover spacing */
.reporting-workspace
.report-builder-card {
 row-gap: 0;
}

</style>
<style>
/* =========================================================
 SYSTEM PREVIEW — COMPACT LAYOUT (final overrides)
 Dense, dashboard-style cards and table consistent with Analytics.
 ========================================================= */
.reporting-detail .report-system-preview { gap: 14px; }
.reporting-detail .report-system-summary-grid { grid-template-columns: repeat(auto-fit, minmax(165px, 1fr)); gap: 10px; }
.reporting-detail .report-system-summary-card { min-height: 0; padding: 12px 14px; border-radius: 9px; }
.reporting-detail .report-system-summary-label { margin-bottom: 3px; font-size: 11px; line-height: 1.3; }
.reporting-detail .report-system-summary-value { font-size: 20px; }
.reporting-detail .report-system-records-card { padding: 14px 16px; }
.reporting-detail .report-system-records-header { margin-bottom: 12px; }
.reporting-detail .report-system-table { min-width: 860px; }
.reporting-detail .report-system-table th, .reporting-detail .report-system-table td { padding: 9px 10px !important; }
/* Only horizontal overflow scrolls inside the card; rows never scroll in a nested box. */
.reporting-detail .report-system-table-scroll { max-height: none; overflow-x: auto; overflow-y: visible; }
.reporting-detail .report-system-table td.report-cell-nowrap { white-space: nowrap !important; }
.reporting-detail .report-records-footer { margin-top: 12px; }
.reporting-detail .report-preview-bar { gap: 12px; margin-bottom: 12px; }
@media (max-width: 760px) {
 .reporting-detail .report-system-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
 .reporting-detail .report-system-records-card { padding: 12px; }
}
@media (max-width: 330px) {
 .reporting-detail .report-system-summary-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>

<style>
/* SPMU-REPORTS-INVENTORY-TABLE-V1 — display only; print/export stays unchanged */
@media screen {
  .reporting-detail .report-system-table-scroll {
    max-width: 100%;
    border: 1px solid var(--border) !important;
    border-radius: 9px !important;
    overflow-x: auto !important;
    scrollbar-width: thin;
  }
  .reporting-detail .report-system-table {
    width: max-content !important;
    min-width: 100% !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
    table-layout: auto !important;
  }
  .reporting-detail .report-system-table thead th,
  .reporting-detail [data-report-system-table] thead th {
    padding: 11px 12px !important;
    background: var(--surface-subtle) !important;
    color: var(--text-secondary) !important;
    border: 0 !important;
    border-bottom: 1px solid var(--border) !important;
    font-size: 10px !important;
    font-weight: 800 !important;
    line-height: 1.3 !important;
    letter-spacing: .045em !important;
    text-transform: uppercase !important;
    vertical-align: middle !important;
  }
  .reporting-detail .report-system-table tbody td,
  .reporting-detail [data-report-system-table] tbody td {
    padding: 10px 12px !important;
    background: var(--surface-elevated) !important;
    border: 0 !important;
    border-bottom: 1px solid var(--border) !important;
    font-size: 11.5px !important;
    line-height: 1.45 !important;
    vertical-align: middle !important;
  }
  .reporting-detail .report-system-table tbody tr:last-child td,
  .reporting-detail [data-report-system-table] tbody tr:last-child td {
    border-bottom: 0 !important;
  }
  .reporting-detail .report-system-table tbody tr:hover td,
  .reporting-detail [data-report-system-table] tbody tr:hover td {
    background: color-mix(in srgb, var(--primary-action) 3%, var(--surface-elevated)) !important;
  }
  .reporting-detail .report-reference-badge {
    display: inline-block;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
    padding: 6px 9px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface-subtle);
    color: var(--heading);
    font-size: 10.5px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
  }
  .reporting-detail .report-inventory-view-action {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    min-width: 30px;
    min-height: 30px;
    padding: 4px !important;
    border: 0 !important;
    border-radius: 7px;
    background: transparent !important;
    color: var(--primary-action) !important;
    text-decoration: none !important;
    box-shadow: none !important;
    font-size: 0 !important; /* Visible eye icon, accessible View Record text remains in HTML */
  }
  .reporting-detail .report-inventory-view-action:hover {
    background: color-mix(in srgb, var(--primary-action) 9%, var(--surface-elevated)) !important;
    color: var(--primary-action) !important;
  }
  .reporting-detail .report-inventory-view-action:focus-visible {
    outline: 2px solid var(--primary-action) !important;
    outline-offset: 2px;
  }
  .reporting-detail .report-inventory-view-action svg {
    display: block;
    visibility: visible !important;
    opacity: 1 !important;
    stroke: currentColor !important;
  }
  .reporting-detail .report-sr-only {
    position: absolute !important;
    width: 1px; height: 1px;
    overflow: hidden; clip: rect(0, 0, 0, 0);
    white-space: nowrap; clip-path: inset(50%);
  }
  .reporting-detail .report-system-action-column {
    min-width: 66px !important;
    text-align: center !important;
    white-space: nowrap !important;
  }
  .reporting-detail .report-system-table td.numeric,
  .reporting-detail [data-report-system-table] td.numeric {
    font-variant-numeric: tabular-nums;
    font-weight: 700 !important;
  }
  /* All Transactions: retain all fields with sensible widths and scroll, never squash. */
  .all-transactions-page .all-transactions-table { min-width: 1280px !important; }
  .all-transactions-page .all-transactions-table thead th { white-space: nowrap !important; }
  .all-transactions-page .all-transactions-table tbody td { height: 54px; white-space: nowrap !important; }
  .all-transactions-page .all-transactions-table th:nth-child(1) { min-width: 186px !important; }
  .all-transactions-page .all-transactions-table th:nth-child(2) { min-width: 116px !important; }
  .all-transactions-page .all-transactions-table th:nth-child(3) { min-width: 147px !important; }
  .all-transactions-page .all-transactions-table th:nth-child(4) { min-width: 195px !important; }
  .all-transactions-page .all-transactions-table th:nth-child(5) { min-width: 185px !important; }
  .all-transactions-page .all-transactions-table th:nth-child(6) { min-width: 85px !important; text-align: center !important; }
  .all-transactions-page .all-transactions-table th:nth-child(7) { min-width: 152px !important; }
  .all-transactions-page .all-transactions-table th:nth-child(8) { min-width: 128px !important; }
  .all-transactions-page .all-transactions-table th:nth-child(9) { min-width: 70px !important; text-align: center !important; }
  .all-transactions-page .all-transactions-table td:nth-child(6) { text-align: center !important; }
  .all-transactions-page .all-transactions-person,
  .all-transactions-page .all-transactions-items {
    display: inline-block;
    max-width: 182px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
    font-weight: 700;
    color: var(--heading);
  }
  .all-transactions-page .all-transactions-person { max-width: 155px; }
  .all-transactions-page .all-transactions-unit { max-width: 175px; }
  .all-transactions-page .all-transactions-type { color: var(--heading); font-weight: 500; }
  .all-transactions-page .all-transactions-date { font-variant-numeric: tabular-nums; color: var(--text-secondary); }
  .all-transactions-page .all-transactions-quantity { text-align: center !important; }
  .all-transactions-page .all-transactions-reference-cell { white-space: nowrap !important; }
  .all-transactions-page .report-system-records-card { padding: 14px 16px !important; }
  .all-transactions-page .report-system-records-header { margin-bottom: 11px !important; }
  @media (max-width: 720px) {
    .all-transactions-page .report-system-records-card { padding: 11px !important; }
  }
}
</style>

<style>
/* SPMU-COMPACT-REPORTS START */
/* Screen UI only. Formal printed/exported report sheets are not changed. */
@media screen {
  .reporting-workspace.reporting-detail .page-heading { margin-bottom: 14px !important; }
  .reporting-workspace.reporting-detail .page-heading h1 { font-size: 21px !important; }
  .reporting-workspace.reporting-detail .reports-detail-page { gap: 12px !important; }
  .reporting-workspace.reporting-detail .report-builder-card { padding: 18px 20px !important; }
  .reporting-workspace.reporting-detail .report-builder-primary { gap: 12px !important; margin-top: 10px !important; }
  .reporting-workspace.reporting-detail .report-filters-panel { margin-top: 12px !important; padding: 12px 14px !important; gap: 10px !important; }
  .reporting-workspace.reporting-detail .report-filter-grid { gap: 12px !important; }
  .reporting-workspace.reporting-detail .report-builder-card .report-description { margin-top: 12px !important; }
  .reporting-workspace.reporting-detail .report-preview-bar { margin-bottom: 8px !important; }
  .reporting-workspace.reporting-detail .report-system-preview { gap: 12px !important; }
  .reporting-workspace.reporting-detail .report-system-summary-grid { gap: 10px !important; }
  .reporting-workspace.reporting-detail .report-system-summary-card { min-height: 78px !important; padding: 12px 14px !important; gap: 10px !important; }
  .reporting-workspace.reporting-detail .report-system-records-card { padding: 14px !important; }
  .reporting-workspace.reporting-detail .report-system-records-header { margin-bottom: 10px !important; }
  .reporting-workspace.reporting-detail .report-system-table th { padding-top: 10px !important; padding-bottom: 10px !important; font-size: 11px !important; }
  .reporting-workspace.reporting-detail .report-system-table td { padding-top: 10px !important; padding-bottom: 10px !important; font-size: 12px !important; line-height: 1.4 !important; }
  .reporting-workspace.reporting-detail .report-output-card,
  .reporting-workspace.reporting-detail .report-generator-card { padding: 18px 20px !important; }
  .reporting-workspace.reporting-detail .report-output-header { margin-bottom: 12px !important; }
  .reporting-workspace.reporting-detail .all-transactions-filters { padding: 14px 16px !important; }
}
@media screen and (max-width: 700px) {
  .reporting-workspace.reporting-detail .report-builder-card,
  .reporting-workspace.reporting-detail .report-output-card,
  .reporting-workspace.reporting-detail .report-generator-card { padding: 14px !important; }
  .reporting-workspace.reporting-detail .report-system-summary-card { min-height: 72px !important; }
}
/* SPMU-COMPACT-REPORTS END */
</style>

<style>
.report-cell-multiline { white-space: pre-line; }
.report-system-table thead th { vertical-align: middle; border: 1px solid var(--border, #ddd); }
.report-system-records-card > .doc-table-notes { padding: 12px 18px; color: var(--muted, #555); font-size: 12px; }
.report-system-records-card > .doc-table-notes p { margin: 3px 0; }
</style>
