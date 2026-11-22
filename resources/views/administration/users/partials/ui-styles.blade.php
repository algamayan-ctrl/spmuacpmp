<style>

.app-main.ictu-admin-main {
    width: 100%;
    max-width: none;
    margin: 0;
    padding: 16px clamp(16px, 2.2vw, 38px) 42px;
    overflow-x: hidden;
}
.app-topbar .topbar-title { display: grid; min-width: 0; gap: 5px; }
.app-topbar .ictu-topbar-subtitle {
    color: var(--text-muted);
    font-size: 12.5px;
    font-weight: 400;
    line-height: 1.4;
}
.ictu-users-area, .ictu-user-form-area { width: 100%; min-width: 0; margin: 0; }
.ictu-users-area { display: grid; gap: 16px; }
.ictu-users-area :is(input, select, button),
.ictu-user-form :is(input, select, button) { font-family: inherit; }
.ictu-users-area :is(button, a, select, input):focus-visible,
.ictu-user-form :is(button, a, select, input):focus-visible {
    outline: 2px solid var(--interactive);
    outline-offset: 2px;
}

.ictu-users-filter-card, .ictu-users-list-card {
    min-width: 0;
    margin: 0;
    padding: 0;
    overflow: hidden;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow-sm);
}
.ictu-users-filter-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    align-items: end;
    gap: 16px;
    padding: 16px;
}
.ictu-users-filter-field { display: grid; min-width: 0; gap: 9px; }
.ictu-users-filter-field > label {
    display: block;
    margin: 0;
    color: var(--heading);
    font-size: 13px;
    font-weight: 700;
}
.ictu-users-filter-field :is(input, select),
.ictu-users-borrowing-actions > button {
    width: 100%;
    min-width: 0;
    height: 48px;
    min-height: 48px;
    border-radius: 9px;
    font-size: 13.5px;
}
.ictu-users-search-control { position: relative; min-width: 0; }
.ictu-users-search-control > .ui-icon {
    position: absolute;
    z-index: 1;
    top: 50%; left: 14px;
    transform: translateY(-50%);
    color: var(--text-muted);
    pointer-events: none;
}
.ictu-users-search-control input { padding-left: 43px; }
.ictu-users-borrowing-actions {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
    min-width: 0;
}
.ictu-users-borrowing-actions > .ictu-users-reset {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: auto;
    gap: 7px;
    padding: 0 12px;
    white-space: nowrap;
    color: var(--text-secondary);
    border: 1px solid var(--border-strong);
}
.ictu-users-reset:hover { color: var(--interactive); border-color: var(--interactive); }

.ictu-users-list-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px 16px;
    min-height: 76px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}
.ictu-users-list-title { display: inline-flex; align-items: center; gap: 12px; min-width: 0; }
.ictu-users-list-title h2 {
    margin: 0;
    font-size: 17px;
    font-weight: 750;
    line-height: 1.3;
}
.ictu-users-total-pill, .ictu-optional-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 11px;
    border-radius: 100px;
    background: var(--surface-muted);
    border: 1px solid var(--border);
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 650;
    white-space: nowrap;
}
.ictu-users-pagination-meta {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 650;
}
.ictu-users-pagination-meta > select {
    width: auto;
    min-width: 134px;
    min-height: 38px;
    padding: 6px 24px 6px 10px;
    border-radius: 8px;
    color: var(--text-secondary);
    font-size: 12px;
}
.ictu-users-pagination-divider { height: 23px; width: 1px; background: var(--border); }
.ictu-users-pagination-buttons { display: inline-flex; align-items: center; gap: 4px; }
.ictu-users-page-numbers { display: inline-flex; align-items: center; gap: 4px; }
.ictu-users-page-button {
    display: inline-grid;
    place-items: center;
    flex: none;
    min-width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid var(--border-strong);
    border-radius: 7px;
    background: var(--surface-elevated);
    box-shadow: none;
    color: var(--text-secondary);
    cursor: pointer;
}
.ictu-users-page-button:not(:disabled) .ui-icon { color: var(--interactive); }
.ictu-users-page-button.is-active { border-color: var(--interactive); background: var(--interactive); color: #fff; }
.ictu-users-page-button:hover:not(:disabled) { color: var(--interactive); border-color: var(--interactive); background: var(--interactive-soft); }
.ictu-users-page-button.is-active:hover:not(:disabled) { color: #fff; background: var(--interactive); }
.ictu-users-page-button:disabled { opacity: .5; cursor: not-allowed; }
.ictu-users-page-button:disabled .ui-icon { color: var(--text-soft); }
.ictu-users-page-button .is-rotated { transform: rotate(180deg); }
.ictu-users-table-scroll { width: 100%; max-width: 100%; overflow-x: auto; overscroll-behavior-x: contain; }
.ictu-users-table { width: 100%; min-width: 950px; table-layout: fixed; border-collapse: collapse; border-spacing: 0; }
.ictu-col-user { width: 26%; }
.ictu-col-number { width: 12%; }
.ictu-col-unit { width: 23%; }
.ictu-col-role { width: 15%; }
.ictu-col-status { width: 16%; }
.ictu-col-action { width: 8%; }
.ictu-users-table th {
    padding: 12px 12px;
    background: var(--table-heading-bg);
    border-bottom: 1px solid var(--border);
    color: var(--text-secondary);
    font-size: 11px;
    font-weight: 750;
    letter-spacing: .045em;
    text-transform: uppercase;
    line-height: 1.35;
    text-align: left;
}
.ictu-users-table td {
    padding: 12px;
    height: 98px;
    vertical-align: middle;
    border-bottom: 1px solid var(--row-border);
    color: var(--text-secondary);
    font-size: 13px;
    line-height: 1.45;
    overflow-wrap: anywhere;
}
.ictu-users-table tbody tr:last-child td { border-bottom: 0; }
.ictu-users-table tbody tr:not([hidden]):hover { background: var(--row-hover); }
.ictu-users-table tbody tr[hidden] { display: none !important; }
.ictu-user-identity-wrap { display: grid; grid-template-columns: 43px minmax(0, 1fr); align-items: center; gap: 12px; min-width: 0; }
.ictu-user-avatar {
    display: inline-grid;
    place-items: center;
    width: 43px;
    height: 43px;
    border-radius: 50%;
    background: var(--blue-50);
    color: var(--interactive);
    font-size: 13px;
    font-weight: 750;
    letter-spacing: .025em;
}
.ictu-user-identity-copy { min-width: 0; }
.ictu-user-identity-copy > :is(strong, span, a),
.ictu-user-unit-cell > :is(strong, span),
.ictu-user-access-cell > :is(strong, span) { display: block; min-width: 0; }
.ictu-user-identity-copy strong, .ictu-user-number-cell strong,
.ictu-user-unit-cell strong, .ictu-user-access-cell strong {
    color: var(--heading);
    font-size: 13px;
    font-weight: 700;
}
.ictu-user-identity-copy span, .ictu-user-identity-copy a,
.ictu-user-unit-cell span, .ictu-user-access-cell span {
    margin-top: 3px;
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.4;
}
.ictu-user-identity-copy a { text-decoration: none; overflow-wrap: anywhere; }
.ictu-user-identity-copy a:hover { color: var(--interactive); text-decoration: underline; }
.ictu-user-status-stack { display: grid; justify-items: start; align-content: center; gap: 5px; }
.ictu-user-status-stack .status-badge { margin: 0; padding: 4px 9px; font-size: 11px; line-height: 1.25; white-space: nowrap; }
.ictu-users-action-heading, .ictu-user-action-cell { text-align: center !important; }
.ictu-user-action-inline { display: inline-flex; justify-content: center; align-items: center; gap: 6px; }
.ictu-user-action-inline form { display: inline-flex; margin: 0; }
.ictu-user-icon-button {
    display: inline-grid !important;
    place-items: center;
    width: 38px !important;
    min-width: 38px !important;
    height: 38px !important;
    min-height: 38px !important;
    margin: 0 !important;
    padding: 0 !important;
    border: 1px solid transparent !important;
    border-radius: 9px !important;
    background: transparent !important;
    color: var(--text-secondary) !important;
    box-shadow: none !important;
    text-decoration: none;
    cursor: pointer;
}
.ictu-user-icon-button:hover { color: var(--interactive) !important; background: var(--surface-hover) !important; border-color: var(--border) !important; }
.ictu-user-icon-button-danger:hover { color: var(--danger) !important; background: var(--danger-bg) !important; }
.ictu-user-icon-button:disabled { opacity: .35; cursor: not-allowed; }
.ictu-users-empty-directory td, .ictu-users-no-results { padding: 20px !important; text-align: center; color: var(--text-muted); font-size: 13px; }
.ictu-users-no-results[hidden] { display: none !important; }

.ictu-form-page-heading { margin: 0 0 16px; }
.ictu-form-page-heading p:not(.eyebrow) { max-width: 1020px; font-size: 13.5px; line-height: 1.55; }
.ictu-user-form { display: grid; width: 100%; min-width: 0; gap: 16px; }
.ictu-form-card {
    min-width: 0;
    width: 100%;
    margin: 0;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-elevated);
    box-shadow: var(--shadow-sm);
}
.ictu-form-card[hidden], .ictu-new-unit-field[hidden] { display: none !important; }
.ictu-form-section-header { display: grid; gap: 5px; margin: 0 0 22px; }
.ictu-form-section-header h2 { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin: 0; font-size: 17px; font-weight: 750; }
.ictu-form-section-header p { margin: 0; color: var(--text-muted); font-size: 12.5px; line-height: 1.5; }
.ictu-field-grid { display: grid; align-items: start; gap: 16px; min-width: 0; }
.ictu-field-grid-two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.ictu-field-grid-three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.ictu-field { display: grid; align-content: start; gap: 8px; min-width: 0; margin: 0; color: var(--text-secondary); font-size: 12.5px; font-weight: 700; }
.ictu-field > span, .ictu-field > label { display: block; min-width: 0; margin: 0; color: var(--text-secondary); font-size: 12.5px; font-weight: 700; }
.ictu-user-form .ictu-field :is(input:not([type="hidden"]), select) {
    display: block;
    width: 100%;
    min-width: 0;
    max-width: none;
    height: 48px;
    min-height: 48px;
    margin: 0;
    padding: 10px 13px;
    border: 1px solid var(--border-strong);
    border-radius: 9px;
    background-color: var(--input-bg);
    color: var(--text);
    font-size: 13.5px;
    font-weight: 400;
}
.ictu-user-form .ictu-field select { padding-right: 32px; }
.ictu-user-form .ictu-field :is(input, select):focus { border-color: var(--interactive); box-shadow: var(--focus-ring); }
.ictu-user-form .ictu-field select:disabled { cursor: not-allowed; }
.ictu-user-form .ictu-field .field-note, .ictu-form-card > .field-note { margin: 0; color: var(--text-muted); font-size: 11.5px; font-weight: 400; line-height: 1.5; }
.ictu-user-form .ictu-field .field-error, .ictu-form-card > .field-error { margin: 0; color: var(--danger); font-size: 12px; font-weight: 550; }
.ictu-new-unit-field { grid-column: 1 / -1; }
.ictu-email-field { align-content: start; }
.ictu-identity-heading-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-width: 0;
}
.ictu-identity-heading-row h2 { min-width: 0; }
.ictu-email-verification-status {
    display: inline-flex;
    align-items: center;
    flex: none;
    min-height: 22px;
    padding: 2px 9px;
    border: 1px solid #f0bd5b;
    border-radius: 999px;
    background: #fff8e8;
    color: #9a6200;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.25;
    white-space: nowrap;
}
.ictu-email-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-width: 0;
}
.ictu-email-label-row > label {
    margin: 0;
    color: var(--text-secondary);
    font-size: 12.5px;
    font-weight: 700;
}
.ictu-email-verification-link {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    flex: none;
    appearance: none;
    width: auto !important;
    height: auto !important;
    min-width: 0 !important;
    min-height: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
    color: #0d6efd !important;
    font-size: 12.5px;
    font-weight: 700;
    line-height: 1.35;
    text-decoration: none;
    cursor: pointer;
    gap: 5px;
}
.ictu-email-verification-link:hover {
    color: #084298 !important;
    border: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
    text-decoration: underline;
    text-underline-offset: 3px;
}
.ictu-email-verification-link:focus-visible {
    outline: 2px solid var(--interactive);
    outline-offset: 3px;
}
@media (max-width: 560px) {
    .ictu-identity-heading-row, .ictu-email-label-row { align-items: flex-start; flex-wrap: wrap; }
}
.admin-additional-assignment-picker { display: grid; grid-template-columns: minmax(0, 85fr) minmax(145px, 15fr); align-items: end; gap: 12px; }
.ictu-add-unit-button { display: inline-flex; align-items: center; justify-content: center; gap: 7px; width: 100%; height: 48px; min-height: 48px; padding: 0 12px; border-radius: 9px; white-space: nowrap; font-size: 13px; }
.admin-additional-unit-list { display: grid; gap: 9px; margin-top: 12px; }
.admin-additional-unit-list:empty { display: none; }
.admin-additional-unit-item { display: flex; min-width: 0; align-items: center; justify-content: space-between; gap: 12px; padding: 11px 12px; background: var(--surface-subtle); border: 1px solid var(--border); border-radius: 9px; }
.admin-additional-unit-item > div { display: grid; gap: 2px; min-width: 0; }
.admin-additional-unit-item strong { font-size: 12.5px; font-weight: 650; color: var(--heading); overflow-wrap: anywhere; }
.admin-additional-unit-item small { font-size: 11.5px; font-weight: 400; color: var(--text-muted); }
.admin-additional-unit-item .button { flex: none; width: auto; min-height: 34px; padding: 5px 12px; font-size: 12px; }
.ictu-form-actions { display: flex; align-items: center; justify-content: flex-start; flex-wrap: wrap; gap: 12px; padding: 2px 0 8px; }
.ictu-form-actions .button { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: auto; min-height: 46px; min-width: 134px; padding: 10px 19px; margin: 0; border-radius: 9px; font-size: 13.5px; font-weight: 700; text-decoration: none; }

.ictu-shell {
    --sidebar-width: 270px;
    --topbar-height: 89px;
    --content-gutter: 20px;
}
.ictu-shell .app-stage { min-width: 0; background: #f5faff; }
.ictu-shell .app-sidebar { background: #eaf5ff !important; }
.ictu-shell .sidebar-brand-row { min-height: 86px; padding: 9px 12px; border-bottom: 0; }
.ictu-shell .sidebar-brand.sidebar-brand-logo { min-height: 55px; padding: 3px 8px; justify-content: flex-start; }
.ictu-shell .sidebar-brand-logo-image { max-width: 220px; max-height: 59px; object-position: left center; }
.ictu-shell .sidebar-label { margin: 11px 16px 12px !important; font-size: 11px !important; letter-spacing: .16em !important; }
.ictu-shell .sidebar-nav { gap: 7px; padding: 0 12px 12px; }
.ictu-shell .sidebar-nav a { min-height: 51px; padding: 0 12px; gap: 12px; font-size: 13px; border-radius: 12px; font-weight: 680; }
.ictu-shell .sidebar-nav a .nav-icon { width: 23px; min-width: 23px; }
.ictu-shell .sidebar-nav a .nav-icon svg { width: 20px; height: 20px; }
.ictu-shell .sidebar-nav a.active, .ictu-shell .sidebar-nav a[aria-current="page"] {
    background: #1869e7 !important; border-color: #1869e7 !important; box-shadow: 0 6px 13px rgba(16,100,221,.1);
}
.ictu-shell .app-topbar { min-height: 89px; padding: 10px 16px; }
.ictu-shell .topbar-title { gap: 3px; }
.ictu-shell .topbar-title strong { font-size: 21px; font-weight: 760; line-height: 1.15; letter-spacing: -.02em; }
.ictu-shell .ictu-topbar-subtitle { font-size: 12.5px; line-height: 1.35; }
.ictu-shell .account-nav { gap: 11px; }
.ictu-shell .account-menu-name { max-width: 220px; font-size: 12.5px; font-weight: 680; }
.ictu-shell .account-menu-toggle { gap: 9px; }
.ictu-shell .account-menu-toggle .user-avatar { width: 43px; height: 43px; flex-basis: 43px; background: #1b3c62; font-size: 12px; }
.ictu-shell .notification-control .ui-icon { width: 21px; height: 21px; }
.app-main.ictu-admin-main { width: 100%; max-width: none; min-width: 0; margin: 0; padding: 12px 16px 38px; }

.ictu-users-heading {
    display: grid; grid-template-columns: minmax(0,1fr) auto; grid-auto-flow: row dense;
    align-items: center; column-gap: 12px; row-gap: 0; width: 100%; margin: 0 0 12px;
}
.ictu-users-heading .eyebrow { font-size: 10.5px; letter-spacing: .12em; margin: 0 0 3px; }
.ictu-users-heading h1 { font-size: clamp(21px, 1.8vw, 25px); line-height: 1.2; margin: 0 0 4px; }
.ictu-users-heading p:not(.eyebrow) { font-size: 12.5px; line-height: 1.4; max-width: 980px; }
.ictu-users-heading .ictu-register-button {
    display: inline-flex; align-items: center; justify-content: center; justify-self: end;
    width: auto; min-width: 0; min-height: 40px; height: 40px; padding: 0 12px; margin: 0;
    border: 1px solid #1769e7; border-radius: 9px; color: #fff; background: #1769e7;
    font-size: 12.5px; line-height: 1; font-weight: 750; text-decoration: none; white-space: nowrap;
}
.ictu-users-heading .ictu-register-button:hover { color: #fff; background: #105bd3; border-color: #105bd3; }
.ictu-users-heading .ictu-register-button:focus-visible { outline: 2px solid #1769e7; outline-offset: 3px; }
.ictu-users-area { display: grid; width: 100%; min-width: 0; align-content: start; gap: 12px; margin: 0 !important; }
.ictu-users-filter-card, .ictu-users-list-card {
    min-width: 0; margin: 0; padding: 0; overflow: hidden;
    background: var(--surface-elevated); border: 1px solid #d2dff0;
    border-radius: 10px; box-shadow: 0 4px 14px rgba(25,70,126,.035);
}
.ictu-users-filter-grid {
    display: grid; grid-template-columns: minmax(0,2.08fr) minmax(0,1fr) minmax(0,1fr) minmax(0,1.64fr);
    align-items: end; gap: 13px; min-width: 0; padding: 12px 19px;
}
.ictu-users-filter-field { min-width: 0; gap: 7px; }
.ictu-users-filter-field > label { margin: 0; color: var(--heading); font-size: 12px; font-weight: 750; }
.ictu-users-filter-field :is(input,select), .ictu-users-borrowing-actions > button {
    width: 100%; min-width: 0; height: 42px; min-height: 42px; margin: 0;
    border: 1px solid #cbdaf1; border-radius: 8px; font-size: 12.5px;
}
.ictu-users-filter-field :is(input,select) { padding-top: 8px; padding-bottom: 8px; }
.ictu-users-search-control .ui-icon { left: 14px; width: 18px; height: 18px; color: #5d7595; }
.ictu-users-search-control input { padding-left: 41px; }
.ictu-users-borrowing-actions { display: grid; grid-template-columns: minmax(0,1fr) 90px; gap: 9px; align-items: center; min-width: 0; }
.ictu-users-borrowing-actions .ictu-users-reset { width: 100%; min-width: 0; padding: 0 10px; gap: 6px; font-weight: 700; }
.ictu-users-reset .ui-icon { width: 15px; height: 15px; }

.ictu-users-list-toolbar { min-height: 56px; padding: 8px 19px; gap: 9px 12px; }
.ictu-users-list-title { gap: 10px; }
.ictu-users-list-title h2 { font-size: 17px; font-weight: 760; }
.ictu-users-total-pill { padding: 3px 10px; font-size: 11.5px; }
.ictu-users-pagination-meta { gap: 9px; font-size: 12px; font-weight: 500; }
.ictu-users-pagination-meta > select { width: auto; min-width: 128px; min-height: 32px; padding: 4px 27px 4px 8px; border: 0; background-color: transparent; font-size: 12px; }
.ictu-users-pagination-divider { height: 20px; }
.ictu-users-table-scroll { min-width: 0; width: 100%; max-width: 100%; overflow-x: auto; }
.ictu-users-table { width: 100%; min-width: 875px; table-layout: fixed; border-collapse: collapse; margin: 0; }
.ictu-col-user { width: 25%; }
.ictu-col-number { width: 12%; }
.ictu-col-unit { width: 23%; }
.ictu-col-role { width: 14%; }
.ictu-col-status { width: 16%; }
.ictu-col-action { width: 10%; }
.ictu-users-table th { padding: 11px 12px; background: #f2f8ff; font-size: 10px; font-weight: 780; letter-spacing: .055em; }
.ictu-users-table td { height: 78px; padding: 5px 12px; font-size: 12px; line-height: 1.27; overflow-wrap: anywhere; }
.ictu-user-identity-wrap { grid-template-columns: 42px minmax(0,1fr); gap: 10px; }
.ictu-user-avatar { width: 42px; height: 42px; font-size: 12.5px; background: #dfeaff; color: #1260d7; }
.ictu-users-table tbody tr:nth-child(7n+2) .ictu-user-avatar { background: #ddf6eb; color: #0f8058; }
.ictu-users-table tbody tr:nth-child(7n+3) .ictu-user-avatar { background: #dfeaff; color: #1963cf; }
.ictu-users-table tbody tr:nth-child(7n+4) .ictu-user-avatar { background: #ffdeec; color: #c82677; }
.ictu-users-table tbody tr:nth-child(7n+5) .ictu-user-avatar { background: #fff0dd; color: #a55a0f; }
.ictu-users-table tbody tr:nth-child(7n+6) .ictu-user-avatar { background: #efddff; color: #7329d3; }
.ictu-users-table tbody tr:nth-child(7n+7) .ictu-user-avatar { background: #d9f5ff; color: #176c96; }
.ictu-user-identity-copy strong, .ictu-user-number-cell strong, .ictu-user-unit-cell strong, .ictu-user-access-cell strong { font-size: 12px; line-height: 1.27; font-weight: 700; }
.ictu-user-identity-copy span, .ictu-user-identity-copy a, .ictu-user-unit-cell span, .ictu-user-access-cell span { font-size: 11.5px; line-height: 1.28; margin-top: 2px; }
.ictu-user-number-cell strong { white-space: nowrap; font-weight: 500; }
.ictu-user-status-stack { gap: 2px; }
.ictu-user-status-stack .status-badge { margin: 0; min-height: 19px; padding: 1px 8px; font-size: 11px; line-height: 1.25; font-weight: 600; }
.ictu-users-action-heading, .ictu-user-action-cell { text-align: center !important; }
.ictu-user-action-inline { display: inline-flex; align-items: center; justify-content: center; flex-wrap: nowrap; gap: 6px; white-space: nowrap; }
.ictu-user-action-inline form { display: inline-flex; margin: 0; padding: 0; background: transparent; border: 0; }
.ictu-user-icon-button { display: inline-grid !important; place-items: center; flex: 0 0 30px; width: 30px !important; min-width: 30px !important; height: 30px !important; min-height: 30px !important; margin: 0 !important; padding: 0 !important; border-radius: 7px !important; color: #506c94 !important; }
.ictu-user-icon-button .ui-icon { width: 17px; height: 17px; }
.ictu-user-deactivate-button:hover:not(:disabled) { color: #ad4d26 !important; background: #fff4ed !important; border-color: #f5d8bf !important; }
.ictu-user-icon-button:disabled { opacity: .32 !important; cursor: not-allowed !important; }
.ictu-users-table tbody tr[hidden] { display: none !important; }

.ictu-form-page-heading { width: 100%; margin: 0 0 12px; }
.ictu-form-page-heading h1 { font-size: clamp(21px,1.8vw,25px); }
.ictu-form-page-heading p:not(.eyebrow) { max-width: none; font-size: 12.5px; }
.ictu-user-form-area { width: 100%; max-width: none; margin: 0 !important; }
.ictu-user-form { gap: 12px; width: 100%; }
.ictu-form-card { width: 100%; padding: 16px 16px; border-radius: 10px; }
.ictu-form-section-header { gap: 4px; margin: 0 0 12px; }
.ictu-form-section-header h2 { font-size: 15px; }
.ictu-form-section-header p { font-size: 11.5px; }
.ictu-field-grid { gap: 12px 16px; }
.ictu-field-grid-two { grid-template-columns: repeat(2,minmax(0,1fr)); }
.ictu-field-grid-three { grid-template-columns: repeat(3,minmax(0,1fr)); }
.ictu-field { gap: 6px; }
.ictu-field > span,.ictu-field > label { font-size: 12px; }
.ictu-user-form .ictu-field :is(input:not([type="hidden"]),select) {
    width: 100%; min-width: 0; max-width: none; height: 43px; min-height: 43px;
    padding: 8px 12px; border-radius: 8px; font-size: 12.5px;
}
.ictu-user-form .ictu-field select { padding-right: 30px; }
.ictu-email-field-heading { display:flex; align-items:center; justify-content:space-between; gap:10px; min-width:0; }
.ictu-email-field-heading > label { margin:0; min-width:0; }
.ictu-email-verification-link {
    display:inline-flex; align-items:center; justify-content:flex-end; gap:3px;
    flex:0 0 auto; min-height:20px; margin:0; padding:0;
    border:0; background:transparent; color:var(--interactive);
    font:inherit; font-size:11px; font-weight:700; line-height:1.2;
    white-space:nowrap; cursor:pointer;
}
.ictu-email-verification-link:hover { text-decoration:underline; }
.ictu-email-verification-link .ui-icon { width:13px; height:13px; }
.ictu-email-verification-link:focus-visible { outline:2px solid var(--interactive); outline-offset:3px; border-radius:3px; }
.admin-additional-assignment-picker { grid-template-columns: minmax(0,85fr) minmax(132px,15fr); gap: 12px; }
.ictu-add-unit-button { height: 43px; min-height: 43px; border-radius: 8px; font-size: 12px; }
.ictu-form-actions { gap: 12px; }
.ictu-form-actions .button { min-height: 42px; min-width: 110px; padding: 8px 12px; border-radius: 8px; font-size: 12.5px; }

html[data-theme="dark"] .ictu-shell .app-stage { background: var(--surface); }
html[data-theme="dark"] .ictu-shell .app-sidebar { background: #070518 !important; }
html[data-theme="dark"] .ictu-users-table th { background: var(--table-heading-bg); }
html[data-theme="dark"] .ictu-users-filter-field :is(input,select), html[data-theme="dark"] .ictu-users-borrowing-actions > button { border-color: var(--border-strong); }
@media (min-width: 1001px) and (max-width: 1280px) {
    .ictu-shell { --sidebar-width: 225px; }
    .ictu-shell .sidebar-brand-row { padding-inline: 9px; }
    .ictu-shell .sidebar-brand-logo-image { max-width: 193px; }
    .ictu-shell .sidebar-nav { padding-inline: 9px; }
    .ictu-shell .sidebar-nav a { padding-inline: 10px; gap: 10px; font-size: 12px; }
}
@media (max-width: 1000px) {
    .ictu-users-filter-grid { grid-template-columns: minmax(0,1.45fr) minmax(0,1fr); }
    .ictu-field-grid-three { grid-template-columns: repeat(2,minmax(0,1fr)); }
    .ictu-field-grid-three > :last-child { grid-column: 1 / -1; }
}
@media (max-width: 560px) {
    .ictu-email-field-heading { align-items:flex-start; flex-direction:column; gap:4px; }
    .ictu-email-verification-link { align-self:flex-start; }
}
@media (max-width: 760px) {
    .ictu-shell { --sidebar-width: 270px; --topbar-height: 66px; }
    .ictu-shell .app-topbar { min-height: 66px; padding: 8px 13px; }
    .ictu-shell .topbar-title strong { font-size: 16px; }
    .ictu-shell .ictu-topbar-subtitle { display: none; }
    .app-main.ictu-admin-main { padding: 12px 12px 20px; }
    .ictu-users-heading { grid-template-columns: minmax(0,1fr) auto; }
    .ictu-users-heading h1 { font-size: 20px; }
    .ictu-users-heading p:not(.eyebrow) { font-size: 11.5px; }
    .ictu-users-filter-grid { gap: 13px; padding: 12px; }
    .ictu-users-list-toolbar { padding: 10px 13px; }
    .ictu-users-pagination-meta { width: 100%; justify-content: space-between; }
    .ictu-field-grid-two,.ictu-field-grid-three { grid-template-columns: minmax(0,1fr); }
    .ictu-field-grid-three > :last-child { grid-column: auto; }
    .ictu-form-card { padding: 12px; }
    .admin-additional-assignment-picker { grid-template-columns: minmax(0,1fr); }
}
@media (max-width: 550px) {
    .ictu-users-heading { display: flex; flex-direction: column; align-items: stretch; gap: 5px; }
    .ictu-users-heading > div:first-child { display: block; }
    .ictu-users-heading .ictu-register-button { align-self: flex-end; margin-top: 4px; }
    .ictu-users-filter-grid { grid-template-columns: minmax(0,1fr); }
    .ictu-users-borrowing-actions { grid-template-columns: minmax(0,1fr) 90px; }
    .ictu-users-list-title { flex-wrap: wrap; }
    .ictu-users-pagination-divider { display: none; }
    .ictu-shell .account-nav { gap: 3px; }
    .ictu-shell .account-menu-toggle .user-avatar { width: 34px; height: 34px; flex-basis: 34px; }
}
@media (prefers-reduced-motion: reduce) {
    .ictu-shell * { transition-duration: 0s !important; scroll-behavior: auto !important; }
}

.ictu-additional-selection-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
.ictu-additional-picker-actions { display:flex; justify-content:flex-end; margin-top:12px; }
.ictu-additional-picker-actions .ictu-add-unit-button { width:auto; min-width:122px; padding-inline:15px; }
.ictu-additional-picker-actions .ictu-add-unit-button:disabled { opacity:.48; cursor:not-allowed; }
.ictu-form-actions { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.ictu-administrative-actions-row { margin-top:12px; padding:0; align-items:center; }
.ictu-form-action-left { display:flex; align-items:center; flex-direction:row; flex-wrap:wrap; gap:8px; min-width:0; }
.ictu-account-action-note { display:inline-flex; align-items:center; gap:5px; max-width:100%; font-size:11px; font-weight:400; line-height:1.35; color:var(--text-muted); }
.ictu-account-action-note .ui-icon { flex:0 0 auto; }
.ictu-account-action-note.is-danger { color:#d92d20; }
.ictu-form-action-right { display:flex; align-items:center; justify-content:flex-end; gap:12px; margin-left:auto; }
.ictu-form-actions .ictu-delete-account-button { display:inline-flex; align-items:center; gap:8px; min-width:0; width:auto; color:#d92d20; border:1px solid #f0a4a0; background:#fff8f7; }
.ictu-form-actions .ictu-delete-account-button:hover { color:#b42318; border-color:#d92d20; background:#fff1f0; }
.ictu-form-actions .ictu-delete-account-button:focus-visible { outline:2px solid #d92d20; outline-offset:2px; }
html[data-theme="dark"] .ictu-form-actions .ictu-delete-account-button { background:rgba(217,45,32,.08); }
@media (max-width:760px) {
  .ictu-additional-selection-grid { grid-template-columns:minmax(0,1fr); }
  .ictu-form-action-left { width:100%; }
  .ictu-form-action-right { width:100%; margin-left:0; justify-content:flex-end; }
}
@media (max-width:440px) { .ictu-form-action-right { flex-wrap:wrap; } }

.ictu-user-form .ictu-field select,
.ictu-user-form .ictu-field select:disabled {
    background-repeat: no-repeat !important;
    background-position: right 13px center !important;
    background-size: 16px 16px !important;
}
.ictu-user-form .ictu-field select:disabled {
    background-color: var(--surface-muted);
    background-image: var(--select-chevron);
}

.ictu-shell .app-topbar {
    background-color: var(--surface-elevated) !important;
}

.ictu-users-filter-grid {
    grid-template-columns: minmax(260px, 2fr) repeat(3, minmax(145px, 1fr)) minmax(145px, 1fr) minmax(150px, 1fr) auto;
    gap: 10px;
    padding: 13px 12px;
}
.ictu-users-filter-field { gap: 5px; }
.ictu-users-filter-field > label { font-size: 11.5px; }
.ictu-users-filter-field :is(input, select),
.ictu-users-filter-actions .ictu-users-reset {
    height: 38px;
    min-height: 38px;
    font-size: 12px;
    border-radius: 8px;
}
.ictu-users-filter-actions { display: flex; align-items: end; }
.ictu-users-filter-actions .ictu-users-reset {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 82px;
    gap: 6px;
    padding: 0 10px;
    white-space: nowrap;
    color: var(--text-secondary);
    border: 1px solid var(--border-strong);
}
.ictu-users-filter-actions .ictu-users-reset:hover { color: var(--interactive); border-color: var(--interactive); }
.ictu-users-list-toolbar { min-height: 50px; padding: 7px 12px; }
.ictu-users-list-title h2 { font-size: 16px; }
.ictu-users-total-pill { padding: 2px 8px; font-size: 11px; }
.ictu-users-table th { padding: 9px 10px; font-size: 9.5px; }
.ictu-users-table td { height: 66px; padding: 4px 10px; font-size: 11.5px; line-height: 1.22; }
.ictu-user-identity-wrap { grid-template-columns: 36px minmax(0,1fr); gap: 8px; }
.ictu-user-avatar { width: 36px; height: 36px; font-size: 11.5px; }
.ictu-user-identity-copy strong,
.ictu-user-number-cell strong,
.ictu-user-unit-cell strong,
.ictu-user-access-cell strong { font-size: 11.5px; line-height: 1.2; }
.ictu-user-identity-copy span,
.ictu-user-identity-copy a,
.ictu-user-unit-cell span,
.ictu-user-access-cell span { font-size: 10.75px; line-height: 1.2; margin-top: 1px; }
.ictu-user-status-stack { gap: 2px; }
.ictu-user-status-stack .status-badge { min-height: 18px; padding: 1px 7px; font-size: 10.5px; }
.ictu-user-icon-button { width: 28px !important; min-width: 28px !important; height: 28px !important; min-height: 28px !important; }

@media (max-width: 1450px) {
    .ictu-users-filter-grid { grid-template-columns: minmax(240px, 2fr) repeat(3, minmax(140px, 1fr)); }
    .ictu-users-filter-actions { align-items: end; }
}
@media (max-width: 900px) {
    .ictu-users-filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ictu-users-filter-field-search { grid-column: 1 / -1; }
}
@media (max-width: 560px) {
    .ictu-users-filter-grid { grid-template-columns: minmax(0,1fr); }
    .ictu-users-filter-field-search { grid-column: auto; }
    .ictu-users-filter-actions .ictu-users-reset { width: 100%; }
}

.ictu-shell {
    --sidebar-width: 232px;
    --topbar-height: 64px;
}
.ictu-shell .sidebar-brand-row {
    min-height: 0;
    padding: 12px 12px 10px;
}
.ictu-shell .sidebar-brand.sidebar-brand-logo {
    min-height: 58px;
    padding: 6px 8px;
    justify-content: center;
}
.ictu-shell .sidebar-brand-logo-image {
    width: 100%;
    max-width: 182px;
    max-height: 50px;
    object-position: center;
}
.ictu-shell .sidebar-label {
    margin: 12px 12px 10px !important;
    font-size: .73rem !important;
    letter-spacing: .14em !important;
}
.ictu-shell .sidebar-nav {
    gap: 8px;
    padding: 0 10px 12px;
}
.ictu-shell .sidebar-nav a {
    min-height: 48px;
    padding: 0 12px;
    gap: 12px;
    border-radius: 14px;
    font-size: inherit;
}
.ictu-shell .sidebar-nav a .nav-icon {
    width: 18px;
    min-width: 18px;
}
.ictu-shell .sidebar-nav a .nav-icon svg {
    width: 18px;
    height: 18px;
}
.ictu-shell .app-topbar {
    min-height: 64px;
    padding: 10px 16px;
}
.ictu-shell .topbar-title strong {
    font-size: 14px;
    line-height: 1.2;
    font-weight: 700;
    letter-spacing: 0;
}
.ictu-shell .account-nav { gap: 10px; }
.ictu-shell .account-menu-name { font-size: inherit; }
.ictu-shell .account-menu-toggle .user-avatar {
    width: 38px;
    height: 38px;
    flex-basis: 38px;
}
.ictu-shell .notification-control .ui-icon {
    width: 18px;
    height: 18px;
}

.ictu-users-filter-grid {
    position: relative;
    grid-template-columns: minmax(300px, 2.2fr) repeat(3, minmax(145px, 1fr)) auto;
    gap: 10px;
    padding: 12px 12px;
}
.ictu-users-filter-actions {
    position: relative;
    display: flex;
    align-items: end;
}
.ictu-users-more {
    height: 38px;
    min-height: 38px;
    width: auto;
    min-width: 118px;
    padding: 0 12px;
    gap: 7px;
    white-space: nowrap;
    font-size: 12px;
    border-radius: 8px;
}
.ictu-users-more-chevron { transition: transform .16s ease; }
.ictu-users-more.is-active .ictu-users-more-chevron { transform: rotate(90deg); }
.ictu-users-extra-filters {
    position: absolute;
    z-index: 30;
    top: calc(100% + 8px);
    right: 0;
    width: min(320px, calc(100vw - 40px));
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-elevated);
    box-shadow: 0 12px 30px rgba(23, 50, 83, .14);
}
.ictu-users-extra-filters[hidden] { display: none !important; }
.ictu-users-extra-heading {
    margin: 0 0 10px;
    color: var(--heading);
    font-size: 12.5px;
    font-weight: 750;
}
.ictu-users-extra-filters .ictu-users-filter-field + .ictu-users-filter-field { margin-top: 10px; }
.ictu-users-extra-filters .ictu-users-reset {
    display: inline;
    width: auto;
    min-width: 0;
    height: auto;
    min-height: 0;
    margin: 12px 0 0;
    padding: 0;
    border: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
    color: #1677ff !important;
    font-size: 12px;
    font-weight: 750;
    line-height: 1.35;
    text-decoration: none;
    cursor: pointer;
}
.ictu-users-extra-filters .ictu-users-reset:hover,
.ictu-users-extra-filters .ictu-users-reset:focus-visible {
    color: #0b5fd7 !important;
    text-decoration: underline;
}
@media (max-width: 1100px) {
    .ictu-users-filter-grid { grid-template-columns: minmax(240px, 1.7fr) repeat(2, minmax(140px, 1fr)); }
    .ictu-users-filter-field-search { grid-column: 1 / -1; }
}
@media (max-width: 760px) {
    .ictu-shell { --sidebar-width: 232px; --topbar-height: 60px; }
    .ictu-shell .app-topbar { min-height: 60px; padding: 8px 13px; }
    .ictu-shell .topbar-title strong { font-size: 14px; }
}
@media (max-width: 620px) {
    .ictu-users-filter-grid { grid-template-columns: minmax(0, 1fr); }
    .ictu-users-filter-field-search { grid-column: auto; }
    .ictu-users-more { width: 100%; }
    .ictu-users-filter-actions { width: 100%; }
    .ictu-users-extra-filters { left: 0; right: auto; width: 100%; }
}

.ictu-form-actions .button.primary:hover,
.ictu-form-actions .button.primary:focus-visible {
    color: #fff;
    background: var(--primary-action-hover);
    border-color: var(--primary-action-hover);
    box-shadow: var(--shadow-sm);
}

.ictu-password-input-wrap {
    position: relative;
    display: block;
    width: 100%;
}
.ictu-password-input-wrap > input {
    width: 100%;
    padding-right: 52px !important;
}
.ictu-password-toggle {
    position: absolute;
    top: 50%;
    right: 10px;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    min-width: 38px;
    min-height: 38px;
    padding: 0;
    margin: 0;
    border: 0 !important;
    border-radius: 50%;
    background: transparent !important;
    box-shadow: none !important;
    color: #405674;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
}
.ictu-password-toggle svg {
    display: block;
    width: 20px;
    height: 20px;
    pointer-events: none;
}
.ictu-password-toggle svg[hidden] { display: none !important; }
.ictu-password-toggle:hover {
    color: #173a67;
    background: transparent !important;
}
.ictu-password-toggle:focus-visible {
    outline: 2px solid var(--primary, #1f6feb);
    outline-offset: 1px;
}
html[data-theme="dark"] .ictu-password-toggle { color: #a9bad0; }

</style>
