/* Route map of one schedule (New Module 2), and GPS capture for "Start inspection" from the plan. */
import L from './leaflet-global';
import 'leaflet/dist/leaflet.css';
import { createMap, schoolIcon, esc, fitTo } from './map-common';

const el = document.getElementById('routeMap');
if (el) {
    const cfg = JSON.parse(el.dataset.config);
    const start = JSON.parse(el.dataset.start || 'null');
    const stops = JSON.parse(el.dataset.stops);
    const map = createMap(el, cfg);
    const pts = [];
    if (start) {
        L.circleMarker(start, { radius: 7, color: '#fff', weight: 2, fillColor: '#0f172a', fillOpacity: 1 }).bindTooltip('Start').addTo(map);
        pts.push(start);
    }
    const status = { planned: 'planned', inspected: 'inspected', skipped: 'available' };
    stops.forEach((s) => {
        if (s.lat === null || s.lng === null) return;
        L.marker([s.lat, s.lng], { icon: schoolIcon(status[s.status] || 'planned', s.n), title: s.name })
            .bindPopup(`<div class="map-pop"><b>${s.n}. ${esc(s.name)}</b><div class="text-xs text-muted">${esc(s.status)}</div></div>`).addTo(map);
        pts.push([s.lat, s.lng]);
    });
    if (pts.length > 1) L.polyline(pts, { color: '#1565c0', weight: 3, opacity: 0.8, dashArray: '6 6' }).addTo(map);
    fitTo(map, pts, cfg, 14);
}

// "Start inspection" from a published plan: capture the location first, like the school page
document.querySelectorAll('form[data-geo-start]').forEach((f) => f.addEventListener('submit', (e) => {
    if (f.dataset.located) return;
    e.preventDefault();
    const btn = f.querySelector('button');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Getting location…';
    const go = () => { f.dataset.located = 1; f.submit(); };
    if (!navigator.geolocation) return go();
    navigator.geolocation.getCurrentPosition((p) => {
        f.lat.value = p.coords.latitude; f.lng.value = p.coords.longitude; f.accuracy.value = Math.round(p.coords.accuracy);
        go();
    }, go, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
}));
