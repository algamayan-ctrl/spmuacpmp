@extends('layouts.app', ['title' => 'Dashboard'])
@section('content')
{{-- This is the Dashboard UI for Borrower, SPMU Action Officer, and SPMU Admin. --}}

@if($dashboardMode === 'BORROWER')
    @include('dashboard.partials.borrower-styles')
@endif

<style>
    .dashboard-heading { margin-bottom:12px; }
    .dashboard-heading > div > p:last-child { max-width:860px; }

    .dashboard-page-shell {
        display:flex;
        flex:1 1 auto;
        min-height:0;
        flex-direction:column;
    }

    .dashboard-stat-grid {
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:10px;
        width:100%;
    }
    .dashboard-kpi-card {
        --dashboard-card-accent:var(--info);
        --dashboard-icon-bg:var(--info-bg);
        --dashboard-icon-border:var(--info-border);
        position:relative;
        display:grid;
        grid-template-columns:42px minmax(0,1fr);
        grid-template-rows:auto auto auto;
        column-gap:12px;
        row-gap:2px;
        min-width:0;
        height:126px;
        min-height:126px;
        padding:12px 34px 12px 16px;
        overflow:hidden;
        border:1px solid var(--border);
        border-radius:12px;
        background:var(--surface-elevated);
        box-shadow:var(--shadow-sm);
        color:inherit;
        text-decoration:none;
        cursor:pointer;
        transition:transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease;
    }
    .dashboard-kpi-card::before {
        content:"";
        position:absolute;
        top:0;
        left:0;
        right:0;
        height:3px;
        background:var(--dashboard-card-accent);
        pointer-events:none;
    }

    .dashboard-kpi-card:hover {
        color:inherit;
        border-color:var(--border-strong);
        box-shadow:0 5px 14px rgba(7, 27, 53, .09);
        transform:translateY(-1px);
    }
    .dashboard-kpi-card:focus-visible {
        outline:none;
        box-shadow:var(--focus-ring);
    }
    .dashboard-kpi-card-arrow {
        position:absolute;
        top:16px;
        right:14px;
        color:var(--text-muted);
        opacity:.35;
        transition:opacity 160ms ease;
    }
    .dashboard-kpi-card:hover .dashboard-kpi-card-arrow,
    .dashboard-kpi-card:focus-visible .dashboard-kpi-card-arrow {
        opacity:.85;
    }
    @media (prefers-reduced-motion: reduce) {
        .dashboard-kpi-card { transition:none; }
        .dashboard-kpi-card:hover { transform:none; }
    }

    .dashboard-stat-grid > .dashboard-kpi-card {
        width:100%;
        max-width:none;
        align-self:stretch;
    }
        .dashboard-kpi-card.kpi-accent-info {
        --dashboard-card-accent:var(--info);
        --dashboard-icon-bg:var(--info-bg);
        --dashboard-icon-border:var(--info-border);
    }
    .dashboard-kpi-card.kpi-accent-warning {
        --dashboard-card-accent:var(--warning);
        --dashboard-icon-bg:var(--warning-bg);
        --dashboard-icon-border:var(--warning-border);
    }
    .dashboard-kpi-card.kpi-accent-danger {
        --dashboard-card-accent:var(--danger);
        --dashboard-icon-bg:var(--danger-bg);
        --dashboard-icon-border:var(--danger-border);
    }
    .dashboard-kpi-card.kpi-accent-success {
        --dashboard-card-accent:var(--success);
        --dashboard-icon-bg:var(--success-bg);
        --dashboard-icon-border:var(--success-border);
    }
    .dashboard-kpi-card.kpi-accent-neutral {
        --dashboard-card-accent:var(--text-soft);
        --dashboard-icon-bg:var(--surface-subtle);
        --dashboard-icon-border:var(--border);
    }
    .dashboard-kpi-card .kpi-icon {
        grid-column:1;
        grid-row:1 / span 3;
        display:grid;
        width:36px;
        height:36px;
        place-items:center;
        align-self:center;
        border:1px solid var(--dashboard-icon-border);
        border-radius:50%;
        background:var(--dashboard-icon-bg);
        color:var(--dashboard-card-accent);
    }
    .dashboard-kpi-card .kpi-label {
        grid-column:2;
        grid-row:1;
        align-self:end;
        color:var(--text-secondary);
        font-size:11px;
        font-weight:800;
        line-height:1.3;
        display:-webkit-box;
        -webkit-box-orient:vertical;
        -webkit-line-clamp:2;
        overflow:hidden;
    }
    .dashboard-kpi-card .kpi-value {
        grid-column:2;
        grid-row:2;
        color:var(--heading);
        font-size:24px;
        font-weight:800;
        line-height:1.12;
        font-variant-numeric:tabular-nums;
    }
    .dashboard-kpi-card .kpi-note {
        grid-column:2;
        grid-row:3;
        color:var(--text-muted);
        font-size:10px;
        line-height:1.35;
        display:-webkit-box;
        -webkit-box-orient:vertical;
        -webkit-line-clamp:2;
        overflow:hidden;
    }

    .dashboard-balanced-grid {
        display:grid;
        gap:12px;
        align-items:stretch;
    }
    .dashboard-balanced-grid > .dashboard-order-primary { order:1; }
    .dashboard-balanced-grid > .dashboard-order-secondary { order:2; }

    .borrower-dashboard-priority-stack {
        display:flex;
        flex-direction:column;
        min-width:0;
    }
    .borrower-dashboard-priority-stack > #borrower-actions { order:1; }
    .borrower-dashboard-priority-stack > .borrower-current-requests { order:2; }
    .borrower-dashboard-priority-stack.borrower-current-first > #borrower-actions { order:2; }
    .borrower-dashboard-priority-stack.borrower-current-first > .borrower-current-requests { order:1; }
    .dashboard-balanced-grid.dashboard-two-panel {
        grid-template-columns:repeat(2,minmax(0,1fr));

        flex:1 1 auto;
        min-height:320px;

    }
    .dashboard-balanced-grid.dashboard-single-panel,
    .dashboard-balanced-grid.borrower-actions-only {
        grid-template-columns:minmax(0,1fr);
    }
    .dashboard-balanced-grid > .queue-card,
    .dashboard-recent-card {
        min-width:0;
        height:auto;
        min-height:0;
        padding:0;
        overflow:hidden;
        border:1px solid var(--border);
        border-radius:12px;
        background:var(--surface-elevated);
        box-shadow:var(--shadow-sm);
    }

    .dashboard-balanced-grid.dashboard-two-panel > .queue-card {
        display:flex;
        flex-direction:column;
    }
    .dashboard-balanced-grid.dashboard-two-panel > .queue-card > .queue-list,
    .dashboard-balanced-grid.dashboard-two-panel > .queue-card > .dashboard-watch-list {
        flex:1 1 auto;
    }
    .dashboard-balanced-grid.dashboard-two-panel > .queue-card > .queue-list,
    .dashboard-balanced-grid.dashboard-two-panel > .queue-card > .dashboard-watch-list {

        align-content:start;
        grid-auto-rows:max-content;
    }
    .dashboard-balanced-grid.dashboard-two-panel > .queue-card > .queue-list:has(> .empty-state:only-child) {
        display:flex;
    }
    .dashboard-balanced-grid.dashboard-two-panel .empty-state {
        width:100%;
    }
    .dashboard-balanced-grid.dashboard-two-panel .queue-list > .empty-state:only-child {
        display:flex;
        align-items:center;
        justify-content:center;
        min-height:100%;
        text-align:center;
    }

    .dashboard-balanced-grid > .queue-card > .card-header,
    .dashboard-recent-card > .card-header {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        flex-wrap:wrap;
        gap:10px 16px;
        margin:0;
        padding:12px 12px;
        border-bottom:1px solid var(--border);
        background:transparent;
    }
    .dashboard-balanced-grid > .queue-card > .card-header h2,
    .dashboard-recent-card > .card-header h2 {
        margin:0;
        color:var(--heading);
        font-size:15px;
        font-weight:750;
        line-height:1.3;
    }
    .dashboard-balanced-grid > .queue-card > .card-header .eyebrow,
    .dashboard-recent-card > .card-header .eyebrow {
        margin-bottom:3px;
    }
    .dashboard-balanced-grid > .queue-card > .card-header .meta,
    .dashboard-recent-card > .card-header .meta,
    .dashboard-role-section-note {
        max-width:650px;
        margin:3px 0 0;
        color:var(--text-muted);
        font-size:11px;
        line-height:1.45;
    }
    .dashboard-view-all {
        display:inline-flex;
        align-items:center;
        gap:6px;
        flex:0 0 auto;
        min-height:30px;
        color:var(--interactive);
        font-size:12px;
        font-weight:750;
        text-decoration:none;
    }

    .dashboard-watch-list,
    .dashboard-recent-list { display:grid; }
    .dashboard-watch-row,
    .dashboard-recent-row,
    .dashboard-primary-queue-row {
        display:grid !important;
        grid-template-columns:38px minmax(0,1fr) max-content 78px !important;
        gap:12px !important;
        align-items:center !important;
        min-height:64px;
        padding:11px 12px !important;
        border-bottom:1px solid var(--row-border);
    }
    .dashboard-primary-queue-row {
        grid-template-columns:38px minmax(0,1fr) 78px !important;
    }
    .dashboard-watch-row:last-child,
    .dashboard-recent-row:last-child,
    .dashboard-primary-queue-row:last-child { border-bottom:0; }
    .dashboard-activity-icon {
        display:grid;
        width:40px;
        height:40px;
        place-items:center;
        justify-self:center;
        border:1px solid var(--info-border);
        border-radius:10px;
        background:var(--info-bg);
        color:var(--info);
    }
    .dashboard-watch-copy,
    .dashboard-recent-copy,
    .dashboard-primary-queue-row > div { min-width:0; }
    .dashboard-watch-copy strong,
    .dashboard-watch-copy span,
    .dashboard-watch-copy small,
    .dashboard-recent-copy strong,
    .dashboard-recent-copy span,
    .dashboard-recent-copy small,
    .dashboard-primary-queue-row > div strong,
    .dashboard-primary-queue-row > div span,
    .dashboard-primary-queue-row > div small { display:block; }
    .dashboard-watch-copy strong,
    .dashboard-recent-copy strong,
    .dashboard-primary-queue-row > div strong {
        color:var(--heading);
        font-size:12px;
        font-weight:750;
        line-height:1.35;
    }
    .dashboard-watch-copy span,
    .dashboard-recent-copy span,
    .dashboard-primary-queue-row > div span {
        margin-top:2px;
        color:var(--text-secondary);
        font-size:12.5px;
        line-height:1.4;
    }
    .dashboard-watch-copy small,
    .dashboard-recent-copy small,
    .dashboard-primary-queue-row > div small {
        margin-top:3px;
        color:var(--text-muted);
        font-size:11.5px;
        line-height:1.45;
    }
    .dashboard-watch-status,
    .dashboard-recent-status { justify-self:end; min-width:0; }
    .dashboard-watch-status .status-badge,
    .dashboard-recent-status .status-badge { white-space:nowrap; }
    .dashboard-watch-row > a.button,
    .dashboard-recent-row > a.button,
    .dashboard-primary-queue-row > a.button {
        justify-self:end;
        width:78px;
        min-width:78px;
        min-height:32px;
        padding:6px 10px;
        border-radius:7px;
        white-space:nowrap;
    }
    .dashboard-balanced-grid .empty-state {
        min-height:0;
        padding:12px 12px;
        text-align:left;
    }
    .dashboard-balanced-grid .empty-state strong,
    .dashboard-balanced-grid .empty-state span { display:inline; }
    .dashboard-recent-card { margin-top:0; }

    @media (max-width:980px) {
        .dashboard-stat-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .dashboard-balanced-grid.dashboard-two-panel {
            grid-template-columns:minmax(0,1fr);
            grid-auto-rows:auto;
            flex:0 0 auto;
            min-height:0;
        }
        .dashboard-balanced-grid.dashboard-two-panel > .queue-card {
            height:auto;
            align-self:start;
        }
        .dashboard-balanced-grid.dashboard-two-panel > .queue-card > .queue-list,
        .dashboard-balanced-grid.dashboard-two-panel > .queue-card > .dashboard-watch-list {
            flex:0 0 auto;
        }
        .dashboard-balanced-grid.dashboard-two-panel .queue-list > .empty-state:only-child {
            min-height:0;
            justify-content:flex-start;
            text-align:left;
        }
    }
    @media (max-width:680px) {
        .dashboard-stat-grid { grid-template-columns:1fr; }
        .dashboard-watch-row,
        .dashboard-recent-row,
        .dashboard-primary-queue-row {
            grid-template-columns:40px minmax(0,1fr) !important;
            gap:12px !important;
        }
        .dashboard-watch-status,
        .dashboard-recent-status,
        .dashboard-watch-row > a.button,
        .dashboard-recent-row > a.button,
        .dashboard-primary-queue-row > a.button {
            grid-column:2;
            justify-self:start;
        }
    }

.dashboard-kpi-card {
    --dashboard-kpi-start:#32a5f5;
    --dashboard-kpi-mid:#1479ea;
    --dashboard-kpi-end:#075fd3;

    position:relative;

    display:grid !important;

    grid-template-columns:44px minmax(0,1fr);
    grid-template-rows:auto auto auto;

    align-content:center;
    align-items:center;

    column-gap:11px;
    row-gap:2px;

    width:100%;
    min-width:0;
    min-height:116px;

    padding:12px 36px 12px 12px !important;

    color:#fff !important;

    background:
        radial-gradient(
            90% 48% at 94% 112%,
            rgba(255,255,255,.15) 0%,
            rgba(255,255,255,.08) 50%,
            transparent 51%
        ),
        linear-gradient(
            150deg,
            rgba(255,255,255,.14) 0%,
            rgba(255,255,255,.04) 31%,
            transparent 49%
        ),
        linear-gradient(
            135deg,
            var(--dashboard-kpi-start) 0%,
            var(--dashboard-kpi-mid) 48%,
            var(--dashboard-kpi-end) 100%
        ) !important;

    border:0 !important;
    border-radius:12px !important;

    box-shadow:
        0 7px 17px rgba(15,35,58,.13) !important;

    overflow:hidden;

    text-decoration:none !important;
    cursor:pointer;

    transition:
        transform 160ms ease,
        box-shadow 160ms ease,
        filter 160ms ease;
}

.dashboard-kpi-card::before {
    display:none !important;
}

.dashboard-kpi-card.kpi-accent-info,
.dashboard-kpi-card.dashboard-kpi-for-approval,
.dashboard-kpi-card.dashboard-kpi-for-verification,
.dashboard-kpi-card.dashboard-kpi-active-borrowings {
    --dashboard-kpi-start:#32a5f5;
    --dashboard-kpi-mid:#1479ea;
    --dashboard-kpi-end:#075fd3;
}
.dashboard-kpi-card.dashboard-kpi-pending-verification {
    --dashboard-kpi-start:#5968f4;
    --dashboard-kpi-mid:#5148e8;
    --dashboard-kpi-end:#4032cb;
}

.dashboard-kpi-card.kpi-accent-success,
.dashboard-kpi-card.dashboard-kpi-active-custodies,
.dashboard-kpi-card.dashboard-kpi-for-release,
.dashboard-kpi-card.dashboard-kpi-upcoming-pickup,
.dashboard-kpi-card.dashboard-kpi-active-accounts {
    --dashboard-kpi-start:#2acb9e;
    --dashboard-kpi-mid:#12ab83;
    --dashboard-kpi-end:#087b69;
}

.dashboard-kpi-card.kpi-accent-danger,
.dashboard-kpi-card.dashboard-kpi-open-accountability-cases,
.dashboard-kpi-card.dashboard-kpi-accountability-cases,
.dashboard-kpi-card.dashboard-kpi-active-obligations,
.dashboard-kpi-card.dashboard-kpi-failed-notifications {
    --dashboard-kpi-start:#fb5d5d;
    --dashboard-kpi-mid:#ea3939;
    --dashboard-kpi-end:#c92525;
}

.dashboard-kpi-card.kpi-accent-warning,
.dashboard-kpi-card.dashboard-kpi-active-restrictions,
.dashboard-kpi-card.dashboard-kpi-for-return,
.dashboard-kpi-card.dashboard-kpi-returns-due,
.dashboard-kpi-card.dashboard-kpi-inactive-accounts {
    --dashboard-kpi-start:#ffc443;
    --dashboard-kpi-mid:#f6a10c;
    --dashboard-kpi-end:#dc7b00;
}

.dashboard-kpi-card.kpi-accent-neutral {
    --dashboard-kpi-start:#718096;
    --dashboard-kpi-mid:#59687b;
    --dashboard-kpi-end:#425064;
}

.dashboard-stat-grid .dashboard-kpi-card .kpi-icon {
    grid-column:1 !important;
    grid-row:1 / span 3 !important;

    display:grid !important;
    place-items:center !important;

    justify-self:start !important;
    align-self:center !important;

    width:44px !important;
    height:44px !important;

    min-width:44px !important;
    min-height:44px !important;

    max-width:44px !important;
    max-height:44px !important;

    inline-size:44px !important;
    block-size:44px !important;

    aspect-ratio:1 / 1 !important;

    padding:0 !important;
    margin:0 !important;

    color:#fff !important;

    background:
        linear-gradient(
            145deg,
            rgba(255,255,255,.20),
            rgba(255,255,255,.10)
        ) !important;

    border:1px solid rgba(255,255,255,.38) !important;
    border-radius:50% !important;

    box-sizing:border-box !important;

    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.15) !important;

    overflow:hidden !important;
}

.dashboard-stat-grid .dashboard-kpi-card .kpi-icon > svg {
    display:block !important;

    width:18px !important;
    height:18px !important;

    min-width:18px !important;
    min-height:18px !important;

    margin:0 !important;

    color:#fff !important;
    stroke:currentColor !important;
}

.dashboard-kpi-card .kpi-label {
    grid-column:2;
    grid-row:1;

    align-self:end;

    margin:0 !important;

    color:#fff !important;

    font-size:11px !important;
    font-weight:800 !important;
    line-height:1.25 !important;

    letter-spacing:0;

    display:-webkit-box;
    -webkit-box-orient:vertical;
    -webkit-line-clamp:2;

    overflow:hidden;
}

.dashboard-kpi-card .kpi-value {
    grid-column:2;
    grid-row:2;

    align-self:center;

    margin:1px 0 0 !important;

    color:#fff !important;

    font-size:26px !important;
    font-weight:800 !important;
    line-height:1 !important;

    letter-spacing:-.025em;
    font-variant-numeric:tabular-nums;
}

.dashboard-kpi-card .kpi-note {
    grid-column:2;
    grid-row:3;

    align-self:start;

    margin:2px 0 0 !important;

    color:rgba(255,255,255,.94) !important;

    font-size:9.5px !important;
    font-weight:600 !important;
    line-height:1.4 !important;

    display:-webkit-box;
    -webkit-box-orient:vertical;
    -webkit-line-clamp:2;

    overflow:hidden;
}

a.dashboard-kpi-card::after {
    content:"\2192";

    position:absolute;

    top:10px;
    right:10px;

    display:grid;
    place-items:center;

    width:26px;
    height:26px;

    color:#fff;

    background:rgba(255,255,255,.13);

    border:1px solid rgba(255,255,255,.08);
    border-radius:50%;

    font-size:15px;
    font-weight:500;
    line-height:1;

    pointer-events:none;

    transition:
        transform 160ms ease,
        background-color 160ms ease;
}

.dashboard-kpi-card-arrow {
    display:none !important;
}

a.dashboard-kpi-card:hover {
    color:#fff !important;

    transform:translateY(-2px);

    filter:saturate(1.03) brightness(1.015);

    border-color:transparent !important;

    box-shadow:
        0 11px 24px rgba(15,35,58,.18) !important;
}

a.dashboard-kpi-card:hover::after {
    transform:translateX(2px);

    background:rgba(255,255,255,.21);
}

a.dashboard-kpi-card:focus-visible {
    outline:3px solid rgba(37,99,235,.24);
    outline-offset:3px;

    box-shadow:
        0 10px 22px rgba(15,35,58,.18) !important;
}

@media (max-width:760px) {
    .dashboard-kpi-card {
        min-height:124px;

        grid-template-columns:46px minmax(0,1fr);

        column-gap:12px;

        padding:12px 40px 12px 12px !important;
    }

    .dashboard-stat-grid .dashboard-kpi-card .kpi-icon {
        width:46px !important;
        height:46px !important;

        min-width:46px !important;
        min-height:46px !important;

        max-width:46px !important;
        max-height:46px !important;

        inline-size:46px !important;
        block-size:46px !important;
    }

    .dashboard-stat-grid .dashboard-kpi-card .kpi-icon > svg {
        width:19px !important;
        height:19px !important;

        min-width:19px !important;
        min-height:19px !important;
    }

    .dashboard-kpi-card .kpi-label {
        font-size:11px !important;
    }

    .dashboard-kpi-card .kpi-value {
        font-size:27px !important;
    }

    .dashboard-kpi-card .kpi-note {
        font-size:10px !important;
    }

    a.dashboard-kpi-card::after {
        top:12px;
        right:12px;

        width:27px;
        height:27px;

        font-size:16px;
    }
}

@media (prefers-reduced-motion:reduce) {
    .dashboard-kpi-card,
    a.dashboard-kpi-card::after {
        transition:none;
    }

    a.dashboard-kpi-card:hover {
        transform:none;
    }
}

.dashboard-kpi-card.dashboard-kpi-for-approval,
.dashboard-kpi-card.dashboard-kpi-for-verification,
.dashboard-kpi-card.dashboard-kpi-active-borrowings {
    --dashboard-kpi-start:#32a5f5 !important;
    --dashboard-kpi-mid:#1479ea !important;
    --dashboard-kpi-end:#075fd3 !important;
}

.dashboard-kpi-card.dashboard-kpi-pending-verification {
    --dashboard-kpi-start:#5968f4 !important;
    --dashboard-kpi-mid:#5148e8 !important;
    --dashboard-kpi-end:#4032cb !important;
}

.dashboard-kpi-card.dashboard-kpi-active-custodies,
.dashboard-kpi-card.dashboard-kpi-for-release,
.dashboard-kpi-card.dashboard-kpi-upcoming-pickup,
.dashboard-kpi-card.dashboard-kpi-active-accounts {
    --dashboard-kpi-start:#2acb9e !important;
    --dashboard-kpi-mid:#12ab83 !important;
    --dashboard-kpi-end:#087b69 !important;
}

.dashboard-kpi-card.dashboard-kpi-open-accountability-cases,
.dashboard-kpi-card.dashboard-kpi-accountability-cases,
.dashboard-kpi-card.dashboard-kpi-active-obligations,
.dashboard-kpi-card.dashboard-kpi-failed-notifications {
    --dashboard-kpi-start:#fb5d5d !important;
    --dashboard-kpi-mid:#ea3939 !important;
    --dashboard-kpi-end:#c92525 !important;
}

.dashboard-kpi-card.dashboard-kpi-active-restrictions,
.dashboard-kpi-card.dashboard-kpi-for-return,
.dashboard-kpi-card.dashboard-kpi-returns-due,
.dashboard-kpi-card.dashboard-kpi-inactive-accounts {
    --dashboard-kpi-start:#ffc443 !important;
    --dashboard-kpi-mid:#f6a10c !important;
    --dashboard-kpi-end:#dc7b00 !important;
}

    html[data-theme="dark"] .dashboard-kpi-card,
    html[data-theme="dark"] .dashboard-balanced-grid > .card,
    html[data-theme="dark"] .dashboard-recent-card { box-shadow:var(--shadow-sm); }

    .is-borrower-dashboard .borrower-dashboard-priority-stack {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        align-items: stretch;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack > #borrower-actions,
    .is-borrower-dashboard .borrower-dashboard-priority-stack > .borrower-current-requests,
    .is-borrower-dashboard .borrower-dashboard-priority-stack > .dashboard-recent-card {
        min-width: 0;
        width: 100%;
        margin: 0;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack > #borrower-actions {
        align-self: stretch;
        min-height: 0;
        grid-template-columns: minmax(0, 1fr);
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack > #borrower-actions > .queue-card {
        height: 100%;
        margin: 0;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack > .dashboard-recent-card {
        order: 3;
        grid-column: 1 / -1;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .card-header {
        flex-wrap: nowrap;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .card-header > div:first-child {
        min-width: 0;
        flex: 1 1 0;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .dashboard-view-all {
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .queue-list .borrower-next-row {
        display: grid;
        grid-template-columns: 38px minmax(0, 1fr) auto;
        gap: 8px 10px;
        padding: 12px 12px;
        align-items: center;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-next-icon {
        grid-column: 1;
        grid-row: 1 / span 2;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-next-copy {
        grid-column: 2;
        grid-row: 1;
        min-width: 0;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-next-when {
        grid-column: 2;
        grid-row: 2;
        justify-self: start;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .queue-list .borrower-next-row > a.button {
        grid-column: 3;
        grid-row: 1 / span 2;
        justify-self: end;
        min-width: 78px;
        width: auto;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-next-row > span:empty {
        display: none;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-scroll {
        width: 100%;
        overflow: visible;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table,
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table tbody {
        display: block;
        width: 100%;
        min-width: 0;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table thead {
        display: none;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table tbody tr {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(100px, 38%);
        gap: 4px 10px;
        padding: 12px 12px;
        border-bottom: 1px solid var(--row-border);
        align-items: center;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table td {
        display: block;
        width: auto;
        min-width: 0;
        padding: 0;
        border: 0;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table td::before {
        content: none;
        display: none;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table td:nth-child(1) {
        grid-column: 1;
        grid-row: 1;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table td:nth-child(2) {
        grid-column: 1;
        grid-row: 2;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table td:nth-child(3) {
        grid-column: 2;
        grid-row: 1;
        justify-self: end;
        text-align: right;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table td:nth-child(4) {
        grid-column: 1;
        grid-row: 3;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table td:nth-child(5) {
        grid-column: 2;
        grid-row: 2 / span 2;
        justify-self: end;
        align-self: center;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-id {
        white-space: normal;
        overflow-wrap: anywhere;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table .status-badge {
        max-width: 100%;
        white-space: normal;
        text-align: center;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table td:nth-child(5) .button {
        width: auto;
        min-width: 78px;
    }

    @media (max-width: 1100px) {
        .is-borrower-dashboard .borrower-dashboard-priority-stack {
            grid-template-columns: minmax(0, 1fr);
        }

        .is-borrower-dashboard .borrower-dashboard-priority-stack > .dashboard-recent-card {
            grid-column: auto;
        }
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack > #borrower-actions .card-header,
    .is-borrower-dashboard .borrower-dashboard-priority-stack > .borrower-current-requests > .card-header,
    .is-borrower-dashboard .borrower-dashboard-priority-stack > .dashboard-recent-card > .card-header {
        min-height: 54px;
        padding: 10px 12px;
        align-items: center;
    }
    .is-borrower-dashboard .borrower-dashboard-priority-stack .card-header h2 {
        font-size: 15px;
        line-height: 1.3;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .queue-list .borrower-next-row,
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table tbody tr {
        min-height: 94px;
        padding: 10px 12px;
        row-gap: 3px;
        align-content: start;
        box-sizing: border-box;
    }
    .is-borrower-dashboard .borrower-dashboard-priority-stack .queue-list .borrower-next-row {
        grid-template-columns: 34px minmax(0, 1fr) max-content;
        column-gap: 10px;
    }
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-next-icon {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        align-self: center;
    }
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-next-copy {
        gap: 2px;
        min-width: 0;
    }

    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-next-copy strong,
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-id {
        font-size: 12px;
        line-height: 1.3;
        overflow-wrap: anywhere;
    }
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-next-copy > span,
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-purpose {
        min-width: 0;
        max-width: 100%;
        font-size: 11.5px;
        line-height: 1.35;
    }
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-next-copy small {
        font-size: 10.5px;
        line-height: 1.35;
    }
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-next-when,
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-schedule strong {
        font-size: 11.5px;
        line-height: 1.3;
    }
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-schedule small {
        margin-top: 1px;
        font-size: 10.5px;
        line-height: 1.3;
    }
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table tbody tr {
        grid-template-columns: minmax(0, 1fr) minmax(120px, 38%);
    }
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table .status-badge {
        padding: 3px 7px;
        font-size: 10px;
        line-height: 1.25;
    }
    .is-borrower-dashboard .borrower-dashboard-priority-stack .queue-list .borrower-next-row > a.button,
    .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table td:nth-child(5) .button {
        min-height: 30px;
        min-width: 76px;
        padding: 5px 10px;
        font-size: 11px;
    }

    @media (max-width: 760px) {
        .is-borrower-dashboard .borrower-dashboard-priority-stack .queue-list .borrower-next-row,
        .is-borrower-dashboard .borrower-dashboard-priority-stack .borrower-active-table tbody tr {
            min-height: 0;
            padding: 12px;
        }
    }

</style>

@php
    $firstName = str($user->full_name)->before(' ')->value();

    $copy = match($dashboardMode) {
        'BORROWER' => [
            'eyebrow' => 'Borrower Dashboard',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Check what needs your attention, track your pickup and return dates, and view recent borrowing updates.',
            'taskEyebrow' => 'Your next step',
            'taskTitle' => 'Next actions',
            'taskDescription' => 'Records that need your response.',
        ],
        'SPMU_OFFICER' => [
            'eyebrow' => 'SPMU Action Officer Dashboard',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Process requests pending verification and monitor release, return, and accountability actions.',
            'taskEyebrow' => 'Action Queue',
            'taskTitle' => 'Verification Queue',
            'taskDescription' => 'Requests awaiting SPMU verification.',
        ],
        'SPMU_HEAD' => [
            'eyebrow' => 'SPMU Head / Administrator Dashboard',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Review requests pending final approval and monitor custody, accountability, and inventory activity.',
            'taskEyebrow' => 'Action Queue',
            'taskTitle' => 'Priority Actions',
            'taskDescription' => 'Verified requests awaiting your final approval.',
        ],
        'ICTU' => [
            'eyebrow' => 'ICTU System Administrator Dashboard',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Administer user accounts, system configuration, audit records, and notification delivery exceptions.',
            'taskEyebrow' => 'System Administration',
            'taskTitle' => 'Recent Accounts',
            'taskDescription' => 'Recently added user accounts.',
        ],
        default => [
            'eyebrow' => 'Overview',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Open the functions assigned to your account.',
            'taskEyebrow' => 'Tasks',
            'taskTitle' => 'Current Activity',
            'taskDescription' => 'Records requiring action under the assigned role are listed in this section.',
        ],
    };

    /*
     * Canonical KPI color semantics (one tone per card, reused everywhere):
     * info/blue = normal active/scheduled workflow, no urgent action;
     * warning/amber = attention or action needed, not yet overdue/failed;
     * danger/red = overdue, failed, or genuinely critical only;
     * success/green = healthy/active-good state; neutral/gray = inactive.
     * Every entry here is a real, currently-emitted $statistics key - see
     * DashboardController for the exact query each one counts.
     */
    $statMeta = match($dashboardMode) {
        'BORROWER' => [
            'Active Borrowings' => ['custody', 'info', route('custody.index', ['kpi' => 'active_borrowings']), 'Items currently on your custody'],
            'Upcoming Pickup' => ['calendar', 'info', route('custody.index', ['kpi' => 'upcoming_pickup']), 'Items scheduled for pickup'],
            'Returns Due' => ['calendar-clock', 'warning', route('custody.index', ['kpi' => 'returns_due']), 'Items due for return today or tomorrow'],
            'Active Obligations' => ['accountability', 'warning', route('accountability.index'), 'Items or payments that need follow-up'],
        ],
        'SPMU_OFFICER' => [
            'For Verification' => ['clipboard-check', 'info', route('verifications.index'), 'Awaiting verification'],
            'For Release' => ['custody', 'info', route('custody.release.index'), 'Awaiting release processing'],
            'For Return' => ['calendar-clock', 'warning', route('custody.return.index'), 'Outstanding property to return'],
            'Accountability Cases' => ['accountability', 'warning', route('accountability.index', ['queue' => 'all']).'#accountability-workspace', 'Open accountability matters'],
        ],
        'SPMU_HEAD' => [
            'For Approval' => ['approval', 'info', route('approvals.index'), 'Awaiting final approval'],
            'Active Custodies' => ['custody', 'info', route('requests.index', ['kpi' => 'active_custodies']), 'Ongoing custody transactions'],
            'Open Accountability Cases' => ['requests', 'warning', route('accountability.index', ['queue' => 'all']).'#accountability-workspace', 'Unresolved accountability matters'],
            'Active Restrictions' => ['lock', 'warning', route('accountability.index', ['queue' => 'all', 'view' => 'restrictions']).'#accountability-workspace', 'Restrictions currently in effect'],
        ],
        'ICTU' => [
            'Active Accounts' => ['users', 'success', route('administration.users.index', ['status' => 'active']), 'User accounts currently enabled'],
            'Failed Notifications' => ['notifications', 'danger', route('reports.notifications', ['status' => 'failed']), 'Current delivery failures requiring technical review'],
            'Pending Verification' => ['email', 'info', route('administration.users.index', ['verification' => 'pending']), 'Institutional accounts awaiting OTP email verification'],
            'Inactive Accounts' => ['lock', 'neutral', route('administration.users.index', ['status' => 'inactive']), 'User accounts currently inactive'],
        ],
        default => [],
    };
@endphp

<div class="dashboard-page-shell {{ $dashboardMode === 'BORROWER' ? 'is-borrower-dashboard' : '' }}">

<section class="page-heading dashboard-heading">
    <div>
        <p class="eyebrow">{{ $copy['eyebrow'] }}</p>
        <h1>{{ $copy['title'] }}</h1>
        <p>{{ $copy['subtitle'] }}</p>
    </div>

    @if($dashboardMode === 'BORROWER')
        <a class="button primary ui-pressable" href="{{ route('requests.create') }}">
            <x-icon name="plus" size="16" />
            Create Borrowing Request
        </a>
    @elseif($dashboardMode === 'SPMU_OFFICER')
        <a class="button primary ui-pressable" href="{{ route('verifications.index') }}"><span>Open Verification Queue</span></a>
    @elseif($dashboardMode === 'SPMU_HEAD')
        <a class="button primary ui-pressable" href="{{ route('approvals.index') }}"><span>Open Approval Queue</span></a>
    @elseif($dashboardMode === 'ICTU')
        <a class="button primary ui-pressable" href="{{ route('administration.users.index') }}"><span>Manage User Accounts</span></a>
    @endif
</section>

<section class="stat-grid dashboard-stat-grid" aria-label="Current totals">
    @foreach($statistics as $label => $value)
@php
            [$icon, $tone, $link, $note] = $statMeta[$label] ?? [
                'dashboard',
                'neutral',
                route('dashboard'),
                'Current system total',
            ];
        @endphp
        {{--
            Each KPI card is a drill-down: its destination always shows
            exactly the record subset the card counted (see $statMeta's
            routes/query params and the matching controller filters), not an
            unfiltered list. Every role shares the same clickable treatment.
        --}}
        <a
            class="card stat-card kpi-card dashboard-kpi-card dashboard-kpi-{{ str($label)->slug() }} kpi-accent-{{ $tone }} ui-pressable"
            href="{{ $link }}"
            aria-label="{{ $label }}: {{ number_format($value) }}. {{ $note }}."
        >
            <span class="kpi-icon" aria-hidden="true"><x-icon :name="$icon" size="18" /></span>
            <span class="kpi-label">{{ $label }}</span>
            <strong class="kpi-value">{{ number_format($value) }}</strong>
            <span class="kpi-note">{{ $note }}</span>
        </a>
    @endforeach
</section>

{{-- SPMU_ADVISORY_ALL_DASHBOARDS_AFTER_KPI_V3: Welcome > KPIs > advisory > operational cards. --}}
@if(in_array($dashboardMode, ['BORROWER', 'SPMU_OFFICER', 'SPMU_HEAD'], true))
    @include('shared.operational-advisory', ['compact' => $dashboardMode !== 'BORROWER'])
@endif

@php
    /*
     * Dashboard section priority is content-aware, but deterministic:
     * actionable content wins when it exists; otherwise a populated
     * monitoring/current-transaction section moves ahead of an empty
     * action panel. Empty sections remain visible after the useful content
     * so the dashboard still confirms that nothing is pending there.
     */
    $borrowerDashboardActionRows = $dashboardMode === 'BORROWER'
        ? $queue->take(max(0, 3 - $borrowerRestrictionActions->take(3)->count()))
        : $queue;
    $primaryActionHasRecords = $queue->isNotEmpty()
        || ($dashboardMode === 'BORROWER' && $borrowerRestrictionActions->isNotEmpty());

    // No empty second column: when there is nothing to watch, the grid
    // stays single-panel rather than showing a contentless card.
    $showOperationalWatch = in_array($dashboardMode, ['SPMU_OFFICER', 'SPMU_HEAD'], true)
        && $nextCustodies->isNotEmpty();

    $operationalWatchFirst = $showOperationalWatch && ! $primaryActionHasRecords;

    $borrowerCurrentHasRecords = $dashboardMode === 'BORROWER'
        && $activeRequestBars->isNotEmpty();
    $borrowerCurrentFirst = $dashboardMode === 'BORROWER'
        && ! $primaryActionHasRecords
        && $borrowerCurrentHasRecords;
@endphp

@if($dashboardMode === 'BORROWER')
    <div class="borrower-dashboard-priority-stack {{ $borrowerCurrentFirst ? 'borrower-current-first' : '' }}">
@endif

<section id="{{ $dashboardMode === 'BORROWER' ? 'borrower-actions' : 'dashboard-actions' }}" class="dashboard-grid dashboard-balanced-grid {{ $showOperationalWatch ? 'dashboard-two-panel' : 'dashboard-single-panel' }} {{ $dashboardMode === 'BORROWER' ? 'borrower-actions-only' : '' }}">
    <article
        class="card queue-card dashboard-panel-equal {{ $dashboardMode === 'BORROWER' ? 'borrower-dash-card' : '' }} {{ $operationalWatchFirst ? 'dashboard-order-secondary' : 'dashboard-order-primary' }}"
        data-dashboard-section="primary-action"
    >
        <div class="card-header">
            <div>
                @if($dashboardMode === 'BORROWER')
                    <h2>{{ $copy['taskTitle'] }}</h2>
                    <p class="meta dashboard-role-section-note">Requests or obligations that need your action now.</p>
                @else
                    <p class="eyebrow">{{ $copy['taskEyebrow'] }}</p>
                    <h2>{{ $copy['taskTitle'] }}</h2>
                    <p class="meta dashboard-role-section-note">{{ $copy['taskDescription'] }}</p>
                @endif
            </div>

            @if($dashboardMode === 'BORROWER')
                <a class="dashboard-view-all" href="{{ route('requests.index') }}">View my requests</a>
            @elseif($dashboardMode === 'SPMU_OFFICER')
                <a class="dashboard-view-all" href="{{ route('verifications.index') }}">View all </a>
            @elseif($dashboardMode === 'SPMU_HEAD')
                <a class="dashboard-view-all" href="{{ route('approvals.index') }}">View all </a>
            @endif
        </div>

        <div class="queue-list {{ $dashboardMode === 'BORROWER' ? 'borrower-next-list' : '' }}">
            @forelse($borrowerDashboardActionRows as $record)
                @if($dashboardMode === 'BORROWER')
@php
                        $custody = $record->custody;
                        $laundry = $custody?->laundryJob;
                        $hasOutstandingProperty = $custody?->hasOutstandingProperty() ?? false;
                        $outstandingLines = $custody?->lines?->filter(
                            fn ($line) => (float) $line->returned_quantity < (float) $line->actual_released_quantity
                        ) ?? collect();
                        $hasOutstandingLinen = $outstandingLines->contains(
                            fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
                        );
                        $hasOutstandingNonLinen = $outstandingLines->contains(
                            fn ($line) => ! (bool) $line->requestItem?->inventoryItem?->laundry_required
                        );
                        $hasAccountabilityMatter = $custody?->activeAccountabilityIndicator() !== null;

                        $nextAction = match(true) {
                            $record->status === App\Enums\RequestStatus::Draft => 'Complete and submit this request.',
                            $record->status === App\Enums\RequestStatus::ReturnedForRevision => 'Update and resubmit this request.',
                            $record->status === App\Enums\RequestStatus::UnderSpmu => 'Wait for the SPMU review.',
                            $custody?->status === 'CLOSED' => 'No action is required.',
                            $custody?->status === 'OVERDUE' && $hasOutstandingLinen && $hasOutstandingNonLinen => 'Return all outstanding items today.',
                            $custody?->status === 'OVERDUE' && $hasOutstandingLinen => 'Return the linen with the Laundry Form.',
                            $custody?->status === 'OVERDUE' && $hasOutstandingProperty => 'Return the outstanding items to SPMU.',
                            $laundry?->status === 'FOR_LAUNDRY' => 'Return the linen with the Laundry Form.',
                            $hasAccountabilityMatter && $hasOutstandingProperty => 'Return the item and review My Obligations.',
                            $hasAccountabilityMatter => 'Open My Obligations to complete the required action.',
                            $custody?->scheduled_release_at && ! $custody?->released_at => 'Pick up the approved items as scheduled.',
                            $custody?->released_at && $custody?->status !== 'CLOSED' => 'Return the items by the due date.',
                            default => 'Open the request to view the next step.',
                        };

                        $actionLabel = match (true) {
                            in_array($record->status, [App\Enums\RequestStatus::Draft, App\Enums\RequestStatus::ReturnedForRevision], true) => 'Continue',
                            $hasAccountabilityMatter => 'View Obligations',
                            $custody?->status === 'OVERDUE' => 'View Return',
                            default => 'View',
                        };
                        $actionHref = $hasAccountabilityMatter
                            ? route('accountability.index')
                            : ($custody?->status === 'OVERDUE'
                                ? route('custody.show', $custody)
                                : route('requests.show', $record));

                        /* Presentation only: an icon and a date drawn from the
                           same record state that already produced $nextAction. */
                        [$actionIcon, $actionTone] = match (true) {
                            $record->status === App\Enums\RequestStatus::Draft => ['edit', 'warning'],
                            $record->status === App\Enums\RequestStatus::ReturnedForRevision => ['warning', 'danger'],
                            $record->status === App\Enums\RequestStatus::UnderSpmu => ['clock', 'info'],
                            $custody?->status === 'CLOSED' => ['check-circle', 'success'],
                            $laundry !== null => ['cycle', 'info'],
                            $custody?->scheduled_release_at && ! $custody?->released_at => ['calendar', 'info'],
                            $custody?->released_at => ['custody', 'warning'],
                            default => ['information', 'info'],
                        };

                        $actionWhen = match (true) {
                            $custody?->scheduled_release_at !== null && $custody?->released_at === null
                                => $custody->scheduled_release_at->format('d M Y, g:i A'),
                            $custody?->released_at !== null && $custody?->due_at !== null
                                => $custody->due_at->format('d M Y'),
                            $record->currentVersion?->return_date !== null
                                => $record->currentVersion->return_date->format('d M Y'),
                            default => null,
                        };
                    @endphp
                    <article class="borrower-next-row">
                        <span class="borrower-next-icon tone-{{ $actionTone }}" aria-hidden="true">
                            <x-icon :name="$actionIcon" size="17" />
                        </span>

                        <div class="borrower-next-copy">
                            <strong>{{ $record->request_no }}</strong>
                            <span>{{ $record->currentVersion?->purpose_event ?: 'Borrowing request' }}</span>
                            <small>{{ $nextAction }}</small>
                        </div>

                        @if($actionWhen)
                            <span class="borrower-next-when">{{ $actionWhen }}</span>
                        @else
                            <span></span>
                        @endif

                        {{--
                            View Obligations must reuse the exact same
                            action pattern as View Request/View Record
                            elsewhere - the bespoke borrower-active-action
                            skin is skipped for it so nothing here can
                            diverge from that universal look, and the
                            button sizes to its own label instead of a
                            fixed width that a longer label can overflow.
                        --}}
                        <a class="button secondary small ui-pressable {{ $hasAccountabilityMatter ? '' : 'borrower-active-action' }}" href="{{ $actionHref }}"><span>{{ $actionLabel }}</span></a>
                    </article>
                @elseif($dashboardMode === 'SPMU_OFFICER')
                    <article class="dashboard-primary-queue-row">
                        <span class="dashboard-activity-icon" aria-hidden="true"><x-icon name="approval" size="17" /></span>
                        <div>
                            <strong>{{ $record->request_no }}</strong>
                            <span>{{ $record->borrower?->full_name }}</span>
                            <small>Verify the request and required documents, or return it for revision.</small>
                        </div>
                        <a class="button primary small ui-pressable" href="{{ route('requests.show', $record) }}"><span>Verify</span></a>
                    </article>
                @elseif($dashboardMode === 'SPMU_HEAD')
                    <article class="dashboard-primary-queue-row">
                        <span class="dashboard-activity-icon" aria-hidden="true"><x-icon name="approval" size="17" /></span>
                        <div>
                            <strong>{{ $record->request_no }}</strong>
                            <span>{{ $record->borrower?->full_name }}</span>
                            <small>Review the verified request, approved quantities, borrowing period, and availability before the final decision.</small>
                        </div>
                        <a class="button primary small ui-pressable" href="{{ route('requests.show', $record) }}"><span>Review</span></a>
                    </article>
                @elseif($dashboardMode === 'ICTU')
                    <article class="dashboard-primary-queue-row">
                        <span class="dashboard-activity-icon" aria-hidden="true"><x-icon name="users" size="17" /></span>
                        <div>
                            <strong>{{ $record->full_name }}</strong>
                            <span>{{ $record->access_classification?->label() ?: 'User account' }}</span>
                            <small>{{ $record->email }}{{ $record->organizationalUnit ? ' · '.$record->organizationalUnit->unit_name : '' }}</small>
                        </div>
                        <a class="button secondary small ui-pressable" href="{{ route('administration.users.edit', $record) }}"><span>Manage</span></a>
                    </article>
                @endif
            @empty
                @if($dashboardMode === 'BORROWER')
                    @if($borrowerRestrictionActions->isEmpty())
                        @if(($statistics['Active Obligations'] ?? 0) > 0)
                            <x-empty-state
                                icon="check-circle"
                                title="No action is required from you right now."
                                message="Your active obligation is currently being processed. Open My Obligations to monitor its status."
                            />
                        @else
                            <x-empty-state
                                icon="check-circle"
                                title="No current action is required."
                                message="No borrowing transaction or obligation currently requires your response."
                            />
                        @endif
                    @endif
                @elseif($dashboardMode === 'SPMU_OFFICER')
                    <x-empty-state icon="check-circle" title="No requests awaiting verification." message="You are all caught up." />
                @elseif($dashboardMode === 'SPMU_HEAD')
                    <x-empty-state icon="check-circle" title="No requests awaiting approval." message="You are all caught up." />
                @elseif($dashboardMode === 'ICTU')
                    <x-empty-state icon="users" title="No recent account activity." />
                @else
                    <x-empty-state
                        icon="box"
                        title="No records currently require action."
                        message="Records requiring action under your assigned role will appear here automatically."
                    />
                @endif
            @endforelse

            @if($dashboardMode === 'BORROWER')
                @foreach($borrowerRestrictionActions->take(3) as $obligationRow)
                    <article class="borrower-next-row">
                        <span class="borrower-next-icon tone-{{ $obligationRow['tone'] ?? 'warning' }}" aria-hidden="true">
                            <x-icon :name="$obligationRow['icon'] ?? 'warning'" size="17" />
                        </span>

                        <div class="borrower-next-copy">
                            <strong>{{ $obligationRow['type'] }}</strong>
                            <span>{{ $obligationRow['summary'] }}</span>
                            <small>Open My Obligations to continue.</small>
                        </div>

                        <span class="borrower-next-when">{{ $obligationRow['badge'] }}</span>

                        <a class="button secondary small ui-pressable" href="{{ route('accountability.index') }}"><span>View Obligation</span></a>
                    </article>
                @endforeach
            @endif
        </div>
    </article>

    @if($showOperationalWatch)
        <article
            class="card queue-card dashboard-panel-equal dashboard-watch-card {{ $operationalWatchFirst ? 'dashboard-order-primary' : 'dashboard-order-secondary' }}"
            data-dashboard-section="operational-watch"
        >
            <div class="card-header">
                <div>
                    <p class="eyebrow">{{ $dashboardMode === 'SPMU_OFFICER' ? 'Operations' : 'Custody Oversight' }}</p>
                    <h2>{{ $dashboardMode === 'SPMU_OFFICER' ? 'Upcoming Operations' : 'Custody Watch' }}</h2>
                    <p class="meta dashboard-role-section-note">
                        {{ $dashboardMode === 'SPMU_OFFICER'
                            ? 'Next pickup and return deadlines.'
                            : 'Nearest due/overdue active custody transactions.' }}
                    </p>
                </div>
                <a class="dashboard-view-all" href="{{ $dashboardMode === 'SPMU_HEAD' ? route('requests.index') : route('custody.index') }}">View all </a>
            </div>

            <div class="dashboard-watch-list">
                @foreach($nextCustodies as $watchCustody)
@php
                        $watchWorkflow = $watchCustody->workflowStatus();
                        $watchDateLabel = null;
                        $watchDateValue = null;

                        if ($watchCustody->released_at && $watchCustody->due_at) {
                            $watchDateLabel = 'Return due';
                            $watchDateValue = $watchCustody->due_at->format('d M Y');
                        } elseif ($watchCustody->scheduled_release_at && ! $watchCustody->released_at) {
                            $watchDateLabel = 'Pickup';
                            $watchDateValue = $watchCustody->scheduled_release_at->format('d M Y, g:i A');
                        }
                    @endphp
                    <div class="dashboard-watch-row">
                        <span class="dashboard-activity-icon" aria-hidden="true"><x-icon name="custody" size="17" /></span>
                        <div class="dashboard-watch-copy">
                            <strong>{{ $watchCustody->custody_no }}</strong>
                            <span>{{ $watchCustody->borrower?->full_name ?: 'Borrower' }}</span>
                            <small>{{ $watchDateLabel && $watchDateValue ? $watchDateLabel.': '.$watchDateValue : 'Active transaction' }}</small>
                        </div>
                        <div class="dashboard-watch-status">
                            @if($watchWorkflow)
                                <x-status-badge :status="$watchWorkflow['key']" :label="$watchWorkflow['label']" />
                            @endif
                        </div>
                        <a class="button secondary small ui-pressable" href="{{ $dashboardMode === 'SPMU_HEAD' ? route('requests.show', $watchCustody->request) : route('custody.show', $watchCustody) }}"><span>View</span></a>
                    </div>
                @endforeach
            </div>
        </article>
    @endif
</section>

@if($dashboardMode === 'BORROWER')
    <article class="borrower-dash-card borrower-current-requests" aria-labelledby="active-requests-title" data-dashboard-section="borrower-current">
        <div class="card-header">
            <div>
                <h2 id="active-requests-title">Current Requests</h2>
                <p class="meta dashboard-role-section-note">Ongoing requests to track; no action is needed right now.</p>
            </div>
            <a class="dashboard-view-all" href="{{ route('requests.index') }}">View my requests</a>
        </div>

        @if($activeRequestBars->isEmpty())
            <x-empty-state icon="requests" title="No current borrowing requests" message="Active requests will appear here once submitted." />
        @else
            <div class="borrower-active-scroll">
                <table class="borrower-active-table">
                    <thead>
                        <tr>
                            <th scope="col">Request</th>
                            <th scope="col">Purpose</th>
                            <th scope="col">Status</th>
                            <th scope="col">Pickup / return</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                @foreach($activeRequestBars->take(3) as $activeRequest)
@php
                        $activeCustody = $activeRequest->custody;
                        $activeCustodyStatus = strtoupper((string) ($activeCustody?->status ?? ''));
                        $activeAccountability = $activeCustody?->activeAccountabilityIndicator();
                        $activeWorkflow = $activeCustody?->workflowStatus();
                        $activeGatePassOnlyPending = $activeCustody?->isGatePassOnlyPending() ?? false;

                        /*
                         * Same key/label resolution as My Requests
                         * (requests/partials/my-requests-row.blade.php), so a
                         * request in a given state never reads differently on
                         * the two pages. SPMU still recording the accomplished
                         * Gate Pass is internal SPMU processing, not a
                         * borrower obligation - never "Accountability Pending".
                         */
                        if ($activeGatePassOnlyPending) {
                            [$activeStateKey, $activeStateLabel] = ['GATE_PASS_PROCESSING', 'Gate Pass Processing'];
                        } elseif ($activeWorkflow) {
                            [$activeStateKey, $activeStateLabel] = match ($activeWorkflow['key']) {
                                'BORROWED' => ['BORROWED', 'On Custody'],
                                'RETURN_PROCESSING' => ['RETURN_PROCESSING', 'Return Processing'],
                                'OVERDUE' => ['OVERDUE', 'Overdue'],
                                'INCIDENT_OPEN' => ['ACCOUNTABILITY_PENDING', 'Accountability Pending'],
                                'OBLIGATION_OPEN' => ['OBLIGATION_OPEN', 'Accountability Pending'],
                                'COMPLETED' => ['COMPLETED', 'Completed'],
                                'BORROWER_CLEARED' => ['BORROWER_CLEARED', 'Borrower Cleared'],
                                'CANCELLED' => ['CANCELLED', 'Cancelled'],
                                'READY_FOR_RELEASE' => ['READY_FOR_RELEASE', 'Ready for Release'],
                                'PICKUP_SCHEDULED' => ['PICKUP_SCHEDULED', 'Pickup Scheduled'],
                                'ITEM_PREPARATION' => ['ITEM_PREPARATION', 'For Item Preparation'],
                                'PICKUP_SCHEDULING' => ['PICKUP_SCHEDULING', 'For Pickup Scheduling'],
                                'PICKUP_EXPIRED' => ['PICKUP_EXPIRED', 'Pickup Missed'],
                                default => [$activeWorkflow['key'], $activeWorkflow['label']],
                            };
                        } else {
                            [$activeStateKey, $activeStateLabel] = match ($activeRequest->status) {
                                App\Enums\RequestStatus::Draft => ['DRAFT', 'Draft'],
                                App\Enums\RequestStatus::ReturnedForRevision => ['RETURNED_FOR_REVISION', 'Returned for Revision'],
                                App\Enums\RequestStatus::UnderSpmu,
                                App\Enums\RequestStatus::UnderGsu,
                                App\Enums\RequestStatus::UnderVpaf => ['UNDER_SPMU', 'Under SPMU Review'],
                                App\Enums\RequestStatus::ApprovedReadyForRelease => ['APPROVED_READY_FOR_RELEASE', 'Ready for Release'],
                                App\Enums\RequestStatus::FinalApprovedAwaitingDownload => ['FINAL_APPROVED_AWAITING_DOWNLOAD', 'Approved'],
                                App\Enums\RequestStatus::Rejected => ['REJECTED', 'Rejected'],
                                App\Enums\RequestStatus::Cancelled => ['CANCELLED', 'Cancelled'],
                                App\Enums\RequestStatus::Expired => ['INACTIVE', 'Inactive'],
                                default => ['SUBMITTED', 'In Progress'],
                            };
                        }

                        $activeOriginalReturnDate = $activeCustody?->original_due_at
                            ?: $activeRequest->currentVersion?->return_date;
                        $activeEffectiveReturnDate = $activeCustody?->due_at ?: $activeOriginalReturnDate;
                        $activeReturnAdjusted = $activeOriginalReturnDate && $activeEffectiveReturnDate
                            && ! $activeOriginalReturnDate->isSameDay($activeEffectiveReturnDate);
                        $activeDateNote = null;

                        [$activeDateLabel, $activeDateValue] = match (true) {
                            $activeCustody?->released_at !== null && $activeEffectiveReturnDate !== null => [
                                $activeReturnAdjusted ? 'Effective Return' : 'Return due',
                                $activeEffectiveReturnDate->format('d M Y'),
                            ],
                            $activeCustody?->scheduled_release_at !== null && $activeCustody?->released_at === null => ['Pickup', $activeCustody->scheduled_release_at->format('d M Y, g:i A')],
                            $activeRequest->currentVersion?->return_date !== null => ['Expected return', $activeRequest->currentVersion->return_date->format('d M Y')],
                            $activeRequest->currentVersion?->needed_from !== null => ['Needed from', $activeRequest->currentVersion->needed_from->format('d M Y')],
                            default => ['Updated', $activeRequest->updated_at->format('d M Y')],
                        };

                        if ($activeReturnAdjusted && $activeOriginalReturnDate) {
                            $activeDateNote = 'Adjusted from '.$activeOriginalReturnDate->format('d M Y');
                        }

                        $activeActionLabel = in_array($activeRequest->status, [App\Enums\RequestStatus::Draft, App\Enums\RequestStatus::ReturnedForRevision], true)
                            ? 'Continue'
                            : 'View request';
                    @endphp
                    <tr>
                        <td class="borrower-active-cell-id">
                            <span class="borrower-active-id">{{ $activeRequest->request_no }}</span>
                        </td>

                        <td data-label="Event / Charter">
                            <span class="borrower-active-purpose">{{ $activeRequest->currentVersion?->purpose_event ?: 'Borrowing request' }}</span>
                        </td>

                        <td data-label="Status">
                            <x-status-badge :status="$activeStateKey" :label="$activeStateLabel" />
                            @if($activeAccountability)
                                <small class="borrower-active-obligation">{{ $activeAccountability['label'] }}</small>
                            @endif
                        </td>

                        <td class="borrower-active-schedule" data-label="Schedule / Due Date">
                            <strong>{{ $activeDateValue }}</strong>
                            @if(!in_array($activeStateKey, ['PICKUP_SCHEDULED', 'PICKUP_EXPIRED'], true))
                                <small>{{ $activeDateLabel }}</small>
                            @endif
                            @if($activeDateNote)
                                <small class="borrower-active-adjusted-date">{{ $activeDateNote }}</small>
                            @endif
                        </td>

                        <td data-label="Action">
                            <a
                                class="button secondary small ui-pressable borrower-active-action"
                                href="{{ route('requests.show', $activeRequest) }}"
                            >
                                <span>{{ $activeActionLabel }}</span>
                            </a>
                        </td>
                    </tr>
                @endforeach
                    </tbody>
                </table>
            </div>

        @endif
    </article>
@endif

@if($dashboardMode === 'BORROWER')
    <article class="borrower-dash-card dashboard-recent-card" aria-labelledby="recent-borrowing-title">
        <div class="card-header">
            <div>
                <h2 id="recent-borrowing-title">Recent History</h2>
                <p class="meta dashboard-role-section-note">Past requests that are no longer active.</p>
            </div>
                <a class="dashboard-view-all" href="{{ route('requests.index') }}">View history</a>
        </div>

        @if($recentBorrowerActivity->isEmpty())
            <x-empty-state icon="clock" title="No recent history" message="Completed, cancelled, rejected, or other closed requests will appear here." />
        @else
            <div class="dashboard-recent-list">
                @foreach($recentBorrowerActivity->take(3) as $recentRequest)
@php
                        $recentWorkflow = $recentRequest->custody?->workflowStatus();
                        $recentGatePassOnlyPending = $recentRequest->custody?->isGatePassOnlyPending() ?? false;
                        $recentStatusKey = $recentGatePassOnlyPending ? 'GATE_PASS_PROCESSING' : ($recentWorkflow['key'] ?? $recentRequest->status->value);
                        $recentStatusLabel = $recentGatePassOnlyPending ? 'Gate Pass Processing' : ($recentWorkflow['label'] ?? $recentRequest->status->label());
                        $recentCancellationReason = $recentStatusKey === 'CANCELLED'
                            ? trim((string) ($recentRequest->latestCancellation?->reason ?? ''))
                            : '';
                    @endphp
                    <div class="dashboard-recent-row">
                        <span class="dashboard-activity-icon" aria-hidden="true"><x-icon name="requests" size="17" /></span>
                        <div class="dashboard-recent-copy">
                            <strong>{{ $recentRequest->request_no }}</strong>
                            <span>{{ $recentRequest->currentVersion?->purpose_event ?: 'Borrowing request' }}</span>
                            @if($recentCancellationReason !== '')
                                <small>Cancellation reason: {{ $recentCancellationReason }}</small>
                            @else
                                <small>Updated {{ $recentRequest->updated_at->format('d M Y, g:i A') }}</small>
                            @endif
                        </div>
                        <div class="dashboard-recent-status">
                            <x-status-badge :status="$recentStatusKey" :label="$recentStatusLabel" />
                        </div>
                        <a class="button secondary small ui-pressable borrower-active-action" href="{{ route('requests.show', $recentRequest) }}"><span>{{ $recentCancellationReason !== '' ? 'View reason' : 'View details' }}</span></a>
                    </div>
                @endforeach
            </div>
        @endif
    </article>
    </div>
@endif

</div>
@endsection
