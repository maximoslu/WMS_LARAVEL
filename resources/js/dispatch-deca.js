document.querySelectorAll('[data-transport-mode-fields]').forEach((panel) => {
    const form = panel.closest('form');
    const mode = panel.querySelector('[data-transport-mode]');
    const vehicle = form.querySelector('[name="vehicle_type"]');
    const update = () => {
        const monge = mode.value === 'monge';
        if (monge && vehicle && !vehicle.disabled) vehicle.value = 'rigid';
        const articulated = form.querySelector('[data-deca-articulated]');
        if (monge && articulated) articulated.checked = false;
        panel.querySelector('[data-transport-external]').hidden = monge;
        panel.querySelector('[data-transport-monge]').hidden = !monge;
        panel.querySelector('[data-transport-trailer]').hidden = !(vehicle && !vehicle.disabled ? vehicle.value === 'trailer' : articulated?.checked);
        panel.querySelector('[data-transport-plate]').setAttribute('list', monge ? 'monge-vehicles' : '');
    };
    form.addEventListener('change', update);
    update();
});

document.querySelectorAll('[data-deca-transport]').forEach((panel) => {
    const update = () => {
        const carrier = panel.querySelector('[data-deca-carrier]');
        if (carrier) panel.querySelector('[data-deca-external]').hidden = carrier.value !== 'external';
        const trailer = panel.querySelector('[data-deca-trailer]');
        if (trailer) trailer.hidden = !panel.querySelector('[data-deca-articulated]').checked;
        panel.querySelector('[data-deca-authorization]').hidden = !panel.querySelector('[data-deca-special]').checked;
    };
    panel.closest('form').addEventListener('change', update);
    update();
});

document.querySelectorAll('[data-goods-receipt-form]').forEach((form) => {
    const client = form.querySelector('[data-receipt-client]');
    const field = form.querySelector('[data-receipt-vehicle-type]');
    if (!client || !field) return;
    const update = () => {
        const ti = client.selectedOptions[0]?.dataset.requiresVehicleType === '1';
        field.hidden = !ti;
        field.querySelector('select').disabled = !ti;

    };
    client.addEventListener('change', update);
    update();
});
