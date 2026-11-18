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
