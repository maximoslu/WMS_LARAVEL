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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupAppDrawer, { once: true });
} else {
    setupAppDrawer();
}
