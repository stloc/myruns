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

// Import long (archive complète) : enchaîne les étapes côté serveur et affiche l'avancement.
document.querySelectorAll('[data-import-job]').forEach((job) => {
    const form = job.querySelector('form[data-import-continue]');
    const button = form.querySelector('button');
    const fill = job.querySelector('[data-import-fill]');
    const label = job.querySelector('[data-import-label]');
    const status = job.querySelector('[data-import-status]');
    let stopped = false;

    const step = async () => {
        button.disabled = true;
        button.textContent = 'Import en cours…';
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const state = await response.json();
            if (state.redirect) {
                window.location.assign(state.redirect);
                return;
            }
            fill.style.setProperty('--meter-value', `${state.percent}%`);
            fill.parentElement.setAttribute('aria-valuenow', state.done);
            label.textContent = `${state.done} / ${state.total} traces (${state.percent} %)`;
            if (!stopped) {
                step();
            }
        } catch {
            button.disabled = false;
            button.textContent = 'Reprendre';
            status.textContent = 'L\'import s\'est interrompu (connexion ou serveur). Les traces déjà lues sont enregistrées : « Reprendre » continue là où il s\'est arrêté.';
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        step();
    });
    job.querySelector('form[data-import-abandon]').addEventListener('submit', () => { stopped = true; });
    step();
});

// Recherche d'activités : changer de critère de tri remet son sens habituel (allure : la plus rapide d'abord).
document.querySelectorAll('select[name="tri"]').forEach((sort) => {
    const direction = sort.form.querySelector('select[name="ordre"]');
    sort.addEventListener('change', () => {
        direction.value = sort.selectedOptions[0].dataset.direction;
    });
});

// Carte des départs (page Activités) : même chargement à la demande.
document.querySelectorAll('[data-activities-map]').forEach((element) => {
    import('./activities_map.js').then(({ drawActivitiesMap }) => drawActivitiesMap(element));
});

// Carte de la trace (page détail d'une activité) : Leaflet n'est chargé que sur cette page.
document.querySelectorAll('[data-route-map]').forEach((element) => {
    import('./route_map.js').then(({ drawRouteMap }) => drawRouteMap(element));
});
