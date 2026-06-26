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
    const container = document.querySelector('[data-receipt-lines]');
    const addButton = document.querySelector('[data-add-line]');
    const template = document.querySelector('[data-line-template]');

    if (!container || !addButton || !template || container.dataset.linesBound === 'true') {
        return;
    }

    const rowCount = () => container.querySelectorAll('[data-line-row]').length;

    const resetRow = (row) => {
        row.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field instanceof HTMLInputElement && field.type === 'number') {
                field.value = field.name.includes('[quantity_units]') || field.name.includes('[pallet_count]') ? '0' : '';
                return;
            }

            if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
                field.value = '';
                return;
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

    container.dataset.linesBound = 'true';
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        setupAppDrawer();
        setupGoodsReceiptLines();
    }, { once: true });
} else {
    setupAppDrawer();
    setupGoodsReceiptLines();
}
