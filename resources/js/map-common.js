/* Shared Leaflet helpers for the Map View (New Module 1) and the schedule planner (New Module 2). */
import L from 'leaflet';

export function createMap(el, cfg) {
    const map = L.map(el, { zoomControl: true, preferCanvas: true }).setView(cfg.center, cfg.zoom);
    L.tileLayer(cfg.tiles, { maxZoom: 18, attribution: cfg.attribution, crossOrigin: true }).addTo(map);
    // the map sits in a card that may change size (sidebar collapse, offcanvas)
    new ResizeObserver(() => map.invalidateSize()).observe(el);
    return map;
}

/** Map pin coloured by inspection status (inspected / in_progress / pending), optionally numbered. */
export function schoolIcon(status, label = '') {
    return L.divIcon({
        className: '',
        html: `<span class="si-pin st-${status}">${label ? `<b>${label}</b>` : '<i class="bi bi-building"></i>'}</span>`,
        iconSize: [30, 38],
        iconAnchor: [15, 36],
        popupAnchor: [0, -32],
    });
}

export function coverageClass(pct) {
    return pct >= 75 ? 'cov-high' : pct >= 40 ? 'cov-mid' : 'cov-low';
}

export function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Fit the view to points or bounds; fall back to the configured centre. */
export function fitTo(map, pts, cfg, maxZoom = 12) {
    if (pts && typeof pts.isValid === 'function') {
        if (pts.isValid()) map.fitBounds(pts, { padding: [24, 24], maxZoom });
        return;
    }
    if (!pts || pts.length === 0) { map.setView(cfg.center, cfg.zoom); return; }
    if (pts.length === 1) { map.setView(pts[0], Math.min(maxZoom, 14)); return; }
    map.fitBounds(L.latLngBounds(pts), { padding: [30, 30], maxZoom });
}

/** Fill / stroke colours for an area's coverage (canvas-rendered layers ignore CSS classes). */
export function coverageColors(pct) {
    if (pct === null || pct === undefined) return { fillColor: '#94a3b8', color: '#64748b' };
    return pct >= 75 ? { fillColor: '#1e9b4b', color: '#167a3a' } : pct >= 40 ? { fillColor: '#f39223', color: '#c96f0c' } : { fillColor: '#d93025', color: '#a8231b' };
}

/*
 * V6 Module 7: a district outline (public/geo/districts/<slug>.geojson, dissolved from the district KML).
 * Drawn as a bold line with the land outside it lightly greyed, so the officer's district stands out.
 * Files are fetched once per page. Returns { layer, bounds } or null when the file cannot be loaded.
 */
const outlineCache = new Map();

export async function drawDistrictOutline(map, url, { mask = true, fit = false, maxZoom = 11 } = {}) {
    if (!url) return null;
    if (!outlineCache.has(url)) {
        outlineCache.set(url, fetch(url, { credentials: 'same-origin' }).then((r) => (r.ok ? r.json() : null)).catch(() => null));
    }
    const geo = await outlineCache.get(url);
    if (!geo) return null;

    const line = L.geoJSON(geo, { interactive: false, style: { color: '#1565c0', weight: 3, opacity: 0.95, fill: false, dashArray: null } });
    const layer = L.featureGroup([line]);
    if (mask) {
        // a world-sized ring with the district's rings as holes (even-odd fill)
        const rings = [];
        for (const f of geo.features || []) {
            const g = f.geometry || {};
            const polys = g.type === 'MultiPolygon' ? g.coordinates : g.type === 'Polygon' ? [g.coordinates] : [];
            polys.forEach((p) => p.forEach((r) => rings.push(r.map(([lng, lat]) => [lat, lng]))));
        }
        const world = [[-89, -179], [-89, 179], [89, 179], [89, -179]];
        L.polygon([world, ...rings], { interactive: false, stroke: false, fillColor: '#0f172a', fillOpacity: 0.12, fillRule: 'evenodd' }).addTo(layer);
    }
    layer.addTo(map);
    line.bringToFront();
    const bounds = line.getBounds();
    if (fit && bounds.isValid()) map.fitBounds(bounds, { padding: [20, 20], maxZoom });
    return { layer, bounds, name: geo.features?.[0]?.properties?.name };
}
