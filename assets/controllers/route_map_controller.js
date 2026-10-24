import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';

/* stimulusFetch: 'lazy' */

// Trace de l'activité sur fond OpenStreetMap (page détail) ; couleurs des tronçons dans app.css
// (classes route-*). Contrôleur chargé à la demande : Leaflet n'est téléchargé que sur cette page.
export default class extends Controller {
    static values = { route: Object };

    connect() {
        const route = this.routeValue;
        // Page remontrée depuis le cache de Turbo : la copie garde le dessin de la carte précédente.
        this.element.replaceChildren();
        this.map = L.map(this.element, { scrollWheelZoom: false });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© <a href="https://www.openstreetmap.org/copyright">contributeurs OpenStreetMap</a>',
        }).addTo(this.map);

        route.segments.forEach((segment) => {
            L.polyline(segment.points, { className: `route-line route-${segment.class}`, weight: 5 })
                .bindTooltip(segment.label, { sticky: true })
                .addTo(this.map);
        });

        const first = route.segments[0].points[0];
        const lastSegment = route.segments[route.segments.length - 1].points;
        L.circleMarker(lastSegment[lastSegment.length - 1], { className: 'route-finish', radius: 7 })
            .bindTooltip('Arrivée').addTo(this.map);
        L.circleMarker(first, { className: 'route-start', radius: 7 }).bindTooltip('Départ').addTo(this.map);

        this.map.fitBounds(route.bounds, { padding: [16, 16] });
    }

    disconnect() {
        this.map.remove();
    }
}
