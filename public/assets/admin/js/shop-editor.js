(() => {
    'use strict';

    // Everything is delegated from the document, so it survives Livewire
    // navigation and in-place page updates without inline handlers.
    if (document.documentElement.dataset.shopEditorInstalled === '1') {
        return;
    }
    document.documentElement.dataset.shopEditorInstalled = '1';

    const root = () => document.querySelector('[data-shop-swap-root]');
    const text = (name, fallback = '') => root()?.dataset[name] ?? fallback;
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    const announce = (message) => {
        const live = root()?.querySelector('[data-shop-live]');
        if (live) live.textContent = message;
    };

    /* Dialogs ---------------------------------------------------------- */

    const show = (dialog) => {
        if (!(dialog instanceof HTMLDialogElement) || dialog.open) return;
        dialog.showModal();
        const first = dialog.querySelector('.notice-error') ? dialog.querySelector('[aria-invalid="true"], input:not([type="hidden"])') : null;
        (first ?? dialog.querySelector('[data-shop-item-id], input:not([type="hidden"]), select'))?.focus({ preventScroll: true });
    };
    const openErroredDialogs = () => {
        document.querySelectorAll('[data-shop-server-page] [data-shop-dialog][data-open-on-error="1"]').forEach(show);
    };

    /* In-place updates: forms post in the background and the page block is swapped. */

    let swapping = false;
    const swapFrom = (html, url, push) => {
        const next = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-shop-swap-root]');
        const current = root();
        if (!next || !current) return false;
        const scroll = window.scrollY;
        document.querySelectorAll('[data-shop-dialog][open]').forEach((dialog) => dialog.close());
        current.replaceWith(document.adoptNode(next));
        if (url) history[push ? 'pushState' : 'replaceState'](history.state, '', url);
        window.scrollTo({ top: scroll });
        openErroredDialogs();
        const flash = root()?.querySelector('[data-shop-flash]');
        if (flash) announce(flash.textContent.trim());
        return true;
    };
    const reload = async () => {
        const response = await fetch(window.location.href, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
        if (response.ok) swapFrom(await response.text(), null, false);
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-shop-async]') || !root()) return;
        const submitter = event.submitter;
        const question = submitter?.dataset.shopConfirm;
        if (question && !window.confirm(question)) {
            event.preventDefault();
            return;
        }
        if (swapping || form.dataset.shopNative === '1') return;
        event.preventDefault();
        swapping = true;
        root()?.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form, submitter ?? undefined),
                credentials: 'same-origin',
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const html = await response.text();
            if (!response.ok || !swapFrom(html, response.url, response.url !== window.location.href)) {
                throw new Error('swap_failed');
            }
        } catch {
            // Fall back to an ordinary submission so nothing is lost.
            form.dataset.shopNative = '1';
            submitter ? form.requestSubmit(submitter) : form.requestSubmit();
        } finally {
            swapping = false;
            root()?.removeAttribute('aria-busy');
        }
    });

    /* Language tabs ---------------------------------------------------- */

    const activateLocale = (container, locale) => {
        container.querySelectorAll('[data-shop-locale-tab]').forEach((tab) => {
            const active = tab.dataset.shopLocaleTab === locale;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        container.querySelectorAll('[data-shop-locale-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.shopLocalePanel !== locale;
        });
    };
    // A required field in a hidden language tab must become visible before the browser reports it.
    document.addEventListener('invalid', (event) => {
        const panel = event.target instanceof Element ? event.target.closest('[data-shop-locale-panel][hidden]') : null;
        const container = panel?.closest('[data-shop-locales]');
        if (panel && container) activateLocale(container, panel.dataset.shopLocalePanel);
    }, true);

    /* Item rows -------------------------------------------------------- */

    const rowsOf = (form) => Array.from(form.querySelectorAll('[data-shop-rows] [data-shop-item-row]'));
    const maxItems = (form) => Number.parseInt(form.dataset.maxItems ?? '100', 10);
    const previewTimers = new WeakMap();

    const renumber = (form) => {
        const rows = rowsOf(form);
        const dialogId = form.closest('dialog')?.id ?? 'shop-product';
        rows.forEach((row, index) => {
            const item = row.querySelector('[data-shop-item-id]');
            const quantity = row.querySelector('[data-shop-item-quantity]');
            if (item) { item.name = `items[${index}][item_id]`; item.id = `${dialogId}-item-${index}`; }
            if (quantity) { quantity.name = `items[${index}][quantity]`; quantity.id = `${dialogId}-qty-${index}`; }
            const labels = row.querySelectorAll('label');
            if (labels[0] && item) labels[0].htmlFor = item.id;
            if (labels[1] && quantity) labels[1].htmlFor = quantity.id;
            const remove = row.querySelector('[data-shop-remove-item]');
            if (remove) remove.hidden = rows.length <= 1;
        });
        const add = form.querySelector('[data-shop-add-item]');
        if (add) add.disabled = rows.length >= maxItems(form);
        markDuplicates(form);
        applyAutoNames(form);
        refreshPreview(form);
    };

    const markDuplicates = (form) => {
        const seen = new Map();
        rowsOf(form).forEach((row) => {
            const input = row.querySelector('[data-shop-item-id]');
            const id = input?.value.trim() ?? '';
            const duplicate = id !== '' && seen.has(id);
            if (id !== '' && !seen.has(id)) seen.set(id, row);
            input?.setCustomValidity(duplicate ? text('shopTextDuplicate', 'Duplicate item') : '');
            row.classList.toggle('is-duplicate', duplicate);
        });
    };

    const fillRow = (row, payload) => {
        const preview = row.querySelector('[data-shop-item-preview]');
        const name = row.querySelector('[data-shop-item-name]');
        if (preview) {
            preview.replaceChildren();
            if (payload?.icon_url) {
                const image = new Image(48, 48);
                image.src = payload.icon_url;
                image.alt = '';
                preview.append(image);
            } else {
                const fallback = document.createElement('i');
                fallback.textContent = '◇';
                preview.append(fallback);
            }
        }
        if (name) name.textContent = payload ? (payload.known === false ? text('shopTextUnknownItem') : payload.name) : text('shopTextEnterItem');
        row.dataset.shopNames = payload?.names ? JSON.stringify(payload.names) : '';
        row.dataset.shopIcon = payload?.icon_url ?? '';
    };

    const applyAutoNames = (form) => {
        const rows = rowsOf(form);
        const names = rows.length === 1 ? JSON.parse(rows[0].dataset.shopNames || '{}') : {};
        // One item names the product by itself; a bundle needs a name typed by the admin.
        form.querySelectorAll('[data-shop-name]').forEach((input) => {
            const locale = input.dataset.shopName;
            const manual = form.querySelector(`[data-shop-name-manual="${locale}"]`);
            if (input.hasAttribute('data-shop-name-default')) input.required = rows.length !== 1;
            // Rows rendered by the server have not been looked up yet: keep the saved name.
            if (manual?.value === '1' || (rows.length === 1 && !('shopNames' in rows[0].dataset))) return;
            input.value = rows.length === 1 ? (names[locale] ?? '') : '';
        });
    };

    const refreshPreview = (form) => {
        const preview = form.querySelector('[data-shop-preview]');
        if (!preview) return;
        preview.replaceChildren();
        rowsOf(form).slice(0, 3).forEach((row) => {
            const icon = row.dataset.shopIcon || row.querySelector('[data-shop-item-preview] img')?.getAttribute('src');
            if (!icon) return;
            const image = new Image(32, 32);
            image.src = icon;
            image.alt = '';
            preview.append(image);
        });
    };

    const lookupItem = async (row) => {
        const input = row.querySelector('[data-shop-item-id]');
        const form = row.closest('[data-shop-product-form]');
        const itemId = Number.parseInt(input?.value ?? '', 10);
        if (!form) return;
        if (!Number.isInteger(itemId) || itemId < 1) {
            fillRow(row, null);
            renumber(form);
            return;
        }
        const url = text('shopItemPreviewUrl').replace(/\/0$/, `/${itemId}`);
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('preview_failed');
            const payload = await response.json();
            if (Number.parseInt(input.value, 10) !== itemId) return;
            fillRow(row, payload);
        } catch {
            fillRow(row, { name: text('shopTextUnknownItem'), known: false });
        }
        renumber(form);
    };
    const scheduleLookup = (row) => {
        window.clearTimeout(previewTimers.get(row));
        previewTimers.set(row, window.setTimeout(() => lookupItem(row), 250));
    };

    const addRow = (form, itemId = '') => {
        const rows = rowsOf(form);
        const empty = rows.find((row) => (row.querySelector('[data-shop-item-id]')?.value ?? '') === '');
        if (itemId !== '') {
            const existing = rows.find((row) => row.querySelector('[data-shop-item-id]')?.value === String(itemId));
            if (existing) {
                existing.querySelector('[data-shop-item-quantity]')?.focus();
                announce(text('shopTextDuplicate'));
                return null;
            }
        }
        let row = itemId !== '' ? empty : null;
        if (!row) {
            if (rows.length >= maxItems(form)) {
                announce(text('shopTextLimit'));
                return null;
            }
            const template = form.querySelector('[data-shop-row-template]');
            if (!(template instanceof HTMLTemplateElement)) return null;
            form.querySelector('[data-shop-rows]')?.append(template.content.cloneNode(true));
            row = rowsOf(form).at(-1);
        }
        const input = row?.querySelector('[data-shop-item-id]');
        if (input) input.value = String(itemId);
        renumber(form);
        return row;
    };

    /* Item search ------------------------------------------------------ */

    const searchTimers = new WeakMap();
    const closeResults = (box) => {
        const list = box.querySelector('[data-shop-item-search-results]');
        const input = box.querySelector('[data-shop-item-search-input]');
        if (list) { list.hidden = true; list.replaceChildren(); }
        input?.setAttribute('aria-expanded', 'false');
        input?.removeAttribute('aria-activedescendant');
    };
    const renderResults = (box, items) => {
        const list = box.querySelector('[data-shop-item-search-results]');
        const input = box.querySelector('[data-shop-item-search-input]');
        if (!list || !input) return;
        list.replaceChildren();
        if (items.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'shop-item-search-empty';
            empty.textContent = text('shopTextNoResults');
            list.append(empty);
        }
        items.forEach((item, index) => {
            const option = document.createElement('li');
            option.id = `${list.id}-${index}`;
            option.setAttribute('role', 'option');
            option.dataset.shopItemOption = String(item.id);
            if (item.icon_url) {
                const image = new Image(28, 28);
                image.src = item.icon_url;
                image.alt = '';
                option.append(image);
            }
            const name = document.createElement('span');
            name.textContent = item.name;
            const id = document.createElement('small');
            id.textContent = `ID ${item.id}`;
            option.append(name, id);
            list.append(option);
        });
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    };
    const runSearch = async (box) => {
        const input = box.querySelector('[data-shop-item-search-input]');
        const query = input?.value.trim() ?? '';
        if (query.length < 2 && !/^\d+$/.test(query)) {
            closeResults(box);
            return;
        }
        const url = new URL(text('shopItemSearchUrl'), window.location.href);
        url.searchParams.set('q', query);
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('search_failed');
            const payload = await response.json();
            if (input.value.trim() === query) renderResults(box, payload.items ?? []);
        } catch {
            renderResults(box, []);
        }
    };
    const choose = (option) => {
        const box = option.closest('[data-shop-item-search]');
        const form = option.closest('[data-shop-product-form]');
        if (!box || !form) return;
        const row = addRow(form, option.dataset.shopItemOption);
        const input = box.querySelector('[data-shop-item-search-input]');
        if (input) input.value = '';
        closeResults(box);
        if (row) {
            lookupItem(row);
            row.querySelector('[data-shop-item-quantity]')?.focus();
        }
    };

    /* Sorting by drag or by arrow keys on the handle ------------------- */

    const saveOrder = async (list) => {
        const ids = Array.from(list.querySelectorAll(':scope > [data-shop-sort-id]')).map((item) => Number(item.dataset.shopSortId));
        try {
            const response = await fetch(list.dataset.shopOrderUrl, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ ids }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error('order_failed');
            announce(payload.message ?? '');
        } catch {
            announce(text('shopTextOrderFailed'));
            reload();
        }
    };
    let drag = null;
    document.addEventListener('pointerdown', (event) => {
        const handle = event.target instanceof Element ? event.target.closest('[data-shop-drag-handle]') : null;
        const item = handle?.closest('[data-shop-sort-id]');
        const list = item?.parentElement;
        if (!item || !list?.matches('[data-shop-sortable]') || event.button !== 0) return;
        event.preventDefault();
        drag = { item, list, moved: false };
        item.classList.add('is-dragging');
        handle.setPointerCapture(event.pointerId);
    });
    document.addEventListener('pointermove', (event) => {
        if (!drag) return;
        const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-shop-sort-id]');
        if (!target || target === drag.item || target.parentElement !== drag.list) return;
        const box = target.getBoundingClientRect();
        const horizontal = getComputedStyle(drag.list).display === 'grid';
        const after = horizontal ? event.clientX > box.left + box.width / 2 : event.clientY > box.top + box.height / 2;
        target[after ? 'after' : 'before'](drag.item);
        drag.moved = true;
    });
    const endDrag = () => {
        if (!drag) return;
        drag.item.classList.remove('is-dragging');
        if (drag.moved) saveOrder(drag.list);
        drag = null;
    };
    document.addEventListener('pointerup', endDrag);
    document.addEventListener('pointercancel', endDrag);

    /* Clicks and keys -------------------------------------------------- */

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        const opener = event.target.closest('[data-shop-open]');
        if (opener) {
            show(document.getElementById(opener.getAttribute('data-shop-open')));
            return;
        }
        if (event.target.closest('[data-shop-close]')) {
            event.target.closest('dialog')?.close();
            return;
        }
        const localeTab = event.target.closest('[data-shop-locale-tab]');
        if (localeTab) {
            const container = localeTab.closest('[data-shop-locales]');
            if (container) activateLocale(container, localeTab.dataset.shopLocaleTab);
            return;
        }
        const option = event.target.closest('[data-shop-item-option]');
        if (option) {
            choose(option);
            return;
        }
        const add = event.target.closest('[data-shop-add-item]');
        if (add) {
            const form = add.closest('[data-shop-product-form]');
            addRow(form)?.querySelector('[data-shop-item-id]')?.focus();
            return;
        }
        const remove = event.target.closest('[data-shop-remove-item]');
        if (remove) {
            const form = remove.closest('[data-shop-product-form]');
            if (form && rowsOf(form).length > 1) {
                remove.closest('[data-shop-item-row]')?.remove();
                renumber(form);
            }
            return;
        }
        document.querySelectorAll('[data-shop-item-search]').forEach((box) => {
            if (!box.contains(event.target)) closeResults(box);
        });
    });
    // A click on the dimmed area outside the panel closes it.
    document.addEventListener('mousedown', (event) => {
        if (event.target instanceof HTMLDialogElement && event.target.matches('[data-shop-dialog]')) {
            const box = event.target.getBoundingClientRect();
            if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) {
                event.target.close();
            }
        }
    });

    document.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) return;
        if (target.matches('[data-shop-item-id]')) {
            const row = target.closest('[data-shop-item-row]');
            if (row) scheduleLookup(row);
            markDuplicates(target.closest('[data-shop-product-form]'));
        } else if (target.matches('[data-shop-name]')) {
            const manual = target.form?.querySelector(`[data-shop-name-manual="${target.dataset.shopName}"]`);
            if (manual) manual.value = target.value.trim() === '' ? '0' : '1';
        } else if (target.matches('[data-shop-item-search-input]')) {
            const box = target.closest('[data-shop-item-search]');
            window.clearTimeout(searchTimers.get(box));
            searchTimers.set(box, window.setTimeout(() => runSearch(box), 250));
        } else if (target.matches('[data-shop-filter]')) {
            const query = target.value.trim().toLowerCase();
            const page = target.closest('[data-shop-server-page]');
            let shown = 0;
            page?.querySelectorAll('[data-shop-filter-text]').forEach((card) => {
                const match = query === '' || card.dataset.shopFilterText.includes(query);
                card.hidden = !match;
                if (match) shown += 1;
            });
            const empty = page?.querySelector('[data-shop-filter-empty]');
            if (empty) empty.hidden = shown > 0;
        }
    });

    document.addEventListener('keydown', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        if (target.matches('[data-shop-item-search-input]')) {
            const box = target.closest('[data-shop-item-search]');
            const options = Array.from(box?.querySelectorAll('[data-shop-item-option]') ?? []);
            const current = options.findIndex((option) => option.id === target.getAttribute('aria-activedescendant'));
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (options.length === 0) return;
                event.preventDefault();
                const next = options[(current + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length];
                options.forEach((option) => option.setAttribute('aria-selected', option === next ? 'true' : 'false'));
                target.setAttribute('aria-activedescendant', next.id);
                next.scrollIntoView({ block: 'nearest' });
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const option = options[current] ?? options[0];
                if (option) choose(option);
            } else if (event.key === 'Escape' && box && !box.querySelector('[data-shop-item-search-results]')?.hidden) {
                event.preventDefault();
                closeResults(box);
            }
            return;
        }
        if (target.matches('[data-shop-drag-handle]')) {
            const item = target.closest('[data-shop-sort-id]');
            const list = item?.parentElement;
            const backward = ['ArrowUp', 'ArrowLeft'].includes(event.key);
            const forward = ['ArrowDown', 'ArrowRight'].includes(event.key);
            if (!item || !list?.matches('[data-shop-sortable]') || (!backward && !forward)) return;
            event.preventDefault();
            const sibling = backward ? item.previousElementSibling : item.nextElementSibling;
            if (!sibling) return;
            sibling[backward ? 'before' : 'after'](item);
            target.focus();
            saveOrder(list);
        }
        if (target.matches('[data-shop-item-id]') && event.key === 'Enter') {
            event.preventDefault();
            const row = target.closest('[data-shop-item-row]');
            if (row) lookupItem(row);
        }
    });

    document.addEventListener('livewire:navigated', openErroredDialogs);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openErroredDialogs, { once: true });
    } else {
        openErroredDialogs();
    }
})();
