// Review & Submit page: expand / collapse all steps, photo viewer, confirm + submit with location.
// Loaded as a Vite module (after app.js has set window.bootstrap). Inline scripts ran before
// bootstrap was available, which broke "Expand all" whenever photos were present (Modification V3 item 8).
import { Collapse, Modal } from 'bootstrap';
import { offlineStore, syncAll } from './offline-queue';

/* ---------------- Expand / collapse all steps ---------------- */
const acc = document.getElementById('reviewAcc');
const toggle = document.querySelector('[data-review-toggle-all]');
if (acc && toggle) {
    const panels = () => [...acc.querySelectorAll(':scope .collapse')];
    const paintToggle = (allOpen) => {
        toggle.innerHTML = allOpen ? '<i class="bi bi-arrows-collapse"></i><span>Collapse all</span>' : '<i class="bi bi-arrows-expand"></i><span>Expand all</span>';
        toggle.setAttribute('aria-expanded', allOpen ? 'true' : 'false');
        toggle.classList.toggle('is-open', allOpen);
    };
    // each step: open accent + "Show" / "Hide" text next to its round caret
    const paintStep = (panel, open) => {
        if (!panel.id?.startsWith('rs-')) return; // only the step panels
        const step = panel.closest('.review-step');
        step?.classList.toggle('is-open', open);
        const t = step?.querySelector('.rc-text');
        if (t) t.textContent = open ? t.dataset.hide : t.dataset.show;
    };
    const label = () => {
        const all = panels();
        const open = all.filter((p) => p.classList.contains('show') || p.classList.contains('collapsing')).length;
        paintToggle(open === all.length);
    };
    toggle.addEventListener('click', () => {
        const all = panels();
        const expand = all.some((p) => !p.classList.contains('show'));
        all.forEach((p) => Collapse.getOrCreateInstance(p, { toggle: false })[expand ? 'show' : 'hide']());
        paintToggle(expand);
    });
    // keep the label right when single steps are opened or closed by hand
    acc.addEventListener('show.bs.collapse', (e) => paintStep(e.target, true));
    acc.addEventListener('hide.bs.collapse', (e) => paintStep(e.target, false));
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
    let can = f.dataset.canSubmit === '1';
    let waiting = false; // changes or photos still on this device (T2)
    box?.addEventListener('change', () => {
        if (waiting) return;
        btn.disabled = !(can && box.checked);
        box.closest('.review-confirm')?.classList.toggle('is-checked', box.checked); // :has() fallback
        if (can) hint.textContent = box.checked ? 'Ready. Your current location is recorded on submission.' : 'Tick the confirmation above to enable Submit.';
    });
    // Working without network (T2): anything still kept on this device must reach the server before Submit.
    const insp = f.dataset.inspectionKey;
    const lockForSync = (c, failed = false) => {
        const parts = [];
        if (c.ops) parts.push(`${c.ops} change${c.ops === 1 ? '' : 's'}`);
        if (c.photos) parts.push(`${c.photos} photo${c.photos === 1 ? '' : 's'}`);
        waiting = true;
        btn.disabled = true;
        f.classList.add('is-locked');
        hint.innerHTML = `<i class="bi bi-phone"></i> ${parts.join(' and ')} saved on this device still to send. `
            + (!navigator.onLine ? 'Connect to the network to submit; they will be sent automatically.'
                : failed ? 'The server could not be reached; trying again every 20 seconds.' : 'Sending now…');
    };
    const checkDevice = async () => {
        if (!insp || !(await offlineStore.available())) return;
        const c = await offlineStore.counts(insp);
        if (!c.ops && !c.photos) return;
        lockForSync(c);
        if (!navigator.onLine) return;
        const r = await syncAll(insp, { evidenceUrl: f.dataset.evidenceUrl });
        if (!r.left) {
            // the server now has everything: reload so the checklist and Submit reflect it
            window.toast('Saved changes sent. Refreshing…', 'success');
            setTimeout(() => window.location.reload(), 800);
        } else {
            lockForSync(await offlineStore.counts(insp), true);
        }
    };
    checkDevice();
    setInterval(() => { if (waiting) checkDevice(); }, 20000);
    window.addEventListener('online', checkDevice);

    f.addEventListener('submit', (e) => {
        if (f.dataset.go) return;
        e.preventDefault();
        if (waiting) { window.toast('Some changes are still on this device. Connect to the network to send them first.', 'warning'); return; }
        if (!box.checked) { window.toast('Please tick the confirmation first.', 'warning'); return; }
        window.SI_BUSY.setBusy(btn, 'Getting location…');
        const go = () => { f.dataset.go = 1; btn.querySelector('.btn-busy-text').textContent = 'Submitting inspection…'; window.SI_LOADER?.show('Submitting inspection…'); f.submit(); };
        if (!navigator.geolocation) { go(); return; }
        navigator.geolocation.getCurrentPosition((p) => {
            f.lat.value = p.coords.latitude;
            f.lng.value = p.coords.longitude;
            f.accuracy.value = Math.round(p.coords.accuracy);
            go();
        }, go, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
    });
}

/* ---------------- Report page: highlight the section in view in the jump bar ---------------- */
const rpNav = document.querySelector('.rp-nav');
if (rpNav && 'IntersectionObserver' in window) {
    const links = new Map([...rpNav.querySelectorAll('a[href^="#rp-step-"]')].map((a) => [a.getAttribute('href').slice(1), a]));
    const io = new IntersectionObserver((entries) => {
        entries.filter((e) => e.isIntersecting).forEach((e) => {
            links.forEach((a) => a.classList.remove('is-active'));
            links.get(e.target.id)?.classList.add('is-active');
        });
    }, { rootMargin: '-35% 0px -55% 0px' });
    links.forEach((_, id) => { const el = document.getElementById(id); if (el) io.observe(el); });
}
