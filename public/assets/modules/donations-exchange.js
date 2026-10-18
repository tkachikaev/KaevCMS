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
  function activateAdmin(row) {
    if (row.dataset.exchangeBound === '1') return;
    row.dataset.exchangeBound = '1';
    const input = row.querySelector('[data-admin-exchange-item]');
    const icon = row.querySelector('[data-admin-exchange-icon]');
    const name = row.querySelector('[data-admin-exchange-name]');
    if (!input || !icon || !name) return;
    let timer;
    let request;
    let revision = 0;
    const render = (label, url = null) => {
      const element = document.createElement(url ? 'img' : 'span');
      if (url) {
        element.src = url;
        element.alt = '';
        element.width = 48;
        element.height = 48;
        element.addEventListener('error', () => {
          const fallback = document.createElement('span');
          fallback.textContent = '◇';
          element.replaceWith(fallback);
        }, { once: true });
      } else {
        element.textContent = '◇';
      }
      icon.replaceChildren(element);
      name.textContent = label;
    };
    const schedule = () => {
      clearTimeout(timer);
      request?.abort();
      const current = ++revision;
      const itemId = Number(input.value);
      if (!Number.isInteger(itemId) || itemId < 1 || itemId > 2147483647) {
        render(row.dataset.emptyLabel);
        return;
      }
      render(row.dataset.loadingLabel);
      timer = setTimeout(async () => {
        request = new AbortController();
        try {
          const response = await fetch(row.dataset.previewUrl.replace('__ITEM__', String(itemId)), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: request.signal,
          });
          if (!response.ok) throw new Error('preview_failed');
          const item = await response.json();
          if (current === revision) render(item.name, item.icon_url);
        } catch (error) {
          if (error.name !== 'AbortError' && current === revision) render(row.dataset.unavailableLabel);
        }
      }, 250);
    };
    input.addEventListener('input', schedule);
    input.addEventListener('change', schedule);
  }
  function boot() {
    document.querySelectorAll('[data-exchange-form]').forEach(activate);
    document.querySelectorAll('[data-admin-exchange-row]').forEach(activateAdmin);
  }
  document.addEventListener('DOMContentLoaded', boot);
  document.addEventListener('livewire:navigated', boot);
  boot();
})();
