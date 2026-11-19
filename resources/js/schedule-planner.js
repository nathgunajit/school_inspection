/*
 * Map-based inspection planner (New Module 2).
 * Schools come from /schedules/plan/schools; they are dragged (SortableJS, works with touch) from
 * "Available schools" into the "Day plan", reordered there, or added from the map popup.
 * Distances are straight-line × road factor; "Optimise route" = nearest neighbour + 2-opt
 * (the same algorithm as App\Support\RouteOptimizer, which recomputes on save).
 */
import L from './leaflet-global';
import 'leaflet/dist/leaflet.css';
import Sortable from 'sortablejs';
import { createMap, schoolIcon, esc, fitTo, drawDistrictOutline } from './map-common';

const root = document.getElementById('planner');
if (root) {
    const cfg = JSON.parse(root.dataset.config);
    const readonly = root.dataset.readonly === '1';
    const map = createMap(document.getElementById('planMap'), cfg);
    // V6 Module 7: the officer's district outline; it frames the map until schools are drawn
    drawDistrictOutline(map, cfg.outline, { fit: !cfg.planned.length }).then((o) => o?.layer.bringToBack());
    const layer = L.layerGroup().addTo(map);
    const routeLine = L.polyline([], { color: '#1565c0', weight: 3, opacity: 0.8, dashArray: '6 6' }).addTo(map);
    let start = cfg.start; // [lat, lng] or null
    let startMarker = null;
    const $ = (id) => document.getElementById(id);
    const schools = new Map(); // id → school
    let plan = cfg.planned.map((s) => s.id);
    cfg.planned.forEach((s) => schools.set(s.id, { ...s, blocked: null, done_month: false }));
    const done = new Map(cfg.planned.map((s) => [s.id, s.status]));
    let fitted = false;

    /* ---------- geometry ---------- */
    const rad = (d) => (d * Math.PI) / 180;
    function metres(a, b) {
        const dLat = rad(b[0] - a[0]);
        const dLng = rad(b[1] - a[1]);
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a[0])) * Math.cos(rad(b[0])) * Math.sin(dLng / 2) ** 2;
        return Math.round(6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h)));
    }
    const pt = (s) => (s && s.lat !== null && s.lng !== null ? [s.lat, s.lng] : null);
    const roadKm = (a, b) => Math.round((metres(a, b) / 1000) * cfg.roadFactor * 100) / 100;

    function optimise(ids) {
        const located = ids.filter((id) => pt(schools.get(id)));
        const rest = ids.filter((id) => !pt(schools.get(id)));
        if (located.length <= 1) return [...located, ...rest];
        const left = [...located];
        const route = [];
        let cur = start || pt(schools.get(left[0]));
        while (left.length) {
            let best = 0;
            let bestD = Infinity;
            left.forEach((id, i) => { const d = metres(cur, pt(schools.get(id))); if (d < bestD) { bestD = d; best = i; } });
            const [id] = left.splice(best, 1);
            route.push(id);
            cur = pt(schools.get(id));
        }
        const p = (i) => (i < 0 ? start : pt(schools.get(route[i])));
        let improved = true;
        for (let guard = 0; improved && guard < 50; guard++) {
            improved = false;
            for (let i = 0; i < route.length - 1; i++) {
                for (let j = i + 1; j < route.length; j++) {
                    const a = p(i - 1); const b = p(i); const c = p(j); const e = j + 1 < route.length ? p(j + 1) : null;
                    const before = (a ? metres(a, b) : 0) + (e ? metres(c, e) : 0);
                    const after = (a ? metres(a, c) : 0) + (e ? metres(b, e) : 0);
                    if (after + 1 < before) {
                        route.splice(i, j - i + 1, ...route.slice(i, j + 1).reverse());
                        improved = true;
                    }
                }
            }
        }
        return [...route, ...rest];
    }

    /* ---------- rendering ---------- */
    function legs() {
        const [h, m] = cfg.dayStart.split(':').map(Number);
        let clock = h * 60 + m;
        let prev = start;
        let km = 0;
        let minutes = 0;
        return plan.map((id) => {
            const here = pt(schools.get(id));
            const d = prev && here ? roadKm(prev, here) : null;
            const t = d !== null ? Math.ceil((d / cfg.speed) * 60) : null;
            clock += t || 0;
            const eta = `${String(Math.floor(clock / 60) % 24).padStart(2, '0')}:${String(clock % 60).padStart(2, '0')}`;
            clock += cfg.visit;
            km += d || 0;
            minutes += (t || 0) + cfg.visit;
            prev = here || prev;
            return { id, d, t, eta, km, minutes };
        });
    }

    function renderPlan() {
        const list = $('planList');
        const L2 = legs();
        list.innerHTML = L2.map(({ id, d, t, eta }, i) => {
            const s = schools.get(id);
            const st = done.get(id);
            return `<li class="list-group-item plan-item" data-id="${id}">
                <span class="plan-no">${i + 1}</span>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold text-truncate">${esc(s.name)}</div>
                    <div class="text-xs text-muted">${esc(s.udise)} · arrive ${eta}${d !== null ? ` · ${d.toFixed(1)} km, ~${t} min` : (pt(s) ? '' : ' · no GPS')}${st && st !== 'planned' ? ` · <b>${esc(st)}</b>` : ''}</div>
                </div>
                ${readonly ? '' : `<span class="drag-handle" title="Drag to reorder" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>
                <button type="button" class="btn btn-sm btn-light btn-icon" data-remove="${id}" aria-label="Remove ${esc(s.name)}"><i class="bi bi-x-lg"></i></button>`}
            </li>`;
        }).join('');
        $('planEmpty').classList.toggle('d-none', plan.length > 0);
        $('planCount').textContent = `${plan.length} / ${cfg.max}`;
        $('planCount').className = 'badge ' + (plan.length > cfg.max ? 'text-bg-danger' : 'text-bg-light');
        const last = L2[L2.length - 1];
        $('planTotals').innerHTML = last
            ? `<span><i class="bi bi-geo-alt"></i> ${plan.length} school(s)</span><span><i class="bi bi-signpost"></i> ~${last.km.toFixed(1)} km</span>
               <span><i class="bi bi-clock"></i> ~${Math.floor(last.minutes / 60)} h ${last.minutes % 60} min</span><span><i class="bi bi-flag"></i> back by ~${addMin(last.eta, cfg.visit)}</span>`
            : '';
        renderMap();
        renderAvailable();
    }

    function addMin(hhmm, m) {
        const [h, mm] = hhmm.split(':').map(Number);
        const t = h * 60 + mm + m;
        return `${String(Math.floor(t / 60) % 24).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
    }

    function statusOf(s) {
        if (plan.includes(s.id)) return 'planned';
        if (s.blocked) return 'pending';
        if (s.done_month) return 'inspected';
        return 'available';
    }

    function popup(s) {
        const st = statusOf(s);
        const btn = readonly ? ''
            : st === 'planned' ? `<button type="button" class="btn btn-light btn-sm w-100 mt-2" data-pop-remove="${s.id}">Remove from plan</button>`
                : s.blocked ? `<div class="text-danger text-xs mt-1">${esc(s.blocked)}</div>`
                    : `<button type="button" class="btn btn-primary btn-sm w-100 mt-2" data-pop-add="${s.id}">Add to plan</button>`;
        return `<div class="map-pop"><div class="fw-semibold">${esc(s.name)}</div><div class="text-xs text-muted">UDISE ${esc(s.udise)}${s.cluster ? ' · ' + esc(s.cluster) : ''}</div>
            <div class="text-xs">${s.last ? 'Last inspected ' + esc(s.last) : 'Never inspected'}</div>${btn}</div>`;
    }

    function renderMap() {
        layer.clearLayers();
        const pts = [];
        schools.forEach((s) => {
            const p = pt(s);
            if (!p) return;
            const st = statusOf(s);
            const n = st === 'planned' ? plan.indexOf(s.id) + 1 : '';
            L.marker(p, { icon: schoolIcon(st, n), title: s.name, zIndexOffset: st === 'planned' ? 1000 : 0 }).bindPopup(popup(s)).addTo(layer);
            pts.push(p);
        });
        routeLine.setLatLngs([start, ...plan.map((id) => pt(schools.get(id)))].filter(Boolean));
        if (start && !startMarker) {
            startMarker = L.circleMarker(start, { radius: 7, color: '#fff', weight: 2, fillColor: '#0f172a', fillOpacity: 1 }).bindTooltip('Start').addTo(map);
        } else if (start) startMarker.setLatLng(start);
        if (!fitted && pts.length) { fitTo(map, pts, cfg, 14); fitted = true; }
    }

    function renderAvailable() {
        const q = $('availSearch').value.trim().toLowerCase();
        const from = start || (plan.length ? pt(schools.get(plan[plan.length - 1])) : null);
        const rows = [...schools.values()]
            .filter((s) => !plan.includes(s.id))
            .filter((s) => !q || s.name.toLowerCase().includes(q) || String(s.udise).startsWith(q))
            .map((s) => ({ s, d: from && pt(s) ? roadKm(from, pt(s)) : Infinity }))
            .sort((a, b) => a.d - b.d || a.s.name.localeCompare(b.s.name))
            .slice(0, 300);
        $('availList').innerHTML = rows.length ? rows.map(({ s, d }) => `
            <div class="list-group-item avail-item ${s.blocked ? 'is-blocked' : ''}" data-id="${s.id}" ${s.blocked ? 'data-blocked="1"' : ''}>
                <span class="si-pin-mini st-${statusOf(s)}"></span>
                <div class="flex-grow-1 min-w-0">
                    <div class="text-truncate">${esc(s.name)}</div>
                    <div class="text-xs text-muted">${esc(s.udise)}${d !== Infinity ? ` · ${d.toFixed(1)} km` : pt(s) ? '' : ' · no GPS'}${s.blocked ? ' · <span class="text-danger">' + esc(s.blocked) + '</span>' : s.done_month ? ' · inspected this month' : ''}</div>
                </div>
                ${readonly || s.blocked ? '' : `<button type="button" class="btn btn-sm btn-soft-primary btn-icon" data-add="${s.id}" aria-label="Add ${esc(s.name)}"><i class="bi bi-plus-lg"></i></button>`}
            </div>`).join('')
            : `<div class="list-group-item text-muted text-sm">${cfg.needsBlock && !$('pBlock')?.value ? 'Choose a block to see its schools.' : 'No more schools.'}</div>`;
    }

    function add(id, at = plan.length) {
        const s = schools.get(id);
        if (!s || s.blocked || plan.includes(id) || readonly) return;
        if (plan.length >= cfg.max) { window.toast?.(`At most ${cfg.max} schools per day.`, 'warning'); renderPlan(); return; }
        plan.splice(at, 0, id);
        map.closePopup();
        renderPlan();
    }
    function remove(id) {
        if (done.get(id) === 'inspected') { window.toast?.('This school is already inspected on this plan.', 'warning'); return; }
        plan = plan.filter((x) => x !== id);
        map.closePopup();
        renderPlan();
    }

    /* ---------- drag and drop ---------- */
    if (!readonly) {
        Sortable.create($('planList'), {
            group: { name: 'plan', pull: false, put: true },
            animation: 150, ghostClass: 'drag-ghost', delay: 150, delayOnTouchOnly: true,
            onAdd: (e) => { const id = +e.item.dataset.id; e.item.remove(); add(id, e.newIndex); },
            onUpdate: () => { plan = [...$('planList').children].map((li) => +li.dataset.id); renderPlan(); },
        });
        Sortable.create($('availList'), {
            group: { name: 'plan', pull: 'clone', put: false }, sort: false, animation: 150, delay: 150, delayOnTouchOnly: true,
            filter: '.is-blocked, button', preventOnFilter: false, ghostClass: 'drag-ghost',
        });
        // the empty-plan hint is also a drop target
        Sortable.create($('planEmpty'), { group: { name: 'plan', pull: false, put: true }, onAdd: (e) => { const id = +e.item.dataset.id; e.item.remove(); add(id); } });
    }

    document.addEventListener('click', (e) => {
        const t = e.target.closest('[data-add],[data-remove],[data-pop-add],[data-pop-remove]');
        if (!t) return;
        if (t.dataset.add) add(+t.dataset.add);
        if (t.dataset.popAdd) add(+t.dataset.popAdd);
        if (t.dataset.remove) remove(+t.dataset.remove);
        if (t.dataset.popRemove) remove(+t.dataset.popRemove);
    });
    $('availSearch').addEventListener('input', renderAvailable);
    $('btnOptimise').addEventListener('click', () => { if (!readonly) { plan = optimise(plan); renderPlan(); window.toast?.('Visit order optimised.', 'info'); } });
    $('btnMyLocation').addEventListener('click', () => {
        if (!navigator.geolocation) { window.toast?.('Location is not available on this device.', 'warning'); return; }
        navigator.geolocation.getCurrentPosition(
            (p) => { start = [p.coords.latitude, p.coords.longitude]; renderPlan(); window.toast?.('The route now starts from your location.', 'info'); },
            () => window.toast?.('Could not read your location.', 'warning'),
            { enableHighAccuracy: true, timeout: 10000 },
        );
    });

    /* ---------- loading schools ---------- */
    async function loadSchools() {
        if (cfg.needsBlock && !$('pBlock').value) { renderPlan(); return; }
        $('mapLoading').classList.remove('d-none');
        try {
            const qs = new URLSearchParams({ officer: cfg.officer, date: cfg.date, ...($('pBlock')?.value ? { block: $('pBlock').value } : {}) });
            const r = await fetch(`${cfg.urls.schools}?${qs}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || 'Could not load the schools.');
            const data = await r.json();
            // keep the planned schools (they may be in another block), replace the rest
            [...schools.keys()].forEach((id) => { if (!plan.includes(id)) schools.delete(id); });
            data.rows.forEach((s) => schools.set(s.id, s));
            fitted = false;
            renderPlan();
        } catch (err) {
            window.toast?.(err.message, 'danger');
        } finally {
            $('mapLoading').classList.add('d-none');
        }
    }
    $('pBlock')?.addEventListener('change', loadSchools);

    /* ---------- saving ---------- */
    document.querySelectorAll('[data-action]').forEach((b) => b.addEventListener('click', async () => {
        if (readonly) return;
        const errors = $('planErrors');
        errors.classList.add('d-none');
        if (!plan.length) { errors.textContent = 'Add at least one school to the plan.'; errors.classList.remove('d-none'); return; }
        document.querySelectorAll('[data-action]').forEach((x) => { x.disabled = true; });
        try {
            const body = {
                officer_id: cfg.officer, date: cfg.date, school_ids: plan, action: b.dataset.action, notes: $('planNotes').value,
                ...(start && cfg.start !== start ? { start_lat: start[0], start_lng: start[1] } : {}),
            };
            const r = await window.axios.post(cfg.urls.store, body);
            window.location = r.data.redirect;
        } catch (err) {
            const e = err.response?.data;
            const msgs = e?.errors ? Object.values(e.errors).flat() : [e?.message || 'Could not save the plan.'];
            errors.innerHTML = msgs.map((m) => `<div>• ${esc(m)}</div>`).join('');
            errors.classList.remove('d-none');
            document.querySelectorAll('[data-action]').forEach((x) => { x.disabled = false; });
        }
    }));

    renderPlan();
    loadSchools();
}
