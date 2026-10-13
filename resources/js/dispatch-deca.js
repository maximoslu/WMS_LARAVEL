document.querySelectorAll('[data-deca-transport]').forEach((panel) => {
    const update = () => {
        panel.querySelector('[data-deca-external]').hidden = panel.querySelector('[data-deca-carrier]').value !== 'external';
        const vehicle = panel.closest('form').querySelector('[data-dispatch-vehicle-type]');
        panel.querySelector('[data-deca-trailer]').hidden = vehicle ? vehicle.value !== 'trailer' : !panel.querySelector('[data-deca-articulated]').checked;
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
        field.hidden = client.selectedOptions[0]?.dataset.requiresVehicleType !== '1';
        field.querySelector('select').disabled = field.hidden;
    };
    client.addEventListener('change', update);
    update();
});
