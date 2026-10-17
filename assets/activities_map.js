import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';

// Rayon (px) de regroupement : les départs plus proches forment un groupe.
const RADIUS = 60;
// À ce zoom, un groupe (ex. départs de la maison) s'ouvre en liste au lieu de zoomer.
const LIST_ZOOM = 17;
const LIST_LIMIT = 30;

// Carte des départs (page Activités) ; couleurs dans app.css (classes start-*).
export function drawActivitiesMap(element) {
    const { points, bounds } = JSON.parse(element.dataset.activitiesMap);
    const map = L.map(element, { scrollWheelZoom: false });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© <a href="https://www.openstreetmap.org/copyright">contributeurs OpenStreetMap</a>',
    }).addTo(map);
    const layer = L.layerGroup().addTo(map);
    // Rang de chaque départ (du plus récent au plus ancien) : ordre de la liste d'un groupe.
    const rank = new Map(points.map((point, i) => [point, i]));

    const pointMarker = ([lat, lon, label, url]) => L.circleMarker([lat, lon], { className: 'start-point', radius: 6 })
        .bindTooltip(label)
        .on('click', () => window.location.assign(url));

    const openList = (group, center) => {
        const content = document.createElement('div');
        content.className = 'start-list';
        const title = document.createElement('strong');
        title.textContent = `${group.length} sorties`;
        const list = document.createElement('ul');
        [...group].sort((p, q) => rank.get(p) - rank.get(q)).slice(0, LIST_LIMIT).forEach(([, , label, url]) => {
            const link = document.createElement('a');
            link.href = url;
            link.textContent = label;
            const item = document.createElement('li');
            item.append(link);
            list.append(item);
        });
        if (group.length > LIST_LIMIT) {
            const more = document.createElement('li');
            more.textContent = `… et ${group.length - LIST_LIMIT} autres (affiner avec la recherche)`;
            list.append(more);
        }
        content.append(title, list);
        L.popup({ maxWidth: 340 }).setLatLng(center).setContent(content).openOn(map);
    };

    const clusterMarker = (group) => {
        const lat = group.reduce((sum, p) => sum + p[0], 0) / group.length;
        const lon = group.reduce((sum, p) => sum + p[1], 0) / group.length;
        const size = group.length < 10 ? 32 : group.length < 100 ? 40 : 48;
        const marker = L.marker([lat, lon], {
            icon: L.divIcon({ className: 'start-cluster', html: `<span>${group.length}</span>`, iconSize: [size, size] }),
            title: `${group.length} sorties : cliquer pour les détailler`,
        });
        // Zoome sur le groupe (au moins d'un niveau, jusqu'à l'englober) ; au zoom LIST_ZOOM, ouvre la liste.
        marker.on('click', () => {
            const zoom = map.getZoom();
            if (zoom >= LIST_ZOOM) {
                openList(group, [lat, lon]);

                return;
            }
            const groupBounds = L.latLngBounds(group.map(([la, lo]) => [la, lo]));
            const target = Math.min(Math.max(map.getBoundsZoom(groupBounds, false, L.point(40, 40)), zoom + 1), LIST_ZOOM);
            map.setView([lat, lon], target);
        });

        return marker;
    };

    // Chaque départ rejoint le groupe le plus proche (à moins de RADIUS px de son centre, en pixels
    // au zoom courant), sinon en crée un.
    const render = () => {
        layer.clearLayers();
        const zoom = map.getZoom();
        const groups = [];
        points.forEach((point) => {
            const { x, y } = map.project([point[0], point[1]], zoom);
            let nearest = null;
            let nearestDistance = RADIUS;
            groups.forEach((group) => {
                const distance = Math.hypot(group.x - x, group.y - y);
                if (distance < nearestDistance) {
                    nearest = group;
                    nearestDistance = distance;
                }
            });
            if (nearest) {
                nearest.points.push(point);
                nearest.x += (x - nearest.x) / nearest.points.length;
                nearest.y += (y - nearest.y) / nearest.points.length;
            } else {
                groups.push({ x, y, points: [point] });
            }
        });
        // En grossissant, le centre d'un groupe se déplace : on fusionne ceux devenus trop proches,
        // jusqu'à ce qu'il n'y en ait plus (une fusion peut en rapprocher d'autres).
        let merged = true;
        while (merged) {
            merged = false;
            for (let i = 0; i < groups.length && !merged; i++) {
                for (let j = i + 1; j < groups.length && !merged; j++) {
                    const [a, b] = [groups[i], groups[j]];
                    if (Math.hypot(a.x - b.x, a.y - b.y) < RADIUS) {
                        const total = a.points.length + b.points.length;
                        a.x = (a.x * a.points.length + b.x * b.points.length) / total;
                        a.y = (a.y * a.points.length + b.y * b.points.length) / total;
                        a.points.push(...b.points);
                        groups.splice(j, 1);
                        merged = true;
                    }
                }
            }
        }
        groups.forEach(({ points: group }) => layer.addLayer(group.length === 1 ? pointMarker(group[0]) : clusterMarker(group)));
    };

    map.on('zoomend', render);
    map.fitBounds(bounds, { padding: [24, 24], maxZoom: 13 });
    render();
}
