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
                ['[data-line-sku]', '[data-line-description]', '[data-line-units]', '[data-line-lot]'].forEach((selector) => {
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
        const lotField = row.querySelector('[data-line-lot]');
        const unitsField = row.querySelector('[data-line-units]');

        if (!itemSelect || !skuField || !descriptionField || !lotField || !unitsField) {
            return;
        }

        const item = itemsById.get(itemSelect.value);

        if (!item) {
            [skuField, descriptionField, lotField, unitsField].forEach((field) => {
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

        if (!lotField.value && item.lot) {
            lotField.value = item.lot;
            markAutofilled(lotField, true);
        }

        markAutofilled(skuField, true);
        markAutofilled(descriptionField, true);
        markAutofilled(unitsField, true);

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
        const lotField = row.querySelector('[data-line-lot]');

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

        [skuField, descriptionField, lotField].forEach((field) => {
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

    const itemCards = Array.from(form.querySelectorAll('[data-request-item-card]'));
    const summaryRows = form.querySelector('[data-request-summary-rows]');
    const summaryEmpty = form.querySelector('[data-request-summary-empty]');
    const summaryLines = form.querySelector('[data-request-summary-lines]');
    const summaryPallets = form.querySelector('[data-request-summary-pallets]');
    const hiddenInputs = form.querySelector('[data-request-hidden-inputs]');
    const pickerSelect = form.querySelector('[data-request-picker-item]');
    const pickerQuantity = form.querySelector('[data-request-picker-quantity]');
    const pickerFeedback = form.querySelector('[data-request-picker-feedback]');
    const pickerAddButton = form.querySelector('[data-request-add-selected]');
    const submitButton = form.querySelector('[data-request-submit]');

    if (
        !summaryRows
        || !summaryEmpty
        || !summaryLines
        || !summaryPallets
        || !hiddenInputs
        || !pickerSelect
        || !pickerQuantity
        || !pickerFeedback
        || !pickerAddButton
        || !submitButton
    ) {
        return;
    }

    const formatNumber = new Intl.NumberFormat('es-ES');

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const sourceInputFor = (itemId) => form.querySelector(`[data-request-quantity][data-item-id="${itemId}"]`);
    const hiddenInputFor = (itemId) => hiddenInputs.querySelector(`[data-request-hidden-quantity][data-item-id="${itemId}"]`);
    const cardFor = (itemId) => form.querySelector(`[data-request-item-card][data-item-id="${itemId}"]`);

    const normalizeInput = (input) => {
        if (!input) {
            return 0;
        }

        const parsed = Number.parseInt(input.value, 10);
        const safeValue = Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
        input.value = String(safeValue);

        return safeValue;
    };

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

    const syncCardState = (itemId) => {
        const card = cardFor(itemId);
        const sourceInput = sourceInputFor(itemId);
        const hiddenInput = hiddenInputFor(itemId);
        const isSelected = hiddenInput !== null;

        card?.classList.toggle('is-selected', isSelected);

        if (sourceInput && hiddenInput) {
            sourceInput.value = hiddenInput.value;
        }
    };

    const selectedItems = () => Array.from(hiddenInputs.querySelectorAll('[data-request-hidden-quantity]'))
        .map((input) => {
            const itemId = input.dataset.itemId;
            const card = cardFor(itemId);
            const pallets = normalizeInput(input);

            if (!itemId || !card || pallets <= 0) {
                return null;
            }

            return {
                itemId,
                sku: card.dataset.itemSku ?? '',
                description: card.dataset.itemDescription ?? '',
                lot: card.dataset.itemLot ?? 'Sin lote',
                unitsPerPallet: card.dataset.unitsPerPallet ?? '',
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
            syncCardState(itemId);
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
        syncCardState(itemId);
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
                        <strong>${escapeHtml(line.sku)}</strong>
                        <span class="users-table-email">
                            ${escapeHtml(line.description)} · ${escapeHtml(line.lot)} · ${escapeHtml(line.unitsPerPallet)} uds/pallet
                        </span>
                    </div>
                </td>
                <td>
                    <input
                        type="number"
                        min="1"
                        step="1"
                        value="${escapeHtml(line.pallets)}"
                        class="auth-input merchandise-request-summary-input"
                        data-summary-quantity
                        data-item-id="${escapeHtml(line.itemId)}"
                    >
                </td>
                <td>
                    <button
                        type="button"
                        class="button-secondary compact-button btn-table"
                        data-summary-remove
                        data-item-id="${escapeHtml(line.itemId)}"
                    >
                        Eliminar
                    </button>
                </td>
            </tr>
        `).join('');
    };

    const addItemToRequest = (itemId, pallets) => {
        const normalizedPallets = parsePositiveInteger(pallets);

        if (!itemId || normalizedPallets <= 0) {
            setFeedback('Indica una cantidad valida de pallets antes de anadir la mercancia.', 'error');
            return;
        }

        upsertHiddenInput(itemId, normalizedPallets);
        renderSummary();

        const card = cardFor(itemId);
        const itemLabel = card?.dataset.itemSku ?? 'La mercancia seleccionada';

        setFeedback(`${itemLabel} se ha anadido al pedido con ${formatNumber.format(normalizedPallets)} pallets.`, 'success');
    };

    pickerAddButton.addEventListener('click', () => {
        addItemToRequest(pickerSelect.value, pickerQuantity.value);
    });

    pickerQuantity.addEventListener('input', () => {
        const validValue = parsePositiveInteger(pickerQuantity.value);

        if (validValue <= 0 && pickerQuantity.value !== '') {
            setFeedback('Usa solo cantidades enteras y mayores que cero.', 'error');
            return;
        }

        setFeedback('Puedes anadir varias lineas. Si repites una mercancia, se actualizara la cantidad en el resumen.');
    });

    itemCards.forEach((card) => {
        const input = sourceInputFor(card.dataset.itemId);
        const addButton = card.querySelector('[data-request-add-item]');

        input?.addEventListener('input', () => {
            if (parsePositiveInteger(input.value) <= 0 && input.value !== '') {
                setFeedback('Usa solo cantidades enteras y mayores que cero.', 'error');
            }
        });

        addButton?.addEventListener('click', () => {
            addItemToRequest(card.dataset.itemId, input?.value ?? '');
        });
    });

    summaryRows.addEventListener('input', (event) => {
        const input = event.target.closest('[data-summary-quantity]');

        if (!input) {
            return;
        }

        const pallets = parsePositiveInteger(input.value);

        upsertHiddenInput(input.dataset.itemId, pallets);
        renderSummary();
    });

    summaryRows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-summary-remove]');

        if (!button) {
            return;
        }

        upsertHiddenInput(button.dataset.itemId, 0);
        renderSummary();
        setFeedback('Linea eliminada del pedido. Puedes volver a anadirla cuando quieras.');
    });

    form.querySelectorAll('[data-request-hidden-quantity]').forEach((input) => {
        syncCardState(input.dataset.itemId);
    });

    form.dataset.requestBuilderBound = 'true';
    renderSummary();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        setupAppDrawer();
        setupGoodsReceiptLines();
        setupMerchandiseRequestBuilder();
    }, { once: true });
} else {
    setupAppDrawer();
    setupGoodsReceiptLines();
    setupMerchandiseRequestBuilder();
}
