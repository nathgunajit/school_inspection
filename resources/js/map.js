/*
 * Interactive Map View (New Module 1).
 * Leaflet + OpenStreetMap, bundled by Vite. One level is loaded at a time
 * (districts → blocks → clusters → schools), so it stays light on slow connections.
 */
import L from './leaflet-global';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import { createMap, schoolIcon, esc, coverageClass, coverageColors, fitTo, drawDistrictOutline } from './map-common';

const el = document.getElementById('map');
if (el) {
    const cfg = JSON.parse(el.dataset.config);
    const start = JSON.parse(el.dataset.start);
    const map = createMap(el, cfg);
    const areaLayer = L.layerGroup().addTo(map);
    const schoolLayer = L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 45 }).addTo(map);
    let boundaries = null;
    const geo = { state: null, districts: null };
    let stateLayer = null;
    let outline = null; // V6 Module 7: { url, layer, bounds } of the district in view
    let current = { ...start };
    let lastRows = [];
    const markers = new Map();

    const $ = (id) => document.getElementById(id);
    const filters = () => ({ from: $('fFrom').value, to: $('fTo').value, officer: $('fOfficer').value });
    const qs = (o) => new URLSearchParams(Object.fromEntries(Object.entries(o).filter(([, v]) => v !== null && v !== '' && v !== undefined))).toString();
    const loading = (on) => $('mapLoading').classList.toggle('d-none', !on);
    const fmt = (n) => Number(n || 0).toLocaleString('en-IN');
    const nextLevel = { district: 'block', block: 'cluster', cluster: 'school' };
    const levelName = { district: 'District', block: 'Block', cluster: 'Cluster', school: 'School' };

    async function getJson(url) {
        const r = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!r.ok) {
            let msg = 'Could not load the map data.';
            try { msg = (await r.json()).message || msg; } catch (e) { /* not JSON */ }
            throw new Error(msg);
        }
        return r.json();
    }

    async function load(level, parent, focusSchool = null) {
        loading(true);
        try {
            const data = level === 'school'
                ? await getJson(cfg.urls.schools + '?' + qs({ cluster: parent, ...filters() }))
                : await getJson(cfg.urls.areas.replace('__L__', level) + '?' + qs({ parent, ...filters() }));
            current = { level, parent };
            lastRows = data.rows;
            renderStats(data.totals);
            renderCrumbs(data.breadcrumb, level);
            level === 'school' ? renderSchools(data.rows, focusSchool) : renderAreas(data.rows, level);
            renderTable(data.rows, level);
            syncSelects(data.breadcrumb, level, data.rows);
            syncOutline(data.outline || null, level);
        } catch (e) {
            window.toast?.(e.message, 'danger');
        } finally {
            loading(false);
        }
    }

    /**
     * V6 Module 7: outline the district being viewed (from its KML), greying the land outside it.
     * The district view (its blocks) zooms to the outline; deeper levels keep their own zoom.
     */
    async function syncOutline(url, level) {
        if (outline && outline.url !== url) { map.removeLayer(outline.layer); outline = null; }
        if (url && !outline) {
            const drawn = await drawDistrictOutline(map, url);
            if (!drawn) return;
            if (current.level !== level) { map.removeLayer(drawn.layer); return; } // the user moved on meanwhile
            outline = { url, ...drawn };
            outline.layer.bringToBack();
            boundaries?.bringToBack();
        }
        if (outline && level === 'block') {
            const pts = [...markers.values()].map((m) => m.getLatLng());
            const box = L.latLngBounds(outline.bounds.getSouthWest(), outline.bounds.getNorthEast()); // a copy: extend() mutates
            if (pts.length) box.extend(L.latLngBounds(pts));
            map.fitBounds(box, { padding: [20, 20], maxZoom: 11 });
        }
    }

    function renderStats(t) {
        const set = (k, v) => { const n = document.querySelector(`[data-stat="${k}"] .stat-value`); if (n) n.textContent = v; };
        set('officers', fmt(t.officers));
        set('schools', fmt(t.schools));
        set('inspected', fmt(t.inspected));
        set('pending', fmt(t.pending + (t.in_progress || 0)));
        set('coverage', `${t.coverage} %`);
        $('mapNoGps').textContent = t.no_gps ? `${fmt(t.no_gps)} school(s) without GPS are counted but not drawn` : '';
    }

    function renderCrumbs(crumbs, level) {
        const ol = $('mapCrumbs');
        ol.innerHTML = '';
        crumbs.forEach((c, i) => {
            const li = document.createElement('li');
            li.className = 'breadcrumb-item';
            const last = i === crumbs.length - 1;
            const order = ['district', 'block', 'cluster', 'school'];
            // areas above the user's own jurisdiction are shown but not clickable
            if (last || order.indexOf(c.level) < order.indexOf(start.level)) {
                li.classList.add('active');
                li.textContent = c.name;
            } else {
                const a = document.createElement('a');
                a.href = '#';
                a.textContent = c.name;
                a.addEventListener('click', (e) => { e.preventDefault(); load(c.level, c.parent); });
                li.appendChild(a);
            }
            ol.appendChild(li);
        });
        const tail = document.createElement('li');
        tail.className = 'breadcrumb-item text-muted';
        tail.textContent = level === 'school' ? 'Schools' : `${levelName[level]}s`;
        ol.appendChild(tail);
    }

    function areaPopup(r, level) {
        return `<div class="map-pop"><div class="fw-semibold">${esc(r.name)}</div>
            <div class="text-xs text-muted mb-1">${levelName[level]} · code ${esc(r.code)}</div>
            <table class="table table-sm mb-1"><tbody>
            <tr><td>Schools</td><td class="text-end">${fmt(r.schools)}</td></tr>
            <tr><td>Inspected</td><td class="text-end text-success">${fmt(r.inspected)}</td></tr>
            <tr><td>In progress</td><td class="text-end text-warning">${fmt(r.in_progress)}</td></tr>
            <tr><td>Not inspected</td><td class="text-end text-danger">${fmt(r.pending)}</td></tr>
            <tr><td>Coverage</td><td class="text-end fw-semibold">${r.coverage} %</td></tr>
            <tr><td>Officers</td><td class="text-end">${fmt(r.officers.total)}</td></tr></tbody></table>
            <button type="button" class="btn btn-primary btn-sm w-100" data-drill="${r.id}">Open ${levelName[nextLevel[level]].toLowerCase()}s</button></div>`;
    }

    function renderAreas(rows, level) {
        schoolLayer.clearLayers();
        areaLayer.clearLayers();
        markers.clear();
        if (boundaries) { map.removeLayer(boundaries); boundaries = null; }
        const pts = [];
        const max = Math.max(1, ...rows.map((r) => r.schools));

        // District polygons (from `php artisan map:boundaries`), coloured by the coverage of the matching District Master row
        let matched = null;
        if (level === 'district' && geo.districts) {
            const norm = (v) => String(v ?? '').toLowerCase().replace(/[^a-z0-9]/g, '');
            const byCode = new Map(rows.map((r) => [String(r.code), r]));
            const byName = new Map(rows.map((r) => [norm(r.name), r]));
            // V6 Module 7: a District Master row linked to a KML outline (boundary_name) wins over name matching
            const byBoundary = new Map(rows.filter((r) => r.boundary).map((r) => [norm(r.boundary), r]));
            const find = (p) => {
                const n = norm(p.name ?? p.district);
                return byBoundary.get(n) || byCode.get(String(p.code ?? p.district_code ?? '')) || byName.get(n)
                    || (n.length >= 5 ? rows.find((r) => norm(r.name).startsWith(n) || n.startsWith(norm(r.name))) : undefined);
            };
            matched = L.featureGroup();
            boundaries = L.geoJSON(geo.districts, {
                style: (f) => {
                    const r = find(f.properties);
                    return r ? { ...coverageColors(r.coverage), color: '#ffffff', weight: 0.6, fillOpacity: 0.4 }
                        : { fillColor: '#94a3b8', color: '#94a3b8', weight: 0.6, fillOpacity: 0.12 };
                },
                onEachFeature: (f, layer) => {
                    const r = find(f.properties);
                    layer.bindTooltip(esc(f.properties.name), { sticky: true, className: 'si-area-label' });
                    if (r) { layer.bindPopup(areaPopup(r, level)); matched.addLayer(L.polygon(layer.getLatLngs())); }
                },
            }).addTo(map);
            boundaries.bringToBack();
        }
        rows.forEach((r) => {
            if (r.lat === null || r.lng === null) return;
            const radius = 10 + 18 * Math.sqrt(r.schools / max);
            const m = L.circleMarker([r.lat, r.lng], { radius, ...coverageColors(r.coverage), weight: 2, fillOpacity: 0.6 })
                .bindTooltip(`${esc(r.name)} · ${r.coverage} %`, { permanent: rows.length <= 40, direction: 'top', className: 'si-area-label' })
                .bindPopup(areaPopup(r, level));
            m.addTo(areaLayer);
            markers.set(r.id, m);
            pts.push([r.lat, r.lng]);
        });
        // the whole state at district level when the outline is available, otherwise the areas drawn
        if (level === 'district' && stateLayer) fitTo(map, stateLayer.getBounds(), cfg);
        else if (pts.length) fitTo(map, matched && matched.getLayers().length ? matched.getBounds().extend(L.latLngBounds(pts)) : pts, cfg);
        else fitTo(map, stateLayer ? stateLayer.getBounds() : [], cfg);
    }

    function schoolPopup(s) {
        const status = { inspected: 'Inspected', in_progress: 'In progress', pending: 'Not inspected' }[s.status];
        const last = s.last
            ? `<div class="text-xs mt-1">Last inspection: <b>${esc(s.last.no || '—')}</b> on ${esc(s.last.date)} by ${esc(s.last.officer || '—')}</div>
               <a class="btn btn-soft-primary btn-sm mt-2 w-100" href="${cfg.urls.report.replace('__ID__', s.last.key)}">View report</a>`
            : '<div class="text-xs text-muted mt-1">Never inspected</div>';
        const open = cfg.canOpenSchool ? `<a class="btn btn-primary btn-sm mt-2 w-100" href="${cfg.urls.school.replace('__ID__', s.key)}">Open school</a>` : '';
        return `<div class="map-pop"><div class="fw-semibold">${esc(s.name)}</div>
            <div class="text-xs text-muted">UDISE ${esc(s.udise)} · ${esc(s.area)}</div>
            ${s.address ? `<div class="text-xs">${esc(s.address)}</div>` : ''}
            <span class="badge mt-1 st-badge st-${s.status}">${status}</span>${last}${open}</div>`;
    }

    function renderSchools(rows, focus) {
        areaLayer.clearLayers();
        schoolLayer.clearLayers();
        markers.clear();
        if (boundaries) { map.removeLayer(boundaries); boundaries = null; }
        const pts = [];
        rows.forEach((s) => {
            if (s.lat === null || s.lng === null) return;
            const m = L.marker([s.lat, s.lng], { icon: schoolIcon(s.status), title: s.name }).bindPopup(schoolPopup(s));
            schoolLayer.addLayer(m);
            markers.set(s.id, m);
            pts.push([s.lat, s.lng]);
        });
        fitTo(map, pts, cfg, 15);
        if (focus && markers.has(focus)) {
            setTimeout(() => schoolLayer.zoomToShowLayer(markers.get(focus), () => markers.get(focus).openPopup()), 300);
        }
    }

    function renderTable(rows, level) {
        $('mapTableTitle').textContent = level === 'school' ? 'Schools in this cluster' : `${levelName[level]}-wise coverage and registered officers`;
        const head = $('mapTableHead');
        const body = $('mapTableBody');
        if (level === 'school') {
            head.innerHTML = '<tr><th>School</th><th>UDISE</th><th>Status</th><th class="d-none d-md-table-cell">Last inspection</th><th class="d-none d-md-table-cell">Officer</th></tr>';
            body.innerHTML = rows.length ? rows.map((s) => `<tr data-row="${s.id}" class="cursor-pointer">
                <td>${esc(s.name)}${s.lat === null ? ' <span class="badge text-bg-light">no GPS</span>' : ''}</td><td>${esc(s.udise)}</td>
                <td><span class="badge st-badge st-${s.status}">${{ inspected: 'Inspected', in_progress: 'In progress', pending: 'Not inspected' }[s.status]}</span></td>
                <td class="d-none d-md-table-cell">${s.last ? esc(s.last.date) : '—'}</td><td class="d-none d-md-table-cell">${s.last ? esc(s.last.officer || '—') : '—'}</td></tr>`).join('')
                : '<tr><td colspan="5" class="text-muted p-3">No schools match the filters.</td></tr>';
        } else {
            head.innerHTML = `<tr><th>${levelName[level]}</th><th class="text-end">Schools</th><th class="text-end">Inspected</th><th class="text-end d-none d-sm-table-cell">In progress</th><th class="text-end">Pending</th><th class="text-end">Coverage</th>
                <th class="text-end d-none d-md-table-cell">District officers</th><th class="text-end d-none d-md-table-cell">Block officers</th><th class="text-end d-none d-md-table-cell">Cluster officers</th><th class="text-end">Officers</th></tr>`;
            body.innerHTML = rows.length ? rows.map((r) => `<tr data-row="${r.id}" class="cursor-pointer">
                <td><a href="#" data-drill="${r.id}">${esc(r.name)}</a></td><td class="text-end">${fmt(r.schools)}</td><td class="text-end">${fmt(r.inspected)}</td>
                <td class="text-end d-none d-sm-table-cell">${fmt(r.in_progress)}</td><td class="text-end">${fmt(r.pending)}</td>
                <td class="text-end"><span class="cov-pill ${coverageClass(r.coverage)}">${r.coverage} %</span></td>
                <td class="text-end d-none d-md-table-cell">${fmt(r.officers.district)}</td><td class="text-end d-none d-md-table-cell">${fmt(r.officers.block)}</td>
                <td class="text-end d-none d-md-table-cell">${fmt(r.officers.cluster)}</td><td class="text-end fw-semibold">${fmt(r.officers.total)}</td></tr>`).join('')
                : '<tr><td colspan="10" class="text-muted p-3">No areas with schools here.</td></tr>';
        }
    }

    /* ---- the District / Block / Cluster selects follow the current drill-down ---- */
    function fill(select, rows, value, placeholder) {
        select.innerHTML = `<option value="">${placeholder}</option>` + rows.map((r) => `<option value="${r.id}">${esc(r.name)}</option>`).join('');
        select.value = value ?? '';
        select.disabled = rows.length === 0;
    }
    // an area above the user's jurisdiction is shown as a single fixed choice
    function lock(select, crumb) {
        if (!crumb) { select.disabled = true; return; }
        select.innerHTML = `<option value="${crumb.parent}">${esc(crumb.name)}</option>`;
        select.disabled = true;
    }
    const cache = { district: null, block: {}, cluster: {} };
    function syncSelects(crumbs, level, rows) {
        if (level === 'district') { cache.district = rows; }
        if (level === 'block') { cache.block[current.parent] = rows; }
        if (level === 'cluster') { cache.cluster[current.parent] = rows; }
        const crumb = (l) => crumbs.find((x) => x.level === l);
        const d = crumb('block')?.parent ?? null;
        const b = crumb('cluster')?.parent ?? null;
        const c = crumb('school')?.parent ?? null;
        if (cache.district) fill($('fDistrict'), cache.district, d, 'All districts');
        else lock($('fDistrict'), crumb('block'));
        if (d && cache.block[d]) fill($('fBlock'), cache.block[d], b, 'All blocks');
        else if (b) lock($('fBlock'), crumb('cluster'));
        else fill($('fBlock'), [], null, 'All blocks');
        if (b && cache.cluster[b]) fill($('fCluster'), cache.cluster[b], c, 'All clusters');
        else if (c) lock($('fCluster'), crumb('school'));
        else fill($('fCluster'), [], null, 'All clusters');
    }

    $('fDistrict').addEventListener('change', (e) => (e.target.value ? load('block', +e.target.value) : load('district', null)));
    $('fBlock').addEventListener('change', (e) => (e.target.value ? load('cluster', +e.target.value) : load('block', +$('fDistrict').value || start.parent)));
    $('fCluster').addEventListener('change', (e) => (e.target.value ? load('school', +e.target.value) : load('cluster', +$('fBlock').value || start.parent)));
    ['fOfficer', 'fFrom', 'fTo'].forEach((id) => $(id).addEventListener('change', () => load(current.level, current.parent)));
    $('fReset').addEventListener('click', () => {
        $('fOfficer').value = '';
        $('fFrom').value = $('fFrom').defaultValue;
        $('fTo').value = $('fTo').defaultValue;
        $('fSchool').value = '';
        load(start.level, start.parent);
    });

    /* ---- school search ---- */
    let timer;
    const results = $('fSchoolResults');
    $('fSchool').addEventListener('input', (e) => {
        clearTimeout(timer);
        const q = e.target.value.trim();
        if (q.length < 3) { results.classList.add('d-none'); return; }
        timer = setTimeout(async () => {
            try {
                const data = await getJson(cfg.urls.schools + '?' + qs({ q, ...filters() }));
                results.innerHTML = data.rows.length
                    ? data.rows.slice(0, 15).map((s) => `<button type="button" class="list-group-item list-group-item-action" data-school="${s.id}" data-cluster="${s.cluster_id}">
                        <div class="fw-semibold text-truncate">${esc(s.name)}</div><div class="text-xs text-muted">${esc(s.udise)} · ${esc(s.area)}</div></button>`).join('')
                    : '<div class="list-group-item text-muted">No school found</div>';
                results.classList.remove('d-none');
            } catch (err) { window.toast?.(err.message, 'danger'); }
        }, 350);
    });
    results.addEventListener('click', (e) => {
        const b = e.target.closest('[data-school]');
        if (!b) return;
        results.classList.add('d-none');
        window.bootstrap?.Offcanvas.getInstance($('mapFilters'))?.hide();
        load('school', +b.dataset.cluster, +b.dataset.school);
    });

    /* ---- drill-down from popups and table rows ---- */
    const drill = (id) => (current.level === 'school' ? null : load(nextLevel[current.level], id));
    map.on('popupopen', (e) => {
        e.popup.getElement()?.querySelector('[data-drill]')?.addEventListener('click', (ev) => drill(+ev.currentTarget.dataset.drill));
    });
    $('mapTableBody').addEventListener('click', (e) => {
        const a = e.target.closest('[data-drill]');
        if (a) { e.preventDefault(); drill(+a.dataset.drill); return; }
        const tr = e.target.closest('[data-row]');
        const m = tr && markers.get(+tr.dataset.row);
        if (!m) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        current.level === 'school' ? schoolLayer.zoomToShowLayer(m, () => m.openPopup()) : m.openPopup();
    });

    (async () => {
        // boundaries are optional: the map works without them
        for (const k of ['state', 'districts']) {
            if (cfg.geojson?.[k]) {
                try { geo[k] = await getJson(cfg.geojson[k]); } catch (e) { geo[k] = null; }
            }
        }
        if (geo.state) {
            stateLayer = L.geoJSON(geo.state, { interactive: false, style: { color: '#1565c0', weight: 2, fill: false, opacity: 0.8 } }).addTo(map);
        }
        // state-wide users also get the district list for the select, other users start lower
        if (start.level !== 'district') {
            $('fDistrict').disabled = true;
        }
        load(start.level, start.parent);
    })();
}
