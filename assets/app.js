import './styles/app.css';

// Info-bulle des graphiques : suit la colonne survolée ou focalisée au clavier.
document.querySelectorAll('[data-chart]').forEach((chart) => {
    const tooltip = chart.querySelector('.chart-tooltip');
    const show = (column) => {
        const box = column.querySelector('.chart-bar, .chart-hit').getBoundingClientRect();
        const origin = chart.getBoundingClientRect();
        tooltip.textContent = column.dataset.tooltip;
        tooltip.style.left = `${box.left - origin.left + box.width / 2}px`;
        tooltip.style.top = `${box.top - origin.top}px`;
        tooltip.hidden = false;
    };
    chart.querySelectorAll('[data-tooltip]').forEach((column) => {
        column.addEventListener('mouseenter', () => show(column));
        column.addEventListener('focus', () => show(column));
        column.addEventListener('mouseleave', () => { tooltip.hidden = true; });
        column.addEventListener('blur', () => { tooltip.hidden = true; });
    });
});

// Les traitements longs (import, analyse IA) désactivent le bouton et l'annoncent.
document.querySelectorAll('form[data-loading-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const button = event.submitter;
        if (!button) {
            return;
        }
        // Le bouton désactivé n'est plus envoyé : on recopie sa valeur dans un champ caché.
        if (button.name) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = button.name;
            hidden.value = button.value;
            form.append(hidden);
        }
        if (button.dataset.loadingLabel) {
            button.textContent = button.dataset.loadingLabel;
        }
        form.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    });
});
