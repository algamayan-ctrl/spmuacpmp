<style>
.gate-pass-browser {
    --gate-pass-blue: #0866df;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    container: gate-pass-records / inline-size;
    color: var(--text);
    font-size: 13px;

    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
}
.gate-pass-browser [hidden] { display: none !important; }

.gate-pass-browser > .gate-pass-empty-card { flex: 1 1 auto; }
.gate-pass-browser .gate-pass-heading { margin: 0 0 16px; }
.gate-pass-browser .gate-pass-heading .eyebrow { margin-bottom: 9px; font-size: 9.5px; letter-spacing: .1em; }
.gate-pass-browser .gate-pass-heading h1 { margin: 0 0 8px; font-size: clamp(21px, 1.65vw, 25px); }
.gate-pass-browser .gate-pass-heading p:not(.eyebrow) { max-width: none; margin: 0; font-size: 12.5px; line-height: 1.4; }
.gate-pass-browser .card { min-width: 0; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-elevated); box-shadow: var(--shadow-sm); }

.gate-pass-browser > #gate-pass-filters {
    display: grid;
    flex: 0 0 auto;
    height: auto;
    min-height: 0;
    grid-template-columns: minmax(0, 1.5fr) repeat(2, minmax(0, 1fr));
    gap: 10px;
    align-items: end;
    align-content: start;
    padding: 12px 16px;
    margin-bottom: 10px;
}
#gate-pass-filters > label { flex: none; gap: 0; min-width: 0; }
.gate-pass-toolbar label {
    min-width: 0;
    margin: 0;
    color: var(--heading);
    font-size: 12px;
    font-weight: 650;
}
.gate-pass-toolbar .search-input-shell,
.gate-pass-toolbar input,
.gate-pass-toolbar select { margin-top: 7px; }
.gate-pass-browser .gate-pass-toolbar input,
.gate-pass-browser .gate-pass-toolbar select {
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
    min-height: 40px;
    font-size: 12px;
}
.gate-pass-browser .gate-pass-toolbar .search-input-shell input { margin-top: 0; }
.gate-pass-browser .gate-pass-toolbar > .button { width: auto; min-width: 0; min-height: 40px; margin: 0; white-space: nowrap; }

.gate-pass-browser .gate-pass-records-card { padding: 12px 16px 4px; }
.gate-pass-records-heading { margin-bottom: 12px; }
.gate-pass-browser .gate-pass-records-heading h2 { margin: 0 0 6px; color: var(--heading); font-size: 17px; font-weight: 750; }
.gate-pass-records-heading p { margin: 0; color: var(--text-muted); font-size: 12px; }
.gate-pass-table-wrap { width: 100%; min-width: 0; overflow-x: auto; }
.gate-pass-table { width: 100%; min-width: 760px; margin: 0; border-collapse: collapse; }
.gate-pass-table th {
    padding: 10px 8px;
    color: var(--heading);
    background: var(--table-heading-bg);
    font-size: 11px;
    font-weight: 750;
    text-align: left;
    white-space: nowrap;
}
.gate-pass-table td {
    padding: 11px 8px;
    border-bottom: 1px solid var(--row-border);
    color: var(--text-secondary);
    font-size: 12px;
    line-height: 1.5;
    vertical-align: middle;
}
.gate-pass-table tr:last-child td { border-bottom: 0; }
.gate-pass-table th:nth-child(1) { width: 15%; }
.gate-pass-table th:nth-child(2) { width: 17%; }
.gate-pass-table th:nth-child(3) { width: 23%; }
.gate-pass-table th:nth-child(4) { width: 12%; }
.gate-pass-table th:nth-child(5) { width: 20%; }
.gate-pass-table th:nth-child(6) { width: 13%; }
.gate-pass-request-link { color: var(--gate-pass-blue); font-weight: 750; white-space: nowrap; text-decoration: none; }
.gate-pass-request-link:hover { color: var(--interactive-hover); text-decoration: underline; }
.gate-pass-table td strong, .gate-pass-table td small { display: block; }
.gate-pass-table td strong { color: var(--heading); font-size: 12px; font-weight: 650; }
.gate-pass-table td small { margin-top: 1px; color: var(--text-muted); font-size: 12px; }
.gate-pass-destination { overflow-wrap: anywhere; }
.gate-pass-release-date { white-space: nowrap; }
.gate-pass-table .status-badge { padding: 4px 10px; border: 0; border-radius: 999px; font-size: 10px; white-space: nowrap; }
.gate-pass-row-actions { display: flex; align-items: center; justify-content: flex-start; flex-wrap: wrap; gap: 6px; min-width: 0; }
.gate-pass-browser .gate-pass-view, .gate-pass-browser .gate-pass-file { flex-shrink: 0; white-space: nowrap; }

.gate-pass-more { position: relative; flex: 0 0 auto; }
.gate-pass-more summary { display: grid; place-items: center; width: 28px; height: 35px; margin-left: auto; color: var(--text-secondary); border-radius: 6px; list-style: none; cursor: pointer; }
.gate-pass-more summary::-webkit-details-marker { display: none; }
.gate-pass-more summary:hover, .gate-pass-more[open] summary { color: var(--gate-pass-blue); background: var(--info-bg); }
.gate-pass-more summary:focus-visible { outline: 2px solid var(--gate-pass-blue); outline-offset: 2px; }
.gate-pass-more-links {
    position: fixed;
    z-index: 1600;
    display: grid;
    min-width: 170px;
    max-width: 220px;
    margin: 0;
    padding: 5px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-elevated);
    box-shadow: var(--shadow-md, 0 10px 28px rgba(15, 23, 42, .16));
}
.gate-pass-more:not([open]) .gate-pass-more-links { display: none; }
.gate-pass-more-links a { padding: 7px 8px; border-radius: 4px; color: var(--text-secondary); font-size: 11px; }
.gate-pass-more-links a:hover, .gate-pass-more-links a:focus-visible { color: var(--gate-pass-blue); background: var(--info-bg); }

.gate-pass-filter-empty td { padding: 20px 12px; text-align: center; }
.gate-pass-filter-empty strong { color: var(--heading); font-size: 14px; }
.gate-pass-filter-empty p { margin: 6px 0 12px; color: var(--text-muted); }
.gate-pass-footer { display: flex; justify-content: flex-end; padding: 13px 0 9px; }
.gate-pass-pagination, .gate-pass-page-numbers { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; }
.gate-pass-pagination { margin-left: auto; }
.gate-pass-browser .gate-pass-page {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    min-width: 34px;
    height: 35px;
    padding: 4px;
    border: 1px solid var(--border);
    border-radius: 5px;
    color: var(--text-secondary);
    background: var(--surface-elevated);
    font-size: 12px;
}
.gate-pass-browser .gate-pass-page.is-current { color: #fff; border-color: #0866df; background: #0866df; font-weight: 700; }
.gate-pass-browser .gate-pass-page:not(:disabled):not(.is-current):hover { color: var(--gate-pass-blue); background: var(--info-bg); }
.gate-pass-browser .gate-pass-page:disabled { color: var(--text-soft); cursor: not-allowed; opacity: .5; transform: none; }
.gate-pass-previous-icon { transform: rotate(180deg); }
.gate-pass-page-ellipsis { padding: 0 2px; color: var(--text-muted); }

.gate-pass-browser .gate-pass-empty-card { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 218px; padding: 16px 16px 24px; text-align: center; }
.gate-pass-empty-illustration { display: block; flex-shrink: 0; width: 86px; height: 86px; margin: 0 0 12px; }
.gate-pass-browser .gate-pass-empty-card h2 { margin: 0 0 7px; color: var(--heading); font-size: 16px; font-weight: 750; line-height: 1.5; }
.gate-pass-browser .gate-pass-empty-card p { margin: 0; color: var(--text-muted); font-size: 13px; line-height: 1.5; }

html[data-theme="dark"] .gate-pass-browser { --gate-pass-blue: #72b7f4; }
@container gate-pass-records (min-width: 900px) {
    .gate-pass-browser > #gate-pass-filters {
        grid-template-columns: minmax(180px, 2.4fr) repeat(2, minmax(135px, 1.1fr)) minmax(110px, .85fr) minmax(130px, 1fr) max-content;
    }
}
@container gate-pass-records (max-width: 620px) {
    .gate-pass-browser > #gate-pass-filters { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; }
    .gate-pass-toolbar > label:first-child { grid-column: 1 / -1; }
}
@media (max-width: 700px) {
    .gate-pass-browser .gate-pass-records-card { padding-right: 12px; padding-left: 12px; }
}
@container gate-pass-records (max-width: 430px) {
    .gate-pass-browser > #gate-pass-filters { grid-template-columns: minmax(0, 1fr); }
    .gate-pass-toolbar > label:first-child { grid-column: auto; }
}

.gate-pass-browser .gate-pass-table th:last-child,
.gate-pass-browser .gate-pass-table td:last-child { text-align: center; }
.gate-pass-browser .gate-pass-row-actions { display: flex; justify-content: center; align-items: center; flex-wrap: nowrap; gap: 7px; overflow: visible; }
.gate-pass-browser .gate-pass-icon-action { position: relative; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 36px; width: 36px; height: 36px; box-sizing: border-box; padding: 0; border: 1px solid var(--border); border-radius: 7px; background: var(--surface-elevated); color: var(--gate-pass-blue); text-decoration: none; cursor: pointer; transition: background-color .15s ease, border-color .15s ease; }
.gate-pass-browser .gate-pass-icon-action svg { display: block; flex-shrink: 0; color: inherit; stroke: currentColor; }
.gate-pass-browser .gate-pass-icon-action:hover,
.gate-pass-browser .gate-pass-icon-action:focus-visible { background: var(--info-bg); border-color: var(--gate-pass-blue); color: var(--gate-pass-blue); }
.gate-pass-browser .gate-pass-icon-action:focus-visible { outline: 2px solid var(--gate-pass-blue); outline-offset: 2px; }
.gate-pass-browser .gate-pass-icon-action::after { content: attr(data-tooltip); pointer-events: none; position: absolute; left: 50%; bottom: calc(100% + 8px); transform: translateX(-50%) translateY(3px); white-space: nowrap; padding: 6px 9px; background: #172d49; color: #fff; border-radius: 5px; font-size: 11px; font-weight: 600; line-height: 1.25; opacity: 0; visibility: hidden; transition: opacity .12s ease, transform .12s ease; z-index: 30; box-shadow: 0 4px 14px rgba(0,0,0,.15); }
.gate-pass-browser .gate-pass-icon-action:hover::after,
.gate-pass-browser .gate-pass-icon-action:focus-visible::after { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
.gate-pass-browser .gate-pass-table-wrap { padding-top: 10px; margin-top: -10px; }
</style>
