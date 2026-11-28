/*
 * Interactive Map View (New Module 1).
 * Leaflet + OpenStreetMap, bundled by Vite. One level is loaded at a time
 * (districts → blocks → clusters → schools), so it stays light on slow connections.
 */
import L from './leaflet-global';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import { createMap, schoolIcon, officeIcon, OFFICE, haversineKm, fmtKm, directionsUrl, esc, coverageClass, coverageColors, fitTo, drawDistrictOutline, createDirections, directionsPanelHtml, bindDirectionsPanel } from './map-common';

const el = document.getElementById('map');
if (el) {
    const cfg = JSON.parse(el.dataset.config);
    const start = JSON.parse(el.dataset.start);
    const map = createMap(el, cfg);

    // 25-11-2026: /map#mapCard (the dashboard's Map view button) opens with the map in view
    const showMap = (smooth = true) => {
        window.bootstrap?.Offcanvas.getInstance(document.getElementById('mapFilters'))?.hide();
        document.getElementById('mapCard')?.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' });
        setTimeout(() => { map.invalidateSize(); el.focus({ preventScroll: true }); }, smooth ? 450 : 50);
    };
    if (location.hash === '#mapCard') showMap(false);
    const areaLayer = L.layerGroup().addTo(map);
    // 23-11-2026: zoomed out, grouped schools show a school icon with the count (coloured by the main status / average rating)
    const schoolLayer = L.markerClusterGroup({
        showCoverageOnHover: false, maxClusterRadius: 45, disableClusteringAtZoom: 16,
        iconCreateFunction: (cluster) => clusterIcon(cluster.getAllChildMarkers(), cluster.getChildCount()),
    }).addTo(map);
    const directions = createDirections(map, cfg); // V6 Module 8: My location, distance and road route
    let boundaries = null;
    const geo = { state: null, districts: null };
    // 26-11-2026 performance: the district polygons (about 186 KB) are needed only in the state-wide view, so they are
    // fetched the first time that view is drawn (officers below the State never download them)
    let districtsLoading = null;
    const loadDistricts = () => (districtsLoading ??= cfg.geojson?.districts
        ? getJson(cfg.geojson.districts).then((d) => { geo.districts = d; }).catch(() => { geo.districts = null; })
        : Promise.resolve());
    let stateLayer = null;
    let outline = null; // V6 Module 7: { url, layer, bounds } of the district in view
    let current = { ...start };
    let lastRows = [];
    const markers = new Map();
    // 23-11-2026: District / Block offices, drawn above the schools, with distances in the school popups
    map.createPane('offices').style.zIndex = 640;
    const officeLayer = L.layerGroup().addTo(map);
    let offices = [];
    const officeOf = (type, id) => offices.find((o) => o.type === type && o.id === id);

    const $ = (id) => document.getElementById(id);
    const filters = () => ({ from: $('fFrom').value, to: $('fTo').value, officer: $('fOfficer').value });
    const qs = (o) => new URLSearchParams(Object.fromEntries(Object.entries(o).filter(([, v]) => v !== null && v !== '' && v !== undefined))).toString();
    const loading = (on) => $('mapLoading').classList.toggle('d-none', !on);
    const fmt = (n) => Number(n || 0).toLocaleString('en-IN');
    const nextLevel = { district: 'block', block: 'cluster', cluster: 'school' };
    const levelName = { district: 'District', block: 'Block', cluster: 'Cluster', school: 'School' };
    // V6 Module 9: school ratings
    let colourBy = document.querySelector('input[name="colourBy"]:checked')?.value || 'status'; // V13: ?colour=rating from the Rating Report
    const showRating = cfg.showRating !== false; // Modification V9: ratings hidden unless RATING_DISPLAY=true
    const pct = (v) => `${Number(v).toFixed(1).replace(/\.0$/, '')}%`;
    const ratingPill = (r) => (r ? `<span class="rating-pill rating-${esc(r.color || 'none')}">${pct(r.score ?? r.avg)} · ${esc(r.band || '')}</span>` : '');
    const ratingIcon = (r) => L.divIcon({ className: '', iconSize: [30, 38], iconAnchor: [15, 36], popupAnchor: [0, -32],
        html: `<span class="si-pin rt-${esc(r?.color || 'none')}">${r ? `<b>${Math.round(r.score)}</b>` : '<i class="bi bi-dash"></i>'}</span>` });

    function clusterIcon(children, count) {
        let cls;
        if (colourBy === 'rating') {
            const rated = children.map((m) => m.options.siRating).filter(Boolean);
            const avg = rated.length ? rated.reduce((a, r) => a + Number(r.score || 0), 0) / rated.length : null;
            // the band of the school closest to the average score
            const near = avg === null ? null : rated.reduce((b, r) => (Math.abs(r.score - avg) < Math.abs(b.score - avg) ? r : b));
            cls = `rt-${near?.color || 'none'}`;
        } else {
            const n = {};
            children.forEach((m) => { const s = m.options.siStatus || 'pending'; n[s] = (n[s] || 0) + 1; });
            cls = `st-${Object.keys(n).sort((a, b) => n[b] - n[a])[0] || 'pending'}`;
        }
        const size = count < 10 ? 'sm' : count < 100 ? 'md' : 'lg';
        const px = { sm: 38, md: 44, lg: 50 }[size];
        return L.divIcon({
            className: '', iconSize: [px, px], iconAnchor: [px / 2, px / 2],
            html: `<span class="si-cluster si-cluster-${size} ${cls}" title="${count} schools"><i class="bi bi-building"></i><b>${count.toLocaleString('en-IN')}</b></span>`,
        });
    }

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
            offices = data.offices || [];
            renderStats(data.totals);
            renderCrumbs(data.breadcrumb, level);
            level === 'school' ? renderSchools(data.rows, focusSchool) : renderAreas(data.rows, level);
            renderOffices();
            renderTable(data.rows, level); // 26-11-2026: the report list below the map
            syncSelects(data.breadcrumb, level, data.rows);
            crumbsNow = data.breadcrumb;
            if (focusSchool === null) picked = null; // a school chip only for the school picked in the search
            renderChips();
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
        // Modification V8 item 2: the colour key above the map carries the counts (26-11-2026: the count tiles were removed)
        const key = (k, v) => { const n = document.querySelector(`[data-key="${k}"]`); if (n) n.textContent = fmt(v); };
        key('inspected', t.inspected);
        key('in_progress', t.in_progress || 0);
        key('pending', t.pending);
        const day = (v) => (v ? new Date(`${v}T00:00:00`).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }) : '');
        $('mapKeyPeriod').textContent = `${day($('fFrom').value)} – ${day($('fTo').value)}`;
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

    // Modification V8 item 1: popups with a coloured header, count tiles and clear actions
    function areaPopup(r, level) {
        const cov = Math.max(0, Math.min(100, Number(r.coverage) || 0));
        return `<div class="map-pop map-pop-area">
            <div class="mp-head"><div class="mp-icon"><i class="bi bi-diagram-3"></i></div>
                <div class="min-w-0"><div class="mp-title">${esc(r.label ?? r.name)}</div><div class="mp-sub">${levelName[level]} · code ${esc(r.code)}</div></div></div>
            <div class="mp-body">
                <div class="mp-tiles">
                    <div class="mp-tile st-inspected"><b>${fmt(r.inspected)}</b><span>Inspected</span></div>
                    <div class="mp-tile st-in_progress"><b>${fmt(r.in_progress)}</b><span>In progress</span></div>
                    <div class="mp-tile st-pending"><b>${fmt(r.pending)}</b><span>Not inspected</span></div>
                </div>
                <div class="mp-cov"><div class="d-flex justify-content-between"><span>Coverage of ${fmt(r.schools)} schools</span><b>${r.coverage} %</b></div>
                    <div class="mp-bar"><span class="${coverageClass(cov)}" style="width:${cov}%"></span></div></div>
                ${showRating ? `<div class="mp-row"><i class="bi bi-star-half"></i><span>Average rating</span><span class="ms-auto">${r.rating ? ratingPill(r.rating) : '<span class="text-muted">Not rated</span>'}</span></div>
                ${r.rating ? `<div class="mp-note">${fmt(r.rating.count)} rated inspection(s)</div>` : ''}` : ''}
                <div class="mp-row"><i class="bi bi-person-badge"></i><span>Officers</span><b class="ms-auto">${fmt(r.officers.total)}</b></div>
                <button type="button" class="btn btn-primary btn-sm w-100 mt-2" data-drill="${r.id}"><i class="bi bi-zoom-in"></i>Open ${levelName[nextLevel[level]].toLowerCase()}s</button>
            </div></div>`;
    }

    function renderAreas(rows, level) {
        if (level === 'district' && !districtsLoading && cfg.geojson?.districts) {
            loadDistricts().then(() => { if (geo.districts && current.level === 'district') renderAreas(rows, level); });
        }
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
                .bindTooltip(`${esc(r.label ?? r.name)} · ${r.coverage} %${r.rating ? ` · ★ ${pct(r.rating.avg)}` : ''}`, { permanent: rows.length <= 40, direction: 'top', className: 'si-area-label' })
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

    /* ---- 23-11-2026: District / Block offices ---- */
    const officeName = (o) => OFFICE[o.type].name;
    const schoolPts = () => (current.level === 'school' ? lastRows.filter((s) => s.lat !== null && s.lng !== null) : []);

    function officePopup(o) {
        const pts = schoolPts().filter((s) => (o.type === 'district' ? s.district_id === o.id : s.block_id === o.id))
            .map((s) => ({ s, km: haversineKm([o.lat, o.lng], [s.lat, s.lng]) })).sort((a, b) => a.km - b.km);
        const span = pts.length
            ? `<div class="mp-row"><i class="bi bi-rulers"></i><span>${fmt(pts.length)} school(s) shown · nearest <b>${fmtKm(pts[0].km)}</b>, farthest <b>${fmtKm(pts[pts.length - 1].km)}</b> <span class="text-muted">(approx.)</span></span></div>`
            : '';
        return `<div class="map-pop map-pop-office office-${o.type}">
            <div class="mp-head"><div class="mp-icon"><i class="bi ${OFFICE[o.type].icon}"></i></div>
                <div class="min-w-0"><div class="mp-title">${esc(officeName(o))}</div><div class="mp-sub">${esc(o.label)}</div></div></div>
            <div class="mp-body">
                ${o.address ? `<div class="mp-row"><i class="bi bi-signpost"></i><span>${esc(o.address)}</span></div>` : ''}
                <div class="mp-row"><i class="bi bi-geo-alt"></i><span class="font-monospace text-xs">${o.lat.toFixed(5)}, ${o.lng.toFixed(5)}</span></div>
                ${span}
                ${directionsPanelHtml(o.lat, o.lng)}
            </div></div>`;
    }

    function renderOffices() {
        officeLayer.clearLayers();
        const on = $('fOffices')?.checked !== false;
        document.querySelector('[data-office-key]')?.classList.toggle('d-none', !(on && offices.length));
        // V14: offices without a location are never listed, and the panel hides when the view has none
        document.querySelector('[data-office-panel]')?.classList.toggle('d-none', !offices.length);
        const note = $('officeNote');
        if (note) note.textContent = offices.length ? `${offices.length} office(s) in this view` : '';
        if (!on) return;
        offices.forEach((o) => {
            L.marker([o.lat, o.lng], { icon: officeIcon(o.type), pane: 'offices', title: `${officeName(o)} · ${o.label}` })
                .bindTooltip(`${OFFICE[o.type].short} · ${esc(o.name)}`, { permanent: offices.length <= 40, direction: 'bottom', offset: [0, 4], className: `si-office-label office-${o.type}` })
                .bindPopup(officePopup(o))
                .addTo(officeLayer);
        });
    }

    /** The school's distance to its Block and District office (straight line), with a Google Maps route from the office. */
    function officeRows(s) {
        if (s.lat === null || s.lng === null) return '';
        const row = (type, id) => {
            const o = officeOf(type, id);
            const name = OFFICE[type].name;
            if (!o) return ''; // V14: an office without a location is not shown
            const km = haversineKm([o.lat, o.lng], [s.lat, s.lng]);
            return `<div class="mp-row"><i class="bi ${OFFICE[type].icon} office-ink-${type}"></i><span class="min-w-0">${name} · ${esc(o.name)}</span>
                <b class="ms-auto text-nowrap">${fmtKm(km)}</b>
                <a class="mp-office-go" href="${directionsUrl([s.lat, s.lng], [o.lat, o.lng])}" target="_blank" rel="noopener noreferrer" title="Route from the ${name.toLowerCase()} (Google Maps)" aria-label="Route from the ${name.toLowerCase()}"><i class="bi bi-sign-turn-right"></i></a></div>`;
        };
        const rows = row('block', s.block_id) + row('district', s.district_id);
        return rows ? `<div class="mp-last mp-offices"><div class="mp-last-label">Distance from offices <span class="text-muted">(approx.)</span></div>${rows}</div>` : '';
    }

    function schoolPopup(s) {
        const status = { inspected: 'Inspected', in_progress: 'In progress', pending: 'Not inspected' }[s.status];
        const icon = { inspected: 'bi-check2-circle', in_progress: 'bi-hourglass-split', pending: 'bi-clock' }[s.status] || 'bi-building';
        const last = s.last
            ? `<div class="mp-last"><div class="mp-last-label">Last inspection</div>
                   <div class="mp-row"><i class="bi bi-hash"></i><b>${esc(s.last.no || '—')}</b></div>
                   <div class="mp-row"><i class="bi bi-calendar3"></i><span>${esc(s.last.date)}</span></div>
                   <div class="mp-row"><i class="bi bi-person"></i><span class="text-truncate">${esc(s.last.officer || '—')}</span></div></div>`
            : '<div class="mp-last mp-last-none"><i class="bi bi-info-circle"></i>Never inspected</div>';
        const actions = [
            cfg.canOpenSchool ? `<a class="btn btn-primary btn-sm" href="${cfg.urls.school.replace('__ID__', s.key)}"><i class="bi bi-building"></i>Open school</a>` : '',
            s.last ? `<a class="btn btn-soft-primary btn-sm" href="${cfg.urls.report.replace('__ID__', s.last.key)}"><i class="bi bi-file-earmark-text"></i>View report</a>` : '',
        ].filter(Boolean).join('');
        return `<div class="map-pop map-pop-school is-${s.status}">
            <div class="mp-head"><div class="mp-icon"><i class="bi ${icon}"></i></div>
                <div class="min-w-0"><div class="mp-title">${esc(s.name)}</div><div class="mp-sub">UDISE ${esc(s.udise)}</div></div></div>
            <div class="mp-body">
                <div class="mp-chips"><span class="mp-status st-${s.status}"><span class="dot st-${s.status}"></span>${status}</span>
                    ${!showRating ? '' : s.rating ? ratingPill(s.rating) : '<span class="mp-chip-muted">Not rated yet</span>'}</div>
                <div class="mp-row"><i class="bi bi-geo-alt"></i><span>${esc(s.area)}</span></div>
                ${s.info ? `<div class="mp-row"><i class="bi bi-building"></i><span>${esc(s.info)}</span></div>` : ''}
                ${s.address ? `<div class="mp-row"><i class="bi bi-signpost"></i><span>${esc(s.address)}</span></div>` : ''}
                ${last}
                ${officeRows(s)}
                ${s.lat !== null && s.lng !== null ? directionsPanelHtml(s.lat, s.lng) : ''}
                ${actions ? `<div class="mp-actions">${actions}</div>` : ''}
            </div></div>`;
    }

    function renderSchools(rows, focus) {
        areaLayer.clearLayers();
        schoolLayer.clearLayers();
        markers.clear();
        if (boundaries) { map.removeLayer(boundaries); boundaries = null; }
        const pts = [];
        rows.forEach((s) => {
            if (s.lat === null || s.lng === null) return;
            const m = L.marker([s.lat, s.lng], { icon: colourBy === 'rating' ? ratingIcon(s.rating) : schoolIcon(s.status), title: s.name, siStatus: s.status, siRating: s.rating }).bindPopup(schoolPopup(s));
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
            // 23-11-2026: straight-line distance to the school's Block office, when its location is set
            const hasBlockOffice = offices.some((o) => o.type === 'block');
            const blockKm = (s) => {
                const o = officeOf('block', s.block_id);
                return o && s.lat !== null && s.lng !== null ? fmtKm(haversineKm([o.lat, o.lng], [s.lat, s.lng])) : '—';
            };
            head.innerHTML = '<tr><th>School</th><th>UDISE</th><th>Status</th>' + (showRating ? '<th>Rating</th>' : '') + (hasBlockOffice ? '<th class="text-end text-nowrap">Block office</th>' : '') + '<th class="d-none d-md-table-cell">Last inspection</th><th class="d-none d-md-table-cell">Officer</th></tr>';
            body.innerHTML = rows.length ? rows.map((s) => `<tr data-row="${s.id}" class="cursor-pointer">
                <td>${esc(s.name)}${s.lat === null ? ' <span class="badge text-bg-light">no GPS</span>' : ''}</td><td>${esc(s.udise)}</td>
                <td><span class="badge st-badge st-${s.status}">${{ inspected: 'Inspected', in_progress: 'In progress', pending: 'Not inspected' }[s.status]}</span></td>
                ${showRating ? `<td>${s.rating ? ratingPill(s.rating) : '—'}</td>` : ''}
                ${hasBlockOffice ? `<td class="text-end text-nowrap">${blockKm(s)}</td>` : ''}
                <td class="d-none d-md-table-cell">${s.last ? esc(s.last.date) : '—'}</td><td class="d-none d-md-table-cell">${s.last ? esc(s.last.officer || '—') : '—'}</td></tr>`).join('')
                : '<tr><td colspan="8" class="text-muted p-3">No schools match the filters.</td></tr>';
        } else {
            head.innerHTML = `<tr><th>${levelName[level]}</th><th class="text-end">Schools</th><th class="text-end">Inspected</th><th class="text-end d-none d-sm-table-cell">In progress</th><th class="text-end">Pending</th><th class="text-end">Coverage</th>${showRating ? '<th class="text-end">Avg rating</th>' : ''}
                <th class="text-end d-none d-md-table-cell">District officers</th><th class="text-end d-none d-md-table-cell">Block officers</th><th class="text-end d-none d-md-table-cell">Cluster officers</th><th class="text-end">Officers</th></tr>`;
            body.innerHTML = rows.length ? rows.map((r) => `<tr data-row="${r.id}" class="cursor-pointer">
                <td><a href="#" data-drill="${r.id}">${esc(r.label ?? r.name)}</a></td><td class="text-end">${fmt(r.schools)}</td><td class="text-end">${fmt(r.inspected)}</td>
                <td class="text-end d-none d-sm-table-cell">${fmt(r.in_progress)}</td><td class="text-end">${fmt(r.pending)}</td>
                <td class="text-end"><span class="cov-pill ${coverageClass(r.coverage)}">${r.coverage} %</span></td>
                ${showRating ? `<td class="text-end">${r.rating ? ratingPill(r.rating) : '—'}</td>` : ''}
                <td class="text-end d-none d-md-table-cell">${fmt(r.officers.district)}</td><td class="text-end d-none d-md-table-cell">${fmt(r.officers.block)}</td>
                <td class="text-end d-none d-md-table-cell">${fmt(r.officers.cluster)}</td><td class="text-end fw-semibold">${fmt(r.officers.total)}</td></tr>`).join('')
                : '<tr><td colspan="11" class="text-muted p-3">No areas with schools here.</td></tr>';
        }
    }

    /* ---- the District / Block / Cluster selects follow the current drill-down ---- */
    function fill(select, rows, value, placeholder) {
        select.innerHTML = `<option value="">${placeholder}</option>` + rows.map((r) => `<option value="${r.id}">${esc(r.label ?? r.name)}</option>`).join('');
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
        ['fFrom', 'fTo'].forEach((id) => $(id).dispatchEvent(new Event('fp-sync')));
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
        picked = { id: +b.dataset.school, name: b.querySelector('.fw-semibold')?.textContent || '' };
        load('school', +b.dataset.cluster, +b.dataset.school);
    });

    /* ---- V6 Module 9: colour school pins by status or by rating band ---- */
    document.querySelectorAll('input[name="colourBy"]').forEach((r) => r.addEventListener('change', (e) => {
        colourBy = e.target.value;
        document.querySelectorAll('[data-legend]').forEach((l) => l.classList.toggle('d-none', l.dataset.legend !== colourBy));
        if (current.level === 'school') renderSchools(lastRows);
    }));

    $('fOffices')?.addEventListener('change', renderOffices);

    /* ---- drill-down from popups and table rows ---- */
    const drill = (id) => (current.level === 'school' ? null : load(nextLevel[current.level], id));
    map.on('popupopen', (e) => {
        e.popup.getElement()?.querySelector('[data-drill]')?.addEventListener('click', (ev) => drill(+ev.currentTarget.dataset.drill));
        bindDirectionsPanel(e.popup.getElement()?.querySelector('.si-dir'), directions);
    });
    $('mapTableBody').addEventListener('click', (e) => {
        const a = e.target.closest('[data-drill]');
        if (a) { e.preventDefault(); drill(+a.dataset.drill); return; }
        const tr = e.target.closest('[data-row]');
        const m = tr && markers.get(+tr.dataset.row);
        if (!m) return;
        document.getElementById('mapCard')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); // 26-11-2026: from the list back to the map
        current.level === 'school' ? schoolLayer.zoomToShowLayer(m, () => m.openPopup()) : m.openPopup();
    });

    /* ---- 26-11-2026: what is selected, as chips above the map; × clears one, Clear all resets ---- */
    let crumbsNow = [];
    let picked = null; // { id, name } of the school picked in the search
    const order = ['district', 'block', 'cluster', 'school'];
    const areaKind = { block: 'District', cluster: 'Block', school: 'Cluster' }; // crumb level -> the area it names
    const longDay = (v) => (v ? new Date(`${v}T00:00:00`).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '…');
    function renderChips() {
        const box = $('mapChips');
        if (!box) return;
        const chips = [];
        // the area path (from the map data's breadcrumb): crumb i names the area whose list is shown
        crumbsNow.forEach((c, i) => {
            if (!i || !areaKind[c.level]) return;
            const back = crumbsNow[i - 1];
            const locked = order.indexOf(back.level) < order.indexOf(start.level); // above the officer's own area
            chips.push({ cls: 'area', label: areaKind[c.level], value: c.name, clear: locked ? null : () => load(back.level, back.parent) });
        });
        if (picked) chips.push({ cls: 'school', label: 'School', value: picked.name, clear: () => { picked = null; $('fSchool').value = ''; renderChips(); } });
        const off = $('fOfficer');
        if (off.value) chips.push({ cls: 'officer', label: 'Officer', value: off.selectedOptions[0]?.textContent.trim(), clear: () => { off.value = ''; off.dispatchEvent(new Event('change')); } });
        const changedDates = $('fFrom').value !== $('fFrom').defaultValue || $('fTo').value !== $('fTo').defaultValue;
        chips.push({ cls: 'period', label: 'Period', value: `${longDay($('fFrom').value)} – ${longDay($('fTo').value)}`,
            clear: changedDates ? () => { $('fFrom').value = $('fFrom').defaultValue; $('fTo').value = $('fTo').defaultValue; ['fFrom', 'fTo'].forEach((id) => $(id).dispatchEvent(new Event('fp-sync'))); load(current.level, current.parent); } : null });
        if (colourBy === 'rating') chips.push({ cls: 'rating', label: 'Colour', value: 'Rating', clear: () => { const r = $('colourStatus'); if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); } } });
        const anything = chips.some((c) => c.clear) || current.level !== start.level || current.parent !== start.parent;
        box.innerHTML = '<span class="map-chips-label"><i class="bi bi-funnel"></i>Showing</span>'
            + chips.map((c, i) => `<span class="map-chip is-${c.cls}"><span class="map-chip-k">${esc(c.label)}</span><b>${esc(c.value || '')}</b>${c.clear
                ? `<button type="button" class="map-chip-x" data-chip="${i}" aria-label="Clear ${esc(c.label)}" title="Clear"><i class="bi bi-x-lg"></i></button>` : ''}</span>`).join('')
            + (anything ? '<button type="button" class="btn btn-link btn-sm map-chips-clear" data-chip-all><i class="bi bi-arrow-counterclockwise"></i>Clear all</button>' : '');
        box.onclick = (e) => {
            const x = e.target.closest('[data-chip]');
            if (x) { chips[+x.dataset.chip]?.clear?.(); return; }
            if (e.target.closest('[data-chip-all]')) {
                const r = $('colourStatus');
                if (r && colourBy === 'rating') { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
                picked = null;
                $('fReset').click();
            }
        };
    }
    ['fOfficer', 'fFrom', 'fTo'].forEach((id) => $(id).addEventListener('change', renderChips));
    document.querySelectorAll('input[name="colourBy"]').forEach((r) => r.addEventListener('change', renderChips));

    /* ---- 26-11-2026: Full screen (filters and map together); a fixed overlay where the browser has no Fullscreen API ---- */
    const layout = $('mapLayout');
    const fullBtn = document.querySelector('[data-map-full]');
    const isFull = () => document.fullscreenElement === layout || layout.classList.contains('map-full-fixed');
    const paintFull = () => {
        const on = isFull();
        layout.classList.toggle('is-full', on);
        document.body.classList.toggle('map-full-open', on);
        if (fullBtn) {
            fullBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            fullBtn.innerHTML = on ? '<i class="bi bi-fullscreen-exit"></i><span>Exit full screen</span>' : '<i class="bi bi-arrows-fullscreen"></i><span>Full screen</span>';
        }
        setTimeout(() => map.invalidateSize(), 120);
    };
    fullBtn?.addEventListener('click', async () => {
        if (isFull()) {
            if (document.fullscreenElement) await document.exitFullscreen().catch(() => {});
            layout.classList.remove('map-full-fixed');
        } else if (layout.requestFullscreen) {
            try { await layout.requestFullscreen(); } catch { layout.classList.add('map-full-fixed'); }
        } else {
            layout.classList.add('map-full-fixed');
        }
        paintFull();
    });
    document.addEventListener('fullscreenchange', paintFull);
    // 28-11-2026: in Full screen the layout never scrolls on wide screens (the map focusing itself after a zoom used to
    // scroll the header with Exit full screen out of sight); phones keep their own scrolling for the filter panel
    layout.addEventListener('scroll', () => {
        if (isFull() && window.matchMedia('(min-width: 992px)').matches && layout.scrollTop) layout.scrollTop = 0;
    }, { passive: true });
    // the map's height follows the chips / key bar above it (they wrap to more lines when a level is picked)
    if (window.ResizeObserver) new ResizeObserver(() => { if (isFull()) map.invalidateSize(); }).observe($('map'));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && layout.classList.contains('map-full-fixed')) { layout.classList.remove('map-full-fixed'); paintFull(); } });
    window.addEventListener('resize', () => map.invalidateSize());

    (async () => {
        // boundaries are optional: the map works without them. The state outline is drawn first; the district
        // polygons load alongside it only when the map opens state-wide (otherwise on first use, see renderAreas)
        if (start.level === 'district') loadDistricts();
        if (cfg.geojson?.state) {
            try { geo.state = await getJson(cfg.geojson.state); } catch (e) { geo.state = null; }
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
