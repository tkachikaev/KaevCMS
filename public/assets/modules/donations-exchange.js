(() => {
  function activate(root) {
    if (root.dataset.exchangeBound === '1') return;
    root.dataset.exchangeBound = '1';
    const server = root.querySelector('[data-exchange-server]');
    const coins = root.querySelector('[data-exchange-coins]');
    const output = root.querySelector('[data-exchange-summary]');
    const maxCoins = Number(root.dataset.maxCoins || 1000000);
    const maxItems = Number(root.dataset.maxGameItems || 2000000000);
    const update = () => {
      const option = server?.selectedOptions?.[0];
      const factor = Number(option?.dataset.factor || 0);
      const amount = Number(coins?.value || 0);
      const limit = factor > 0 ? Math.min(maxCoins, Math.floor(maxItems / factor)) : 0;
      if (coins) coins.max = String(limit);
      if (!Number.isSafeInteger(amount) || amount < 1 || amount > limit) {
        output.textContent = '—';
        return;
      }
      output.textContent = `${amount.toLocaleString()} × ${factor.toLocaleString()} = ${(amount * factor).toLocaleString()}`;
    };
    server?.addEventListener('change', update);
    coins?.addEventListener('input', update);
    update();
  }
  function boot() { document.querySelectorAll('[data-exchange-form]').forEach(activate); }
  document.addEventListener('DOMContentLoaded', boot);
  document.addEventListener('livewire:navigated', boot);
  boot();
})();
