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

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

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

const normalizeVariantKey = (value) => String(value ?? '')
    .trim()
    .replace(/[^a-zA-Z0-9_-]+/g, '_');

const lineTypeLabel = (lineType) => lineType === 'peak' ? 'Pico' : 'Pallet completo';

const quantityLabel = (lineType, quantity = 1) => {
    if (lineType === 'peak') {
        return quantity === 1 ? 'pico' : 'picos';
    }

    return quantity === 1 ? 'pallet' : 'pallets';
};

const quantityFieldLabel = (lineType, loaded = false) => {
    if (lineType === 'peak') {
        return loaded ? 'Picos cargados' : 'Picos';
    }

    return loaded ? 'Pallets cargados' : 'Pallets';
};

const formatVariantUnits = (item) => item.line_type === 'peak'
    ? `${formatNumber.format(item.units_per_peak ?? 0)} uds`
    : `${formatNumber.format(item.units_per_pallet ?? 0)} uds/pallet`;

const formatRequestedQuantity = (item, quantity) => `${formatNumber.format(quantity)} ${quantityLabel(item.line_type, quantity)}`;

const renderVariantPreview = (item, quantity = null) => {
    if (!item) {
        return `
            <strong>Selecciona una referencia para ver el detalle</strong>
            <p>Lote, unidades, disponibilidad y tipo de línea aparecerán aquí antes de añadirla.</p>
        `;
    }

    const quantityMarkup = Number.isFinite(quantity) && quantity > 0
        ? `<span>${escapeHtml(formatRequestedQuantity(item, quantity))}</span>`
        : '';
    const lotMarkup = item.lot ? `<span>Lote ${escapeHtml(item.lot)}</span>` : '<span>Sin lote</span>';
    const locationMarkup = item.location_text ? `<span>Ubicación ${escapeHtml(item.location_text)}</span>` : '';
    const availability = item.line_type === 'peak'
        ? `Pico ${escapeHtml(item.stock_peak_index ?? '')} · ${escapeHtml(formatVariantUnits(item))}`
        : item.available_pallets
            ? `${formatNumber.format(item.available_pallets)} pallets visibles`
            : 'Pallet genÃ©rico';
    const extraAvailability = item.available_peaks
        ? `<span>${formatNumber.format(item.available_peaks)} picos visibles</span>`
        : '';

    return `
        <strong>${escapeHtml(item.sku)}</strong>
        <p>${escapeHtml(item.description ?? '')}</p>
        <div class="wms-line-card-meta">
            <span>${escapeHtml(lineTypeLabel(item.line_type))}</span>
            <span>${escapeHtml(availability)}</span>
            <span>${escapeHtml(formatVariantUnits(item))}</span>
            ${lotMarkup}
            ${locationMarkup}
            ${extraAvailability}
            ${quantityMarkup}
        </div>
    `;
};

const renderVariantAutocompleteOption = (item) => {
    const typeClass = item.line_type === 'peak' ? 'wms-line-type-pill--peak' : 'wms-line-type-pill--pallet';
    const availability = item.line_type === 'peak'
        ? `Pico ${escapeHtml(item.stock_peak_index ?? '')} · ${escapeHtml(formatVariantUnits(item))}`
        : item.available_pallets
            ? `${formatNumber.format(item.available_pallets)} pallets disponibles`
            : 'Pallet genÃ©rico';
    const secondary = [
        item.lot ? `Lote ${item.lot}` : 'Sin lote',
        formatVariantUnits(item),
        item.available_peaks ? `${formatNumber.format(item.available_peaks)} picos` : null,
        item.location_text ? `Ubicación ${item.location_text}` : null,
    ].filter(Boolean).join(' · ');

    return `
        <div class="wms-autocomplete-option">
            <div class="wms-autocomplete-option-head">
                <strong>${escapeHtml(item.sku ?? item.label ?? '')}</strong>
                <span class="wms-line-type-pill ${typeClass}">${escapeHtml(lineTypeLabel(item.line_type))}</span>
            </div>
            <p>${escapeHtml(item.description ?? '')}</p>
            <small>${escapeHtml(availability)}</small>
            <small>${escapeHtml(secondary)}</small>
        </div>
    `;
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
    const useFixedPanel = root.dataset.autocompleteFloating === 'fixed';

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

    const clearPanelPosition = () => {
        if (!useFixedPanel) {
            return;
        }

        panel.style.left = '';
        panel.style.top = '';
        panel.style.bottom = '';
        panel.style.width = '';
    };

    const setStatus = (message) => {
        status.textContent = message;
    };

    const positionPanel = () => {
        const rootRect = root.getBoundingClientRect();
        const estimatedPanelHeight = Math.min(panel.scrollHeight || 320, 320);
        const spaceBelow = window.innerHeight - rootRect.bottom;
        const spaceAbove = rootRect.top;
        const shouldFlip = spaceBelow < estimatedPanelHeight && spaceAbove > spaceBelow;

        panel.classList.toggle('ajax-autocomplete-panel--flip', shouldFlip);

        if (!useFixedPanel) {
            clearPanelPosition();
            return;
        }

        const panelWidth = Math.min(Math.max(rootRect.width, 320), window.innerWidth - 24);
        const left = Math.min(Math.max(rootRect.left, 12), Math.max(window.innerWidth - panelWidth - 12, 12));

        panel.classList.add('ajax-autocomplete-panel--fixed');
        panel.style.width = `${panelWidth}px`;
        panel.style.left = `${left}px`;
        panel.style.top = shouldFlip
            ? `${Math.max(rootRect.top - estimatedPanelHeight - 6, 12)}px`
            : `${Math.min(rootRect.bottom + 6, window.innerHeight - estimatedPanelHeight - 12)}px`;
        panel.style.bottom = 'auto';
    };

    const openPanel = () => {
        panel.hidden = false;
        root.classList.add('is-open');
        positionPanel();
    };

    const closePanel = () => {
        panel.hidden = true;
        root.classList.remove('is-open');
        highlightedIndex = -1;
        panel.classList.remove('ajax-autocomplete-panel--fixed');
        clearPanelPosition();
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
                ${options.renderOption?.(item, index) ?? `
                    <strong>${escapeHtml(item.label ?? item.value ?? '')}</strong>
                    ${item.meta ? `<small>${escapeHtml(item.meta)}</small>` : ''}
                `}
            </button>
        `).join('');

        if (!panel.hidden) {
            positionPanel();
        }
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

            if (results.length === 0) {
                options.onNoResults?.(query);
            }
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

    if (useFixedPanel) {
        window.addEventListener('resize', () => {
            if (!panel.hidden) {
                positionPanel();
            }
        });

        window.addEventListener('scroll', () => {
            if (!panel.hidden) {
                positionPanel();
            }
        }, true);
    }

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
    const summaryPeaks = form.querySelector('[data-request-summary-peaks]');
    const hiddenInputs = form.querySelector('[data-request-hidden-inputs]');
    const feedback = form.querySelector('[data-request-search-feedback]');
    const quantityInput = form.querySelector('[data-request-picker-quantity]');
    const quantityLabelNode = form.querySelector('[data-request-picker-label]');
    const addButton = form.querySelector('[data-request-add-selected]');
    const submitButton = form.querySelector('[data-request-submit]');
    const preview = form.querySelector('[data-request-selection-preview]');
    const initialItems = parseJsonNode('[data-request-selected-items]', form);
    const cache = new Map(initialItems.map((item) => [String(item.variant_key ?? item.id), item]));
    const lines = new Map(initialItems
        .filter((item) => Number.isFinite(Number(item.selected_quantity)) && Number(item.selected_quantity) > 0)
        .map((item) => [String(item.variant_key ?? item.id), {
            ...item,
            selected_quantity: Number(item.selected_quantity),
        }]));
    let selectedItemKey = '';

    const setFeedback = (message, type = 'default') => {
        feedback.textContent = message;
        feedback.classList.toggle('helper-text--error', type === 'error');
        feedback.classList.toggle('helper-text--success', type === 'success');
    };

    const syncHiddenInputs = () => {
        hiddenInputs.innerHTML = Array.from(lines.values()).map((item) => {
            const key = normalizeVariantKey(item.variant_key ?? item.id);

            return [
                `<input type="hidden" name="lines[${key}][item_id]" value="${escapeHtml(item.item_id)}">`,
                `<input type="hidden" name="lines[${key}][line_type]" value="${escapeHtml(item.line_type)}">`,
                `<input type="hidden" name="lines[${key}][stock_pallet_id]" value="${escapeHtml(item.stock_pallet_id ?? '')}">`,
                `<input type="hidden" name="lines[${key}][stock_peak_index]" value="${escapeHtml(item.stock_peak_index ?? '')}">`,
                `<input type="hidden" name="lines[${key}][quantity]" value="${escapeHtml(item.selected_quantity)}">`,
            ].join('');
        }).join('');
    };

    const updatePickerForItem = (item) => {
        quantityLabelNode.textContent = quantityFieldLabel(item?.line_type ?? 'pallet');

        if (!item) {
            quantityInput.value = '1';
            quantityInput.max = '';
            quantityInput.min = '1';
            preview.innerHTML = renderVariantPreview(null);
            return;
        }

        quantityInput.value = '1';
        quantityInput.min = '1';
        quantityInput.max = item.quantity_max ? String(item.quantity_max) : '';
        preview.innerHTML = renderVariantPreview(item, 1);
    };

    const selectedItems = () => Array.from(lines.values());

    const renderSummary = () => {
        const selected = selectedItems();
        const totalPallets = selected.reduce((total, line) => total + (line.line_type === 'pallet' ? line.selected_quantity : 0), 0);
        const totalPeaks = selected.reduce((total, line) => total + (line.line_type === 'peak' ? line.selected_quantity : 0), 0);

        summaryLines.textContent = formatNumber.format(selected.length);
        summaryPallets.textContent = formatNumber.format(totalPallets);
        summaryPeaks.textContent = formatNumber.format(totalPeaks);
        summaryEmpty.hidden = selected.length > 0;
        submitButton.disabled = selected.length === 0;
        syncHiddenInputs();

        summaryRows.innerHTML = selected.map((line, index) => {
            const key = escapeHtml(line.variant_key ?? line.id);
            const maxAttribute = line.quantity_max ? `max="${escapeHtml(line.quantity_max)}"` : '';
            const palletValue = line.line_type === 'pallet' ? line.selected_quantity : 0;
            const peakValue = line.line_type === 'peak' ? line.selected_quantity : 0;
            const location = line.location_text ? ` · Ubicación ${line.location_text}` : '';

            return `
            <article class="merchandise-request-line-row">
                <span class="merchandise-request-line-number">Línea ${index + 1}</span>
                <div class="merchandise-request-line-ref">
                    <strong>${escapeHtml(line.sku)}</strong>
                    <span>${escapeHtml(line.description)}</span>
                    <small>${escapeHtml(formatVariantUnits(line))} · ${escapeHtml(line.lot ? `Lote ${line.lot}` : 'Sin lote')}${escapeHtml(location)}</small>
                </div>
                <label class="auth-field merchandise-request-line-quantity">
                    <span>Pallets</span>
                    <input type="number" min="1" step="1" ${line.line_type === 'pallet' ? maxAttribute : 'disabled'} value="${escapeHtml(palletValue)}" class="auth-input merchandise-request-summary-input" ${line.line_type === 'pallet' ? `data-request-summary-quantity data-line-key="${key}"` : ''}>
                </label>
                <label class="auth-field merchandise-request-line-quantity">
                    <span>Picos</span>
                    <input type="number" min="1" step="1" ${line.line_type === 'peak' ? maxAttribute : 'disabled'} value="${escapeHtml(peakValue)}" class="auth-input merchandise-request-summary-input" ${line.line_type === 'peak' ? `data-request-summary-quantity data-line-key="${key}"` : ''}>
                </label>
                <button type="button" class="button-secondary compact-button btn-compact" data-request-summary-remove data-line-key="${key}">
                    Quitar
                </button>
            </article>
        `;
        }).join('');
    };

    const autocomplete = createAutocomplete(form.querySelector('[data-request-item-picker]'), {
        buildParams: () => ({
            client_id: form.dataset.clientId ?? '',
            active_only: '1',
            limit: '10',
        }),
        renderOption: (item) => renderVariantAutocompleteOption(item),
        hasSelection: () => selectedItemKey !== '',
        onSelect: (item) => {
            selectedItemKey = String(item.variant_key ?? item.id);
            cache.set(selectedItemKey, item);
            updatePickerForItem(item);
            setFeedback(`${item.sku} lista.`, 'success');
        },
        onInputChange: () => {
            selectedItemKey = '';
            updatePickerForItem(null);
            setFeedback('Busca una referencia.');
        },
        onClear: () => {
            selectedItemKey = '';
            updatePickerForItem(null);
            setFeedback('Busca una referencia.');
        },
    });

    addButton.addEventListener('click', () => {
        const quantity = parsePositiveInteger(quantityInput.value);
        const item = cache.get(selectedItemKey);

        if (!item || !Number.isFinite(quantity) || quantity <= 0) {
            setFeedback('Selecciona una referencia y una cantidad mayor que cero.', 'error');
            return;
        }

        if (item.quantity_max && quantity > Number(item.quantity_max)) {
            setFeedback(`La cantidad supera lo visible para esta línea (${formatNumber.format(item.quantity_max)}).`, 'error');
            return;
        }

        const currentQuantity = lines.get(selectedItemKey)?.selected_quantity ?? 0;
        const nextQuantity = item.line_type === 'peak' ? 1 : currentQuantity + quantity;

        if (item.quantity_max && nextQuantity > Number(item.quantity_max)) {
            setFeedback(`Esta partida solo muestra ${formatNumber.format(item.quantity_max)} pallets visibles. Ajusta la cantidad o añade otra partida.`, 'error');
            return;
        }

        lines.set(selectedItemKey, {
            ...item,
            selected_quantity: nextQuantity,
        });
        renderSummary();
        setFeedback(`${item.sku} añadida.`, 'success');
        quantityInput.value = '1';
        autocomplete?.clear();
    });

    summaryRows.addEventListener('input', (event) => {
        const input = event.target.closest('[data-request-summary-quantity]');

        if (!input) {
            return;
        }

        const lineKey = String(input.dataset.lineKey ?? '');
        const item = lines.get(lineKey);

        if (!item) {
            return;
        }

        const quantity = parsePositiveInteger(input.value);

        if (!Number.isFinite(quantity) || quantity <= 0) {
            lines.delete(lineKey);
        } else {
            const safeQuantity = item.quantity_max ? Math.min(quantity, Number(item.quantity_max)) : quantity;
            lines.set(lineKey, { ...item, selected_quantity: safeQuantity });
        }

        renderSummary();
    });

    summaryRows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-request-summary-remove]');

        if (!button) {
            return;
        }

        lines.delete(String(button.dataset.lineKey ?? ''));
        renderSummary();
        setFeedback('Línea eliminada del pedido.');
    });

    updatePickerForItem(null);
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
    const summaryPeaks = form.querySelector('[data-dispatch-summary-peaks]');
    const submitButton = form.querySelector('[data-dispatch-submit]');
    const quantityLabelNode = form.querySelector('[data-dispatch-picker-label]');
    const preview = form.querySelector('[data-dispatch-selection-preview]');
    const initialItems = parseJsonNode('[data-dispatch-items]', form);
    const cache = new Map(initialItems.map((item) => [String(item.variant_key ?? item.id), item]));
    const lines = new Map(initialItems
        .filter((item) => Number.isFinite(Number(item.selected_quantity)) && Number(item.selected_quantity) > 0)
        .map((item) => [String(item.variant_key ?? item.id), {
            ...item,
            selected_quantity: Number(item.selected_quantity),
        }]));
    let selectedItemKey = '';

    const setFeedback = (message, type = 'default') => {
        feedback.textContent = message;
        feedback.classList.toggle('helper-text--error', type === 'error');
        feedback.classList.toggle('helper-text--success', type === 'success');
    };

    const syncHiddenInputs = () => {
        hiddenInputs.innerHTML = Array.from(lines.values()).map((item) => {
            const key = normalizeVariantKey(item.variant_key ?? item.id);

            return [
                `<input type="hidden" name="lines[${key}][item_id]" value="${escapeHtml(item.item_id)}">`,
                `<input type="hidden" name="lines[${key}][line_type]" value="${escapeHtml(item.line_type)}">`,
                `<input type="hidden" name="lines[${key}][stock_pallet_id]" value="${escapeHtml(item.stock_pallet_id ?? '')}">`,
                `<input type="hidden" name="lines[${key}][stock_peak_index]" value="${escapeHtml(item.stock_peak_index ?? '')}">`,
                `<input type="hidden" name="lines[${key}][quantity]" value="${escapeHtml(item.selected_quantity)}">`,
            ].join('');
        }).join('');
    };

    const updatePickerForItem = (item) => {
        quantityLabelNode.textContent = quantityFieldLabel(item?.line_type ?? 'pallet');

        if (!item) {
            quantityInput.value = '1';
            quantityInput.max = '';
            quantityInput.min = '1';
            preview.innerHTML = `
                <strong>Selecciona el cliente y después una referencia</strong>
                <p>Cuando elijas una coincidencia verás aquí si estás añadiendo un pallet completo o un pico concreto.</p>
            `;
            return;
        }

        quantityInput.value = '1';
        quantityInput.min = '1';
        quantityInput.max = item.quantity_max ? String(item.quantity_max) : '';
        preview.innerHTML = renderVariantPreview(item, 1);
    };

    const selectedItems = () => Array.from(lines.values());

    const renderSummary = () => {
        const selected = selectedItems();
        const totalPallets = selected.reduce((total, line) => total + (line.line_type === 'pallet' ? line.selected_quantity : 0), 0);
        const totalPeaks = selected.reduce((total, line) => total + (line.line_type === 'peak' ? line.selected_quantity : 0), 0);

        summaryLines.textContent = formatNumber.format(selected.length);
        summaryPallets.textContent = formatNumber.format(totalPallets);
        summaryPeaks.textContent = formatNumber.format(totalPeaks);
        summaryEmpty.hidden = selected.length > 0;
        submitButton.disabled = selected.length === 0;
        syncHiddenInputs();

        summaryRows.innerHTML = selected.map((line) => `
            <article class="wms-line-editor-card">
                <div class="wms-line-card-head">
                    <div class="stock-cell-main">
                        <strong>${escapeHtml(line.sku)}</strong>
                        <span class="users-table-email">${escapeHtml(line.description)}</span>
                    </div>
                    <span class="wms-line-type-pill wms-line-type-pill--${escapeHtml(line.line_type)}">${escapeHtml(lineTypeLabel(line.line_type))}</span>
                </div>
                <div class="wms-line-card-meta">
                    <span>${escapeHtml(formatVariantUnits(line))}</span>
                    <span>${escapeHtml(line.lot ? `Lote ${line.lot}` : 'Sin lote')}</span>
                    ${line.location_text ? `<span>Ubicación ${escapeHtml(line.location_text)}</span>` : ''}
                </div>
                <div class="wms-line-editor-actions">
                    <label class="auth-field">
                        <span>${escapeHtml(quantityFieldLabel(line.line_type))}</span>
                        <input type="number" min="1" step="1" ${line.quantity_max ? `max="${escapeHtml(line.quantity_max)}"` : ''} value="${escapeHtml(line.selected_quantity)}" class="auth-input merchandise-request-summary-input" data-dispatch-summary-quantity data-line-key="${escapeHtml(line.variant_key ?? line.id)}">
                    </label>
                    <button type="button" class="button-secondary compact-button btn-compact" data-dispatch-summary-remove data-line-key="${escapeHtml(line.variant_key ?? line.id)}">
                        Eliminar
                    </button>
                </div>
            </article>
        `).join('');
    };

    const autocomplete = createAutocomplete(form.querySelector('[data-dispatch-item-picker]'), {
        buildParams: () => ({
            client_id: clientSelect?.value ?? '',
            active_only: '1',
            limit: '10',
        }),
        renderOption: (item) => renderVariantAutocompleteOption(item),
        hasSelection: () => selectedItemKey !== '',
        onSelect: (item) => {
            selectedItemKey = String(item.variant_key ?? item.id);
            cache.set(selectedItemKey, item);
            updatePickerForItem(item);
            setFeedback(`${item.sku} lista para añadir a la salida como ${lineTypeLabel(item.line_type).toLowerCase()}.`, 'success');
        },
        onInputChange: () => {
            selectedItemKey = '';
            updatePickerForItem(null);
        },
        onClear: () => {
            selectedItemKey = '';
            updatePickerForItem(null);
        },
    });

    clientSelect?.addEventListener('change', () => {
        selectedItemKey = '';
        lines.clear();
        autocomplete?.clear();
        renderSummary();
        updatePickerForItem(null);
        setFeedback('Selecciona o cambia el cliente y busca después la referencia correcta.');
    });

    addButton.addEventListener('click', () => {
        if (!clientSelect?.value) {
            setFeedback('Selecciona un cliente antes de buscar referencias.', 'error');
            return;
        }

        const quantity = parsePositiveInteger(quantityInput.value);
        const item = cache.get(selectedItemKey);

        if (!item || !Number.isFinite(quantity) || quantity <= 0) {
            setFeedback('Selecciona una referencia y una cantidad entera mayor que cero.', 'error');
            return;
        }

        if (item.quantity_max && quantity > Number(item.quantity_max)) {
            setFeedback(`La cantidad supera lo visible para esta línea (${formatNumber.format(item.quantity_max)}).`, 'error');
            return;
        }

        const currentQuantity = lines.get(selectedItemKey)?.selected_quantity ?? 0;
        const nextQuantity = item.line_type === 'peak' ? 1 : currentQuantity + quantity;

        if (item.quantity_max && nextQuantity > Number(item.quantity_max)) {
            setFeedback(`Esta partida solo muestra ${formatNumber.format(item.quantity_max)} pallets visibles. Ajusta la cantidad o añade otra partida.`, 'error');
            return;
        }

        lines.set(selectedItemKey, {
            ...item,
            selected_quantity: nextQuantity,
        });
        renderSummary();
        setFeedback(`${item.sku} se ha añadido a la salida como ${formatRequestedQuantity(item, nextQuantity)}.`, 'success');
        quantityInput.value = '1';
        autocomplete?.clear();
    });

    summaryRows.addEventListener('input', (event) => {
        const input = event.target.closest('[data-dispatch-summary-quantity]');

        if (!input) {
            return;
        }

        const lineKey = String(input.dataset.lineKey ?? '');
        const item = lines.get(lineKey);

        if (!item) {
            return;
        }

        const quantity = parsePositiveInteger(input.value);

        if (!Number.isFinite(quantity) || quantity <= 0) {
            lines.delete(lineKey);
        } else {
            const safeQuantity = item.quantity_max ? Math.min(quantity, Number(item.quantity_max)) : quantity;
            lines.set(lineKey, { ...item, selected_quantity: safeQuantity });
        }

        renderSummary();
    });

    summaryRows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dispatch-summary-remove]');

        if (!button) {
            return;
        }

        lines.delete(String(button.dataset.lineKey ?? ''));
        renderSummary();
    });

    updatePickerForItem(null);
    renderSummary();
    form.dataset.dispatchBuilderBound = 'true';
};

const setupGoodsReceiptLines = () => {
    const form = document.querySelector('[data-goods-receipt-form]');
    const container = document.querySelector('[data-receipt-lines]');
    const addButtons = Array.from(document.querySelectorAll('[data-add-line]'));
    const template = document.querySelector('[data-line-template]');
    const clientSelect = document.querySelector('[data-receipt-client]');
    const documentInput = document.querySelector('[data-receipt-document-input]');
    const aiSubmitButton = document.querySelector('[data-ai-create-submit]');
    const aiSubmitHelp = document.querySelector('[data-ai-submit-help]');

    if (!form || !container || addButtons.length === 0 || !template || !clientSelect || container.dataset.linesBound === 'true') {
        return;
    }

    const rows = () => Array.from(container.querySelectorAll('[data-line-row]'));
    const rowCount = () => rows().length;

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

    const updateNewItemWarning = (row) => {
        const warning = row.querySelector('[data-line-new-item-warning]');
        const itemIdField = row.querySelector('[data-line-item-id]');
        const skuField = row.querySelector('[data-line-sku]');
        const descriptionField = row.querySelector('[data-line-description]');
        const unitsField = row.querySelector('[data-line-units]');

        if (!warning || !itemIdField || !skuField || !descriptionField || !unitsField) {
            return;
        }

        const itemId = itemIdField.value.trim();
        const sku = skuField.value.trim();
        const description = descriptionField.value.trim();
        const unitsPerPallet = Number.parseInt(unitsField.value, 10);

        if (itemId !== '' || sku === '') {
            warning.hidden = true;
            warning.textContent = '';
            warning.classList.remove('is-pending');
            return;
        }

        if (description === '' || !Number.isFinite(unitsPerPallet) || unitsPerPallet <= 0) {
            warning.hidden = false;
            warning.textContent = `El SKU ${sku} no existe todavia. Completa descripcion y uds/palet para crearlo al guardar la entrada.`;
            warning.classList.add('is-pending');
            return;
        }

        warning.hidden = false;
        warning.textContent = `El articulo con SKU ${sku} no existe. Al guardar la entrada se creara automaticamente con la descripcion "${description}" y ${unitsPerPallet} uds/palet.`;
        warning.classList.remove('is-pending');
    };

    const renumberRows = () => {
        rows().forEach((row, index) => {
            const lineNumber = row.querySelector('[data-line-number]');

            if (lineNumber) {
                lineNumber.textContent = `Linea ${index + 1}`;
            }
        });
    };

    const updateAiSubmitState = () => {
        if (!aiSubmitButton) {
            return;
        }

        const hasDocument = documentInput?.files?.length > 0;

        aiSubmitButton.disabled = !hasDocument;

        if (aiSubmitHelp) {
            aiSubmitHelp.hidden = hasDocument;
        }
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
        const pickerRoot = row.querySelector('[data-receipt-item-picker]');
        const createWrapper = row.querySelector('[data-line-create-item]');
        const createTrigger = row.querySelector('[data-line-create-item-trigger]');
        const createFeedback = row.querySelector('[data-line-create-item-feedback]');
        const createEndpoint = pickerRoot?.dataset.createItemEndpoint;
        let lastSearchQuery = '';

        const setCreateFeedback = (message, type = 'default') => {
            if (!createFeedback) {
                return;
            }

            createFeedback.textContent = message;
            createFeedback.classList.toggle('helper-text--error', type === 'error');
            createFeedback.classList.toggle('helper-text--success', type === 'success');
        };

        const hideCreateItem = () => {
            if (createWrapper) {
                createWrapper.hidden = true;
            }

            setCreateFeedback('');
        };

        const showCreateItem = (query) => {
            if (!createWrapper || (itemIdField?.value ?? '') !== '') {
                return;
            }

            lastSearchQuery = query;
            createWrapper.hidden = false;
            setCreateFeedback('');
        };

        const autocomplete = createAutocomplete(pickerRoot, {
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
                updateNewItemWarning(row);
                hideCreateItem();
            },
            onInputChange: () => {
                if (itemIdField) {
                    itemIdField.value = '';
                }

                clearAutofilledFields(row);
                updateNewItemWarning(row);
                hideCreateItem();
            },
            onClear: () => {
                if (itemIdField) {
                    itemIdField.value = '';
                }

                clearAutofilledFields(row);
                updateNewItemWarning(row);
                hideCreateItem();
            },
            onNoResults: (query) => showCreateItem(query),
        });

        createTrigger?.addEventListener('click', async () => {
            if (!window.confirm('No existe un artículo con esta referencia. ¿Quieres crearlo para este cliente?')) {
                return;
            }

            if (skuField && skuField.value.trim() === '') {
                skuField.value = lastSearchQuery;
            }

            const clientId = clientSelect.value ?? '';
            const sku = skuField?.value.trim() ?? '';
            const description = descriptionField?.value.trim() ?? '';
            const unitsPerPallet = Number.parseInt(unitsField?.value ?? '', 10);

            if (!clientId) {
                setCreateFeedback('Selecciona primero el cliente de la entrada.', 'error');
                return;
            }

            if (sku === '' || description === '' || !Number.isFinite(unitsPerPallet) || unitsPerPallet <= 0) {
                setCreateFeedback('Completa SKU, descripcion y uds/pallet para crear el articulo.', 'error');
                descriptionField?.focus();
                return;
            }

            if (!createEndpoint) {
                return;
            }

            createTrigger.disabled = true;
            setCreateFeedback('Creando articulo...');

            try {
                const response = await fetch(createEndpoint, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({
                        client_id: clientId,
                        sku,
                        description,
                        units_per_pallet: unitsPerPallet,
                    }),
                });

                const payload = await response.json().catch(() => ({}));

                if (!response.ok || !payload.item) {
                    setCreateFeedback(payload.message ?? 'No se pudo crear el articulo.', 'error');
                    return;
                }

                const item = payload.item;

                // Reuses the same selection path as picking a search result, so the
                // newly created (or reused) article ends up populated and marked as
                // selected in the line exactly like any other autocomplete match.
                autocomplete?.setItem({
                    ...item,
                    label: item.label ?? `${item.sku} - ${item.description}`,
                });
            } catch (error) {
                setCreateFeedback('Error al crear el articulo. Intentalo de nuevo.', 'error');
            } finally {
                createTrigger.disabled = false;
            }
        });

        [quantityField, unitsField].forEach((field) => {
            field?.addEventListener('input', () => {
                if (field === unitsField) {
                    markAutofilled(unitsField, false);
                }

                recalculateRow(row);
                updateNewItemWarning(row);
            });
        });

        [skuField, descriptionField].forEach((field) => {
            field?.addEventListener('input', () => {
                markAutofilled(field, false);
                updateNewItemWarning(row);
            });
        });

        row.dataset.rowBound = 'true';
        recalculateRow(row);
        updateNewItemWarning(row);
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

    const addRow = () => {
        const markup = template.innerHTML.replaceAll('__INDEX__', String(rowCount()));
        container.insertAdjacentHTML('beforeend', markup);
        bindRow(container.querySelectorAll('[data-line-row]')[rowCount() - 1]);
        renumberRows();
    };

    addButtons.forEach((button) => {
        button.addEventListener('click', addRow);
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
            updateNewItemWarning(row);
            return;
        }

        row.remove();
        renumberRows();
    });

    container.querySelectorAll('[data-line-row]').forEach(bindRow);
    renumberRows();

    clientSelect.addEventListener('change', () => {
        container.querySelectorAll('[data-line-row]').forEach((row) => {
            const itemIdField = row.querySelector('[data-line-item-id]');

            if (itemIdField?.value) {
                itemIdField.value = '';
            }

            updateNewItemWarning(row);
        });
    });

    documentInput?.addEventListener('change', updateAiSubmitState);

    container.dataset.linesBound = 'true';
    updateAiSubmitState();
};

const setupSupplierPicker = () => {
    const pickerRoot = document.querySelector('[data-receipt-supplier-picker]');
    const clientField = document.querySelector('[data-receipt-client]');

    if (!pickerRoot || !clientField || pickerRoot.dataset.supplierPickerBound === 'true') {
        return;
    }

    const wrapper = pickerRoot.closest('label');
    const supplierIdField = pickerRoot.querySelector('[data-supplier-id]');
    const createWrapper = wrapper?.querySelector('[data-supplier-create-item]');
    const createTrigger = createWrapper?.querySelector('[data-supplier-create-trigger]');
    const createFeedback = createWrapper?.querySelector('[data-supplier-create-feedback]');
    const createEndpoint = pickerRoot.dataset.createSupplierEndpoint;

    if (!supplierIdField || !createEndpoint) {
        return;
    }

    let lastSearchQuery = '';

    const setCreateFeedback = (message, type = 'default') => {
        if (!createFeedback) {
            return;
        }

        createFeedback.textContent = message;
        createFeedback.classList.toggle('helper-text--error', type === 'error');
        createFeedback.classList.toggle('helper-text--success', type === 'success');
    };

    const hideCreateSupplier = () => {
        if (createWrapper) {
            createWrapper.hidden = true;
        }

        setCreateFeedback('');
    };

    const showCreateSupplier = (query) => {
        if (!createWrapper || (supplierIdField.value ?? '') !== '') {
            return;
        }

        lastSearchQuery = query;
        createWrapper.hidden = false;
        setCreateFeedback('');
    };

    const autocomplete = createAutocomplete(pickerRoot, {
        buildParams: () => ({
            client_id: clientField.value ?? '',
            limit: '10',
        }),
        hasSelection: () => (supplierIdField.value ?? '') !== '',
        onSelect: (supplier) => {
            supplierIdField.value = String(supplier.id);
            hideCreateSupplier();
        },
        onInputChange: () => {
            supplierIdField.value = '';
            hideCreateSupplier();
        },
        onClear: () => {
            supplierIdField.value = '';
            hideCreateSupplier();
        },
        onNoResults: (query) => showCreateSupplier(query),
    });

    createTrigger?.addEventListener('click', async () => {
        if (!window.confirm('No existe un proveedor con este nombre. ¿Quieres crearlo?')) {
            return;
        }

        const clientId = clientField.value ?? '';
        const name = lastSearchQuery.trim();

        if (!clientId) {
            setCreateFeedback('Selecciona primero el cliente de la entrada.', 'error');
            return;
        }

        if (name === '') {
            setCreateFeedback('Escribe el nombre del proveedor antes de crearlo.', 'error');
            return;
        }

        createTrigger.disabled = true;
        setCreateFeedback('Creando proveedor...');

        try {
            const response = await fetch(createEndpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    client_id: clientId,
                    name,
                }),
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok || !payload.supplier) {
                setCreateFeedback(payload.message ?? 'No se pudo crear el proveedor.', 'error');
                return;
            }

            // Reuses the same selection path as picking a search result.
            autocomplete?.setItem({
                ...payload.supplier,
                label: payload.supplier.label ?? payload.supplier.name,
            });
        } catch (error) {
            setCreateFeedback('Error al crear el proveedor. Intentalo de nuevo.', 'error');
        } finally {
            createTrigger.disabled = false;
        }
    });

    if (clientField.tagName === 'SELECT') {
        clientField.addEventListener('change', () => {
            supplierIdField.value = '';
            autocomplete?.clear();
            hideCreateSupplier();
        });
    }

    pickerRoot.dataset.supplierPickerBound = 'true';
};

const bindDispatchExtraAutocomplete = (row, clientId, endpoint) => {
    const itemIdField = row.querySelector('[data-dispatch-extra-item-id]');
    const lineTypeField = row.querySelector('[data-dispatch-extra-line-type]');
    const stockPalletField = row.querySelector('[data-dispatch-extra-stock-pallet-id]');
    const peakIndexField = row.querySelector('[data-dispatch-extra-peak-index]');
    const quantityInput = row.querySelector('input[name*="[loaded_quantity]"]');
    const quantityLabelNode = row.querySelector('[data-dispatch-extra-quantity-label]');
    const typeLabelNode = row.querySelector('[data-dispatch-extra-type-label]');
    const preview = row.querySelector('[data-dispatch-extra-preview]');

    const resetSelection = () => {
        if (itemIdField) {
            itemIdField.value = '';
        }

        if (lineTypeField) {
            lineTypeField.value = 'pallet';
        }

        if (stockPalletField) {
            stockPalletField.value = '';
        }

        if (peakIndexField) {
            peakIndexField.value = '';
        }

        if (quantityLabelNode) {
            quantityLabelNode.textContent = quantityFieldLabel('pallet', true);
        }

        if (typeLabelNode) {
            typeLabelNode.textContent = lineTypeLabel('pallet');
            typeLabelNode.classList.remove('wms-line-type-pill--peak');
            typeLabelNode.classList.add('wms-line-type-pill--pallet');
        }

        if (quantityInput) {
            quantityInput.max = '';
        }

        if (preview) {
            preview.innerHTML = `
                <strong>Selecciona una referencia</strong>
                <p>Verás si estás añadiendo un pallet completo o un pico concreto.</p>
            `;
        }
    };

    const applySelection = (item) => {
        if (!item) {
            resetSelection();
            return;
        }

        if (itemIdField) {
            itemIdField.value = String(item.item_id);
        }

        if (lineTypeField) {
            lineTypeField.value = item.line_type ?? 'pallet';
        }

        if (stockPalletField) {
            stockPalletField.value = item.stock_pallet_id ? String(item.stock_pallet_id) : '';
        }

        if (peakIndexField) {
            peakIndexField.value = item.stock_peak_index ? String(item.stock_peak_index) : '';
        }

        if (quantityLabelNode) {
            quantityLabelNode.textContent = quantityFieldLabel(item.line_type ?? 'pallet', true);
        }

        if (typeLabelNode) {
            typeLabelNode.textContent = lineTypeLabel(item.line_type ?? 'pallet');
            typeLabelNode.classList.toggle('wms-line-type-pill--peak', item.line_type === 'peak');
            typeLabelNode.classList.toggle('wms-line-type-pill--pallet', item.line_type !== 'peak');
        }

        if (quantityInput) {
            quantityInput.max = item.quantity_max ? String(item.quantity_max) : '';
            if (item.line_type === 'peak') {
                quantityInput.value = '1';
            }
        }

        if (preview) {
            preview.innerHTML = renderVariantPreview(item, item.line_type === 'peak' ? 1 : null);
        }
    };

    createAutocomplete(row.querySelector('[data-dispatch-extra-picker]'), {
        buildParams: () => ({
            client_id: clientId,
            active_only: '1',
            limit: '10',
        }),
        renderOption: (item) => renderVariantAutocompleteOption(item),
        hasSelection: () => (itemIdField?.value ?? '') !== '',
        onSelect: (item) => {
            applySelection(item);
        },
        onInputChange: () => {
            resetSelection();
        },
        onClear: () => {
            resetSelection();
        },
    });

    if (itemIdField?.value) {
        if (quantityLabelNode) {
            quantityLabelNode.textContent = quantityFieldLabel(lineTypeField?.value ?? 'pallet', true);
        }

        if (typeLabelNode) {
            typeLabelNode.textContent = lineTypeLabel(lineTypeField?.value ?? 'pallet');
            typeLabelNode.classList.toggle('wms-line-type-pill--peak', (lineTypeField?.value ?? 'pallet') === 'peak');
            typeLabelNode.classList.toggle('wms-line-type-pill--pallet', (lineTypeField?.value ?? 'pallet') !== 'peak');
        }

        if (quantityInput) {
            quantityInput.max = (lineTypeField?.value ?? 'pallet') === 'peak' ? '1' : '';
        }

        if (preview) {
            preview.innerHTML = `
                <strong>Referencia ya seleccionada</strong>
                <p>Si necesitas cambiarla, vuelve a buscar y elige otra variante.</p>
            `;
        }
    } else {
        resetSelection();
    }
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
    setupSupplierPicker();
    setupMerchandiseRequestBuilder();
    setupGoodsDispatchBuilder();
    setupDispatchLoadingEditor();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
