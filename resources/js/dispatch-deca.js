document.querySelectorAll('[data-deca-transport]').forEach((panel) => {
    const update = () => {
        panel.querySelector('[data-deca-external]').hidden = panel.querySelector('[data-deca-carrier]').value !== 'external';
        panel.querySelector('[data-deca-trailer]').hidden = !panel.querySelector('[data-deca-articulated]').checked;
        panel.querySelector('[data-deca-authorization]').hidden = !panel.querySelector('[data-deca-special]').checked;
    };
    panel.addEventListener('change', update);
    update();
});
