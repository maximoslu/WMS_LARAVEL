document.querySelectorAll('[data-deca-form]').forEach((form) => {
    const carrier = form.querySelector('[data-deca-carrier]');
    const updateCarrier = () => form.querySelectorAll('[data-deca-carrier-details]').forEach((panel) => {
        panel.hidden = panel.dataset.decaCarrierDetails !== carrier.value;
    });
    carrier.addEventListener('change', updateCarrier);
    updateCarrier();
    form.querySelectorAll('[data-deca-toggle]').forEach((toggle) => {
        const panel = form.querySelector(`[data-deca-conditional="${toggle.dataset.decaToggle}"]`);
        const update = () => { panel.hidden = !toggle.checked; panel.querySelector('input').required = toggle.checked; };
        toggle.addEventListener('change', update);
        update();
    });
    form.addEventListener('submit', () => {
        const button = form.querySelector('[type="submit"]');
        button.disabled = true; button.textContent = 'Generando documento…';
    });
    window.addEventListener('pageshow', () => {
        const button = form.querySelector('[type="submit"]');
        button.disabled = false; button.textContent = 'Generar PDF con QR';
    });
});
document.querySelectorAll('[data-deca-share]').forEach((button) => {
    if (!navigator.share && !navigator.clipboard) return;
    button.hidden = false;
    button.addEventListener('click', async () => {
        const status = document.querySelector('[data-deca-share-status]');
        try {
            const url = button.dataset.decaShare;
            if (navigator.share) await navigator.share({ title: 'Documento DECA', url });
            else { await navigator.clipboard.writeText(url); status.textContent = 'Enlace al PDF copiado.'; }
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = 'No se pudo compartir. Puedes descargar el PDF y enviarlo.';
        }
    });
});
