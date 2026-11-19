/*
 * V6 Module 8: the school page map. Shows the school, the officer's position ("My location"),
 * the distance between them and, on request, the road route; plus a Google Maps directions link.
 */
import L from './leaflet-global';
import 'leaflet/dist/leaflet.css';
import { createMap, schoolIcon, esc, drawDistrictOutline, createDirections, directionsPanelHtml, bindDirectionsPanel } from './map-common';

const el = document.getElementById('schoolMap');
if (el) {
    const cfg = JSON.parse(el.dataset.config);
    const map = createMap(el, cfg);
    const s = cfg.school;
    L.marker([s.lat, s.lng], { icon: schoolIcon('planned'), title: s.name }).bindTooltip(esc(s.name)).addTo(map);
    drawDistrictOutline(map, cfg.outline, { mask: false }).then((o) => o?.layer.bringToBack());

    const directions = createDirections(map, cfg);
    const box = document.getElementById('schoolDir');
    box.innerHTML = directionsPanelHtml(s.lat, s.lng);
    bindDirectionsPanel(box.querySelector('.si-dir'), directions);

    // the header button does the same as the map button, and frames both points once the position is known
    let wantFit = false;
    const fitBoth = () => map.fitBounds(L.latLngBounds([directions.position, [s.lat, s.lng]]), { padding: [40, 40], maxZoom: 15 });
    directions.onChange(() => { if (wantFit) { wantFit = false; fitBoth(); } });
    document.querySelector('[data-locate-me]')?.addEventListener('click', () => {
        if (directions.position) { fitBoth(); return; }
        wantFit = true;
        directions.start({ centre: false });
    });
}
