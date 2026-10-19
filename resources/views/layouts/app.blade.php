<!doctype html>
<html lang="en" @auth data-theme-storage-key="spmu-acpmp.appearance.{{ hash('sha256', (string) auth()->id()) }}" data-sidebar-storage-key="spmu-acpmp.sidebar.{{ hash('sha256', (string) auth()->id()) }}" @endauth>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ \App\Support\StaticAsset::url('images/cspc-logo.png') }}">
    <title>{{ isset($title) ? $title.' | ' : '' }}{{ config('app.name') }}</title>
    <script>
        (() => {
            const root = document.documentElement;
            const storageKey = root.dataset.themeStorageKey;
            let preference = 'light';

            if (storageKey) {
                try {
                    const stored = localStorage.getItem(storageKey);
                    if (['light', 'dark', 'system'].includes(stored)) preference = stored;
                } catch (_) {}
            }

            const resolved = preference === 'dark' ? 'dark' : 'light';

            root.dataset.theme = resolved;
            root.dataset.themePreference = preference;
            root.style.colorScheme = resolved;
        })();
    </script>
    @auth
    <script>
        (() => {
            const root = document.documentElement;
            const storageKey = root.dataset.sidebarStorageKey;
            let state = 'collapsed';

            if (storageKey) {
                try {
                    const stored = localStorage.getItem(storageKey);
                    if (stored === 'expanded' || stored === 'collapsed') state = stored;
                } catch (_) {}
            }

            root.dataset.sidebarState = state;
        })();
    </script>
    @endauth
    <link rel="stylesheet" href="{{ \App\Support\StaticAsset::url('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\StaticAsset::url('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">

    <style>
        .app-sidebar {
            background: #E5F2FC !important;
            border-right: 1px solid #d6e8f5 !important;
            color: #17324d !important;
        }

        .sidebar-brand-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 14px 10px;
            border-bottom: 1px solid #e6eff8;
        }

        .sidebar-brand.sidebar-brand-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 58px;
            padding: 6px 8px;
            border: 0 !important;
            border-radius: 0;
            background: transparent !important;
            text-decoration: none;
            overflow: hidden;
            box-shadow: none !important;
        }

        .sidebar-brand.sidebar-brand-logo:hover {
            background: transparent !important;
            border-color: transparent !important;
            box-shadow: none !important;
        }

        .sidebar-brand-logo-image {
            display: block;
            width: 100%;
            max-width: 182px;
            height: auto;
            max-height: 50px;
            object-fit: contain;
            object-position: center;
        }

        .sidebar-label {
            margin: 14px 18px 10px !important;
            color: #6f8096 !important;
            font-size: 0.73rem !important;
            font-weight: 800 !important;
            letter-spacing: 0.14em !important;
            text-transform: uppercase !important;
        }

        .sidebar-nav {
            display: grid;
            gap: 8px;
            padding: 0 10px 14px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 48px;
            padding: 0 14px;
            border: 1px solid transparent;
            border-radius: 14px;
            background: transparent !important;
            color: #17324d !important;
            text-decoration: none;
            font-weight: 700;
            transition: background-color .16s ease, color .16s ease, border-color .16s ease, box-shadow .16s ease, transform .12s ease;
        }

        .sidebar-nav a:hover {
            background: #edf5ff !important;
            border-color: #cfe1f4;
            color: #0d63d8 !important;
        }

        .sidebar-nav a.active,
        .sidebar-nav a[aria-current="page"] {
            background: linear-gradient(135deg, #1f6fe5 0%, #0d63d8 100%) !important;
            border-color: #0d63d8 !important;
            color: #ffffff !important;
            box-shadow: 0 10px 24px rgba(13, 99, 216, 0.22);
        }

        .sidebar-nav a .nav-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            min-width: 18px;
        }

        .sidebar-nav a svg {
            color: currentColor;
            stroke: currentColor;
        }

        .sidebar-nav .nav-icon-dashboard { color: #1f6fe5; }
        .sidebar-nav .nav-icon-approval { color: #19a36b; }
        .sidebar-nav .nav-icon-upload { color: #e9870c; }
        .sidebar-nav .nav-icon-custody { color: #7a5af8; }
        .sidebar-nav .nav-icon-success { color: #00a3bf; }
        .sidebar-nav .nav-icon-requests { color: #e55353; }
        .sidebar-nav .nav-icon-inventory { color: #0f9488; }
        .sidebar-nav .nav-icon-calendar { color: #2f80ed; }
        .sidebar-nav .nav-icon-accountability { color: #d97706; }

        /* Additional sidebar module icon colors */
        .sidebar-nav .nav-icon-users { color: #7A5AF8; }
        .sidebar-nav .nav-icon-settings { color: #0F9488; }
        .sidebar-nav .nav-icon-analytics { color: #7A5AF8; }
        .sidebar-nav .nav-icon-reports,
        .sidebar-nav .nav-icon-report-document { color: #2F80ED; }
        .sidebar-nav .nav-icon-notifications { color: #E9870C; }

        .sidebar-nav a.active .nav-icon,
        .sidebar-nav a[aria-current="page"] .nav-icon,
        .sidebar-nav a.active svg,
        .sidebar-nav a[aria-current="page"] svg {
            color: #ffffff !important;
            stroke: currentColor !important;
        }

        .sidebar-foot {
            margin-top: auto;
            padding: 16px 18px 18px !important;
            border-top: 1px solid #e6eff8 !important;
            color: #6c7f96 !important;
            background: #E5F2FC !important;
        }

        .sidebar-foot span {
            display: block;
            margin-bottom: 4px;
            color: #17324d;
            font-weight: 700;
        }

        .sidebar-foot small {
            display: block;
            line-height: 1.45;
        }

        .sidebar-close {
            color: #6c7f96;
        }

        /* DARK MODE: sidebar follows the selected appearance */
        html[data-theme="dark"] .app-sidebar {
            background: #070518 !important;
            border-right-color: rgba(255, 255, 255, .06) !important;
            color: #dce8f5 !important;
        }

        html[data-theme="dark"] .sidebar-brand-row {
            border-bottom-color: rgba(255, 255, 255, .06);
        }

        html[data-theme="dark"] .sidebar-brand.sidebar-brand-logo {
            background: transparent !important;
        }

        html[data-theme="dark"] .sidebar-brand-logo-image {
            filter: brightness(0) invert(1);
            opacity: .96;
        }

        html[data-theme="dark"] .sidebar-label {
            color: #8fa7c3 !important;
        }

        html[data-theme="dark"] .sidebar-nav a {
            background: transparent !important;
            border-color: transparent !important;
            color: #dce8f6 !important;
        }

        html[data-theme="dark"] .sidebar-nav a:hover {
            background: #102a45 !important;
            border-color: #294966 !important;
            color: #ffffff !important;
        }

        html[data-theme="dark"] .sidebar-nav a.active,
        html[data-theme="dark"] .sidebar-nav a[aria-current="page"] {
            background: linear-gradient(135deg, #1f6fe5 0%, #0d63d8 100%) !important;
            border-color: #2c7bed !important;
            color: #ffffff !important;
            box-shadow: 0 10px 24px rgba(13, 99, 216, .28);
        }

        html[data-theme="dark"] .sidebar-nav a.active .nav-icon,
        html[data-theme="dark"] .sidebar-nav a[aria-current="page"] .nav-icon,
        html[data-theme="dark"] .sidebar-nav a.active svg,
        html[data-theme="dark"] .sidebar-nav a[aria-current="page"] svg {
            color: #ffffff !important;
            stroke: currentColor !important;
        }

        html[data-theme="dark"] .sidebar-foot {
            background: #070518 !important;
            border-top-color: #1b324b !important;
            color: #8fa7c3 !important;
        }

        html[data-theme="dark"] .sidebar-foot span {
            color: #eaf2fb !important;
        }

        html[data-theme="dark"] .sidebar-foot small {
            color: #8fa7c3 !important;
        }

        html[data-theme="dark"] .sidebar-close {
            color: #b6c8db !important;
        }

        @media (max-width: 760px) {
            .sidebar-brand-row {
                padding: 12px;
            }

            .sidebar-brand.sidebar-brand-logo {
                min-height: 54px;
                padding: 4px 6px;
            }

            .sidebar-brand-logo-image {
                max-width: 168px;
                max-height: 44px;
            }

            .sidebar-nav {
                padding-inline: 8px;
            }
        }

        /* SIDEBAR HIDDEN SCROLLBAR
           Keep scrolling functional without showing a slider. */
        .app-sidebar,
        .sidebar-nav {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        .app-sidebar::-webkit-scrollbar,
        .sidebar-nav::-webkit-scrollbar {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
        }
</style>

    @guest
        <style>
            .public-header {
                position: relative;
                z-index: 50;
                min-height: 74px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 28px;
                padding: 10px clamp(28px, 4vw, 64px);
                border-bottom: 1px solid #e6edf6;
                background: rgba(255, 255, 255, 0.98);
                box-shadow: 0 4px 18px rgba(7, 27, 53, 0.04);
                color: #071b35;
            }

            .public-header .brand {
                display: inline-flex;
                align-items: center;
                min-width: 0;
                text-decoration: none;
                line-height: 1;
            }

            .public-header .brand:hover {
                text-decoration: none;
            }

            .public-header .brand-logo-image {
                display: block;
                width: clamp(210px, 18vw, 260px);
                height: auto;
                max-height: 52px;
                object-fit: contain;
                object-position: left center;
            }

            .public-header nav {
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .public-header nav a {
                position: relative;
                display: inline-flex;
                align-items: center;
                min-height: 42px;
                padding: 0 13px;
                border-radius: 9px;
                color: #152a47;
                text-decoration: none;
                font-size: .82rem;
                font-weight: 700;
                transition:
                    color .16s ease,
                    background-color .16s ease,
                    transform .12s ease;
            }

            .public-header nav a:hover {
                color: #0d63d8;
                background: #f4f8ff;
            }

            .public-header nav a:active {
                transform: scale(.98);
            }

            .public-header nav a.public-nav-home {
                color: #0d63d8;
            }

            .public-header nav a.public-nav-home::after {
                content: "";
                position: absolute;
                left: 13px;
                right: 13px;
                bottom: 5px;
                height: 2px;
                border-radius: 99px;
                background: #0d63d8;
            }

            .public-header nav a.public-signin {
                min-height: 40px;
                margin-left: 5px;
                padding-inline: 18px;
                border: 1px solid #0d63d8;
                background: #0d63d8;
                color: #ffffff;
                box-shadow: 0 7px 16px rgba(13, 99, 216, .18);
            }

            .public-header nav a.public-signin:hover {
                background: #0758c9;
                color: #ffffff;
            }

            @media (max-width: 760px) {
                .public-header .brand-logo-image {
                    width: 190px;
                    max-height: 44px;
                }

                .public-header {
                    align-items: flex-start;
                    flex-direction: column;
                    gap: 10px;
                    padding: 12px 18px;
                }

                .public-header nav {
                    width: 100%;
                    overflow-x: auto;
                    padding-bottom: 2px;
                }

                .public-header nav a {
                    flex: 0 0 auto;
                }

                .public-header nav a.public-signin {
                    margin-left: auto;
                }
            }

        /* SIDEBAR HIDDEN SCROLLBAR
           Keep scrolling functional without showing a slider. */
        .app-sidebar,
        .sidebar-nav {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        .app-sidebar::-webkit-scrollbar,
        .sidebar-nav::-webkit-scrollbar {
            width: 0 !important;
            height: 0 !important;
            display: none !important;
        }
</style>
    @endguest
    @if(request()->routeIs('reports.audit'))
        <link rel="stylesheet" href="{{ \App\Support\StaticAsset::url('css/reports-audit.css') }}?v={{ filemtime(public_path('css/reports-audit.css')) }}">
    @elseif(request()->routeIs('reports.notifications'))
        <link rel="stylesheet" href="{{ \App\Support\StaticAsset::url('css/reports-notifications.css') }}?v={{ filemtime(public_path('css/reports-notifications.css')) }}">
    @endif
    {{-- ICTU COMPACT WORKSPACE START --}}
    <style id="ictu-compact-workspace">
        /* One visual rhythm for the ICTU workspace. Page structures stay intact;
           only shell sizing, spacing, and dense data presentation are normalized. */
        .ictu-workspace-shell {
            --sidebar-width: 232px;
            --topbar-height: 60px;
            --content-gutter: 22px;
        }

        .ictu-workspace-shell .app-stage { min-width: 0; }
        .ictu-workspace-shell .app-sidebar { background: #eaf5ff !important; }
        .ictu-workspace-shell .sidebar-brand-row {
            min-height: 66px !important;
            padding: 7px 12px 5px !important;
            border-bottom: 0 !important;
        }
        .ictu-workspace-shell .sidebar-brand.sidebar-brand-logo {
            min-height: 48px !important;
            padding: 3px 6px !important;
            justify-content: flex-start !important;
        }
        .ictu-workspace-shell .sidebar-brand-logo-image {
            max-width: 176px !important;
            max-height: 44px !important;
            object-position: left center !important;
        }
        .ictu-workspace-shell .sidebar-label {
            margin: 10px 17px 8px !important;
            font-size: 10.5px !important;
            letter-spacing: .14em !important;
        }
        .ictu-workspace-shell .sidebar-nav {
            gap: 5px !important;
            padding: 0 9px 12px !important;
        }
        .ictu-workspace-shell .sidebar-nav a {
            min-height: 44px !important;
            padding: 0 12px !important;
            gap: 10px !important;
            border-radius: 11px !important;
            font-size: 12.5px !important;
            font-weight: 700 !important;
        }
        .ictu-workspace-shell .sidebar-nav a .nav-icon {
            width: 20px !important;
            min-width: 20px !important;
        }
        .ictu-workspace-shell .sidebar-nav a .nav-icon svg {
            width: 19px !important;
            height: 19px !important;
        }

        .ictu-workspace-shell .app-topbar {
            min-height: 60px !important;
            padding: 7px 22px !important;
            gap: 10px !important;
            background: var(--surface-elevated) !important;
        }
        .ictu-workspace-shell .topbar-title {
            display: grid !important;
            gap: 0 !important;
            min-width: 0;
        }
        .ictu-workspace-shell .topbar-title strong {
            font-size: 15px !important;
            font-weight: 750 !important;
            line-height: 1.2 !important;
            letter-spacing: -.01em !important;
        }
        .ictu-workspace-shell .account-nav { gap: 8px !important; }
        .ictu-workspace-shell .account-menu-toggle { gap: 8px !important; }
        .ictu-workspace-shell .account-menu-name {
            max-width: 190px !important;
            font-size: 12.5px !important;
        }
        .ictu-workspace-shell .account-menu-toggle .user-avatar {
            width: 36px !important;
            height: 36px !important;
            flex-basis: 36px !important;
            font-size: 11px !important;
        }
        .ictu-workspace-shell .notification-control .ui-icon {
            width: 19px !important;
            height: 19px !important;
        }

        .ictu-workspace-shell .app-main,
        .ictu-workspace-shell .app-main.ictu-admin-main,
        .ictu-workspace-shell .app-main.ictu-audit-main {
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 18px 22px 30px !important;
        }
        .ictu-workspace-shell .page-heading { margin-bottom: 12px !important; column-gap: 14px; }
        .ictu-workspace-shell .page-heading h1 {
            margin: 1px 0 3px !important;
            font-size: clamp(21px, 1.65vw, 25px) !important;
            line-height: 1.18 !important;
        }
        .ictu-workspace-shell .page-heading p:not(.eyebrow) {
            font-size: 12.5px !important;
            line-height: 1.4 !important;
        }
        .ictu-workspace-shell .eyebrow {
            margin-bottom: 3px !important;
            font-size: 9.5px !important;
        }
        .ictu-workspace-shell .content-area { margin-top: 12px !important; }

        /* User Accounts: preserve layout, tighten controls and rows. */
        .ictu-workspace-shell .ictu-users-heading { margin-bottom: 10px !important; }
        .ictu-workspace-shell .ictu-users-heading .ictu-register-button {
            height: 36px !important;
            min-height: 36px !important;
            padding-inline: 13px !important;
            font-size: 12px !important;
        }
        .ictu-workspace-shell .ictu-users-area { gap: 10px !important; }
        .ictu-workspace-shell .ictu-users-filter-grid {
            gap: 10px !important;
            padding: 12px 14px !important;
        }
        .ictu-workspace-shell .ictu-users-filter-field { gap: 5px !important; }
        .ictu-workspace-shell .ictu-users-filter-field > label { font-size: 11px !important; }
        .ictu-workspace-shell .ictu-users-filter-field :is(input, select),
        .ictu-workspace-shell .ictu-users-borrowing-actions > button {
            height: 38px !important;
            min-height: 38px !important;
            font-size: 12px !important;
        }
        .ictu-workspace-shell .ictu-users-list-toolbar {
            min-height: 48px !important;
            padding: 7px 14px !important;
        }
        .ictu-workspace-shell .ictu-users-list-title h2 { font-size: 16px !important; }
        .ictu-workspace-shell .ictu-users-table th { padding: 9px 10px !important; }
        .ictu-workspace-shell .ictu-users-table td {
            height: 62px !important;
            padding: 5px 10px !important;
            font-size: 11.5px !important;
        }
        .ictu-workspace-shell .ictu-user-identity-wrap {
            grid-template-columns: 36px minmax(0, 1fr) !important;
            gap: 9px !important;
        }
        .ictu-workspace-shell .ictu-user-avatar {
            width: 36px !important;
            height: 36px !important;
            font-size: 11.5px !important;
        }
        .ictu-workspace-shell .ictu-user-identity-copy strong,
        .ictu-workspace-shell .ictu-user-number-cell strong,
        .ictu-workspace-shell .ictu-user-unit-cell strong,
        .ictu-workspace-shell .ictu-user-access-cell strong { font-size: 11.5px !important; }
        .ictu-workspace-shell .ictu-user-identity-copy span,
        .ictu-workspace-shell .ictu-user-identity-copy a,
        .ictu-workspace-shell .ictu-user-unit-cell span,
        .ictu-workspace-shell .ictu-user-access-cell span { font-size: 10.75px !important; }

        /* Audit Trail: same hierarchy with Gmail-like compact rows. */
        .ictu-workspace-shell .ictu-audit-area { gap: 10px !important; }
        .ictu-workspace-shell .ictu-audit-filter-grid {
            gap: 10px !important;
            padding: 12px 14px !important;
        }
        .ictu-workspace-shell .ictu-audit-filter-field { gap: 5px !important; }
        .ictu-workspace-shell .ictu-audit-filter-field label { font-size: 11px !important; }
        .ictu-workspace-shell .ictu-audit-filter-field :is(input, select),
        .ictu-workspace-shell .ictu-audit-filter-field button {
            height: 38px !important;
            min-height: 38px !important;
            font-size: 12px !important;
        }
        .ictu-workspace-shell .ictu-audit-table-toolbar {
            min-height: 48px !important;
            padding: 7px 14px !important;
        }
        .ictu-workspace-shell .ictu-audit-table-heading h1 { font-size: 16px !important; }
        .ictu-workspace-shell .ictu-audit-table th { padding: 9px 12px !important; }
        .ictu-workspace-shell .ictu-audit-table td {
            height: 58px !important;
            padding: 6px 12px !important;
            font-size: 11.5px !important;
        }
        .ictu-workspace-shell .ictu-audit-activity {
            grid-template-columns: 34px minmax(0, 1fr) !important;
            gap: 9px !important;
        }
        .ictu-workspace-shell .ictu-audit-row-icon { width: 34px !important; height: 34px !important; }
        .ictu-workspace-shell .ictu-audit-cell-copy > strong,
        .ictu-workspace-shell .ictu-audit-time > strong { font-size: 11.5px !important; }
        .ictu-workspace-shell .ictu-audit-cell-copy > span,
        .ictu-workspace-shell .ictu-audit-time > span { font-size: 10.75px !important; }
        .ictu-workspace-shell .ictu-audit-view-button {
            min-width: 108px !important;
            min-height: 34px !important;
            padding: 5px 9px !important;
            font-size: 11.5px !important;
        }
        .ictu-workspace-shell .ictu-audit-expanded-row td { padding: 9px 12px !important; }
        .ictu-workspace-shell .ictu-audit-details-panel { padding: 10px 12px !important; }

        /* System Configuration: preserve cards, reduce empty space. */
        .ictu-workspace-shell .ictu-system-config-area { gap: 10px !important; }
        .ictu-workspace-shell .ictu-config-section-heading h2 { margin-bottom: 2px !important; }
        .ictu-workspace-shell .ictu-config-section-heading p { font-size: 12px !important; }
        .ictu-workspace-shell .ictu-service-status-grid,
        .ictu-workspace-shell .ictu-technical-settings-grid { gap: 12px !important; }
        .ictu-workspace-shell .ictu-service-status-card,
        .ictu-workspace-shell .ictu-technical-setting-card { padding: 15px !important; }
        .ictu-workspace-shell .ictu-service-status-card > p,
        .ictu-workspace-shell .ictu-technical-setting-card > p {
            margin: 8px 0 !important;
            font-size: 12px !important;
        }
        .ictu-workspace-shell .ictu-readonly-value { padding: 9px 10px !important; }

        /* Delivery Records: shared density with Audit Trail. */
        .ictu-workspace-shell .notification-delivery-area { gap: 10px !important; }
        .ictu-workspace-shell .notification-delivery-toolbar {
            gap: 10px !important;
            padding: 12px 14px !important;
        }
        .ictu-workspace-shell .notification-delivery-toolbar label {
            margin-bottom: 5px !important;
            font-size: 11px !important;
        }
        .ictu-workspace-shell .notification-delivery-toolbar input,
        .ictu-workspace-shell .notification-delivery-toolbar select,
        .ictu-workspace-shell .notification-delivery-reset {
            min-height: 38px !important;
            height: 38px !important;
            font-size: 12px !important;
        }
        .ictu-workspace-shell .notification-delivery-table-toolbar {
            min-height: 48px !important;
            padding: 7px 14px !important;
        }
        .ictu-workspace-shell .notification-delivery-table th { padding: 9px 10px !important; }
        .ictu-workspace-shell .notification-delivery-table td {
            padding: 8px 10px !important;
            font-size: 11.5px !important;
        }
        .ictu-workspace-shell .notification-delivery-view-button {
            min-height: 34px !important;
            padding: 5px 9px !important;
            font-size: 11.5px !important;
        }
        .ictu-workspace-shell .notification-delivery-details-panel { padding: 9px 10px 10px !important; }

        /* Dashboard structure remains unchanged; only the outer rhythm is tighter. */
        .ictu-workspace-shell .dashboard-heading { margin-bottom: 12px !important; }
        .ictu-workspace-shell .dashboard-stat-grid { margin-bottom: 12px !important; }
        .ictu-workspace-shell .dashboard-recent-row,
        .ictu-workspace-shell .dashboard-watch-row,
        .ictu-workspace-shell .dashboard-primary-queue-row {
            min-height: 52px !important;
            padding: 8px 14px !important;
        }
        .ictu-workspace-shell .dashboard-recent-card > .card-header,
        .ictu-workspace-shell .dashboard-balanced-grid > .queue-card > .card-header {
            padding: 10px 14px !important;
        }

        html[data-theme="dark"] .ictu-workspace-shell .app-sidebar { background: #070518 !important; }

        @media (max-width: 1000px) {
            .ictu-workspace-shell { --sidebar-width: 220px; --content-gutter: 16px; }
            .ictu-workspace-shell .app-main,
            .ictu-workspace-shell .app-main.ictu-admin-main,
            .ictu-workspace-shell .app-main.ictu-audit-main { padding: 16px !important; }
        }
        @media (max-width: 760px) {
            .ictu-workspace-shell { --sidebar-width: 248px; --topbar-height: 58px; }
            .ictu-workspace-shell .app-topbar { min-height: 58px !important; padding: 7px 12px !important; }
            .ictu-workspace-shell .account-menu-name { display: none; }
        }
    </style>
    {{-- ICTU COMPACT WORKSPACE END --}}

    {{-- UNIVERSAL COLLAPSIBLE SIDEBAR START --}}
    <style id="universal-collapsible-sidebar">
        :root {
            --sidebar-collapsed-width: 82px;
        }

        .sidebar-desktop-toggle {
            display: none;
        }

        .sidebar-nav .nav-label {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        @media (min-width: 1050px) {
            .app-shell {
                transition: grid-template-columns var(--motion) ease;
            }

            html[data-sidebar-state="collapsed"] .app-shell {
                grid-template-columns: var(--sidebar-collapsed-width) minmax(0, 1fr);
            }

            html[data-sidebar-state="expanded"] .app-shell {
                grid-template-columns: var(--sidebar-width) minmax(0, 1fr);
            }

            .app-sidebar {
                overflow-x: hidden !important;
                transition: width var(--motion) ease, box-shadow var(--motion) ease;
            }

            .sidebar-brand-row {
                min-height: 72px;
                padding: 10px 10px 8px !important;
            }

            .sidebar-desktop-toggle {
                display: inline-grid !important;
                width: 36px;
                height: 36px;
                flex: 0 0 36px;
                place-items: center;
                color: #526b84;
                background: rgba(255, 255, 255, .36);
                border-color: rgba(112, 143, 174, .16);
            }

            .sidebar-desktop-toggle:hover {
                color: #0d63d8;
                background: rgba(255, 255, 255, .78);
                border-color: #cfe1f4;
            }

            html[data-theme="dark"] .sidebar-desktop-toggle {
                color: #b6c8db;
                background: rgba(255, 255, 255, .04);
                border-color: rgba(255, 255, 255, .08);
            }

            html[data-theme="dark"] .sidebar-desktop-toggle:hover {
                color: #ffffff;
                background: #102a45;
                border-color: #294966;
            }

            html[data-sidebar-state="expanded"] .sidebar-brand-row {
                gap: 8px !important;
                align-items: center !important;
                padding-top: 8px !important;
                padding-bottom: 4px !important;
                box-sizing: border-box;
            }

            html[data-sidebar-state="expanded"] .sidebar-brand.sidebar-brand-logo {
                min-width: 0;
                flex: 1 1 auto;
                justify-content: flex-start !important;
                min-height: 46px !important;
                padding: 2px 0 !important;
            }

            html[data-sidebar-state="expanded"] .sidebar-brand-logo-image {
                width: 100% !important;
                max-width: 158px !important;
                max-height: 43px !important;
                object-position: left center !important;
            }

            html[data-sidebar-state="collapsed"] .sidebar-brand-row {
                justify-content: center;
                min-height: 58px;
                gap: 0 !important;
                padding: 10px 8px 6px !important;
            }

            html[data-sidebar-state="collapsed"] .sidebar-brand.sidebar-brand-logo,
            html[data-sidebar-state="collapsed"] .sidebar-label {
                display: none !important;
            }

            html[data-sidebar-state="collapsed"] .sidebar-nav {
                gap: 5px !important;
                padding: 6px 8px 12px !important;
            }

            html[data-sidebar-state="collapsed"] .sidebar-nav a {
                min-height: 44px !important;
                justify-content: center;
                gap: 0 !important;
                padding: 0 !important;
                border-radius: 12px !important;
            }

            html[data-sidebar-state="collapsed"] .sidebar-nav a .nav-icon {
                width: 22px !important;
                min-width: 22px !important;
            }

            html[data-sidebar-state="collapsed"] .sidebar-nav a .nav-icon svg {
                width: 20px !important;
                height: 20px !important;
            }

            html[data-sidebar-state="collapsed"] .sidebar-nav a .nav-label {
                display: none;
            }

            html[data-sidebar-state="collapsed"] .sidebar-nav a.active,
            html[data-sidebar-state="collapsed"] .sidebar-nav a[aria-current="page"] {
                box-shadow: 0 8px 20px rgba(13, 99, 216, .2) !important;
            }

            .sidebar-close {
                display: none !important;
            }
        }

        /* Keep the sidebar brand visible while the sidebar menu scrolls. */
        .sidebar-brand-row {
            position: sticky !important;
            top: 0 !important;
            z-index: 30 !important;
            flex: 0 0 auto !important;
            overflow: visible !important;
            background: inherit !important;
        }

        @media (max-width: 1049px) {
            .sidebar-desktop-toggle {
                display: none !important;
            }
        }
        /* SIDEBAR SECTION NAVIGATION START */
        .sidebar-subnav {
            display: grid;
            gap: 2px;
            margin: -2px 8px 4px 34px;
            padding: 2px 0 2px 10px;
            border-left: 1px solid #d9e6f3;
        }

        .sidebar-nav .sidebar-subnav a {
            min-height: 32px !important;
            padding: 6px 9px !important;
            border-radius: 9px !important;
            font-size: 11.5px !important;
            font-weight: 650 !important;
            line-height: 1.25;
            color: #60758c !important;
            background: transparent !important;
            box-shadow: none !important;
            white-space: normal;
        }

        .sidebar-nav .sidebar-subnav a:hover {
            color: #0d63d8 !important;
            background: #edf5ff !important;
            border-color: #cfe1f4 !important;
            transform: none;
        }

        .sidebar-nav .sidebar-subnav a.active,
        .sidebar-nav .sidebar-subnav a[aria-current="page"] {
            color: #0d63d8 !important;
            background: #e8f2ff !important;
            border-color: #bfd8f4 !important;
            box-shadow: inset 2px 0 #0d63d8 !important;
            transform: none;
        }

        html[data-theme="dark"] .sidebar-subnav {
            border-left-color: #294966;
        }

        html[data-theme="dark"] .sidebar-nav .sidebar-subnav a {
            color: #b8cbe0 !important;
            background: transparent !important;
        }

        html[data-theme="dark"] .sidebar-nav .sidebar-subnav a:hover {
            color: #ffffff !important;
            background: #102a45 !important;
            border-color: #294966 !important;
        }

        html[data-theme="dark"] .sidebar-nav .sidebar-subnav a.active,
        html[data-theme="dark"] .sidebar-nav .sidebar-subnav a[aria-current="page"] {
            color: #ffffff !important;
            background: #12385d !important;
            border-color: #2c7bed !important;
            box-shadow: inset 2px 0 #4c91f0 !important;
        }

        @media (min-width: 1050px) {
            html[data-sidebar-state="collapsed"] .sidebar-subnav {
                display: none !important;
            }
        }

        /* SIDEBAR SECTION ACCORDION START */

        .sidebar-subnav[hidden] {
            display: none !important;
        }

        .sidebar-section-chevron {
            width: 8px;
            height: 8px;
            flex: 0 0 8px;
            margin-left: auto;
            margin-right: 3px;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg) translateY(-2px);
            transform-origin: center;
            transition: transform .18s ease;
        }

        .sidebar-nav a[data-sidebar-section-toggle][aria-expanded="true"]
        .sidebar-section-chevron {
            transform: rotate(225deg) translateY(-2px);
        }

        @media (min-width: 1050px) {
            html[data-sidebar-state="collapsed"]
            .sidebar-section-chevron {
                display: none !important;
            }

            html[data-sidebar-state="collapsed"]
            .sidebar-subnav {
                display: none !important;
            }
        }

        /* SIDEBAR SECTION ACCORDION END */
        /* SIDEBAR SECTION NAVIGATION END */
    </style>
    {{-- UNIVERSAL COLLAPSIBLE SIDEBAR END --}}


<!-- GLOBAL APP NOTICE ALIGNMENT START -->
<style>
/*
 * SPMU universal flash / validation notice alignment.
 * Keep the notice on the normal page grid while centering
 * its icon against the complete text block.
 */

.app-main > .notice {
    box-sizing: border-box !important;
    width: 100% !important;
    max-width: none !important;

    margin: 0 0 18px !important;
    padding: 14px 16px !important;

    display: grid !important;
    grid-template-columns: 20px minmax(0, 1fr) !important;

    /*
     * Important:
     * Center the icon against the complete message,
     * including wrapped validation text.
     */
    align-items: center !important;
    column-gap: 12px !important;

    line-height: 1.45 !important;
}

/* Notice icon */
.app-main > .notice > svg,
.app-main > .notice > .ui-icon,
.app-main > .notice > [class*="icon"] {
    display: block !important;

    width: 20px !important;
    height: 20px !important;

    min-width: 20px !important;
    min-height: 20px !important;

    margin: 0 !important;

    align-self: center !important;
}

/* Main text column */
.app-main > .notice > div {
    min-width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;

    align-self: center !important;
}

/* Error heading */
.app-main > .notice strong {
    display: block !important;

    margin: 0 !important;
    padding: 0 !important;

    line-height: 1.35 !important;
}

/* Validation list */
.app-main > .notice ul {
    margin: 6px 0 0 18px !important;
    padding: 0 !important;
}

/* Individual validation message */
.app-main > .notice li {
    margin: 0 !important;
    padding: 0 0 0 1px !important;

    line-height: 1.5 !important;

    overflow-wrap: anywhere !important;
}

/*
 * Single success/status messages should also sit vertically
 * centered with their status icon.
 */
.app-main > .notice.success > div {
    display: flex !important;
    align-items: center !important;
    min-height: 20px !important;
}

/*
 * Error messages with a heading + list stay compact while
 * preserving a clear visual hierarchy.
 */
.app-main > .notice.error > div {
    display: block !important;
}

/* Narrow screen */
@media (max-width: 700px) {
    .app-main > .notice {
        grid-template-columns: 18px minmax(0, 1fr) !important;
        column-gap: 10px !important;

        padding: 12px 14px !important;
        margin-bottom: 16px !important;
    }

    .app-main > .notice > svg,
    .app-main > .notice > .ui-icon,
    .app-main > .notice > [class*="icon"] {
        width: 18px !important;
        height: 18px !important;
        min-width: 18px !important;
        min-height: 18px !important;
    }
}
</style>
<!-- GLOBAL APP NOTICE ALIGNMENT END -->
<!-- SPMU COMPACT CONSISTENCY V2 START -->
<style id="spmu-compact-consistency-v2">
@media screen and (min-width: 1050px) {
    /* Sidebar: 42px links, consistent icons, existing active/hover colors. */
    .app-shell:not(.ictu-workspace-shell) .sidebar-nav { gap: 4px !important; }
    .app-shell:not(.ictu-workspace-shell) .sidebar-nav > a {
        min-height: 42px !important;
        max-height: 42px !important;
        height: 42px !important;
        padding: 0 12px !important;
        gap: 10px !important;
        border-radius: 11px !important;
        font-size: 12.5px !important;
        line-height: 1.2 !important;
    }
    .app-shell:not(.ictu-workspace-shell) .sidebar-nav > a .nav-label {
        font-size: 12.5px !important;
        line-height: 1.2 !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
    }
    .app-shell:not(.ictu-workspace-shell) .sidebar-nav > a .nav-icon {
        flex: 0 0 18px !important;
        width: 18px !important;
        min-width: 18px !important;
        height: 18px !important;
    }
    .app-shell:not(.ictu-workspace-shell) .sidebar-nav > a .nav-icon svg {
        width: 18px !important;
        height: 18px !important;
    }
    html[data-sidebar-state="collapsed"] .app-shell:not(.ictu-workspace-shell) .sidebar-nav > a {
        justify-content: center !important;
        padding: 0 !important;
        gap: 0 !important;
    }
    html[data-sidebar-state="collapsed"] .app-shell:not(.ictu-workspace-shell) .sidebar-nav > a .nav-label {
        display: none !important;
    }

    /* Shared page hierarchy; leaves the top bar, table data and functionality intact. */
    .app-shell .app-main { padding-top: 18px !important; padding-bottom: 28px !important; min-width: 0; }
    .app-shell .app-main > .page-heading { margin-bottom: 14px !important; }
    .app-shell .app-main > .page-heading h1 { font-size: 20px !important; line-height: 1.22 !important; }
    .app-shell .app-main > .page-heading p:not(.eyebrow) { font-size: 12px !important; line-height: 1.4 !important; }
    .app-shell .app-main > .page-heading .eyebrow { font-size: 10px !important; }
    .app-shell .app-main > .content-area { margin-top: 12px; min-width: 0; }
    .app-shell .app-main .content-grid { gap: 12px; }
    .app-shell .app-main .content-area > .card { padding: 15px !important; }
    .app-shell .app-main .card-header { gap: 10px; margin-bottom: 10px; }

    /* Borrower, Action Officer and Head dashboards only. */
    .app-shell .app-main .dashboard-page-shell .dashboard-heading { margin-bottom: 12px !important; }
    .app-shell .app-main .dashboard-page-shell .dashboard-heading h1 { font-size: 20px !important; }
    .app-shell .app-main .dashboard-page-shell .dashboard-heading p:not(.eyebrow) { font-size: 12px !important; }
    .app-shell .app-main .dashboard-page-shell .dashboard-kpi-card {
        min-height: 112px !important; padding: 13px 15px !important;
    }
    .app-shell .app-main .dashboard-page-shell .dashboard-primary-queue-row {
        padding-top: 9px !important; padding-bottom: 9px !important;
    }

    /* Ordinary screen tables (not PDF/print previews): same comfortable density. */
    .app-shell .app-main .content-area > .table-wrap > table thead th {
        padding-top: 9px !important; padding-bottom: 9px !important; font-size: 11px !important;
    }
    .app-shell .app-main .content-area > .table-wrap > table tbody td {
        padding-top: 10px !important; padding-bottom: 10px !important;
        font-size: 12.5px !important; line-height: 1.35 !important;
    }

    /* Request Records and Release & Return filters, without touching date validation. */
    .app-shell .app-main .record-browser-toolbar,
    .app-shell .app-main .operational-browser-toolbar {
        padding: 12px !important; gap: 10px !important; margin-bottom: 12px !important;
    }
    .app-shell .app-main .record-browser-toolbar label,
    .app-shell .app-main .operational-browser-toolbar label { font-size: 12px !important; }
    .app-shell .app-main .record-browser-toolbar input,
    .app-shell .app-main .record-browser-toolbar select,
    .app-shell .app-main .operational-browser-toolbar input,
    .app-shell .app-main .operational-browser-toolbar select {
        font-size: 12px !important; min-height: 36px !important;
    }
    .app-shell .app-main .record-browser-toolbar + .table-wrap > table th,
    .app-shell .app-main .record-browser-toolbar + .table-wrap > table td {
        padding-top: 9px !important; padding-bottom: 9px !important; font-size: 12px !important;
    }

    /* Operational Audit screen only: text/readability with shorter rows. */
    .app-shell.ictu-audit-shell .app-main .spmu-operational-audit-area { gap: 10px !important; }
    .app-shell.ictu-audit-shell .app-main .spmu-operational-audit-area .ictu-audit-filter-grid {
        padding: 12px !important; gap: 10px !important;
    }
    .app-shell.ictu-audit-shell .app-main .spmu-operational-audit-area .ictu-audit-table-toolbar {
        min-height: 46px !important; padding: 8px 12px !important;
    }
    .app-shell.ictu-audit-shell .app-main .spmu-operational-audit-area .ictu-audit-table-heading h1 {
        font-size: 14px !important;
    }
    .app-shell.ictu-audit-shell .app-main .spmu-operational-audit-area .ictu-audit-table th {
        font-size: 10.5px !important; padding: 9px 10px !important;
    }
    .app-shell.ictu-audit-shell .app-main .spmu-operational-audit-area .ictu-audit-table td {
        height: 49px !important; padding: 7px 10px !important;
        font-size: 11.5px !important; line-height: 1.32 !important;
    }
    .app-shell.ictu-audit-shell .app-main .spmu-operational-audit-area .ictu-audit-expanded-row td {
        height: auto !important;
    }
    .app-shell.ictu-audit-shell .app-main .spmu-operational-audit-area .ictu-audit-view-button {
        min-height: 32px !important; font-size: 11px !important;
    }

    /* Operational Configuration: don't stretch two small groups to viewport height. */
    .app-shell .app-main > .operational-config-hub {
        flex: 0 0 auto !important; margin-top: 12px !important;
    }
    .app-shell .app-main .operational-config-group { gap: 10px !important; margin-bottom: 14px !important; }
    .app-shell .app-main .operational-config-grid { gap: 12px !important; }
    .app-shell .app-main .operational-config-card {
        min-height: 72px !important; padding: 13px 16px !important; gap: 12px !important;
    }

    /* Calendar: compact controls, never compress the actual event grid. */
    .app-shell .app-main .borrowing-calendar .calendar-toolbar { gap: 10px !important; }
    .app-shell .app-main .borrowing-calendar .calendar-month-title { font-size: 15px !important; }
}
</style>
<!-- SPMU COMPACT CONSISTENCY V2 END -->
<style>
/* SIDEBAR ACTION BADGES START - soft, compact, readable counters */
.sidebar-nav > a { position: relative; }
.sidebar-nav > a .sidebar-action-count {
    margin-left: auto;
    flex: 0 0 auto;
    min-width: 21px;
    height: 21px;
    padding: 0 6px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #e4f2ff;
    color: #175380;
    font-size: 11px;
    line-height: 1;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    box-sizing: border-box;
    box-shadow: none;
    text-shadow: none;
}
.sidebar-nav > a .sidebar-action-count[hidden] { display: none !important; }
/* Keep tones in the data, but use one subtle visual style across the sidebar. */
.sidebar-nav > a .sidebar-action-count.is-urgent,
.sidebar-nav > a .sidebar-action-count.is-soon {
    background: #e4f2ff;
    color: #175380;
}
/* The light pill remains legible on the selected blue menu and on hover. */
.sidebar-nav > a.active .sidebar-action-count,
.sidebar-nav > a[aria-current="page"] .sidebar-action-count,
.sidebar-nav > a:hover .sidebar-action-count {
    background: #e4f2ff;
    color: #175380;
    box-shadow: none;
}
/* Section toggles share one right-hand chevron column; a visible count sits just before it. */
.sidebar-nav > a[data-sidebar-section-toggle] .sidebar-action-count {
    margin-left: auto;
}
.sidebar-nav > a[data-sidebar-section-toggle] .sidebar-section-chevron {
    margin-left: auto;
    align-self: center;
}
.sidebar-nav > a[data-sidebar-section-toggle] .sidebar-action-count:not([hidden]) + .sidebar-section-chevron {
    margin-left: 0;
}
html[data-theme="dark"] .sidebar-nav > a .sidebar-action-count,
html[data-theme="dark"] .sidebar-nav > a .sidebar-action-count.is-urgent,
html[data-theme="dark"] .sidebar-nav > a .sidebar-action-count.is-soon,
html[data-theme="dark"] .sidebar-nav > a.active .sidebar-action-count,
html[data-theme="dark"] .sidebar-nav > a:hover .sidebar-action-count {
    background: #294e6d;
    color: #f3f9ff;
}
@media (min-width:1050px) {
    html[data-sidebar-state="collapsed"] .sidebar-nav > a .sidebar-action-count {
        position: absolute;
        margin: 0;
        right: 2px;
        top: 2px;
        min-width: 17px;
        height: 17px;
        padding: 0 3px;
        font-size: 9px;
        z-index: 1;
    }
}
/* SIDEBAR ACTION BADGES END */
</style>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
@auth
    @php
        $activeWorkspace = auth()->user()->primaryWorkspace();
        $unread = App\Models\NotificationDelivery::where('recipient_user_id', auth()->id())->where('channel', 'SYSTEM')->whereNull('read_at')->count();
        $classification = auth()->user()->access_classification;
        // Load advisory badge counts asynchronously; never block HTML page rendering
        // on potentially expensive accountability work-queue queries.
        $sidebarActionCounts = [];
        $sidebarTones = [];
        $navigation = match ($activeWorkspace) {
            'BORROWER' => [
                ['dashboard', 'dashboard', 'Dashboard', 'dashboard'],
                ['inventory.index', 'inventory.*', 'Available Items', 'inventory'],
                ['requests.index', 'requests.*', 'My Requests', 'requests'],
                ['custody.index', 'custody.*', 'My Borrowings', 'custody'],
                ['calendar.index', 'calendar.*', 'Borrowing Calendar', 'calendar'],
                ['accountability.index', 'accountability.*', 'My Obligations', 'accountability'],
            ],
            'SPMU' => $classification === App\Enums\AccessClassification::SpmuHead
                ? [
                    ['dashboard', 'dashboard', 'Dashboard', 'dashboard'],
                    ['approvals.index', 'approvals.*', 'For Approval', 'approval'],
                    ['requests.index', 'requests.*', 'Request Records', 'requests'],
                    ['inventory.index', 'inventory.*', 'Inventory', 'inventory'],
                    ['calendar.index', 'calendar.*', 'Operations Calendar', 'calendar'],
                    ['accountability.index', 'accountability.*', 'Accountability', 'accountability'],
                    ['analytics.index', 'analytics.*', 'Analytics', 'analytics'],
                    ['reports.index', 'reports.index', 'Reports', 'report-document'],
                    ['reports.audit', 'reports.audit', 'Operational Audit Trail', 'reports'],
                    ['policies.index', 'policies.*', 'Configuration', 'settings'],
                    ['administration.users.index', 'administration.users.*', 'User Accounts', 'users'],
                    /* System Configuration and Delivery Records are ICTU technical custody and have no menu entry. */
                ]
                : [
                    ['dashboard', 'dashboard', 'Dashboard', 'dashboard'],
                    ['verifications.index', 'verifications.*', 'For Verification', 'approval'],
                    ['registrations.index', 'registrations.*', 'Borrower Registrations', 'users'],
                    ['requests.index', 'requests.*', 'Request Records', 'requests'],
                    ['custody.release.index', 'custody.release.*', 'Release', 'upload'],
                    ['custody.return.index', 'custody.return.*', 'Return', 'custody'],
                    ['laundry.index', 'laundry.*', 'Laundry', 'success'],
                    ['gate-passes.index', 'gate-passes.*', 'Gate Pass', 'requests'],
                    ['inventory.index', 'inventory.*', 'Inventory', 'inventory'],
                    ['calendar.index', 'calendar.*', 'Operations Calendar', 'calendar'],
                    ['accountability.index', 'accountability.*', 'Accountability', 'accountability'],
                ],
            default => [],
        };
    @endphp
    <div class="app-shell {{ $activeWorkspace === 'ICTU' ? 'ictu-workspace-shell' : '' }} {{ request()->routeIs('administration.users.*') ? 'ictu-shell' : (request()->routeIs('reports.audit') ? 'ictu-audit-shell' : '') }}">
        <aside class="app-sidebar" id="primary-sidebar">
            <div class="sidebar-brand-row">
                <button class="icon-button sidebar-desktop-toggle" type="button" aria-label="Expand main menu" title="Expand main menu" aria-controls="primary-sidebar" aria-expanded="false" data-sidebar-desktop-toggle><x-icon name="menu" /></button>
                <a class="brand sidebar-brand sidebar-brand-logo" href="{{ route('dashboard') }}" aria-label="SPMU-ACPMP dashboard">
                    <img
                        class="sidebar-brand-logo-image"
                        src="{{ \App\Support\StaticAsset::url('images/spmu-acpmp-logo.png') }}"
                        alt="SPMU-ACPMP Ã¢â‚¬â€ Supply and Property Management Unit, Asset Custody Monitoring Program"
                    >
                </a>
                <button class="icon-button sidebar-close" type="button" aria-label="Close main menu" title="Close main menu" data-sidebar-close><x-icon name="close" /></button>
            </div>
            <p class="sidebar-label">Main menu</p>
            <nav class="sidebar-nav" aria-label="Primary navigation">
                @php
                    /*
                     * Preserve the current Analytics scope while moving
                     * between its sidebar sections.
                     */
                    $analyticsSidebarCarry = request()->routeIs('analytics.*')
                        ? request()->only([
                            'academic_period',
                            'borrower_type',
                            'group',
                            'unit',
                        ])
                        : [];

                    $analyticsSidebarSection = (string) request('section', 'overview');

                    $accountabilitySidebarQueue = (string) request('queue', 'work');
                @endphp

                @foreach($navigation as $navItem)
                    @php
                        [$routeName, $routePattern, $label, $icon] = $navItem;
                        // Optional fifth element: query parameters that also decide the active state.
                        $navParameters = $navItem[4] ?? [];
                        $isNavActive = request()->routeIs($routePattern)
                            && collect($navParameters)->every(fn ($value, $key) => request()->query($key) === $value);
                        $sidebarCount = max(0, (int) ($sidebarActionCounts[$routeName] ?? 0));

                        $hasSidebarSections =
                            $activeWorkspace === 'SPMU'
                            && in_array($routeName, [
                                'accountability.index',
                                'analytics.index',
                            ], true);

                        $sidebarSectionId = $routeName === 'accountability.index'
                            ? 'sidebar-section-accountability'
                            : ($routeName === 'analytics.index'
                                ? 'sidebar-section-analytics'
                                : null);
                    @endphp

                    <a
                        class="interactive {{ $isNavActive ? 'active' : '' }}"
                        href="{{ route($routeName, $navParameters) }}"
                        aria-label="{{ $label }}"
                        data-sidebar-tooltip="{{ $label }}"
                        data-sidebar-count-route="{{ $routeName }}"
                        @if($hasSidebarSections)
                            data-sidebar-section-toggle
                            aria-expanded="false"
                            aria-controls="{{ $sidebarSectionId }}"
                        @elseif($isNavActive)
                            aria-current="page"
                        @endif
                    >
                        <span class="nav-icon nav-icon-{{ $icon }}">
                            <x-icon :name="$icon" />
                        </span>

                        <span class="nav-label">{{ $label }}</span>
                        <span
                            class="sidebar-action-count {{ match($sidebarTones[$routeName] ?? 'normal') { 'urgent' => 'is-urgent', 'soon' => 'is-soon', default => '' } }}"
                            data-sidebar-action-count
                            @if($sidebarCount < 1) hidden @endif
                            aria-hidden="true"
                        >{{ $sidebarCount > 99 ? '99+' : $sidebarCount }}</span>

                        @if($hasSidebarSections)
                            <span
                                class="sidebar-section-chevron"
                                aria-hidden="true"
                            ></span>
                        @endif
                    </a>

                    @if($routeName === 'accountability.index' && $activeWorkspace === 'SPMU')
                        <div
                            class="sidebar-subnav"
                            id="sidebar-section-accountability"
                            aria-label="Accountability sections"
                            hidden
                        >
                            <a
                                class="interactive {{ request()->routeIs('accountability.index') && $accountabilitySidebarQueue === 'work' ? 'active' : '' }}"
                                href="{{ route('accountability.index', ['queue' => 'work']) }}#accountability-workspace"
                                data-sidebar-tooltip="{{ $classification === App\Enums\AccessClassification::SpmuHead ? 'Needs My Decision' : 'For Follow-Up' }}"
                                @if(request()->routeIs('accountability.index') && $accountabilitySidebarQueue === 'work') aria-current="page" @endif
                            >
                                {{ $classification === App\Enums\AccessClassification::SpmuHead ? 'Needs My Decision' : 'For Follow-Up' }}
                            </a>

                            <a
                                class="interactive {{ request()->routeIs('accountability.borrower') || (request()->routeIs('accountability.index') && $accountabilitySidebarQueue === 'all') ? 'active' : '' }}"
                                href="{{ route('accountability.index', ['queue' => 'all']) }}#accountability-workspace"
                                data-sidebar-tooltip="All Active Cases"
                                @if(request()->routeIs('accountability.borrower') || (request()->routeIs('accountability.index') && $accountabilitySidebarQueue === 'all')) aria-current="page" @endif
                            >
                                All Active Cases
                            </a>

                            @if($classification === App\Enums\AccessClassification::SpmuHead)
                                <a
                                    class="interactive {{ request()->routeIs('accountability.index') && $accountabilitySidebarQueue === 'sanctions' ? 'active' : '' }}"
                                    href="{{ route('accountability.index', ['queue' => 'sanctions']) }}#accountability-workspace"
                                    data-sidebar-tooltip="Sanctions"
                                    @if(request()->routeIs('accountability.index') && $accountabilitySidebarQueue === 'sanctions') aria-current="page" @endif
                                >
                                    Sanctions
                                </a>
                            @endif

                            <a
                                class="interactive {{ request()->routeIs('accountability.index') && $accountabilitySidebarQueue === 'history' ? 'active' : '' }}"
                                href="{{ route('accountability.index', ['queue' => 'history']) }}#accountability-workspace"
                                data-sidebar-tooltip="History"
                                @if(request()->routeIs('accountability.index') && $accountabilitySidebarQueue === 'history') aria-current="page" @endif
                            >
                                History
                            </a>
                        </div>

                    @elseif($routeName === 'analytics.index' && $activeWorkspace === 'SPMU')

                        <div
                            class="sidebar-subnav"
                            id="sidebar-section-analytics"
                            aria-label="Analytics sections"
                            hidden
                        >
                            @foreach(App\Http\Controllers\AnalyticsController::SECTIONS as $analyticsKey => $analyticsLabel)

                                <a
                                    class="interactive {{ request()->routeIs('analytics.*') && $analyticsSidebarSection === $analyticsKey ? 'active' : '' }}"
                                    href="{{ route('analytics.index', array_merge($analyticsSidebarCarry, ['section' => $analyticsKey])) }}"
                                    data-sidebar-tooltip="{{ $analyticsLabel }}"
                                    @if(request()->routeIs('analytics.*') && $analyticsSidebarSection === $analyticsKey) aria-current="page" @endif
                                >
                                    {{ $analyticsLabel }}
                                </a>

                            @endforeach
                        </div>

                    @endif
                @endforeach
            </nav>

        </aside>
{{-- SIDEBAR ACTION COUNTS AUTO-REFRESH START --}}
        <script>
        (function () {
            'use strict';
            const nav = document.querySelector('#primary-sidebar .sidebar-nav');
            if (!nav) return;
            const links = Array.from(nav.querySelectorAll('a[data-sidebar-count-route]'));
            if (!links.length) return;
            const url = @json(route('sidebar.actions.status'));
            let refreshing = false;
            const apply = function (counts, tones) {
                if (!counts || typeof counts !== 'object') return;
                links.forEach(function (link) {
                    const badge = link.querySelector('[data-sidebar-action-count]');
                    if (!badge) return;
                    const raw = Number(counts[link.dataset.sidebarCountRoute] || 0);
                    const count = Number.isFinite(raw) ? Math.max(0, Math.floor(raw)) : 0;
                    const label = link.dataset.sidebarTooltip || '';
                    const tone = tones && tones[link.dataset.sidebarCountRoute] || 'normal';
                    badge.classList.toggle('is-urgent', tone === 'urgent');
                    badge.classList.toggle('is-soon', tone === 'soon');
                    badge.hidden = count < 1;
                    badge.textContent = count > 99 ? '99+' : String(count);
                    link.setAttribute('aria-label', count ? label + ': ' + count + ' pending' : label);
                });
            };
            const refresh = async function () {
                if (refreshing || document.hidden) return;
                refreshing = true;
                try {
                    const response = await fetch(url, {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { Accept: 'application/json' }
                    });
                    if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) return;
                    const payload = await response.json();
                    apply(payload.counts, payload.tones);
                } catch (_) {
                    // Keep the last server-rendered counts when offline.
                } finally {
                    refreshing = false;
                }
            };
            // Render the page first, then load current role-specific badge counts.
            // The existing endpoint still enforces authentication and authorization.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', refresh, { once: true });
            } else {
                refresh();
            }
            setInterval(refresh, 120000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) refresh();
            });
        })();
        </script>
        {{-- SIDEBAR ACTION COUNTS AUTO-REFRESH END --}}
        <button class="sidebar-backdrop" type="button" aria-label="Close main menu" tabindex="-1" data-sidebar-close></button>
        <div class="app-stage">
            <header class="app-topbar">
                <button class="icon-button menu-toggle" type="button" aria-label="Open main menu" title="Open main menu" aria-controls="primary-sidebar" aria-expanded="false" data-sidebar-toggle><x-icon name="menu" /></button>
                <div class="topbar-title">
                    <strong>{{ isset($topbarTitle) ? $topbarTitle : (isset($title) ? $title : 'Dashboard') }}</strong>
                </div>
                <nav class="account-nav" aria-label="Account navigation">
                    <a class="icon-button interactive notification-control" href="{{ route('notifications.index') }}" aria-label="Notifications{{ $unread ? ': '.$unread.' unread' : '' }}" title="Notifications">
                        <x-icon name="notifications" />
                        @if($unread)<span class="notification-count" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>@endif
                    </a>
                    <x-account-menu :user="auth()->user()" />
                </nav>
            </header>
            <main class="app-main {{ request()->routeIs('administration.users.*') ? 'ictu-admin-main' : (request()->routeIs('reports.audit') ? 'ictu-audit-main' : '') }}" id="main-content" tabindex="-1">
                @unless($inlinePageNotices ?? false)
                @if(session('status'))
                    <div class="notice success" role="status">
                        <x-icon name="success" />
                        <div>{{ session('status') }}</div>
                    </div>
                @endif

                @if($errors->any() && ! request()->routeIs('requests.show'))
                    <div class="notice error" role="alert">
                        <x-icon name="error" />
                        <div>
                            <strong>Please correct the following:</strong>
                            <ul>
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif
                @endunless
                @yield('content')
            </main>
        </div>
    </div>
@else
    {{-- LOGIN PORTAL HEADER VISIBILITY START --}}
    @unless(request()->routeIs('home', 'login'))
    <header class="site-header public-header">
        <a class="brand" href="{{ route('home') }}" aria-label="SPMU-ACPMP home">
            <img
                class="brand-logo-image"
                src="{{ \App\Support\StaticAsset::url('images/spmu-acpmp-logo.png') }}"
                alt="SPMU-ACPMP Ã¢â‚¬â€ Supply and Property Management Unit, Asset Custody Monitoring Program"
            >
        </a>
                @unless(request()->routeIs('home', 'login'))
        <nav aria-label="Public navigation">
            <a class="public-signin" href="{{ route('login') }}">Sign in</a>
        </nav>
        @endunless
    </header>
    @endunless
    {{-- LOGIN PORTAL HEADER VISIBILITY END --}}
    <main id="main-content" tabindex="-1">
        @unless(($inlinePageNotices ?? false) || request()->routeIs('home', 'login'))
        @if(session('status'))<div class="notice success" role="status"><x-icon name="success" /><div>{{ session('status') }}</div></div>@endif
        @if($errors->any())<div class="notice error" role="alert"><x-icon name="error" /><div><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
        @endunless
        @yield('content')
    </main>
    <footer><span>Camarines Sur Polytechnic Colleges</span><span><a href="{{ route('legal.privacy') }}">Privacy Policy</a> &middot; <a href="{{ route('legal.terms') }}">Terms of Service</a></span><span>Official operational time: Asia/Manila</span></footer>
@endauth

{{-- Universal transaction/detail typography: keep operational details readable and compact. --}}
<style id="universal-detail-typography">
    :root {
        --detail-section-title-size: 17px;
        --detail-label-size: 12px;
        --detail-value-size: 13px;
        --detail-meta-size: 12px;
    }

    /* Section/card headings: consistent with the compact Return workspace. */
    .release-flow-page .release-card-title h2,
    .request-operational-page .request-section-title,
    .request-operational-page .request-section-title h2,
    .borrower-custody-stack .card-header h2,
    .laundry-detail h2,
    .gate-pass-detail-grid .card-header h2,
    .dashboard-balanced-grid > .queue-card > .card-header h2,
    .dashboard-recent-card > .card-header h2 {
        font-size: var(--detail-section-title-size);
        line-height: 1.4;
    }

    /* Detail labels and values use one readable scale; layout remains page-specific. */
    .release-flow-page .release-context-list dt,
    .request-operational-page .request-information-list dt,
    .borrower-custody-stack .borrower-summary-fact small,
    .laundry-detail .laundry-linen-facts dt,
    .gate-pass-detail-grid .detail-list dt {
        font-size: var(--detail-label-size);
        line-height: 1.5;
    }

    .release-flow-page .release-context-list dd,
    .request-operational-page .request-information-list dd,
    .borrower-custody-stack .borrower-summary-fact strong,
    .laundry-detail .laundry-linen-facts dd,
    .gate-pass-detail-grid .detail-list dd {
        font-size: var(--detail-value-size);
        line-height: 1.5;
    }

    /* Supporting copy should never compete visually with the actual transaction values. */
    .release-flow-page .release-page-heading > div > p:last-child,
    .request-operational-page .request-operational-identity > p,
    .laundry-detail .laundry-detail-heading p:not(.eyebrow),
    .dashboard-balanced-grid > .queue-card > .card-header .meta,
    .dashboard-recent-card > .card-header .meta,
    .dashboard-role-section-note {
        font-size: var(--detail-meta-size);
        line-height: 1.5;
    }

    /* Dashboard figures/statuses stay intentionally distinct; only record text is normalized. */
    .dashboard-watch-copy strong,
    .dashboard-recent-copy strong,
    .dashboard-primary-queue-row > div strong {
        font-size: 12px;
    }

    .dashboard-watch-copy span,
    .dashboard-recent-copy span,
    .dashboard-primary-queue-row > div span {
        font-size: var(--detail-value-size);
    }

    .dashboard-watch-copy small,
    .dashboard-recent-copy small,
    .dashboard-primary-queue-row > div small {
        font-size: 11.5px;
    }

    @media (max-width: 760px) {
        :root { --detail-section-title-size: 16px; }
    }
</style>

<script src="{{ \App\Support\StaticAsset::url('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ \App\Support\StaticAsset::url('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
    {{-- SPMU-ACPMP ANTI-DUPLICATE-SUBMISSION START --}}
    <script>
    (() => {
        'use strict';

        const TOKEN_NAME = '_action_token';

        function createActionToken() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }

            return [
                Date.now().toString(36),
                Math.random().toString(36).slice(2),
                Math.random().toString(36).slice(2)
            ].join('-');
        }

        function ensureActionToken(form) {
            let input = form.querySelector(`input[name="${TOKEN_NAME}"]`);
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = TOKEN_NAME;
                input.value = createActionToken();
                form.appendChild(input);
            }
            if (!input.value) input.value = createActionToken();
            return input.value;
        }

        function isStateChangingForm(form) {
            return (form.getAttribute('method') || 'GET').toUpperCase() !== 'GET';
        }

        // A submit request that never navigates away (e.g. a dropped
        // connection) would otherwise leave the form locked forever. This
        // safety net re-enables it automatically; it never fires on a normal
        // submission because the page has already navigated away by then.
        const STUCK_SUBMIT_TIMEOUT_MS = 20000;

        function lockForm(form) {
            form.dataset.submitLocked = '1';
            form.setAttribute('aria-busy', 'true');

            // The clicked submit button keeps its normal visible label - only
            // its interactivity is locked - and every other submit control in
            // the same form is locked with it so a second, conflicting action
            // cannot be started while the first is still in flight.
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((control) => {
                control.style.pointerEvents = 'none';
                control.setAttribute('aria-disabled', 'true');
            });

            form.dataset.submitLockTimer = String(window.setTimeout(() => {
                unlockForm(form);
            }, STUCK_SUBMIT_TIMEOUT_MS));
        }

        function unlockForm(form) {
            delete form.dataset.submitLocked;
            form.removeAttribute('aria-busy');

            if (form.dataset.submitLockTimer) {
                window.clearTimeout(Number(form.dataset.submitLockTimer));
                delete form.dataset.submitLockTimer;
            }

            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((control) => {
                control.style.pointerEvents = '';
                control.removeAttribute('aria-disabled');
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('form').forEach((form) => {
                if (isStateChangingForm(form)) ensureActionToken(form);
            });
        });

        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (!isStateChangingForm(form)) return;
            if (form.dataset.allowRepeatedSubmit === 'true') return;

            ensureActionToken(form);

            if (form.dataset.submitLocked === '1') {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }

            lockForm(form);
        }, true);

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (!(node instanceof Element)) return;
                    if (node.matches('form') && isStateChangingForm(node)) ensureActionToken(node);
                    node.querySelectorAll?.('form').forEach((form) => {
                        if (isStateChangingForm(form)) ensureActionToken(form);
                    });
                });
            });
        });

        observer.observe(document.documentElement, { childList: true, subtree: true });

        window.addEventListener('pageshow', (event) => {
            if (!event.persisted) return;

            document.querySelectorAll('form[data-submit-locked="1"]').forEach((form) => {
                unlockForm(form);
                const token = form.querySelector(`input[name="${TOKEN_NAME}"]`);
                if (token) token.value = createActionToken();
            });
        });
    })();
    </script>
    {{-- SPMU-ACPMP ANTI-DUPLICATE-SUBMISSION END --}}

    {{-- SPMU-ACPMP NUMBER INPUT SAFETY START --}}
    <style>
        /*
         * Hide native spinner arrows on all numeric fields so a stray click
         * cannot silently bump a manually-typed value.
         */
        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        input[type="number"] {
            -moz-appearance: textfield;
        }
    </style>
    <script>
    (() => {
        'use strict';

        /*
         * Prevent mouse-wheel / touchpad scrolling from changing the value of
         * a focused <input type="number">. Only acts while the field is
         * focused, so normal page scrolling while the pointer happens to
         * pass over a number field elsewhere in the app is never affected.
         */
        document.addEventListener('wheel', (event) => {
            const input = event.target;
            if (
                input instanceof HTMLInputElement &&
                input.type === 'number' &&
                document.activeElement === input
            ) {
                event.preventDefault();
            }
        }, { passive: false, capture: true });

        /*
         * Prevent Arrow Up / Arrow Down from stepping the value of a focused
         * <input type="number">. Every other key (digits, Backspace, Delete,
         * Tab, Shift+Tab, arrow-left/right for cursor movement, etc.) is left
         * untouched, so typing and keyboard navigation keep working normally.
         */
        document.addEventListener('keydown', (event) => {
            const input = event.target;
            if (
                (event.key === 'ArrowUp' || event.key === 'ArrowDown') &&
                input instanceof HTMLInputElement &&
                input.type === 'number'
            ) {
                event.preventDefault();
            }
        }, { capture: true });
    })();
    </script>
    {{-- SPMU-ACPMP NUMBER INPUT SAFETY END --}}


@auth

<style>
/* SPMU SESSION ENDED MODAL START */

/*
|--------------------------------------------------------------------------
| Compact login-matched session ended UI
|--------------------------------------------------------------------------
*/

.spmu-session-ended-overlay {
    position: fixed;
    inset: 0;
    z-index: 999999;

    display: grid;
    place-items: center;

    box-sizing: border-box;
    padding: 18px;

    background: rgba(246, 249, 252, .78);

    -webkit-backdrop-filter: blur(4px);
    backdrop-filter: blur(4px);
}


/*
|--------------------------------------------------------------------------
| Compact card
|--------------------------------------------------------------------------
*/
.spmu-session-ended-dialog {
    width: min(100%, 380px);

    box-sizing: border-box;

    padding: 22px 22px 20px;

    border: 1px solid #d6e1ed;
    border-radius: 11px;

    background: #ffffff;

    box-shadow:
        0 16px 38px rgba(37, 61, 86, .13),
        0 3px 10px rgba(37, 61, 86, .05);

    color: #243f5b;

    font-family: inherit !important;

    text-align: center;
}


/*
|--------------------------------------------------------------------------
| Lock icon
|--------------------------------------------------------------------------
*/
.spmu-session-ended-icon {
    display: grid;
    place-items: center;

    width: 44px;
    height: 44px;

    margin: 0 auto 13px;

    border: 1px solid #d8e7fb;
    border-radius: 50%;

    background: #eef5ff;
    color: #0969e8;
}

.spmu-session-ended-icon svg {
    width: 19px;
    height: 19px;

    fill: none;
    stroke: currentColor;
}


/*
|--------------------------------------------------------------------------
| Heading
|--------------------------------------------------------------------------
*/
.spmu-session-ended-dialog h2 {
    margin: 0;

    color: #243f5b;

    font-family: inherit !important;

    font-size: 20px !important;
    font-weight: 750 !important;
    line-height: 1.2 !important;

    letter-spacing: -.025em !important;
}


/*
|--------------------------------------------------------------------------
| Supporting text
|--------------------------------------------------------------------------
*/
.spmu-session-ended-dialog p {
    margin: 6px auto 0;

    max-width: 285px;

    color: #62758a;

    font-family: inherit !important;

    font-size: 11.5px;
    font-weight: 500;
    line-height: 1.45;
}


/*
|--------------------------------------------------------------------------
| OK button
|--------------------------------------------------------------------------
*/
.spmu-session-ended-dialog .spmu-session-ended-ok.button.primary {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;

    width: 100% !important;
    min-height: 40px !important;

    margin: 18px 0 0 !important;
    padding: 8px 14px !important;

    border: 1px solid #0969e8 !important;
    border-radius: 8px !important;

    background: #0969e8 !important;
    color: #ffffff !important;

    box-shadow: none !important;

    font-family: inherit !important;
    font-size: 12px !important;
    font-weight: 750 !important;
    line-height: 1.2 !important;

    cursor: pointer !important;

    transform: none !important;

    transition:
        background-color .16s ease,
        border-color .16s ease,
        box-shadow .16s ease !important;
}

.spmu-session-ended-dialog .spmu-session-ended-ok.button.primary:hover {
    border-color: #064eb0 !important;
    background: #064eb0 !important;
    color: #ffffff !important;

    box-shadow:
        0 4px 12px rgba(9, 105, 232, .14) !important;

    transform: none !important;
}

.spmu-session-ended-dialog .spmu-session-ended-ok.button.primary:focus-visible {
    outline: none !important;

    border-color: #0969e8 !important;

    box-shadow:
        0 0 0 3px rgba(9, 105, 232, .16) !important;
}

.spmu-session-ended-dialog .spmu-session-ended-ok.button.primary:active {
    background: #064eb0 !important;
    border-color: #064eb0 !important;

    transform: scale(.99) !important;
}


/*
|--------------------------------------------------------------------------
| Expired page lock
|--------------------------------------------------------------------------
*/
html.spmu-session-ended,
html.spmu-session-ended body {
    overflow: hidden !important;
}


/*
|--------------------------------------------------------------------------
| Mobile
|--------------------------------------------------------------------------
*/
@media (max-width: 520px) {
    .spmu-session-ended-overlay {
        padding: 15px;
    }

    .spmu-session-ended-dialog {
        width: 100%;

        padding: 21px 18px 18px;
    }

    .spmu-session-ended-dialog h2 {
        font-size: 19px !important;
    }
}

/* SPMU SESSION ENDED MODAL END */
</style>

<script>
/* SPMU IDLE SESSION MONITOR START */
(() => {
    const timeoutMinutes = Number(
        @json((int) config('session.idle_timeout', 15))
    );

    const timeoutMs = Math.max(
        1,
        timeoutMinutes
    ) * 60 * 1000;

    const activityRoute = @json(route('session.activity'));
    const logoutRoute = @json(route('logout'));
    const loginRoute = @json(route('login'));
    const csrfToken = @json(csrf_token());

    const userKey = @json(
        'spmu-acpmp.idle.' .
        hash('sha256', (string) auth()->id())
    );

    let lastActivity = Date.now();
    let lastStorageWrite = 0;
    let lastHeartbeat = 0;

    let logoutStarted = false;
    let sessionEndedDialogShown = false;

    /*
     * Loading an authenticated page counts as initial activity.
     */
    try {
        localStorage.setItem(
            userKey,
            String(lastActivity)
        );
    } catch (_) {
        // Local timer remains functional without localStorage.
    }


    const syncStorage = (timestamp) => {
        /*
         * Avoid excessive storage writes during continuous mouse movement.
         */
        if (timestamp - lastStorageWrite < 5000) {
            return;
        }

        lastStorageWrite = timestamp;

        try {
            localStorage.setItem(
                userKey,
                String(timestamp)
            );
        } catch (_) {
            // Ignore storage restrictions.
        }
    };


    const heartbeat = async (timestamp) => {
        /*
         * Real user activity can refresh the server clock at most once
         * per minute. Background application polling never calls this.
         */
        if (
            logoutStarted ||
            timestamp - lastHeartbeat < 60000
        ) {
            return;
        }

        lastHeartbeat = timestamp;

        try {
            await fetch(
                activityRoute,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                }
            );
        } catch (_) {
            /*
             * Server-side idle enforcement still protects the next request
             * if the temporary heartbeat request cannot be delivered.
             */
        }
    };


    const showSessionEndedDialog = () => {
        if (sessionEndedDialogShown) {
            return;
        }

        sessionEndedDialogShown = true;

        document.documentElement.classList.add(
            'spmu-session-ended'
        );

        const overlay = document.createElement('div');

        overlay.className =
            'spmu-session-ended-overlay';

        overlay.setAttribute(
            'data-session-ended-overlay',
            ''
        );

        overlay.innerHTML = `
            <section
                class="spmu-session-ended-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="spmu-session-ended-title"
                aria-describedby="spmu-session-ended-message"
            >
                <div
                    class="spmu-session-ended-icon"
                    aria-hidden="true"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <rect
                            x="5"
                            y="10"
                            width="14"
                            height="10"
                            rx="2"
                        ></rect>

                        <path
                            d="M8 10V7a4 4 0 0 1 8 0v3"
                        ></path>

                        <path
                            d="M12 14v2"
                        ></path>
                    </svg>
                </div>

                <h2 id="spmu-session-ended-title">
                    Session ended
                </h2>

                <p id="spmu-session-ended-message">
                    Your session ended. Please sign in again.
                </p>

                <button
                    type="button"
                    class="button primary spmu-session-ended-ok"
                    data-session-ended-ok
                >
                    OK
                </button>
            </section>
        `;

        /*
         * Clicking the faded area intentionally does nothing.
         * The user must acknowledge the ended session through OK.
         */
        overlay.addEventListener(
            'click',
            (event) => {
                if (event.target === overlay) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            }
        );

        document.body.appendChild(overlay);

        const okButton = overlay.querySelector(
            '[data-session-ended-ok]'
        );

        okButton?.addEventListener(
            'click',
            () => {
                /*
                 * replace() removes the expired authenticated page from
                 * the browser history before opening Login.
                 */
                window.location.replace(loginRoute);
            }
        );

        /*
         * Escape must not dismiss a security/session-expiration dialog.
         */
        document.addEventListener(
            'keydown',
            (event) => {
                if (
                    sessionEndedDialogShown &&
                    event.key === 'Escape'
                ) {
                    event.preventDefault();
                    event.stopImmediatePropagation();

                    okButton?.focus();
                }
            },
            true
        );

        window.setTimeout(
            () => okButton?.focus(),
            0
        );
    };


    const logoutForIdle = () => {
        if (logoutStarted) {
            return;
        }

        logoutStarted = true;

        /*
         * Keep the current page on screen, but immediately cover and disable
         * it. The server logout request is sent without navigating the page.
         */
        showSessionEndedDialog();

        const formData = new FormData();

        formData.append(
            '_token',
            csrfToken
        );

        formData.append(
            'reason',
            'idle'
        );

        /*
         * fetch() intentionally consumes any Laravel redirect response.
         * The browser remains on the current page until the user clicks OK.
         */
        void fetch(
            logoutRoute,
            {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html,application/xhtml+xml',
                },
            }
        ).catch(() => {
            /*
             * The modal still remains active if connectivity fails.
             * The server-side idle middleware will invalidate the expired
             * session on the next request.
             */
        });
    };


    const recordActivity = () => {
        if (logoutStarted) {
            return;
        }

        const timestamp = Date.now();

        /*
         * If interaction happens only after the idle limit has already
         * elapsed, the expired session cannot be revived.
         */
        if (
            timestamp - lastActivity >= timeoutMs
        ) {
            logoutForIdle();
            return;
        }

        lastActivity = timestamp;

        syncStorage(timestamp);

        void heartbeat(timestamp);
    };


    const events = [
        'pointerdown',
        'mousemove',
        'keydown',
        'scroll',
        'touchstart',
    ];

    events.forEach((eventName) => {
        window.addEventListener(
            eventName,
            recordActivity,
            {
                passive: true,
                capture: true,
            }
        );
    });


    /*
     * Returning to the application tab is genuine activity, but not if
     * the idle deadline already passed while the tab was hidden.
     */
    window.addEventListener(
        'focus',
        recordActivity,
        {
            passive: true,
        }
    );

    document.addEventListener(
        'visibilitychange',
        () => {
            if (!document.hidden) {
                recordActivity();
            }
        }
    );


    /*
     * Real activity from another SPMU-ACPMP tab keeps all tabs synchronized.
     */
    window.addEventListener(
        'storage',
        (event) => {
            if (
                logoutStarted ||
                event.key !== userKey ||
                !event.newValue
            ) {
                return;
            }

            const timestamp = Number(
                event.newValue
            );

            if (
                Number.isFinite(timestamp) &&
                timestamp > lastActivity
            ) {
                lastActivity = timestamp;
            }
        }
    );


    /*
     * Check locally every second so the visual expiration prompt appears
     * without requiring another HTTP request.
     */
    window.setInterval(
        () => {
            if (logoutStarted) {
                return;
            }

            if (
                Date.now() - lastActivity >= timeoutMs
            ) {
                logoutForIdle();
            }
        },
        1000
    );


    /*
     * Initialize the server-side user-activity timestamp.
     */
    void heartbeat(lastActivity);
})();
/* SPMU IDLE SESSION MONITOR END */
</script>

@endauth
</body>
</html>

