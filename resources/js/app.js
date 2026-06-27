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

    const openDrawer = () => syncState(true);
    const closeDrawer = () => syncState(false);

    toggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            const isOpen = body.classList.contains('drawer-open');
            syncState(!isOpen);
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

const setupGoodsReceiptLines = () => {
    const form = document.querySelector('[data-goods-receipt-form]');
    const container = document.querySelector('[data-receipt-lines]');
    const addButton = document.querySelector('[data-add-line]');
    const template = document.querySelector('[data-line-template]');
    const clientSelect = document.querySelector('[data-receipt-client]');
    const itemsCatalogNode = document.querySelector('[data-goods-receipt-items]');

    if (!form || !container || !addButton || !template || !clientSelect || !itemsCatalogNode || container.dataset.linesBound === 'true') {
        return;
    }

    let itemsCatalog = [];

    try {
        itemsCatalog = JSON.parse(itemsCatalogNode.textContent ?? '[]');
    } catch {
        itemsCatalog = [];
    }

    const itemsById = new Map(itemsCatalog.map((item) => [String(item.id), item]));
    const rowCount = () => container.querySelectorAll('[data-line-row]').length;
    const currentClientId = () => clientSelect.value;

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

        const palletCount = Math.floor(quantity / unitsPerPallet);
        const picoUnits = quantity % unitsPerPallet;

        palletCountField.value = String(palletCount);
        picoField.value = picoUnits > 0 ? String(picoUnits) : '';
    };

    const syncItemOptionsForRow = (row) => {
        const itemSelect = row.querySelector('[data-line-item]');

        if (!itemSelect) {
            return;
        }

        const clientId = currentClientId();

        itemSelect.querySelectorAll('option[data-item-client-id]').forEach((option) => {
            const matchesClient = clientId === '' || option.dataset.itemClientId === clientId;
            option.hidden = !matchesClient;
            option.disabled = !matchesClient;
        });

        if (itemSelect.value !== '') {
            const selectedOption = itemSelect.selectedOptions[0];

            if (selectedOption?.disabled) {
                itemSelect.value = '';
                ['[data-line-sku]', '[data-line-description]', '[data-line-units]'].forEach((selector) => {
                    const field = row.querySelector(selector);

                    if (field?.dataset.autofilled === 'true') {
                        field.value = '';
                        markAutofilled(field, false);
                    }
                });

                recalculateRow(row);
            }
        }
    };

    const applyItemToRow = (row) => {
        const itemSelect = row.querySelector('[data-line-item]');
        const skuField = row.querySelector('[data-line-sku]');
        const descriptionField = row.querySelector('[data-line-description]');
        const unitsField = row.querySelector('[data-line-units]');
        const locationField = row.querySelector('[data-line-location]');

        if (!itemSelect || !skuField || !descriptionField || !unitsField) {
            return;
        }

        const item = itemsById.get(itemSelect.value);

        if (!item) {
            [skuField, descriptionField, unitsField].forEach((field) => {
                if (field.dataset.autofilled === 'true') {
                    markAutofilled(field, false);
                }
            });

            recalculateRow(row);
            return;
        }

        skuField.value = item.sku ?? '';
        descriptionField.value = item.description ?? '';
        unitsField.value = item.units_per_pallet ? String(item.units_per_pallet) : '';

        markAutofilled(skuField, true);
        markAutofilled(descriptionField, true);
        markAutofilled(unitsField, true);

        if (locationField && !locationField.value && item.default_location_id) {
            locationField.value = String(item.default_location_id);
        }

        recalculateRow(row);
    };

    const bindRow = (row) => {
        if (!row || row.dataset.rowBound === 'true') {
            return;
        }

        syncItemOptionsForRow(row);
        applyItemToRow(row);
        recalculateRow(row);

        const itemSelect = row.querySelector('[data-line-item]');
        const quantityField = row.querySelector('[data-line-quantity]');
        const unitsField = row.querySelector('[data-line-units]');
        const skuField = row.querySelector('[data-line-sku]');
        const descriptionField = row.querySelector('[data-line-description]');

        itemSelect?.addEventListener('change', () => {
            applyItemToRow(row);
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
    };

    const resetRow = (row) => {
        row.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field instanceof HTMLInputElement && field.type === 'number') {
                field.value = '';
                markAutofilled(field, false);
                return;
            }

            if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
                field.value = '';
                markAutofilled(field, false);
                return;
            }

            if (field instanceof HTMLSelectElement) {
                field.selectedIndex = 0;
            }
        });

        recalculateRow(row);
    };

    addButton.addEventListener('click', () => {
        const nextIndex = rowCount();
        const markup = template.innerHTML.replaceAll('__INDEX__', String(nextIndex));
        container.insertAdjacentHTML('beforeend', markup);
        const rows = container.querySelectorAll('[data-line-row]');
        const newRow = rows[rows.length - 1];

        bindRow(newRow);
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

    clientSelect.addEventListener('change', () => {
        container.querySelectorAll('[data-line-row]').forEach((row) => {
            syncItemOptionsForRow(row);
        });
    });

    container.querySelectorAll('[data-line-row]').forEach((row) => {
        bindRow(row);
    });

    container.dataset.linesBound = 'true';
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
    const searchInput = form.querySelector('[data-request-search]');
    const searchFeedback = form.querySelector('[data-request-search-feedback]');
    const resultsNode = form.querySelector('[data-request-results]');
    const submitButton = form.querySelector('[data-request-submit]');
    const selectedItemsNode = form.querySelector('[data-request-selected-items]');
    const searchEndpoint = form.dataset.searchEndpoint;

    if (
        !summaryRows
        || !summaryEmpty
        || !summaryLines
        || !summaryPallets
        || !hiddenInputs
        || !searchInput
        || !searchFeedback
        || !resultsNode
        || !submitButton
        || !selectedItemsNode
        || !searchEndpoint
    ) {
        return;
    }

    const formatNumber = new Intl.NumberFormat('es-ES');
    const itemCache = new Map();
    let searchResults = [];
    let searchTimer = null;
    let currentRequest = null;

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const hiddenInputFor = (itemId) => hiddenInputs.querySelector(`[data-request-hidden-quantity][data-item-id="${itemId}"]`);

    const parsePositiveInteger = (value) => {
        const normalized = String(value ?? '').trim();

        if (!/^[1-9]\d*$/.test(normalized)) {
            return 0;
        }

        const parsed = Number.parseInt(normalized, 10);

        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    };

    const setFeedback = (message, type = 'default') => {
        searchFeedback.textContent = message;
        searchFeedback.classList.toggle('helper-text--error', type === 'error');
        searchFeedback.classList.toggle('helper-text--success', type === 'success');
    };

    const rememberItem = (item) => {
        if (!item || !item.id) {
            return;
        }

        itemCache.set(String(item.id), item);
    };

    const selectedItems = () => Array.from(hiddenInputs.querySelectorAll('[data-request-hidden-quantity]'))
        .map((input) => {
            const itemId = input.dataset.itemId;
            const item = itemCache.get(String(itemId));
            const pallets = parsePositiveInteger(input.value);

            if (!itemId || !item || pallets <= 0) {
                return null;
            }

            return {
                itemId,
                sku: item.sku ?? '',
                description: item.description ?? '',
                unitsPerPallet: item.units_per_pallet ?? '',
                pallets,
            };
        })
        .filter(Boolean);

    const upsertHiddenInput = (itemId, pallets) => {
        if (!itemId) {
            return;
        }

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

    const renderSummary = () => {
        const lines = selectedItems();
        const totalPallets = lines.reduce((total, line) => total + line.pallets, 0);

        summaryLines.textContent = formatNumber.format(lines.length);
        summaryPallets.textContent = formatNumber.format(totalPallets);
        summaryEmpty.hidden = lines.length > 0;
        submitButton.disabled = lines.length === 0;

        if (lines.length === 0) {
            summaryRows.innerHTML = '';
            return;
        }

        summaryRows.innerHTML = lines.map((line) => `
            <article class="merchandise-request-summary-row">
                <div class="merchandise-request-summary-main">
                    <strong>${escapeHtml(line.sku)}</strong>
                    <span>${escapeHtml(line.description)}</span>
                    <small>${escapeHtml(line.unitsPerPallet)} uds/pallet</small>
                </div>
                <label class="auth-field merchandise-request-summary-field">
                    <span>Pallets</span>
                    <input
                        type="number"
                        min="1"
                        step="1"
                        value="${escapeHtml(line.pallets)}"
                        class="auth-input merchandise-request-summary-input"
                        data-summary-quantity
                        data-item-id="${escapeHtml(line.itemId)}"
                    >
                </label>
                <button
                    type="button"
                    class="button-secondary compact-button btn-compact"
                    data-summary-remove
                    data-item-id="${escapeHtml(line.itemId)}"
                >
                    Quitar
                </button>
            </article>
        `).join('');
    };

    const renderResults = () => {
        const query = searchInput.value.trim();

        if (query.length < 2) {
            resultsNode.innerHTML = `
                <div class="merchandise-request-results-empty">
                    Empieza a escribir para buscar mercancías activas sin cargar todo el catálogo.
                </div>
            `;
            return;
        }

        if (searchResults.length === 0) {
            resultsNode.innerHTML = `
                <div class="merchandise-request-results-empty">
                    No hay resultados para "${escapeHtml(query)}".
                </div>
            `;
            return;
        }

        resultsNode.innerHTML = searchResults.map((item) => {
            const selected = hiddenInputFor(String(item.id));
            const currentPallets = selected ? selected.value : '1';

            return `
                <article class="merchandise-request-result-row ${selected ? 'is-selected' : ''}">
                    <div class="merchandise-request-result-main">
                        <strong>${escapeHtml(item.sku)}</strong>
                        <span>${escapeHtml(item.description)}</span>
                        <small>${escapeHtml(item.units_per_pallet)} uds/pallet</small>
                    </div>
                    <label class="auth-field merchandise-request-result-quantity">
                        <span>Pallets</span>
                        <input
                            type="number"
                            min="1"
                            step="1"
                            value="${escapeHtml(currentPallets)}"
                            class="auth-input"
                            data-result-quantity
                            data-item-id="${escapeHtml(item.id)}"
                        >
                    </label>
                    <button
                        type="button"
                        class="button-primary compact-button btn-compact"
                        data-result-add
                        data-item-id="${escapeHtml(item.id)}"
                    >
                        ${selected ? 'Actualizar' : 'A�adir'}
                    </button>
                </article>
            `;
        }).join('');
    };

    const addItemToRequest = (itemId, pallets) => {
        const normalizedPallets = parsePositiveInteger(pallets);
        const item = itemCache.get(String(itemId));

        if (!itemId || !item || normalizedPallets <= 0) {
            setFeedback('Indica una cantidad v�lida de pallets antes de a�adir la mercanc�a.', 'error');
            return;
        }

        upsertHiddenInput(itemId, normalizedPallets);
        renderSummary();
        renderResults();
        setFeedback(`${item.sku} se ha a�adido al pedido con ${formatNumber.format(normalizedPallets)} pallets.`, 'success');
    };

    const performSearch = async () => {
        const query = searchInput.value.trim();

        if (query.length < 2) {
            searchResults = [];
            setFeedback('Escribe al menos 2 caracteres para buscar en tu cat�logo activo.');
            renderResults();
            return;
        }

        currentRequest?.abort();
        currentRequest = new AbortController();
        setFeedback('Buscando mercanc�as...');

        try {
            const response = await fetch(`${searchEndpoint}?search=${encodeURIComponent(query)}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: currentRequest.signal,
            });

            if (!response.ok) {
                throw new Error('search_failed');
            }

            const payload = await response.json();
            searchResults = Array.isArray(payload.data) ? payload.data : [];
            searchResults.forEach(rememberItem);
            setFeedback(
                searchResults.length > 0
                    ? `${formatNumber.format(searchResults.length)} resultados encontrados.`
                    : 'No se han encontrado mercanc�as con ese criterio.',
                searchResults.length > 0 ? 'success' : 'default',
            );
            renderResults();
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            searchResults = [];
            setFeedback('No se ha podido completar la b�squeda. Int�ntalo de nuevo en unos segundos.', 'error');
            renderResults();
        }
    };

    searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            performSearch();
        }, 250);
    });

    resultsNode.addEventListener('input', (event) => {
        const input = event.target.closest('[data-result-quantity]');

        if (!input) {
            return;
        }

        if (parsePositiveInteger(input.value) <= 0 && input.value !== '') {
            setFeedback('Usa solo cantidades enteras y mayores que cero.', 'error');
        }
    });

    resultsNode.addEventListener('click', (event) => {
        const button = event.target.closest('[data-result-add]');

        if (!button) {
            return;
        }

        const quantityInput = resultsNode.querySelector(`[data-result-quantity][data-item-id="${button.dataset.itemId}"]`);
        addItemToRequest(button.dataset.itemId, quantityInput?.value ?? '0');
    });

    summaryRows.addEventListener('input', (event) => {
        const input = event.target.closest('[data-summary-quantity]');

        if (!input) {
            return;
        }

        const pallets = parsePositiveInteger(input.value);

        upsertHiddenInput(input.dataset.itemId, pallets);
        renderSummary();
        renderResults();
    });

    summaryRows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-summary-remove]');

        if (!button) {
            return;
        }

        upsertHiddenInput(button.dataset.itemId, 0);
        renderSummary();
        renderResults();
        setFeedback('L�nea eliminada del pedido. Puedes volver a a�adirla cuando quieras.');
    });

    try {
        const initialItems = JSON.parse(selectedItemsNode.textContent ?? '[]');

        if (Array.isArray(initialItems)) {
            initialItems.forEach(rememberItem);
        }
    } catch {
        // Ignore invalid bootstrap data and keep search working.
    }

    form.dataset.requestBuilderBound = 'true';
    renderSummary();
    renderResults();
};

const setupGoodsDispatchBuilder = () => {
    const form = document.querySelector('[data-goods-dispatch-form]');

    if (!form || form.dataset.dispatchBuilderBound === 'true') {
        return;
    }

    const clientSelect = form.querySelector('[data-dispatch-client]');
    const pickerSelect = form.querySelector('[data-dispatch-picker-item]');
    const pickerQuantity = form.querySelector('[data-dispatch-picker-quantity]');
    const pickerFeedback = form.querySelector('[data-dispatch-picker-feedback]');
    const pickerAddButton = form.querySelector('[data-dispatch-add-selected]');
    const hiddenInputs = form.querySelector('[data-dispatch-hidden-inputs]');
    const summaryRows = form.querySelector('[data-dispatch-summary-rows]');
    const summaryEmpty = form.querySelector('[data-dispatch-summary-empty]');
    const summaryLines = form.querySelector('[data-dispatch-summary-lines]');
    const summaryPallets = form.querySelector('[data-dispatch-summary-pallets]');
    const submitButton = form.querySelector('[data-dispatch-submit]');
    const itemsCatalogNode = form.querySelector('[data-dispatch-items]');

    if (
        !clientSelect
        || !pickerSelect
        || !pickerQuantity
        || !pickerFeedback
        || !pickerAddButton
        || !hiddenInputs
        || !summaryRows
        || !summaryEmpty
        || !summaryLines
        || !summaryPallets
        || !submitButton
        || !itemsCatalogNode
    ) {
        return;
    }

    let itemsCatalog = [];

    try {
        itemsCatalog = JSON.parse(itemsCatalogNode.textContent ?? '[]');
    } catch {
        itemsCatalog = [];
    }

    const itemsById = new Map(itemsCatalog.map((item) => [String(item.id), item]));
    const formatNumber = new Intl.NumberFormat('es-ES');

    const hiddenInputFor = (itemId) => hiddenInputs.querySelector(`[data-dispatch-hidden-quantity][data-item-id="${itemId}"]`);

    const parsePositiveInteger = (value) => {
        const normalized = String(value ?? '').trim();

        if (!/^[1-9]\d*$/.test(normalized)) {
            return 0;
        }

        const parsed = Number.parseInt(normalized, 10);

        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    };

    const setFeedback = (message, type = 'default') => {
        pickerFeedback.textContent = message;
        pickerFeedback.classList.toggle('helper-text--error', type === 'error');
        pickerFeedback.classList.toggle('helper-text--success', type === 'success');
    };

    const syncPickerOptions = () => {
        const currentClientId = clientSelect.value;

        pickerSelect.querySelectorAll('option[data-item-client-id]').forEach((option) => {
            const matchesClient = currentClientId !== '' && option.dataset.itemClientId === currentClientId;
            option.hidden = !matchesClient;
            option.disabled = !matchesClient;
        });

        if (pickerSelect.selectedOptions[0]?.disabled) {
            pickerSelect.value = '';
        }
    };

    const selectedItems = () => Array.from(hiddenInputs.querySelectorAll('[data-dispatch-hidden-quantity]'))
        .map((input) => {
            const item = itemsById.get(input.dataset.itemId);
            const pallets = parsePositiveInteger(input.value);

            if (!item || pallets <= 0) {
                return null;
            }

            return {
                itemId: String(item.id),
                sku: item.sku ?? '',
                description: item.description ?? '',
                unitsPerPallet: item.units_per_pallet ?? '',
                pallets,
            };
        })
        .filter(Boolean);

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

    const renderSummary = () => {
        const lines = selectedItems();
        const totalPallets = lines.reduce((total, line) => total + line.pallets, 0);

        summaryLines.textContent = formatNumber.format(lines.length);
        summaryPallets.textContent = formatNumber.format(totalPallets);
        summaryEmpty.hidden = lines.length > 0;
        submitButton.disabled = lines.length === 0;

        if (lines.length === 0) {
            summaryRows.innerHTML = '';
            return;
        }

        summaryRows.innerHTML = lines.map((line) => `
            <tr>
                <td>
                    <div class="stock-cell-main">
                        <strong>${line.sku}</strong>
                        <span class="users-table-email">${line.description} · ${line.unitsPerPallet} uds/pallet</span>
                    </div>
                </td>
                <td>
                    <input
                        type="number"
                        min="1"
                        step="1"
                        value="${line.pallets}"
                        class="auth-input merchandise-request-summary-input"
                        data-dispatch-summary-quantity
                        data-item-id="${line.itemId}"
                    >
                </td>
                <td>
                    <button type="button" class="button-secondary compact-button btn-table" data-dispatch-summary-remove data-item-id="${line.itemId}">
                        Eliminar
                    </button>
                </td>
            </tr>
        `).join('');
    };

    const addItem = () => {
        if (clientSelect.value === '') {
            setFeedback('Selecciona un cliente antes de anadir referencias a la salida.', 'error');
            return;
        }

        const itemId = pickerSelect.value;
        const pallets = parsePositiveInteger(pickerQuantity.value);

        if (!itemId || pallets <= 0) {
            setFeedback('Indica una referencia valida y una cantidad entera mayor que cero.', 'error');
            return;
        }

        upsertHiddenInput(itemId, pallets);
        renderSummary();

        const item = itemsById.get(itemId);
        setFeedback(`${item?.sku ?? 'La referencia'} se ha anadido a la salida.`, 'success');
    };

    clientSelect.addEventListener('change', () => {
        syncPickerOptions();
        setFeedback('Cliente seleccionado. Ya puedes anadir referencias a la salida.');
    });

    pickerAddButton.addEventListener('click', addItem);

    summaryRows.addEventListener('input', (event) => {
        const input = event.target.closest('[data-dispatch-summary-quantity]');

        if (!input) {
            return;
        }

        upsertHiddenInput(input.dataset.itemId, parsePositiveInteger(input.value));
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

    syncPickerOptions();
    renderSummary();
    form.dataset.dispatchBuilderBound = 'true';
};

const setupDispatchLoadingEditor = () => {
    const form = document.querySelector('[data-dispatch-loading-editor]');

    if (!form || form.dataset.loadingEditorBound === 'true') {
        return;
    }

    const rowsContainer = form.querySelector('[data-dispatch-loading-rows]');
    const addButton = form.querySelector('[data-dispatch-loading-add]');
    const template = form.querySelector('[data-dispatch-loading-row-template]');

    if (!rowsContainer || !addButton || !template) {
        return;
    }

    let counter = rowsContainer.querySelectorAll('[data-dispatch-loading-row]').length;

    addButton.addEventListener('click', () => {
        const key = `new_${counter}`;
        const markup = template.innerHTML.replaceAll('__KEY__', key);

        rowsContainer.insertAdjacentHTML('beforeend', markup);
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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        setupAppDrawer();
        setupGoodsReceiptLines();
        setupMerchandiseRequestBuilder();
        setupGoodsDispatchBuilder();
        setupDispatchLoadingEditor();
    }, { once: true });
} else {
    setupAppDrawer();
    setupGoodsReceiptLines();
    setupMerchandiseRequestBuilder();
    setupGoodsDispatchBuilder();
    setupDispatchLoadingEditor();
}
