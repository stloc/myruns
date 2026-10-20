import { Chart, Filler, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js';

Chart.register(Filler, LinearScale, LineController, LineElement, PointElement, Tooltip);

// Profil de la sortie (page détail) : un panneau par série, empilés sur un axe des distances commun,
// chacun avec sa propre échelle graduée (jamais deux échelles sur une même courbe). Couleurs dans
// app.css (variables --profile-*), relues au changement de thème.
const numberFormat = (digits) => new Intl.NumberFormat('fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits });

const formatPace = (seconds) => {
    const rounded = Math.round(seconds);
    return `${Math.floor(rounded / 60)}:${String(rounded % 60).padStart(2, '0')}`;
};

const formatValue = (series, value) => {
    switch (series.format) {
        case 'pace':
            return `${formatPace(value)}${series.unit}`;
        case 'decimal':
            return `${numberFormat(1).format(value)} ${series.unit}`;
        default:
            return `${numberFormat(0).format(value)} ${series.unit}`;
    }
};

// Trait vertical qui suit la distance survolée, sur toute la hauteur des panneaux.
const crosshair = {
    id: 'crosshair',
    afterDatasetsDraw(chart) {
        const active = chart.tooltip?.getActiveElements() ?? [];
        if (0 === active.length) {
            return;
        }
        const { ctx, chartArea } = chart;
        ctx.save();
        ctx.strokeStyle = chart.options.plugins.crosshair.color;
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(active[0].element.x, chartArea.top);
        ctx.lineTo(active[0].element.x, chartArea.bottom);
        ctx.stroke();
        ctx.restore();
    },
};

export function drawActivityProfile(element) {
    const profile = JSON.parse(element.dataset.activityProfile);
    const canvas = element.querySelector('canvas');
    const color = (name) => getComputedStyle(element).getPropertyValue(name).trim();
    const distances = profile.distances;
    // Panneaux du haut vers le bas ; Chart.js empile les échelles d'une même pile de bas en haut.
    const panels = [...profile.series].reverse();

    const scales = {
        x: {
            type: 'linear',
            min: 0,
            max: distances[distances.length - 1],
            ticks: { callback: (value) => `${numberFormat(1).format(value)} km`, maxTicksLimit: 10 },
        },
    };
    panels.forEach((series) => {
        scales[series.key] = {
            type: 'linear',
            stack: 'profile',
            stackWeight: 'altitude' === series.key ? 0.8 : 1,
            offset: true,
            // Allure : le plus rapide en haut.
            reverse: 'pace' === series.format,
            title: { display: true, text: series.label },
            ticks: {
                maxTicksLimit: 4,
                // Allure graduée à la minute ronde.
                stepSize: 'pace' === series.format ? 60 : undefined,
                callback: (value) => ('pace' === series.format ? formatPace(value) : numberFormat(0).format(value)),
            },
        };
    });

    const chart = new Chart(canvas, {
        type: 'line',
        data: {
            datasets: panels.map((series) => ({
                label: series.label,
                data: series.values.map((y, i) => ({ x: distances[i], y })),
                yAxisID: series.key,
                fill: 'altitude' === series.key ? 'start' : false,
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 4,
                spanGaps: false,
                series,
            })),
        },
        options: {
            animation: false,
            maintainAspectRatio: false,
            parsing: false,
            normalized: true,
            interaction: { mode: 'index', axis: 'x', intersect: false },
            scales,
            plugins: {
                crosshair: {},
                tooltip: {
                    // Du haut vers le bas, dans l'ordre des panneaux.
                    itemSort: (a, b) => b.datasetIndex - a.datasetIndex,
                    filter: (item) => null !== item.parsed.y,
                    callbacks: {
                        title: (items) => `${numberFormat(2).format(items[0].parsed.x)} km`,
                        label: (item) => `${item.dataset.series.label} : ${formatValue(item.dataset.series, item.parsed.y)}`,
                    },
                },
            },
        },
        plugins: [crosshair],
    });

    const applyColors = () => {
        const text = color('--chart-label');
        const grid = color('--chart-grid');
        chart.data.datasets.forEach((dataset) => {
            const key = dataset.series.key.replace('_', '-');
            dataset.borderColor = color(`--profile-${key}`);
            dataset.backgroundColor = 'altitude' === dataset.series.key ? color('--profile-altitude-fill') : dataset.borderColor;
            dataset.pointHoverBorderColor = color('--surface');
            dataset.pointHoverBackgroundColor = dataset.borderColor;
        });
        Object.values(chart.options.scales).forEach((scale) => {
            scale.grid = { color: grid };
            scale.border = { color: color('--chart-baseline') };
            scale.ticks.color = text;
            if (scale.title) {
                scale.title.color = text;
            }
        });
        chart.options.plugins.crosshair.color = color('--chart-baseline');
        const tooltip = chart.options.plugins.tooltip;
        tooltip.backgroundColor = color('--surface');
        tooltip.titleColor = color('--text');
        tooltip.bodyColor = color('--text-secondary');
        tooltip.borderColor = color('--border');
        tooltip.borderWidth = 1;
        chart.update('none');
    };
    applyColors();
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyColors);

    // Boutons sous le graphique : masquer une série libère son panneau pour les autres.
    element.querySelectorAll('[data-profile-toggle]').forEach((button) => {
        button.disabled = false;
        button.addEventListener('click', () => {
            const index = chart.data.datasets.findIndex((dataset) => dataset.series.key === button.dataset.profileToggle);
            const visible = !chart.isDatasetVisible(index);
            if (!visible && 1 === chart.data.datasets.filter((_, i) => chart.isDatasetVisible(i)).length) {
                return;
            }
            chart.setDatasetVisibility(index, visible);
            const scale = chart.options.scales[button.dataset.profileToggle];
            scale.display = visible;
            // Un panneau masqué ne garde pas sa place : la hauteur suit le nombre de panneaux.
            scale.stack = visible ? 'profile' : undefined;
            button.setAttribute('aria-pressed', String(visible));
            const box = canvas.parentElement;
            box.style.setProperty('--panels', String(chart.data.datasets.filter((_, i) => chart.isDatasetVisible(i)).length));
            chart.update();
        });
    });
}
