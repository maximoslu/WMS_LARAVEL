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

const setupMerchandiseRequestLines = () => {
    const form = document.querySelector('[data-merchandise-request-form]');
    const container = document.querySelector('[data-request-lines]');
    const addButton = document.querySelector('[data-add-request-line]');
    const template = document.querySelector('[data-request-line-template]');
    const clientField = document.querySelector('[data-request-client]');
    const itemsCatalogNode = document.querySelector('[data-merchandise-request-items]');

    if (!form || !container || !addButton || !template || !clientField || !itemsCatalogNode || container.dataset.requestLinesBound === 'true') {
        return;
    }

    let itemsCatalog = [];

    try {
        itemsCatalog = JSON.parse(itemsCatalogNode.textContent ?? '[]');
    } catch {
        itemsCatalog = [];
    }

    const itemsById = new Map(itemsCatalog.map((item) => [String(item.id), item]));
    const currentClientId = () => clientField.value;
    const rowCount = () => container.querySelectorAll('[data-request-line-row]').length;

    const markDerived = (field, enabled) => {
        if (!field) {
            return;
        }

        field.classList.toggle('is-autofilled', enabled);
    };

    const syncRowTotals = (row) => {
        const palletsField = row.querySelector('[data-request-pallets]');
        const unitsField = row.querySelector('[data-request-units-per-pallet]');
        const totalField = row.querySelector('[data-request-total-units]');

        if (!palletsField || !unitsField || !totalField) {
            return;
        }

        const pallets = Number.parseInt(palletsField.value, 10);
        const units = Number.parseInt(unitsField.value, 10);

        if (!Number.isFinite(pallets) || pallets <= 0 || !Number.isFinite(units) || units <= 0) {
            totalField.value = '';
            return;
        }

        totalField.value = String(pallets * units);
    };

    const syncItemOptionsForRow = (row) => {
        const itemSelect = row.querySelector('[data-request-item]');

        if (!itemSelect) {
            return;
        }

        const clientId = currentClientId();

        itemSelect.querySelectorAll('option[data-item-client-id]').forEach((option) => {
            const matchesClient = clientId === '' || option.dataset.itemClientId === clientId;
            option.hidden = !matchesClient;
            option.disabled = !matchesClient;
        });

        const selectedOption = itemSelect.selectedOptions[0];

        if (selectedOption?.disabled) {
            itemSelect.value = '';
            applyItemToRow(row);
        }
    };

    const applyItemToRow = (row) => {
        const itemSelect = row.querySelector('[data-request-item]');
        const lotField = row.querySelector('[data-request-lot]');
        const unitsField = row.querySelector('[data-request-units-per-pallet]');

        if (!itemSelect || !lotField || !unitsField) {
            return;
        }

        const item = itemsById.get(itemSelect.value);

        if (!item) {
            if (lotField.dataset.autofilled === 'true') {
                lotField.value = '';
            }

            unitsField.value = '';
            lotField.dataset.autofilled = 'false';
            markDerived(unitsField, false);
            markDerived(lotField, false);
            syncRowTotals(row);
            return;
        }

        unitsField.value = item.units_per_pallet ? String(item.units_per_pallet) : '';
        markDerived(unitsField, true);

        if (!lotField.value && item.lot) {
            lotField.value = item.lot;
            lotField.dataset.autofilled = 'true';
            markDerived(lotField, true);
        }

        syncRowTotals(row);
    };

    const bindRow = (row) => {
        if (!row || row.dataset.requestRowBound === 'true') {
            return;
        }

        syncItemOptionsForRow(row);
        applyItemToRow(row);
        syncRowTotals(row);

        row.querySelector('[data-request-item]')?.addEventListener('change', () => applyItemToRow(row));
        row.querySelector('[data-request-pallets]')?.addEventListener('input', () => syncRowTotals(row));
        row.querySelector('[data-request-lot]')?.addEventListener('input', (event) => {
            event.currentTarget.dataset.autofilled = 'false';
            markDerived(event.currentTarget, false);
        });

        row.dataset.requestRowBound = 'true';
    };

    const resetRow = (row) => {
        row.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
                field.value = '';
                markDerived(field, false);
            }

            if (field instanceof HTMLSelectElement) {
                field.selectedIndex = 0;
            }
        });
    };

    addButton.addEventListener('click', () => {
        const nextIndex = rowCount();
        const markup = template.innerHTML.replaceAll('__INDEX__', String(nextIndex));
        container.insertAdjacentHTML('beforeend', markup);
        const rows = container.querySelectorAll('[data-request-line-row]');
        bindRow(rows[rows.length - 1]);
    });

    container.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-remove-request-line]');

        if (!trigger) {
            return;
        }

        const row = trigger.closest('[data-request-line-row]');

        if (!row) {
            return;
        }

        if (rowCount() === 1) {
            resetRow(row);
            return;
        }

        row.remove();
    });

    clientField.addEventListener('change', () => {
        container.querySelectorAll('[data-request-line-row]').forEach((row) => syncItemOptionsForRow(row));
    });

    container.querySelectorAll('[data-request-line-row]').forEach((row) => bindRow(row));
    container.dataset.requestLinesBound = 'true';
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        setupAppDrawer();
        setupGoodsReceiptLines();
        setupMerchandiseRequestLines();
    }, { once: true });
} else {
    setupAppDrawer();
    setupGoodsReceiptLines();
    setupMerchandiseRequestLines();
}
