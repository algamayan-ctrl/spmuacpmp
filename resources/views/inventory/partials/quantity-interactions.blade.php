<script>
(() => {
    const input = document.querySelector('[data-inventory-total-quantity]');
    const message = document.querySelector('[data-inventory-quantity-change]');
    if (!input || !message) return;

    const update = () => {
        const current = Number(input.dataset.currentQuantity);
        const next = input.value.trim() === '' ? NaN : Number(input.value);
        if (!Number.isSafeInteger(next) || next < 0 || next > 999999999) {
            message.textContent = 'Enter a whole quantity of 0 or more.';
            return;
        }
        const change = next - current;
        if (change === 0) {
            message.textContent = 'No quantity change.';
            return;
        }
        const unit = Math.abs(change) === 1 ? input.dataset.unit : input.dataset.unitPlural;
        message.textContent = 'Change: ' + (change > 0 ? '+' : '−') + Math.abs(change) + ' ' + unit;
    };

    input.addEventListener('input', update);
    update();
})();
</script>
