(() => {
  function activate(root) {
    if (root.dataset.exchangeBound === '1') return;
    root.dataset.exchangeBound = '1';
    const server = root.querySelector('[data-exchange-server]');
    const coins = root.querySelector('[data-exchange-coins]');
    const output = root.querySelector('[data-exchange-receive]');
    const preview = root.querySelector('[data-exchange-preview]');
    const icon = root.querySelector('[data-exchange-item-icon]');
    const submit = root.querySelector('button[type="submit"]');
    const blocked = submit.disabled;
    const maxCoins = Number(root.dataset.maxCoins || 1000000);
    const maxItems = Number(root.dataset.maxGameItems || 2000000000);
    const update = () => {
      const option = server?.selectedOptions?.[0];
      const factor = Number(option?.dataset.factor || 0);
      const amount = Number(coins?.value || 0);
      const limit = factor > 0 ? Math.min(maxCoins, Number(root.dataset.balance), Math.floor(maxItems / factor)) : 0;
      if (coins) coins.max = String(Math.max(1, limit));
      const valid = Number.isSafeInteger(amount) && amount >= 1 && amount <= limit;
      submit.disabled = blocked || !valid;
      if (icon) {
        icon.hidden = !option?.dataset.icon;
        if (option?.dataset.icon) icon.src = option.dataset.icon;
      }
      preview.hidden = false;
      output.textContent = `${option.dataset.name} × ${(valid ? amount * factor : 0).toLocaleString()}`;
      output.title = output.textContent;
    };
    server?.addEventListener('change', update);
    coins?.addEventListener('input', update);
    root.addEventListener('submit', async event => {
      const content = root.closest('[data-exchange-content]');
      if (!content) return;
      event.preventDefault();
      if (root.getAttribute('aria-busy') === 'true') return;
      const body = new FormData(root);
      const controls = Array.from(root.elements).filter(control => !control.disabled);
      const error = root.querySelector('[data-exchange-error]');
      root.setAttribute('aria-busy', 'true');
      controls.forEach(control => { control.disabled = true; });
      error.hidden = true;
      try {
        const response = await fetch(root.action, { method: 'POST', credentials: 'same-origin', body, headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (!response.ok) {
          const validation = Object.values(payload.errors || {}).flat()[0];
          throw new Error(response.status === 422 ? (validation || payload.message || root.dataset.failed) : root.dataset.failed);
        }
        const success = content.querySelector('[data-exchange-success]');
        success.querySelector('[data-exchange-received]').textContent = payload.received;
        success.querySelector('[data-exchange-inventory]').href = payload.inventory_url;
        root.hidden = true;
        success.hidden = false;
        document.querySelectorAll('[data-wallet-balance-label]').forEach(label => { label.textContent = payload.wallet.label; });
        document.querySelectorAll('[data-wallet-balance-amount]').forEach(label => { label.textContent = payload.wallet.formatted; });
        document.querySelectorAll('[data-account-wallet-menu] summary').forEach(summary => { summary.setAttribute('aria-label', `${root.dataset.walletMenuLabel}: ${payload.wallet.label}`); });
        success.querySelector('[data-exchange-success-title]').focus();
        const history = document.querySelector('[data-wallet-history]');
        if (history) {
          const historyUrl = location.href;
          fetch(historyUrl, { credentials: 'same-origin', cache: 'no-store' })
            .then(response => response.ok ? response.text() : null)
            .then(markup => {
              if (!markup || !history.isConnected || location.href !== historyUrl) return;
              const updated = new DOMParser().parseFromString(markup, 'text/html').querySelector('[data-wallet-history]');
              if (updated) {
                history.replaceWith(document.importNode(updated, true));
                document.dispatchEvent(new Event('wallet:history-updated'));
              }
            }).catch(() => {});
        }
      } catch (exception) {
        error.textContent = exception instanceof SyntaxError || exception instanceof TypeError ? root.dataset.failed : exception.message;
        error.hidden = false;
      } finally {
        root.removeAttribute('aria-busy');
        controls.forEach(control => { control.disabled = false; });
        update();
      }
    });
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
        element.width = 28;
        element.height = 28;
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
  document.addEventListener('donations:content-loaded', boot);
  boot();
})();
