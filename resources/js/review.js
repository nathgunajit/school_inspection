// Review & Submit page: expand / collapse all steps, photo viewer, confirm + submit with location.
// Loaded as a Vite module (after app.js has set window.bootstrap). Inline scripts ran before
// bootstrap was available, which broke "Expand all" whenever photos were present (Modification V3 item 8).
import { Collapse, Modal } from 'bootstrap';

/* ---------------- Expand / collapse all steps ---------------- */
const acc = document.getElementById('reviewAcc');
const toggle = document.querySelector('[data-review-toggle-all]');
if (acc && toggle) {
    const panels = () => [...acc.querySelectorAll(':scope .collapse')];
    const label = () => {
        const all = panels();
        const open = all.filter((p) => p.classList.contains('show') || p.classList.contains('collapsing')).length;
        toggle.textContent = open === all.length ? 'Collapse all' : 'Expand all';
        toggle.setAttribute('aria-expanded', open === all.length ? 'true' : 'false');
    };
    toggle.addEventListener('click', () => {
        const all = panels();
        const expand = all.some((p) => !p.classList.contains('show'));
        all.forEach((p) => Collapse.getOrCreateInstance(p, { toggle: false })[expand ? 'show' : 'hide']());
        toggle.textContent = expand ? 'Collapse all' : 'Expand all';
    });
    // keep the label right when single steps are opened or closed by hand
    acc.addEventListener('shown.bs.collapse', label);
    acc.addEventListener('hidden.bs.collapse', label);
    label();
}

/* ---------------- Photo viewer: previous / next, arrow keys, swipe ---------------- */
const tiles = [...document.querySelectorAll('.rv-photo')];
const pv = document.getElementById('photoViewer');
if (tiles.length && pv) {
    const modal = Modal.getOrCreateInstance(pv);
    const img = document.getElementById('pv-img');
    let at = 0;
    const show = (i) => {
        at = (i + tiles.length) % tiles.length;
        const t = tiles[at];
        img.src = t.dataset.photo;
        img.alt = t.dataset.cap;
        document.getElementById('pv-cap').textContent = t.dataset.cap;
        document.getElementById('pv-meta').textContent = t.dataset.meta || '';
        document.getElementById('pv-pos').textContent = `${at + 1} / ${tiles.length}`;
        pv.querySelectorAll('.pv-nav').forEach((b) => b.classList.toggle('d-none', tiles.length < 2));
    };
    tiles.forEach((t, i) => t.addEventListener('click', () => { show(i); modal.show(); }));
    pv.querySelectorAll('[data-pv]').forEach((b) => b.addEventListener('click', () => show(at + Number(b.dataset.pv))));
    pv.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft') show(at - 1);
        if (e.key === 'ArrowRight') show(at + 1);
    });
    let x0 = null;
    pv.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
    pv.addEventListener('touchend', (e) => {
        if (x0 === null) return;
        const dx = e.changedTouches[0].clientX - x0;
        if (Math.abs(dx) > 50) show(at + (dx < 0 ? 1 : -1));
        x0 = null;
    });
}

/* ---------------- Confirm + submit (with current location) ---------------- */
const f = document.getElementById('submit-form');
if (f) {
    const box = document.getElementById('confirm');
    const btn = document.getElementById('submit-btn');
    const hint = document.getElementById('submit-hint');
    const can = f.dataset.canSubmit === '1';
    box?.addEventListener('change', () => {
        btn.disabled = !(can && box.checked);
        box.closest('.review-confirm')?.classList.toggle('is-checked', box.checked); // :has() fallback
        if (can) hint.textContent = box.checked ? 'Ready. Your current location is recorded on submission.' : 'Tick the confirmation above to enable Submit.';
    });
    f.addEventListener('submit', (e) => {
        if (f.dataset.go) return;
        e.preventDefault();
        if (!box.checked) { window.toast('Please tick the confirmation first.', 'warning'); return; }
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Getting location…';
        const go = () => { f.dataset.go = 1; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Submitting…'; f.submit(); };
        if (!navigator.geolocation) { go(); return; }
        navigator.geolocation.getCurrentPosition((p) => {
            f.lat.value = p.coords.latitude;
            f.lng.value = p.coords.longitude;
            f.accuracy.value = Math.round(p.coords.accuracy);
            go();
        }, go, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
    });
}
