import { Controller } from '@hotwired/stimulus';

// Carte des départs (page Activités), repliée par défaut : dessinée à la 1re ouverture du <details>
// (Leaflet mesure mal un conteneur caché, et Leaflet comme les tuiles ne sont demandés qu'à ce moment).
export default class extends Controller {
    static values = { data: Object };

    connect() {
        this.details = this.element.closest('details');
        this.open = () => this.draw();
        if (null === this.details || this.details.open) {
            this.draw();
        } else {
            this.details.addEventListener('toggle', this.open, { once: true });
        }
    }

    disconnect() {
        this.details?.removeEventListener('toggle', this.open);
        this.map?.remove();
        this.map = null;
        this.disconnected = true;
    }

    async draw() {
        this.disconnected = false;
        const { drawActivitiesMap } = await import('../activities_map.js');
        if (!this.disconnected && !this.map) {
            this.map = drawActivitiesMap(this.element, this.dataValue);
        }
    }
}
