<script>
(() => {
    const dialog = document.getElementById('stock-export-options');
    if (!dialog) return;
    const form = dialog.querySelector('[data-stock-export-form]');
    const format = dialog.querySelector('[data-stock-export-format]');
    const margins = dialog.querySelector('[data-stock-export-margins]');
    const custom = dialog.querySelector('[data-stock-custom-margins]');
    const submit = dialog.querySelector('[data-stock-export-submit]');
    const labels = @json(\App\Reports\ReportExportOptions::ACTION_LABELS);
    const printUrl = @json(route('inventory.stock-card.print', $item));
    const apply = () => {
        const paginated = ['pdf','docx','print'].includes(format.value);
        dialog.querySelectorAll('[data-stock-page-setting]').forEach(el => {
            el.hidden = !paginated;
            el.querySelectorAll('input,select').forEach(input => input.disabled = !paginated);
        });
        custom.hidden = !paginated || margins.value !== 'custom';
        custom.querySelectorAll('input').forEach(input => input.disabled = custom.hidden);
        submit.textContent = labels[format.value] || 'Generate';
    };
    format.addEventListener('change', apply);
    margins.addEventListener('change', apply);
    document.querySelectorAll('[data-stock-export-open]').forEach(button => button.addEventListener('click', () => { apply(); dialog.showModal(); }));
    dialog.querySelectorAll('[data-stock-export-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    form.addEventListener('submit', event => {
        if (format.value === 'print') {
            event.preventDefault();
            const url = new URL(printUrl, window.location.origin);
            new FormData(form).forEach((value,key) => url.searchParams.set(key,value));
            window.open(url.toString(), '_blank', 'noopener');
            dialog.close();
            return;
        }
        if (submit.disabled) { event.preventDefault(); return; }
        submit.disabled = true;
        submit.textContent = 'Preparing…';
        setTimeout(() => { submit.disabled = false; apply(); }, 4000);
    });
    apply();
})();
</script>
