<style>
.sao-stock-card { padding: 0; }
.sao-stock-card-heading { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 18px 20px; }
.sao-stock-card-heading h2 { margin: 0; font-size: 16px; }
.sao-stock-card-heading .meta { margin: 0; }
.sao-stock-card-heading > .button { flex-shrink: 0; }
.sao-stock-card form { margin: 0; }
.sao-stock-card-filters { display: grid; grid-template-columns: minmax(0, 1fr) 220px; gap: 14px; padding: 16px 20px; border-top: 1px solid var(--border); }
.sao-stock-card-filters label { display: grid; gap: 7px; margin: 0; font-size: 12px; font-weight: 650; }
.sao-stock-card-filters input, .sao-stock-card-filters select { min-height: 44px; width: 100%; margin: 0; }
.sao-stock-card-table-wrap { border-top: 1px solid var(--border); border-radius: 0; }
.sao-stock-card-identity { width: 100%; min-width: 860px; table-layout: fixed; border-collapse: collapse; }
.sao-stock-card-identity td { padding: 8px 10px; border: 1px solid var(--border); font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }
.sao-stock-card-identity td > strong { display: inline; margin-right: 5px; color: var(--heading); }
.sao-stock-card-identity td:first-child { border-left: 0; }
.sao-stock-card-identity td:last-child { border-right: 0; }
.sao-stock-card-identity tbody tr:last-child > td { border-bottom: 0; }
.sao-stock-card-table { width: 100%; min-width: 860px; table-layout: fixed; border-collapse: collapse; }
.sao-stock-card-table th { padding: 8px 10px; text-align: center; border: 1px solid var(--border); background: var(--surface-subtle); font-size: 11px; line-height: 1.35; white-space: normal; }
.sao-stock-card-table td { padding: 10px; border: 1px solid var(--border); vertical-align: middle; font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }
.sao-stock-card-table th:first-child, .sao-stock-card-table td:first-child { border-left: 0; }
.sao-stock-card-table th:last-child, .sao-stock-card-table td:last-child { border-right: 0; }
.sao-stock-card-table td:first-child, .sao-stock-card-table td:nth-child(5) { text-align: center; }
.sao-stock-card-table tbody tr:last-child > td { border-bottom: 1px solid var(--border); }
.sao-stock-card-table th:first-child { min-width: 100px; }
.sao-stock-card-table td:nth-child(2) { max-width: 240px; }
.sao-stock-card-table .stock-number { text-align: center; font-variant-numeric: tabular-nums; white-space: nowrap; }
.sao-stock-card-table .stock-balance { font-weight: 750; }
.sao-stock-card-footer { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 16px 20px; }
.sao-stock-card-pagination-meta, .sao-history-pagination-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.sao-stock-card-pagination-meta > p, .sao-history-pagination-meta > p { margin: 0; }
.sao-stock-card-pagination, .sao-stock-card-pagination nav { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sao-stock-card-pagination-meta select { width: auto; min-height: 32px; margin: 0; padding: 4px 28px 4px 9px; font-size: 11px; }
.sao-stock-card-pagination [aria-disabled="true"] { opacity: .5; cursor: default; }
.sao-stock-card-pagination .app-pagination, .sao-history-pagination .app-pagination { margin: 0; }
.sao-stock-card-warning { margin: 0 20px 16px; padding: 12px; border: 1px solid var(--warning); border-radius: 8px; color: var(--heading); font-size: 12px; }
@media (max-width: 560px) {
    .sao-stock-card-heading { align-items: flex-start; flex-direction: column; }
    .sao-stock-card-filters { grid-template-columns: minmax(0, 1fr); }
}
</style>
