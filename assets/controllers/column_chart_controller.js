import { Controller } from '@hotwired/stimulus';

// Info-bulle des histogrammes SVG : suit la colonne survolée ou focalisée au clavier.
export default class extends Controller {
    static targets = ['tooltip'];

    show({ currentTarget: column }) {
        const box = column.querySelector('.chart-bar, .chart-hit').getBoundingClientRect();
        const origin = this.element.getBoundingClientRect();
        this.tooltipTarget.textContent = column.dataset.tooltip;
        this.tooltipTarget.style.left = `${box.left - origin.left + box.width / 2}px`;
        this.tooltipTarget.style.top = `${box.top - origin.top}px`;
        this.tooltipTarget.hidden = false;
    }

    hide() {
        this.tooltipTarget.hidden = true;
    }
}
