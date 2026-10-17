import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';

// Trace de l'activité sur fond OpenStreetMap ; couleurs des tronçons dans app.css (classes route-*).
export function drawRouteMap(element) {
    const route = JSON.parse(element.dataset.routeMap);
    const map = L.map(element, { scrollWheelZoom: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© <a href="https://www.openstreetmap.org/copyright">contributeurs OpenStreetMap</a>',
    }).addTo(map);

    route.segments.forEach((segment) => {
        L.polyline(segment.points, { className: `route-line route-${segment.class}`, weight: 5 })
            .bindTooltip(segment.label, { sticky: true })
            .addTo(map);
    });

    const first = route.segments[0].points[0];
    const lastSegment = route.segments[route.segments.length - 1].points;
    L.circleMarker(lastSegment[lastSegment.length - 1], { className: 'route-finish', radius: 7 })
        .bindTooltip('Arrivée').addTo(map);
    L.circleMarker(first, { className: 'route-start', radius: 7 }).bindTooltip('Départ').addTo(map);

    map.fitBounds(route.bounds, { padding: [16, 16] });
}
