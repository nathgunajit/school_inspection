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

/* ------------------------------------------------------------------
 * V6 Module 8: my location, distance to a school and the road route.
 * The position stays in the browser. It is sent to the routing server (OSRM, config inspection.map.routing)
 * only when the officer presses "Show route"; "Google Maps" opens turn-by-turn directions in a new tab.
 * ------------------------------------------------------------------ */

/** 23-11-2026: District (D, blue) / Block (B, green) office pin. */
export function officeIcon(type) {
    return L.divIcon({
        className: '',
        html: `<span class="si-office office-${type}" title="${type === 'district' ? 'District office' : 'Block office'}"><i class="bi bi-building-fill"></i><b>${type === 'district' ? 'D' : 'B'}</b></span>`,
        iconSize: [34, 40],
        iconAnchor: [17, 38],
        popupAnchor: [0, -34],
    });
}

/** Straight-line distance in km between two [lat, lng] points. */
export function haversineKm(a, b) {
    const R = 6371;
    const rad = (d) => (d * Math.PI) / 180;
    const dLat = rad(b[0] - a[0]);
    const dLng = rad(b[1] - a[1]);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a[0])) * Math.cos(rad(b[0])) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

export const fmtKm = (km) => (km < 1 ? `${Math.round(km * 1000)} m` : `${km.toFixed(km < 10 ? 1 : 0)} km`);
export const fmtMinutes = (min) => (min < 60 ? `${Math.max(1, Math.round(min))} min` : `${Math.floor(min / 60)} h ${Math.round(min % 60)} min`);

/** Google Maps directions link (from my position when known, otherwise Google Maps uses the device's location). */
export function directionsUrl(to, from = null) {
    const q = new URLSearchParams({ api: '1', destination: `${to[0]},${to[1]}`, travelmode: 'driving' });
    if (from) q.set('origin', `${from[0]},${from[1]}`);
    return `https://www.google.com/maps/dir/?${q}`;
}

/** Why the position is not available, in words an officer can act on. */
function locationError(err) {
    if (!window.isSecureContext) return 'Your browser shares location only over HTTPS (or on localhost). Open the app with https:// to use My location.';
    if (!navigator.geolocation) return 'This device or browser does not support location.';
    if (err?.code === 1) return 'Location permission is blocked. Allow location for this site in the browser settings, then try again.';
    if (err?.code === 3) return 'Getting your location took too long. Move to open sky or turn on GPS, then try again.';
    return 'Your location is not available right now. Turn on GPS / location services and try again.';
}

/**
 * Adds a "My location" button to the map and keeps the officer's position (blue dot + accuracy circle).
 * routeTo() draws the road route to a school. onChange(position) runs whenever the position changes.
 */
export function createDirections(map, cfg = {}) {
    let pos = null;
    let watch = null;
    let dot = null;
    let ring = null;
    let routeLayer = null;
    let recentre = true;
    let btn = null;
    const listeners = [];

    function update(p) {
        pos = [p.coords.latitude, p.coords.longitude];
        const acc = Math.min(p.coords.accuracy || 0, 5000);
        if (!dot) {
            ring = L.circle(pos, { radius: acc, color: '#1a73e8', weight: 1, fillColor: '#1a73e8', fillOpacity: 0.1, interactive: false }).addTo(map);
            dot = L.circleMarker(pos, { radius: 8, color: '#ffffff', weight: 3, fillColor: '#1a73e8', fillOpacity: 1 }).bindTooltip('You are here').addTo(map);
        } else {
            dot.setLatLng(pos);
            ring.setLatLng(pos).setRadius(acc);
        }
        if (recentre) { recentre = false; map.setView(pos, Math.max(map.getZoom(), 13)); }
        listeners.forEach((fn) => fn(pos, acc));
    }

    function start({ centre = true } = {}) {
        if (!window.isSecureContext || !navigator.geolocation) { window.toast?.(locationError(), 'warning'); return; }
        if (pos) { if (centre) map.setView(pos, Math.max(map.getZoom(), 13)); return; }
        if (watch !== null) return;
        recentre = centre;
        btn?.classList.add('is-busy');
        watch = navigator.geolocation.watchPosition((p) => {
            btn?.classList.remove('is-busy');
            btn?.classList.add('is-on');
            update(p);
        }, (err) => {
            btn?.classList.remove('is-busy', 'is-on');
            navigator.geolocation.clearWatch(watch);
            watch = null;
            window.toast?.(locationError(err), 'warning');
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 15000 });
    }

    // the map button, under the zoom buttons
    const Control = L.Control.extend({
        options: { position: 'topleft' },
        onAdd() {
            const wrap = L.DomUtil.create('div', 'leaflet-bar si-locate');
            btn = L.DomUtil.create('a', '', wrap);
            btn.href = '#';
            btn.setAttribute('role', 'button');
            btn.title = 'My location';
            btn.setAttribute('aria-label', 'Show my location');
            btn.innerHTML = '<i class="bi bi-crosshair"></i>';
            L.DomEvent.on(btn, 'click', (e) => { L.DomEvent.stop(e); start(); });
            L.DomEvent.disableClickPropagation(wrap);
            return wrap;
        },
    });
    new Control().addTo(map);

    function clearRoute() {
        if (routeLayer) { map.removeLayer(routeLayer); routeLayer = null; }
    }

    /** Draws the road route to `to`; returns { km, minutes, road } (road=false: straight line, routing unavailable). */
    async function routeTo(to) {
        if (!pos) throw new Error('Tap "My location" first, so the route can start from where you are.');
        clearRoute();
        const r = cfg.routing || {};
        if (r.enabled && r.url) {
            try {
                const ctl = new AbortController();
                const t = setTimeout(() => ctl.abort(), r.timeout || 8000);
                const url = `${r.url}/route/v1/${r.profile || 'driving'}/${pos[1]},${pos[0]};${to[1]},${to[0]}?overview=full&geometries=geojson`;
                const res = await fetch(url, { signal: ctl.signal });
                clearTimeout(t);
                const data = res.ok ? await res.json() : null;
                const route = data?.code === 'Ok' ? data.routes?.[0] : null;
                if (route) {
                    // white casing under a green line (canvas paints in creation order, so the casing is created first)
                    const casing = L.geoJSON(route.geometry, { interactive: false, style: { color: '#ffffff', weight: 9, opacity: 0.9 } });
                    const line = L.geoJSON(route.geometry, { interactive: false, style: { color: '#1e9b4b', weight: 5, opacity: 0.95 } });
                    routeLayer = L.layerGroup([casing, line]).addTo(map);
                    line.bringToFront();
                    dot?.bringToFront();
                    map.fitBounds(line.getBounds().extend(pos).extend(to), { padding: [40, 40], maxZoom: 16 });
                    return { km: route.distance / 1000, minutes: route.duration / 60, road: true };
                }
            } catch (e) { /* fall through to the straight line */ }
        }
        routeLayer = L.polyline([pos, to], { color: '#1e9b4b', weight: 4, dashArray: '8 8', interactive: false }).addTo(map);
        map.fitBounds(L.latLngBounds([pos, to]), { padding: [40, 40], maxZoom: 16 });
        return { km: haversineKm(pos, to), minutes: null, road: false };
    }

    return {
        start,
        routeTo,
        clearRoute,
        get position() { return pos; },
        distanceTo: (to) => (pos ? haversineKm(pos, to) : null),
        onChange: (fn) => listeners.push(fn),
    };
}

/** HTML of the directions panel for a school at [lat, lng]. */
export function directionsPanelHtml(lat, lng) {
    return `<div class="si-dir" data-lat="${lat}" data-lng="${lng}">
        <div class="si-dir-distance text-xs" data-dir-distance></div>
        <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-soft-primary btn-sm flex-fill" data-dir-route><i class="bi bi-signpost-split"></i>Show route</button>
            <a class="btn btn-light btn-sm flex-fill" data-dir-google target="_blank" rel="noopener noreferrer" href="${directionsUrl([lat, lng])}"><i class="bi bi-google"></i>Google Maps</a>
        </div>
    </div>`;
}

/**
 * Wires a directions panel (see directionsPanelHtml): fills [data-dir-distance], handles [data-dir-route]
 * and keeps [data-dir-google] starting from my position once it is known.
 */
export function bindDirectionsPanel(el, dir) {
    if (!el || el.dataset.dirBound) return;
    el.dataset.dirBound = '1';
    const to = [+el.dataset.lat, +el.dataset.lng];
    const distEl = el.querySelector('[data-dir-distance]');
    const google = el.querySelector('[data-dir-google]');
    const routeBtn = el.querySelector('[data-dir-route]');
    const refresh = () => {
        const km = dir.distanceTo(to);
        if (distEl && !distEl.dataset.road) {
            distEl.innerHTML = km === null
                ? '<i class="bi bi-crosshair"></i> Tap <b>My location</b> to see how far it is'
                : `<i class="bi bi-geo-alt"></i> <b>${fmtKm(km)}</b> from you (straight line)`;
        }
        if (google) google.href = directionsUrl(to, dir.position);
    };
    refresh();
    dir.onChange(() => { if (el.isConnected) refresh(); });
    routeBtn?.addEventListener('click', async () => {
        if (!dir.position) {
            dir.start({ centre: false });
            window.toast?.('Finding your location. Tap "Show route" again in a moment.', 'info');
            return;
        }
        routeBtn.disabled = true;
        const label = routeBtn.innerHTML;
        routeBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Route';
        try {
            const r = await dir.routeTo(to);
            if (distEl) {
                distEl.dataset.road = '1';
                distEl.innerHTML = r.road
                    ? `<i class="bi bi-signpost-2"></i> <b>${fmtKm(r.km)}</b> by road · about <b>${fmtMinutes(r.minutes)}</b> by car`
                    : `<i class="bi bi-geo-alt"></i> <b>${fmtKm(r.km)}</b> straight line <span class="text-muted">(road route unavailable)</span>`;
            }
        } catch (e) {
            window.toast?.(e.message, 'warning');
        } finally {
            routeBtn.disabled = false;
            routeBtn.innerHTML = label;
        }
    });
}
