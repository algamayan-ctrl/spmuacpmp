<style>
/* Keep the original Laundry page width; only reduce the space beneath
   the vertically stretched records panel and its pagination footer. */
.app-main:has(> .laundry-operations) { padding-bottom: 18px; }
.laundry-operations {
    --laundry-blue: var(--interactive, #0863db);
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    width: 100%;
    min-width: 0;
    min-height: max(0px, calc(100dvh - var(--topbar-height, 64px) - 150px));
    color: var(--text);
    font: inherit;
}
.laundry-operations [hidden] { display: none !important; }
.laundry-unified-workspace {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-width: 0;
    min-height: 0;
}
.laundry-operations [data-laundry-panel],
.laundry-completed-block {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-width: 0;
    min-height: 0;
}
.laundry-completed-block { margin: 0; scroll-margin-top: 86px; }
.laundry-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
}
.laundry-operations .laundry-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 36px;
    padding: 7px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text-secondary);
    background: var(--surface-elevated);
    font: inherit;
    font-size: 12px;
    font-weight: 750;
    line-height: 1.35;
    cursor: pointer;
    transition: background .15s ease, border-color .15s ease, color .15s ease;
}
.laundry-operations .laundry-tab:hover {
    border-color: var(--laundry-blue);
    color: var(--laundry-blue);
}
.laundry-operations .laundry-tab.is-selected {
    border-color: var(--laundry-blue);
    background: var(--laundry-blue);
    color: #fff;
}
.laundry-tab:focus-visible { outline: 2px solid var(--laundry-blue); outline-offset: 3px; }
.laundry-tab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    min-height: 20px;
    padding: 1px 6px;
    border-radius: 999px;
    background: var(--surface-subtle);
    color: var(--text-muted);
    font-size: 11px;
    line-height: 1;
}
.laundry-tab.is-selected .laundry-tab-count { background: rgba(255, 255, 255, .2); color: #fff; }
.laundry-operations .laundry-toolbar {
    display: grid;
    /* Search, status/result, sort and the Apply/Clear buttons stay on one row. */
    grid-template-columns: minmax(220px, 1fr) minmax(160px, 210px) minmax(140px, 185px) max-content;
    align-items: end;
    min-width: 0;
    margin-top: 0;
    margin-bottom: 12px;
    padding: 12px;
    gap: 10px;
}
.laundry-toolbar .record-table-actions {
    align-self: end;
    justify-content: flex-end;
    min-height: 36px;
}
.laundry-toolbar .record-table-actions .button {
    min-height: 36px;
    padding-inline: 12px;
}
.laundry-toolbar > label {
    display: grid;
    gap: 6px;
    min-width: 0;
    margin: 0;
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 800;
}
.laundry-toolbar .search-input-shell,
.laundry-toolbar select { margin: 0; }
.laundry-toolbar input,
.laundry-toolbar select { width: 100%; min-height: 36px; font-family: inherit; font-size: 12px; }
.laundry-operations .laundry-cases-card {
    min-width: 0;
    margin: 0;
    padding: 0;
    overflow: hidden;
}
.laundry-operations .laundry-cases-card:not(.is-empty) {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: max(330px, calc(100dvh - 410px));
}
.laundry-operations .laundry-cases-card:not(.is-empty) > .laundry-pagination {
    margin-top: auto;
}
.laundry-operations .laundry-cases-card.is-empty {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: max(300px, calc(100dvh - 410px));
}
.laundry-cases-heading { padding: 15px 16px 12px; }
.laundry-cases-heading h2 {
    margin: 0 0 4px;
    color: var(--heading);
    font-size: 15px;
    font-weight: 750;
    line-height: 1.35;
}
.laundry-cases-heading p { margin: 0; color: var(--text-muted); font-size: 12px; line-height: 1.4; }
.laundry-table-wrap { width: 100%; min-width: 0; max-width: 100%; overflow-x: auto; }
.laundry-cases-table { width: 100%; min-width: 0; margin: 0; table-layout: fixed; border-collapse: collapse; }
.laundry-cases-table th,
.laundry-cases-table td { padding: 10px 12px; text-align: left; overflow-wrap: anywhere; vertical-align: middle; }
.laundry-cases-table th {
    border-block: 1px solid var(--border);
    color: var(--text-secondary);
    background: var(--table-heading-bg);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
    line-height: 1.35;
}
.laundry-cases-table td { border-bottom: 1px solid var(--row-border); font-size: 12.5px; line-height: 1.35; }
.laundry-cases-table tr:last-child td { border-bottom: 0; }
.laundry-cases-table td > strong,
.laundry-cases-table td > small { display: block; }
.laundry-cases-table td > strong { color: var(--heading); font-weight: 600; }
.laundry-cases-table td > small { margin-top: 3px; color: var(--text-muted); font-size: 11.5px; }
.laundry-request-link { color: var(--heading); font-weight: 750; text-decoration: none; }
.laundry-request-link:hover { color: var(--laundry-blue); text-decoration: underline; }
.laundry-returned-date { white-space: nowrap; }
.laundry-cases-table .laundry-view-link { white-space: normal; text-align: center; }
.laundry-cases-table th:last-child,
.laundry-cases-table td:last-child { text-align: right; }
.laundry-cases-table th:nth-child(1) { width: 17%; }
.laundry-cases-table th:nth-child(2) { width: 17%; }
.laundry-cases-table th:nth-child(3) { width: 14%; }
.laundry-cases-table th:nth-child(4) { width: 16%; }
.laundry-cases-table th:nth-child(5) { width: 15%; }
.laundry-cases-table th:nth-child(6) { width: 10%; }
.laundry-cases-table th:nth-child(7) { width: 11%; }
.laundry-completed-table th:nth-child(1) { width: 24%; }
.laundry-completed-table th:nth-child(2) { width: 25%; }
.laundry-completed-table th:nth-child(3) { width: 8%; }
.laundry-completed-table th:nth-child(4) { width: 16%; }
.laundry-completed-table th:nth-child(5) { width: 15%; }
.laundry-completed-table th:nth-child(6) { width: 12%; }
.laundry-completed-date time,
.laundry-completed-date time > span { display: block; }
.laundry-completed-date time { white-space: nowrap; }
.laundry-outcomes { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.laundry-outcome-badge { display: inline-block; max-width: 100%; padding: 3px 9px; border: 1px solid transparent; border-radius: 999px; font-size: 11px; font-weight: 750; line-height: 1.35; }
.laundry-outcome-badge.is-available { color: var(--success); background: var(--success-bg); border-color: var(--success-border); }
.laundry-outcome-badge.is-accountability { color: var(--warning); background: var(--warning-bg); border-color: var(--warning-border); }
.laundry-outcome-badge.is-neutral { color: var(--text-muted); background: var(--surface-subtle); border-color: var(--border); }
.laundry-inline-empty {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    align-items: center;
    justify-content: center;
    gap: 18px;
    min-height: 300px;
    padding: 40px 22px;
    color: var(--text-muted);
    text-align: center;
}
.laundry-empty-icon { display: grid; place-items: center; flex-shrink: 0; width: 72px; height: 72px; border-radius: 50%; background: var(--info-bg); color: var(--laundry-blue); }
.laundry-inline-empty > div { max-width: 400px; }
.laundry-inline-empty strong { display: block; margin: 0 0 8px; color: var(--heading); font-size: 16px; font-weight: 750; line-height: 1.4; }
.laundry-inline-empty p { margin: 0; color: var(--text-secondary); font-size: 13px; line-height: 1.65; }
.laundry-filter-empty { display: flex; flex-direction: column; align-items: center; gap: 8px; min-height: 160px; padding: 28px 16px; color: var(--text-muted); text-align: center; }
.laundry-filter-empty strong { color: var(--heading); font-size: 14px; }
.laundry-filter-empty p { margin: 0; }
.laundry-pagination { padding: 12px 16px; border-top: 1px solid var(--border); }
@media (max-width: 1100px) {
    .laundry-operations .laundry-toolbar { grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, .9fr) max-content; }
    .laundry-cases-table th,
    .laundry-cases-table td { padding-inline: 9px; }
}
@media (max-width: 900px) {
    .laundry-cases-table { min-width: 860px; }
    .laundry-completed-table { min-width: 700px; }
}
@media (max-width: 760px) {
    .laundry-operations .laundry-toolbar { grid-template-columns: minmax(0, 1fr); }
    .laundry-toolbar .record-table-actions { justify-content: flex-start; }
    .laundry-toolbar .record-table-actions .button { flex: 1 1 auto; }
    .laundry-tabs { gap: 7px; }
    .laundry-inline-empty { padding: 30px 16px; }
    .laundry-operations { min-height: 0; }
    .laundry-operations .laundry-cases-card:not(.is-empty),
    .laundry-operations .laundry-cases-card.is-empty { min-height: 300px; }
}
</style>
