import './bootstrap';

const setupAppDrawer = () => {
    const body = document.body;

    if (!body || !body.classList.contains('app-shell-body') || body.dataset.drawerBound === 'true') {
        return;
    }

    const drawer = document.querySelector('[data-app-drawer]');
    const backdrop = document.querySelector('[data-drawer-backdrop]');
    const toggles = document.querySelectorAll('[data-drawer-toggle]');
    const closers = document.querySelectorAll('[data-drawer-close]');

    if (!drawer) {
        return;
    }

    const syncState = (isOpen) => {
        body.classList.toggle('drawer-open', isOpen);
        drawer.classList.toggle('is-open', isOpen);
        drawer.setAttribute('aria-hidden', isOpen ? 'false' : 'true');

        if (backdrop) {
            backdrop.hidden = !isOpen;
        }

        toggles.forEach((toggle) => {
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    };

    const closeDrawer = () => syncState(false);

    toggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            syncState(!body.classList.contains('drawer-open'));
        });
    });

    closers.forEach((closer) => {
        closer.addEventListener('click', closeDrawer);
    });

    backdrop?.addEventListener('click', closeDrawer);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && body.classList.contains('drawer-open')) {
            closeDrawer();
        }
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 1280 && body.classList.contains('drawer-open')) {
            closeDrawer();
        }
    });

    body.dataset.drawerBound = 'true';
    syncState(false);
};

const parseJsonNode = (selector, scope = document) => {
    const node = scope.querySelector(selector);

    if (!node) {
        return [];
    }

    try {
        return JSON.parse(node.textContent ?? '[]');
    } catch {
        return [];
    }
};

const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

const formatNumber = new Intl.NumberFormat('es-ES');

const parsePositiveInteger = (value, allowZero = false) => {
    const normalized = String(value ?? '').trim();
    const pattern = allowZero ? /^(0|[1-9]\d*)$/ : /^[1-9]\d*$/;

    if (!pattern.test(normalized)) {
        return allowZero && normalized === '' ? 0 : NaN;
    }

    const parsed = Number.parseInt(normalized, 10);

    return Number.isFinite(parsed) ? parsed : NaN;
};

const createAutocomplete = (root, options = {}) => {
    if (!root || root.dataset.autocompleteBound === 'true') {
        return null;
    }

    const input = root.querySelector('[data-autocomplete-input]');
    const clearButton = root.querySelector('[data-autocomplete-clear]');
    const panel = root.querySelector('[data-autocomplete-panel]');
    const status = root.querySelector('[data-autocomplete-status]');
    const list = root.querySelector('[data-autocomplete-list]');
    const endpoint = root.dataset.endpoint;

    if (!input || !clearButton || !panel || !status || !list || !endpoint) {
        return null;
    }

    const minChars = Number.parseInt(root.dataset.minChars ?? '2', 10);
    const messages = {
        empty: root.dataset.emptyMessage ?? 'Escribe al menos 2 caracteres...',
        searching: root.dataset.searchingMessage ?? 'Buscando...',
        noResults: root.dataset.noResultsMessage ?? 'Sin resultados',
        error: root.dataset.errorMessage ?? 'Error al buscar',
    };

    let timer = null;
    let controller = null;
    let results = [];
    let highlightedIndex = -1;
    let selectedLabel = input.value.trim();

    const setStatus = (message) => {
        status.textContent = message;
    };

    const openPanel = () => {
        panel.hidden = false;
        root.classList.add('is-open');
    };

    const closePanel = () => {
        panel.hidden = true;
        root.classList.remove('is-open');
        highlightedIndex = -1;
        renderResults();
    };

    const updateClearButton = () => {
        clearButton.hidden = input.value.trim() === '' && !options.hasSelection?.();
    };

    const setSelection = (item) => {
        selectedLabel = item?.label ?? '';
        input.value = item?.label ?? '';
        updateClearButton();

        if (item) {
            options.onSelect?.(item);
        } else {
            options.onClear?.();
        }

        closePanel();
    };

    const renderResults = () => {
        list.innerHTML = results.map((item, index) => `
            <button
                type="button"
                class="ajax-autocomplete-option${index === highlightedIndex ? ' is-active' : ''}"
                data-autocomplete-option
                data-index="${index}"
                role="option"
                aria-selected="${index === highlightedIndex ? 'true' : 'false'}"
            >
                <strong>${escapeHtml(item.label ?? item.value ?? '')}</strong>
                ${item.meta ? `<small>${escapeHtml(item.meta)}</small>` : ''}
            </button>
        `).join('');
    };

    const fetchResults = async () => {
        const query = input.value.trim();

        if (query.length < minChars) {
            results = [];
            setStatus(messages.empty);
            renderResults();
            closePanel();
            return;
        }

        controller?.abort();
        controller = new AbortController();
        setStatus(messages.searching);
        results = [];
        renderResults();
        openPanel();

        try {
            const params = new URLSearchParams({
                q: query,
                ...(options.buildParams?.() ?? {}),
            });

            const response = await fetch(`${endpoint}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error('autocomplete_failed');
            }

            const payload = await response.json();
            results = Array.isArray(payload.data) ? payload.data : [];
            highlightedIndex = results.length > 0 ? 0 : -1;
            setStatus(results.length > 0 ? '' : messages.noResults);
            renderResults();
            openPanel();
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            results = [];
            highlightedIndex = -1;
            setStatus(messages.error);
            renderResults();
            openPanel();
        }
    };

    input.addEventListener('input', () => {
        window.clearTimeout(timer);

        if (input.value.trim() !== selectedLabel) {
            selectedLabel = '';
            options.onInputChange?.(input.value.trim());
        }

        updateClearButton();
        timer = window.setTimeout(fetchResults, 300);
    });

    input.addEventListener('focus', () => {
        if (results.length > 0 || status.textContent !== '') {
            openPanel();
        }
    });

    input.addEventListener('keydown', (event) => {
        if (panel.hidden || results.length === 0) {
            if (event.key === 'ArrowDown' && input.value.trim().length >= minChars) {
                openPanel();
            }

            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            highlightedIndex = highlightedIndex < results.length - 1 ? highlightedIndex + 1 : 0;
            renderResults();
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            highlightedIndex = highlightedIndex > 0 ? highlightedIndex - 1 : results.length - 1;
            renderResults();
        }

        if (event.key === 'Enter' && highlightedIndex >= 0) {
            event.preventDefault();
            setSelection(results[highlightedIndex]);
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closePanel();
        }
    });

    list.addEventListener('click', (event) => {
        const option = event.target.closest('[data-autocomplete-option]');

        if (!option) {
            return;
        }

        const index = Number.parseInt(option.dataset.index ?? '-1', 10);

        if (results[index]) {
            setSelection(results[index]);
        }
    });

    clearButton.addEventListener('click', () => {
        input.value = '';
        selectedLabel = '';
        results = [];
        setSelection(null);
        setStatus(messages.empty);
        updateClearButton();
        input.focus();
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) {
            closePanel();
        }
    });

    root.dataset.autocompleteBound = 'true';
    setStatus(messages.empty);
    updateClearButton();

    return {
        root,
        input,
        clear: () => {
            input.value = '';
            selectedLabel = '';
            results = [];
            options.onClear?.();
            updateClearButton();
            closePanel();
        },
        setItem: (item) => setSelection(item),
    };
};

const setupStockFilters = () => {
    const form = document.querySelector('.stock-filters');

    if (!form || form.dataset.stockFiltersBound === 'true') {
        return;
    }

    const clientSelect = form.querySelector('select[name="client_id"]');
    const itemIdInput = form.querySelector('[data-stock-item-id]');
    const searchValueInput = form.querySelector('[data-stock-search-value]');
    const lotValueInput = form.querySelector('[data-stock-lot-value]');
    const locationIdInput = form.querySelector('[data-stock-location-id]');
    const locationValueInput = form.querySelector('[data-stock-location-value]');
    const batchStatusSelect = form.querySelector('select[name="batch_status"]');

    createAutocomplete(form.querySelector('[data-stock-item-filter]'), {
        buildParams: () => ({
            client_id: clientSelect?.value ?? '',
            active_only: '0',
            limit: '10',
        }),
        hasSelection: () => (itemIdInput?.value ?? '') !== '',
        onSelect: (item) => {
            if (itemIdInput) {
                itemIdInput.value = String(item.id);
            }

            if (searchValueInput) {
                searchValueInput.value = item.value ?? item.label ?? '';
            }
        },
        onInputChange: (value) => {
            if (itemIdInput) {
                itemIdInput.value = '';
            }

            if (searchValueInput) {
                searchValueInput.value = value;
            }
        },
        onClear: () => {
            if (itemIdInput) {
                itemIdInput.value = '';
            }

            if (searchValueInput) {
                searchValueInput.value = '';
            }
        },
    });

    createAutocomplete(form.querySelector('[data-stock-lot-filter]'), {
        buildParams: () => ({
            client_id: clientSelect?.value ?? '',
            item_id: itemIdInput?.value ?? '',
            stock_status: batchStatusSelect?.value === 'all' ? '' : (batchStatusSelect?.value ?? ''),
            limit: '10',
        }),
        hasSelection: () => (lotValueInput?.value ?? '') !== '',
        onSelect: (item) => {
            if (lotValueInput) {
                lotValueInput.value = item.value ?? item.label ?? '';
            }
        },
        onInputChange: (value) => {
            if (lotValueInput) {
                lotValueInput.value = value;
            }
        },
        onClear: () => {
            if (lotValueInput) {
                lotValueInput.value = '';
            }
        },
    });

    createAutocomplete(form.querySelector('[data-stock-location-filter]'), {
        buildParams: () => ({
            limit: '10',
        }),
        hasSelection: () => (locationIdInput?.value ?? '') !== '' || (locationValueInput?.value ?? '') !== '',
        onSelect: (item) => {
            if (locationIdInput) {
                locationIdInput.value = String(item.id);
            }

            if (locationValueInput) {
                locationValueInput.value = item.value ?? item.label ?? '';
            }
        },
        onInputChange: (value) => {
            if (locationIdInput) {
                locationIdInput.value = '';
            }

            if (locationValueInput) {
                locationValueInput.value = value;
            }
        },
        onClear: () => {
            if (locationIdInput) {
                locationIdInput.value = '';
            }

            if (locationValueInput) {
                locationValueInput.value = '';
            }
        },
    });

    form.dataset.stockFiltersBound = 'true';
};

const setupStockDetailToggles = () => {
    const toggles = document.querySelectorAll('[data-stock-detail-toggle]');

    if (toggles.length === 0) {
        return;
    }

    toggles.forEach((toggle) => {
        if (toggle.dataset.stockDetailBound === 'true') {
            return;
        }

        toggle.addEventListener('click', () => {
            const targetId = toggle.dataset.target;
            const detailRow = targetId ? document.getElementById(targetId) : null;

            if (!detailRow) {
                return;
            }

            const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
            detailRow.hidden = isExpanded;
        });

        toggle.dataset.stockDetailBound = 'true';
    });
};

const setupMerchandiseRequestBuilder = () => {
    const form = document.querySelector('[data-merchandise-request-form]');

    if (!form || form.dataset.requestBuilderBound === 'true') {
        return;
    }

    const summaryRows = form.querySelector('[data-request-summary-rows]');
    const summaryEmpty = form.querySelector('[data-request-summary-empty]');
    const summaryLines = form.querySelector('[data-request-summary-lines]');
    const summaryPallets = form.querySelector('[data-request-summary-pallets]');
    const hiddenInputs = form.querySelector('[data-request-hidden-inputs]');
    const feedback = form.querySelector('[data-request-search-feedback]');
    const quantityInput = form.querySelector('[data-request-picker-quantity]');
    const addButton = form.querySelector('[data-request-add-selected]');
    const submitButton = form.querySelector('[data-request-submit]');
    const cache = new Map(parseJsonNode('[data-request-selected-items]', form).map((item) => [String(item.id), item]));
    let selectedItemId = '';

    const setFeedback = (message, type = 'default') => {
        feedback.textContent = message;
        feedback.classList.toggle('helper-text--error', type === 'error');
        feedback.classList.toggle('helper-text--success', type === 'success');
    };

    const hiddenInputFor = (itemId) => hiddenInputs.querySelector(`[data-request-hidden-quantity][data-item-id="${itemId}"]`);

    const upsertHiddenInput = (itemId, pallets) => {
        let hiddenInput = hiddenInputFor(itemId);

        if (pallets <= 0) {
            hiddenInput?.remove();
            return;
        }

        if (!hiddenInput) {
            hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = `quantities[${itemId}]`;
            hiddenInput.dataset.requestHiddenQuantity = 'true';
            hiddenInput.dataset.itemId = itemId;
            hiddenInputs.append(hiddenInput);
        }

        hiddenInput.value = String(pallets);
    };

    const selectedItems = () => Array.from(hiddenInputs.querySelectorAll('[data-request-hidden-quantity]'))
        .map((input) => {
            const item = cache.get(String(input.dataset.itemId));
            const pallets = parsePositiveInteger(input.value);

            if (!item || !Number.isFinite(pallets) || pallets <= 0) {
                return null;
            }

            return {
                ...item,
                pallets,
            };
        })
        .filter(Boolean);

    const renderSummary = () => {
        const lines = selectedItems();
        const totalPallets = lines.reduce((total, line) => total + line.pallets, 0);

        summaryLines.textContent = formatNumber.format(lines.length);
        summaryPallets.textContent = formatNumber.format(totalPallets);
        summaryEmpty.hidden = lines.length > 0;
        submitButton.disabled = lines.length === 0;

        summaryRows.innerHTML = lines.map((line) => `
            <article class="merchandise-request-summary-row">
                <div class="merchandise-request-summary-main">
                    <strong>${escapeHtml(line.sku)}</strong>
                    <span>${escapeHtml(line.description)}</span>
                    <small>${escapeHtml(line.units_per_pallet)} uds/pallet</small>
                </div>
                <label class="auth-field merchandise-request-summary-field">
                    <span>Pallets</span>
                    <input type="number" min="1" step="1" value="${escapeHtml(line.pallets)}" class="auth-input merchandise-request-summary-input" data-request-summary-quantity data-item-id="${escapeHtml(line.id)}">
                </label>
                <button type="button" class="button-secondary compact-button btn-compact" data-request-summary-remove data-item-id="${escapeHtml(line.id)}">
                    Quitar
                </button>
            </article>
        `).join('');
    };

    const autocomplete = createAutocomplete(form.querySelector('[data-request-item-picker]'), {
        buildParams: () => ({
            client_id: form.dataset.clientId ?? '',
            active_only: '1',
            limit: '10',
        }),
        hasSelection: () => selectedItemId !== '',
        onSelect: (item) => {
            selectedItemId = String(item.id);
            cache.set(String(item.id), item);
            setFeedback(`${item.sku} lista para añadir al pedido.`, 'success');
        },
        onInputChange: () => {
            selectedItemId = '';
            setFeedback('Escribe al menos 2 caracteres para buscar en tu catálogo activo.');
        },
        onClear: () => {
            selectedItemId = '';
            setFeedback('Escribe al menos 2 caracteres para buscar en tu catálogo activo.');
        },
    });

    addButton.addEventListener('click', () => {
        const pallets = parsePositiveInteger(quantityInput.value);
        const item = cache.get(selectedItemId);

        if (!item || !Number.isFinite(pallets) || pallets <= 0) {
            setFeedback('Selecciona una mercancía y una cantidad entera mayor que cero.', 'error');
            return;
        }

        upsertHiddenInput(selectedItemId, pallets);
        renderSummary();
        setFeedback(`${item.sku} se ha añadido al pedido con ${formatNumber.format(pallets)} pallets.`, 'success');
        quantityInput.value = '1';
        autocomplete?.clear();
    });

    summaryRows.addEventListener('input', (event) => {
        const input = event.target.closest('[data-request-summary-quantity]');

        if (!input) {
            return;
        }

        const pallets = parsePositiveInteger(input.value);
        upsertHiddenInput(input.dataset.itemId, Number.isFinite(pallets) ? pallets : 0);
        renderSummary();
    });

    summaryRows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-request-summary-remove]');

        if (!button) {
            return;
        }

        upsertHiddenInput(button.dataset.itemId, 0);
        renderSummary();
        setFeedback('Línea eliminada del pedido.');
    });

    renderSummary();
    form.dataset.requestBuilderBound = 'true';
};

const setupGoodsDispatchBuilder = () => {
    const form = document.querySelector('[data-goods-dispatch-form]');

    if (!form || form.dataset.dispatchBuilderBound === 'true') {
        return;
    }

    const clientSelect = form.querySelector('[data-dispatch-client]');
    const quantityInput = form.querySelector('[data-dispatch-picker-quantity]');
    const feedback = form.querySelector('[data-dispatch-picker-feedback]');
    const addButton = form.querySelector('[data-dispatch-add-selected]');
    const hiddenInputs = form.querySelector('[data-dispatch-hidden-inputs]');
    const summaryRows = form.querySelector('[data-dispatch-summary-rows]');
    const summaryEmpty = form.querySelector('[data-dispatch-summary-empty]');
    const summaryLines = form.querySelector('[data-dispatch-summary-lines]');
    const summaryPallets = form.querySelector('[data-dispatch-summary-pallets]');
    const submitButton = form.querySelector('[data-dispatch-submit]');
    const cache = new Map(parseJsonNode('[data-dispatch-items]', form).map((item) => [String(item.id), item]));
    let selectedItemId = '';

    const setFeedback = (message, type = 'default') => {
        feedback.textContent = message;
        feedback.classList.toggle('helper-text--error', type === 'error');
        feedback.classList.toggle('helper-text--success', type === 'success');
    };

    const hiddenInputFor = (itemId) => hiddenInputs.querySelector(`[data-dispatch-hidden-quantity][data-item-id="${itemId}"]`);

    const upsertHiddenInput = (itemId, pallets) => {
        let hiddenInput = hiddenInputFor(itemId);

        if (pallets <= 0) {
            hiddenInput?.remove();
            return;
        }

        if (!hiddenInput) {
            hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = `quantities[${itemId}]`;
            hiddenInput.dataset.dispatchHiddenQuantity = 'true';
            hiddenInput.dataset.itemId = itemId;
            hiddenInputs.append(hiddenInput);
        }

        hiddenInput.value = String(pallets);
    };

    const selectedItems = () => Array.from(hiddenInputs.querySelectorAll('[data-dispatch-hidden-quantity]'))
        .map((input) => {
            const item = cache.get(String(input.dataset.itemId));
            const pallets = parsePositiveInteger(input.value);

            if (!item || !Number.isFinite(pallets) || pallets <= 0) {
                return null;
            }

            return {
                ...item,
                pallets,
            };
        })
        .filter(Boolean);

    const renderSummary = () => {
        const lines = selectedItems();
        const totalPallets = lines.reduce((total, line) => total + line.pallets, 0);

        summaryLines.textContent = formatNumber.format(lines.length);
        summaryPallets.textContent = formatNumber.format(totalPallets);
        summaryEmpty.hidden = lines.length > 0;
        submitButton.disabled = lines.length === 0;

        summaryRows.innerHTML = lines.map((line) => `
            <tr>
                <td>
                    <div class="stock-cell-main">
                        <strong>${escapeHtml(line.sku)}</strong>
                        <span class="users-table-email">${escapeHtml(line.description)} · ${escapeHtml(line.units_per_pallet)} uds/pallet</span>
                    </div>
                </td>
                <td>
                    <input type="number" min="1" step="1" value="${escapeHtml(line.pallets)}" class="auth-input merchandise-request-summary-input" data-dispatch-summary-quantity data-item-id="${escapeHtml(line.id)}">
                </td>
                <td>
                    <button type="button" class="button-secondary compact-button btn-table" data-dispatch-summary-remove data-item-id="${escapeHtml(line.id)}">
                        Eliminar
                    </button>
                </td>
            </tr>
        `).join('');
    };

    const autocomplete = createAutocomplete(form.querySelector('[data-dispatch-item-picker]'), {
        buildParams: () => ({
            client_id: clientSelect?.value ?? '',
            active_only: '1',
            limit: '10',
        }),
        hasSelection: () => selectedItemId !== '',
        onSelect: (item) => {
            selectedItemId = String(item.id);
            cache.set(String(item.id), item);
            setFeedback(`${item.sku} lista para añadir a la salida.`, 'success');
        },
        onInputChange: () => {
            selectedItemId = '';
        },
        onClear: () => {
            selectedItemId = '';
        },
    });

    clientSelect?.addEventListener('change', () => {
        selectedItemId = '';
        autocomplete?.clear();
        setFeedback('Selecciona o cambia el cliente y busca después la referencia correcta.');
    });

    addButton.addEventListener('click', () => {
        if (!clientSelect?.value) {
            setFeedback('Selecciona un cliente antes de buscar referencias.', 'error');
            return;
        }

        const pallets = parsePositiveInteger(quantityInput.value);
        const item = cache.get(selectedItemId);

        if (!item || !Number.isFinite(pallets) || pallets <= 0) {
            setFeedback('Selecciona una referencia y una cantidad entera mayor que cero.', 'error');
            return;
        }

        upsertHiddenInput(selectedItemId, pallets);
        renderSummary();
        setFeedback(`${item.sku} se ha añadido a la salida.`, 'success');
        quantityInput.value = '1';
        autocomplete?.clear();
    });

    summaryRows.addEventListener('input', (event) => {
        const input = event.target.closest('[data-dispatch-summary-quantity]');

        if (!input) {
            return;
        }

        const pallets = parsePositiveInteger(input.value);
        upsertHiddenInput(input.dataset.itemId, Number.isFinite(pallets) ? pallets : 0);
        renderSummary();
    });

    summaryRows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dispatch-summary-remove]');

        if (!button) {
            return;
        }

        upsertHiddenInput(button.dataset.itemId, 0);
        renderSummary();
    });

    renderSummary();
    form.dataset.dispatchBuilderBound = 'true';
};

const setupGoodsReceiptLines = () => {
    const form = document.querySelector('[data-goods-receipt-form]');
    const container = document.querySelector('[data-receipt-lines]');
    const addButton = document.querySelector('[data-add-line]');
    const template = document.querySelector('[data-line-template]');
    const clientSelect = document.querySelector('[data-receipt-client]');

    if (!form || !container || !addButton || !template || !clientSelect || container.dataset.linesBound === 'true') {
        return;
    }

    const rowCount = () => container.querySelectorAll('[data-line-row]').length;

    const markAutofilled = (field, isAutofilled) => {
        if (!field) {
            return;
        }

        field.classList.toggle('is-autofilled', isAutofilled);
        field.dataset.autofilled = isAutofilled ? 'true' : 'false';
    };

    const recalculateRow = (row) => {
        const quantityField = row.querySelector('[data-line-quantity]');
        const unitsField = row.querySelector('[data-line-units]');
        const palletCountField = row.querySelector('[data-line-pallet-count]');
        const picoField = row.querySelector('[data-line-pico]');

        if (!quantityField || !unitsField || !palletCountField || !picoField) {
            return;
        }

        const quantity = Number.parseInt(quantityField.value, 10);
        const unitsPerPallet = Number.parseInt(unitsField.value, 10);

        if (!Number.isFinite(quantity) || quantity <= 0 || !Number.isFinite(unitsPerPallet) || unitsPerPallet <= 0) {
            palletCountField.value = '';
            picoField.value = '';
            return;
        }

        palletCountField.value = String(Math.floor(quantity / unitsPerPallet));
        const picoUnits = quantity % unitsPerPallet;
        picoField.value = picoUnits > 0 ? String(picoUnits) : '';
    };

    const clearAutofilledFields = (row) => {
        ['[data-line-sku]', '[data-line-description]', '[data-line-units]'].forEach((selector) => {
            const field = row.querySelector(selector);

            if (field?.dataset.autofilled === 'true') {
                field.value = '';
                markAutofilled(field, false);
            }
        });

        recalculateRow(row);
    };

    const bindRow = (row) => {
        if (!row || row.dataset.rowBound === 'true') {
            return;
        }

        const itemIdField = row.querySelector('[data-line-item-id]');
        const skuField = row.querySelector('[data-line-sku]');
        const descriptionField = row.querySelector('[data-line-description]');
        const unitsField = row.querySelector('[data-line-units]');
        const locationField = row.querySelector('[data-line-location]');
        const quantityField = row.querySelector('[data-line-quantity]');

        createAutocomplete(row.querySelector('[data-receipt-item-picker]'), {
            buildParams: () => ({
                client_id: clientSelect.value ?? '',
                active_only: '1',
                limit: '10',
            }),
            hasSelection: () => (itemIdField?.value ?? '') !== '',
            onSelect: (item) => {
                if (itemIdField) {
                    itemIdField.value = String(item.id);
                }

                if (skuField) {
                    skuField.value = item.sku ?? '';
                    markAutofilled(skuField, true);
                }

                if (descriptionField) {
                    descriptionField.value = item.description ?? '';
                    markAutofilled(descriptionField, true);
                }

                if (unitsField) {
                    unitsField.value = item.units_per_pallet ? String(item.units_per_pallet) : '';
                    markAutofilled(unitsField, true);
                }

                if (locationField && !locationField.value && item.default_location_id) {
                    locationField.value = String(item.default_location_id);
                }

                recalculateRow(row);
            },
            onInputChange: () => {
                if (itemIdField) {
                    itemIdField.value = '';
                }

                clearAutofilledFields(row);
            },
            onClear: () => {
                if (itemIdField) {
                    itemIdField.value = '';
                }

                clearAutofilledFields(row);
            },
        });

        [quantityField, unitsField].forEach((field) => {
            field?.addEventListener('input', () => {
                if (field === unitsField) {
                    markAutofilled(unitsField, false);
                }

                recalculateRow(row);
            });
        });

        [skuField, descriptionField].forEach((field) => {
            field?.addEventListener('input', () => {
                markAutofilled(field, false);
            });
        });

        row.dataset.rowBound = 'true';
        recalculateRow(row);
    };

    const resetRow = (row) => {
        row.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
                field.value = '';
                markAutofilled(field, false);
            }

            if (field instanceof HTMLSelectElement) {
                field.selectedIndex = 0;
            }
        });

        recalculateRow(row);
    };

    addButton.addEventListener('click', () => {
        const markup = template.innerHTML.replaceAll('__INDEX__', String(rowCount()));
        container.insertAdjacentHTML('beforeend', markup);
        bindRow(container.querySelectorAll('[data-line-row]')[rowCount() - 1]);
    });

    container.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-remove-line]');

        if (!trigger) {
            return;
        }

        const row = trigger.closest('[data-line-row]');

        if (!row) {
            return;
        }

        if (rowCount() === 1) {
            resetRow(row);
            return;
        }

        row.remove();
    });

    container.querySelectorAll('[data-line-row]').forEach(bindRow);

    clientSelect.addEventListener('change', () => {
        container.querySelectorAll('[data-line-row]').forEach((row) => {
            const itemIdField = row.querySelector('[data-line-item-id]');

            if (itemIdField?.value) {
                itemIdField.value = '';
            }
        });
    });

    container.dataset.linesBound = 'true';
};

const bindDispatchExtraAutocomplete = (row, clientId, endpoint) => {
    const itemIdField = row.querySelector('[data-dispatch-extra-item-id]');

    createAutocomplete(row.querySelector('[data-dispatch-extra-picker]'), {
        buildParams: () => ({
            client_id: clientId,
            active_only: '1',
            limit: '10',
        }),
        hasSelection: () => (itemIdField?.value ?? '') !== '',
        onSelect: (item) => {
            if (itemIdField) {
                itemIdField.value = String(item.id);
            }
        },
        onInputChange: () => {
            if (itemIdField) {
                itemIdField.value = '';
            }
        },
        onClear: () => {
            if (itemIdField) {
                itemIdField.value = '';
            }
        },
    });
};

const setupDispatchLoadingEditor = () => {
    const form = document.querySelector('[data-dispatch-loading-editor]');

    if (!form || form.dataset.loadingEditorBound === 'true') {
        return;
    }

    const rowsContainer = form.querySelector('[data-dispatch-loading-rows]');
    const addButton = form.querySelector('[data-dispatch-loading-add]');
    const template = form.querySelector('[data-dispatch-loading-row-template]');
    const endpoint = form.dataset.searchEndpoint ?? '';
    const clientId = form.dataset.clientId ?? '';

    if (!rowsContainer || !addButton || !template || !endpoint) {
        return;
    }

    let counter = rowsContainer.querySelectorAll('[data-dispatch-loading-row]').length;

    rowsContainer.querySelectorAll('[data-dispatch-loading-row]').forEach((row) => {
        if (row.querySelector('[data-dispatch-extra-picker]')) {
            bindDispatchExtraAutocomplete(row, clientId, endpoint);
        }
    });

    addButton.addEventListener('click', () => {
        const key = `new_${counter}`;
        rowsContainer.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__KEY__', key));
        const newRow = rowsContainer.querySelectorAll('[data-dispatch-loading-row]')[rowsContainer.querySelectorAll('[data-dispatch-loading-row]').length - 1];
        bindDispatchExtraAutocomplete(newRow, clientId, endpoint);
        counter += 1;
    });

    rowsContainer.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-dispatch-loading-remove]');

        if (!trigger) {
            return;
        }

        const row = trigger.closest('[data-dispatch-loading-row]');

        if (!row) {
            return;
        }

        const removeFlag = row.querySelector('[data-dispatch-remove-flag]');

        if (removeFlag) {
            removeFlag.value = '1';
        }

        row.remove();
    });

    form.dataset.loadingEditorBound = 'true';
};

const boot = () => {
    setupAppDrawer();
    setupStockFilters();
    setupStockDetailToggles();
    setupGoodsReceiptLines();
    setupMerchandiseRequestBuilder();
    setupGoodsDispatchBuilder();
    setupDispatchLoadingEditor();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
