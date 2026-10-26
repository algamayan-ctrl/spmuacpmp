<style>
/* Release-only scope: borrower records, return inspection and oversight stay unchanged. */
.release-flow-page {
    --release-blue: #0863db;
    --release-green: #08a536;
    --release-actions-width: 96px;
    font-size: 13px;
}
.release-flow-page [hidden] { display: none !important; }
.release-flow-page .release-page-heading {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(330px, .95fr);
    align-items: center;
    gap: 18px;
    margin-bottom: 14px;
}
/* The global heading utility uses display:contents so actions align to the title
   row. This release header is a true two-column summary; keep its copy grouped
   so the status card cannot create a tall empty row between the title and ID. */
.release-flow-page .release-page-heading > div:first-child {
    display: block;
    min-width: 0;
}
.release-flow-page .release-page-heading .release-eyebrow {
    margin: 2px 0 8px;
    color: var(--release-blue);
    font-size: 11px;
    font-weight: 750;
}
.release-flow-page .release-page-heading h1 {
    margin: 0 0 4px;
    font-size: clamp(26px, 2.65vw, 34px);
    font-weight: 750;
    overflow-wrap: anywhere;
}
.release-flow-page .release-page-heading > div > p:last-child { max-width: 900px; margin: 0; font-size: 12.5px; line-height: 1.4; }
.release-flow-page .release-status-summary {
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface-elevated);
    box-shadow: var(--shadow-sm);
}
.release-status-heading {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--row-border);
}
.release-status-heading > .ui-icon { flex-shrink: 0; margin-top: 1px; }
.release-status-heading > div { display: grid; gap: 2px; }
.release-status-heading strong { font-size: 13px; font-weight: 750; }
.release-status-heading span { color: var(--text-muted); font-size: 12px; }
.release-status-heading--success { color: #078f2c; background: color-mix(in srgb, var(--success-bg) 35%, var(--surface-elevated)); }
.release-status-heading--info { color: var(--info); background: var(--info-bg); }
.release-status-heading--warning { color: var(--warning); background: var(--warning-bg); }
.release-status-dates {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(95px, 1fr);
    padding: 13px 16px;
}
.release-status-dates > div { display: flex; align-items: flex-start; gap: 8px; min-width: 0; }
.release-status-dates > div + div { margin-left: 12px; padding-left: 14px; border-left: 1px solid var(--row-border); }
.release-status-dates .ui-icon { flex-shrink: 0; color: #397fe4; margin-top: 2px; }
.release-status-dates strong, .release-status-dates span { display: block; color: var(--heading); font-size: 11px; line-height: 1.5; }
.release-status-dates span { font-weight: 600; }
.release-flow-page .request-tracker-card { margin-top: 0; }
.release-flow-page .request-tracker { padding: 10px 10px 8px; }
.release-flow-page .request-tracker__scroll { padding: 4px 0 2px; }
.release-flow-page .request-tracker__rail { min-width: 0; }
.release-flow-page .request-tracker__step { padding: 0 4px; }
.release-flow-page .request-tracker__marker { width: 36px; height: 36px; margin-bottom: 12px; }
.release-flow-page .request-tracker__marker > svg { width: 20px; height: 20px; }
.release-flow-page .request-tracker__step::after { top: 18px; left: calc(50% + 22px); width: calc(100% - 44px); height: 2px; }
.release-flow-page .request-tracker__step.is-complete::after { background: var(--release-green); }
.release-flow-page .request-tracker__step.is-complete .request-tracker__marker { background: linear-gradient(180deg, #0aae42, #069329); border-color: #099c32; color: #fff; }
.release-flow-page .request-tracker__step.is-current .request-tracker__marker { background: var(--release-blue); border-color: var(--release-blue); color: #fff; box-shadow: 0 0 0 4px var(--surface-elevated), 0 0 0 6px var(--info-bg); }
.release-flow-page .request-tracker__step.is-current .request-tracker__copy strong { color: var(--release-blue); }
.release-flow-page .request-tracker__copy strong { max-width: 90px; font-size: 12px; line-height: 1.5; }
.release-flow-page .request-tracker__copy time,
.release-flow-page .request-tracker__pending-label { display: none; }
.release-flow-page .request-tracker__step.is-pending .request-tracker__copy { opacity: 1; }
.release-flow-page .request-tracker__step.is-pending .request-tracker__copy strong { color: var(--text-muted); }
.release-flow-page .content-area, .release-flow-page .content-grid { margin-top: 16px; }
.release-flow-page .release-overview-grid { grid-template-columns: minmax(0, 1.08fr) minmax(0, .92fr); gap: 16px; align-items: stretch; }
.release-flow-page .release-context-card { padding: 15px 14px 12px; }
.release-flow-page .release-details-columns { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.release-flow-page .release-detail-group { min-width: 0; }
.release-flow-page .release-detail-group h3 { margin: 0 8px 5px; color: var(--text-muted); font-size: 10.5px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
.release-flow-page .release-card-title { display: flex; align-items: center; gap: 9px; margin-bottom: 13px; }
.release-card-title > .ui-icon { flex-shrink: 0; color: #5388ce; }
.release-flow-page .release-card-title h2 { margin: 0; font-size: 13px; font-weight: 800; text-transform: uppercase; line-height: 1.4; }
.release-flow-page .release-context-card .release-card-title { padding: 0 10px 13px; border-bottom: 1px solid var(--row-border); }
.release-flow-page .release-context-list { grid-template-columns: minmax(105px, .9fr) minmax(0, 1fr); margin: 0 8px; }
.release-flow-page .release-context-list dt,
.release-flow-page .release-context-list dd { padding: 6px 0; border-bottom: 1px solid var(--row-border); font-size: 11.5px; line-height: 1.4; overflow-wrap: anywhere; }
.release-flow-page .release-context-list dt { padding-right: 10px; }
.release-flow-page .release-context-list dt:last-of-type,
.release-flow-page .release-context-list dd:last-of-type { border-bottom: 0; }
.release-flow-page .release-approved-card,
.release-flow-page .release-schedule-exception-card,
.release-flow-page .release-documents-card,
.release-flow-page .release-physical-card { padding: 15px 14px 13px; }
.release-flow-page .release-documents-card { min-width: 0; }
.release-flow-page .table-wrap { overflow-x: auto; box-shadow: none; }
.release-flow-page .release-approved-table { min-width: 480px; }
.release-flow-page .release-approved-table th { font-size: 11px; letter-spacing: 0; padding: 8px 13px; }
.release-flow-page .release-approved-table td { padding: 10px 13px; font-size: 13px; line-height: 1.4; }
.release-flow-page .release-approved-table th:first-child { width: 46%; }
.release-flow-page .release-approved-table th:not(:first-child),
.release-flow-page .release-approved-table td:not(:first-child) { text-align: center; }
.release-approved-table td > strong, .release-approved-table td > small { display: block; }
.release-approved-table td > strong { font-size: 12px; color: var(--heading); }
.release-approved-table td > small { margin-top: 2px; font-size: 11px; }
.release-approved-actions { display: flex; justify-content: flex-end; margin-top: 14px; }
.release-flow-page .release-schedule-fields { margin-top: 0; gap: 16px; }
.release-flow-page .release-schedule-form { margin: 0; }
.release-flow-page .release-schedule-form > .meta { margin: 0 0 12px; color: var(--text-muted); font-size: 12px; line-height: 1.55; }
.release-flow-page .release-schedule-details { display: grid; gap: 8px; margin: 0; padding: 0; }
.release-flow-page .release-schedule-detail-row { display: grid; grid-template-columns: minmax(150px, 220px) minmax(0, 1fr); align-items: baseline; gap: 14px; padding: 10px 0; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
.release-flow-page .release-schedule-detail-label { color: var(--text-muted); font-size: 11.5px; font-weight: 700; }
.release-flow-page .release-schedule-detail-value { color: var(--heading); font-size: 12px; font-weight: 700; }
.release-flow-page .release-schedule-detail-help { margin: 0; color: var(--text-muted); font-size: 11.5px; line-height: 1.55; }
.release-flow-page .release-schedule-exception { margin: 12px 0 0; padding: 12px 0 0; border-top: 1px solid var(--border); background: transparent; color: var(--text); }
.release-flow-page .release-schedule-exception strong { color: var(--heading); }
.release-flow-page .release-schedule-exception p { margin: 5px 0 0; color: var(--text-muted); font-size: 11.5px; line-height: 1.55; }
.release-flow-page .release-schedule-exception .meta { color: var(--text-muted); }
.release-flow-page .release-schedule-exception-note { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border); }
.release-preparation-status { display: inline-flex; align-items: center; justify-content: center; min-height: 24px; padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 750; line-height: 1.3; white-space: nowrap; }
.release-preparation-status.is-pending { color: var(--text-muted); background: var(--surface-muted); }
.release-preparation-status.is-issue { color: var(--danger, #b42318); background: var(--danger-bg, #fff1f0); }
.release-preparation-status.is-recheck { color: var(--warning, #a35b00); background: var(--warning-bg, #fff4df); }
.release-preparation-status.is-complete { color: var(--success); background: var(--success-bg); }
.release-form-actions { display: flex; justify-content: flex-end; align-items: center; gap: 10px; padding-right: 40px; }
.release-document-row { display: grid; grid-template-columns: minmax(0, 1fr) var(--release-actions-width); align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--row-border); }
.release-document-row:last-child { border-bottom: 0; }
.release-document-copy { display: grid; grid-template-columns: 20px minmax(130px, .52fr) minmax(0, 1fr); align-items: center; gap: 9px; }
.release-document-copy > .ui-icon { color: #5388ce; }
.release-document-copy > strong { font-size: 12px; color: var(--heading); }
.release-document-copy > small { font-size: 12px; text-transform: none; }
.release-document-row > .button { justify-self: end; min-width: 56px; }
.release-flow-page .release-step-note { max-width: 900px; margin: 10px 0 0; color: var(--text-muted); font-size: 12px; line-height: 1.5; }
.release-flow-page .release-handover-form { margin-top: 10px; gap: 12px; }
.release-flow-page .release-handover-intro { max-width: 900px; margin: 0; font-size: 12px; line-height: 1.55; color: var(--text-muted); }
.release-flow-page .release-handover-form .checkbox { align-items: flex-start; gap: 10px; margin: 0; font-size: 12px; font-weight: 400; line-height: 1.45; color: var(--heading); }
.release-handover-form .checkbox > span { max-width: 960px; }
.release-handover-form input[type="checkbox"] { flex: 0 0 14px; width: 14px; height: 14px; min-height: 14px; margin-top: 2px; accent-color: var(--release-blue); }
.release-handover-footer { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: end; gap: 14px; }
.release-flow-page .release-handover-footer label { gap: 8px; color: var(--heading); font-size: 12px; font-weight: 700; line-height: 1.4; }
.release-flow-page .release-handover-footer textarea { min-height: 62px; padding: 10px 12px; border-color: var(--border); resize: vertical; font-size: 12px; }
.release-flow-page .release-handover-footer textarea:focus { border-color: var(--release-blue); }
.release-flow-page #confirm-physical-release-button { justify-self: end; width: auto; min-width: 190px; min-height: 42px; margin-top: 0; padding: 9px 16px; border-radius: 7px; color: #fff; border-color: #0863db; background: #0863db; font-size: 12px; }
.release-flow-page #confirm-physical-release-button:disabled { color: var(--text-soft); border-color: var(--border); background: var(--surface-elevated); opacity: 1; box-shadow: none; transform: none; }
.release-flow-page .button.release-primary { display: inline-flex; align-items: center; justify-content: center; gap: 9px; justify-self: end; min-height: 37px; padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; }
.release-flow-page .button.ui-pressable.release-primary:not(:disabled) { border-color: #0863db; background: #0863db; color: #fff; cursor: pointer; }
.release-flow-page .button.ui-pressable.release-primary:not(:disabled):hover,
.release-flow-page .button.ui-pressable.release-primary:not(:disabled):focus-visible { border-color: #0452bc !important; background: #0452bc !important; color: #fff !important; }
.release-flow-page .button.release-primary .ui-icon { color: inherit; flex-shrink: 0; }
.release-flow-page .button.release-outline { display: inline-flex; align-items: center; justify-content: center; gap: 9px; min-height: 37px; padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; }
.release-flow-page .button.ui-pressable.release-outline:not(:disabled) { border-color: var(--interactive); background: transparent; color: var(--interactive); cursor: pointer; }
.release-flow-page .button.ui-pressable.release-outline:not(:disabled):hover,
.release-flow-page .button.ui-pressable.release-outline:not(:disabled):focus-visible { border-color: var(--interactive) !important; background: var(--blue-50) !important; color: var(--interactive) !important; }
.release-flow-page .release-window-notice { margin-top: 10px; }
.release-flow-page #physical-release-availability { margin-bottom: 12px; }
html[data-theme="dark"] .release-flow-page { --release-blue: #72b7f4; }
html[data-theme="dark"] .release-status-heading--success { color: var(--success); }
html[data-theme="dark"] .release-flow-page .request-tracker__step.is-current .request-tracker__marker { background: #0863db; border-color: #0863db; }
@media (max-width: 1100px) {
    .release-flow-page .release-page-heading { grid-template-columns: 1fr; }
    /* The shared heading pins actions to column 2. Reset that placement when
       this summary stacks, otherwise Grid creates an implicit second column. */
    .release-flow-page .release-page-heading > .release-status-summary { grid-column: auto; }
    .release-flow-page .release-overview-grid { grid-template-columns: minmax(0, 1fr); }
    .release-flow-page .release-status-summary { width: 100%; max-width: 540px; justify-self: end; }
    .release-flow-page { --release-actions-width: 96px; }
}
@media (max-width: 850px) {
    .release-flow-page .release-details-columns { grid-template-columns: minmax(0, 1fr); }
    .release-flow-page .release-status-summary { max-width: none; }
    .release-document-copy { grid-template-columns: 20px minmax(0, 1fr); }
    .release-document-copy > small { grid-column: 2; }
}
@media (max-width: 640px) {
    .release-document-row { grid-template-columns: minmax(0, 1fr) auto; }
    .release-flow-page .release-schedule-fields { grid-template-columns: minmax(0, 1fr); }
    .release-flow-page .release-schedule-detail-row { grid-template-columns: minmax(0, 1fr); gap: 4px; }
    .release-flow-page .release-handover-form .checkbox { gap: 10px; }
    .release-handover-footer { grid-template-columns: minmax(0, 1fr); }
    .release-flow-page #confirm-physical-release-button { justify-self: stretch; width: 100%; }
    .release-form-actions { padding-right: 0; flex-wrap: wrap; }
    .release-approved-actions { justify-content: stretch; }
    .release-approved-actions form,
    .release-approved-actions .button { width: 100%; }
    .release-flow-page .release-context-list { grid-template-columns: minmax(120px, .95fr) minmax(0, 1fr); margin: 0; }
    .release-status-dates { grid-template-columns: minmax(0, 1.5fr) minmax(90px, 1fr); padding: 12px; }
}
@media (prefers-reduced-motion: reduce) {
    .release-flow-page *, .release-flow-page *::after { transition: none !important; }
}
</style>
