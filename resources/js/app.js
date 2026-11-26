// 25-11-2026, faster loading: only the Bootstrap plugins the app uses (bootstrap-lite.js)
import { Alert, Button, Collapse, Dropdown, Modal, Offcanvas, Tab, Toast, Tooltip } from './bootstrap-lite';
import axios from 'axios';
import { escapeHtml } from './util';
import './sidebar'; // 26-11-2026: phone menu + desktop icon rail, set up first and only once
import './button-busy';
import './download-busy'; // 26-11-2026: PDF / Excel links busy until the file arrives
import './password-encrypt';
import './mobile-input';
import './date-picker';
import './rating-settings';
import './imports';
import './network-status';
import './filter-partial';
import './school-suggest'; // V14: school name / UDISE suggestions
import './notice-badge'; // 25-11-2026: live Notices & Orders unread counters
import './alert-badge'; // V15 (26-11-2026): the alerts bell
import './holiday-day';
import './downloads'; // 26-11-2026: background downloads (Downloads page)
import './session-guard'; // 25-11-2026: one user per browser // 25-11-2026: calendar day popup (see / add / remove holidays)

const bootstrap = { Alert, Button, Collapse, Dropdown, Modal, Offcanvas, Tab, Toast, Tooltip };
window.bootstrap = bootstrap;
window.axios = axios;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';
const token = document.querySelector('meta[name="csrf-token"]')?.content;
if (token) axios.defaults.headers.common['X-CSRF-TOKEN'] = token;

/* ---------- Toasts ---------- */
window.toast = function (message, type = 'success') {
    let stack = document.querySelector('.toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'toast-stack';
        document.body.appendChild(stack);
    }
    const icons = { success: 'check-circle-fill', danger: 'exclamation-octagon-fill', warning: 'exclamation-triangle-fill', info: 'info-circle-fill' };
    const el = document.createElement('div');
    el.className = `toast align-items-center border-0 shadow mb-2 text-bg-${type}`;
    el.setAttribute('role', 'status');
    el.innerHTML = `<div class="d-flex"><div class="toast-body d-flex gap-2 align-items-center">
        <i class="bi bi-${icons[type] || icons.info}"></i><span></span></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>`;
    el.querySelector('span').textContent = message;
    stack.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 3500 });
    el.addEventListener('hidden.bs.toast', () => el.remove());
    t.show();
};

document.addEventListener('DOMContentLoaded', () => {
    /* ---------- "Other" choice reveals a text box (Modification V7: designation) ---------- */
    // <select data-other-value="<id of Other>" data-other-target="#wrap">; the input in #wrap is required only when shown
    document.querySelectorAll('select[data-other-value]').forEach((sel) => {
        const wrap = document.querySelector(sel.dataset.otherTarget);
        const input = wrap?.querySelector('input, textarea');
        if (!wrap || !input) return;
        const sync = (focus) => {
            const on = sel.value !== '' && sel.value === sel.dataset.otherValue;
            wrap.classList.toggle('d-none', !on);
            input.required = on;
            input.disabled = !on; // not sent when another designation is chosen
            if (on && focus) input.focus();
        };
        sel.addEventListener('change', () => sync(true));
        sync(false);
    });

    /* Sidebar (phone menu and desktop icon rail): sidebar.js since 26-11-2026 */

    /* ---------- Flash messages -> toasts ---------- */
    document.querySelectorAll('[data-flash]').forEach((f) => toast(f.dataset.flash, f.dataset.type || 'success'));

    /* ---------- V12 item 7: an Edit window whose save failed opens again with its errors ---------- */
    const reopen = document.querySelector('.modal[data-reopen]');
    if (reopen) bootstrap.Modal.getOrCreateInstance(reopen).show();

    /* ---------- Tooltips ---------- */
    // 26-11-2026: delegated, so tooltips in sections swapped in by filter-partial.js work too
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => bootstrap.Tooltip.getOrCreateInstance(el));
    ['mouseover', 'focusin'].forEach((ev) => document.addEventListener(ev, (e) => {
        const el = e.target.closest?.('[data-bs-toggle="tooltip"]');
        if (el && !bootstrap.Tooltip.getInstance(el)) bootstrap.Tooltip.getOrCreateInstance(el).show();
    }));

    /* ---------- Confirm before submit ---------- */
    // delegated (capture), so rows swapped in by filter-partial.js still ask
    document.addEventListener('submit', (e) => {
        const form = e.target.closest?.('form[data-confirm]');
        if (form && !confirm(form.dataset.confirm)) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);

    /* ---------- Location cascade: select[data-cascade-child="#id"][data-cascade-url="/api/..?parent="] ---------- */
    document.querySelectorAll('select[data-cascade-child]').forEach((parent) => {
        parent.addEventListener('change', () => cascade(parent));
    });

    /* ---------- Auto-submit filter selects ---------- */
    // delegated, so selects swapped in by filter-partial.js keep working; requestSubmit() fires the submit
    // event, which filter-partial.js turns into a section load. data-clears="block_id,cluster_id" empties the
    // lower selects first (a new district / block).
    document.addEventListener('change', (e) => {
        const el = e.target.closest?.('[data-autosubmit]');
        if (!el?.form) return;
        (el.dataset.clears || '').split(',').filter(Boolean).forEach((n) => { const s = el.form.elements[n]; if (s) s.value = ''; });
        if (el.form.requestSubmit) el.form.requestSubmit(); else el.form.submit();
    });
});

export async function cascade(parent) {
    const child = document.querySelector(parent.dataset.cascadeChild);
    if (!child) return;
    const placeholder = child.dataset.placeholder || 'Select';
    // reset this child and all descendants
    let c = child;
    while (c) {
        c.innerHTML = `<option value="">${c.dataset.placeholder || 'Select'}</option>`;
        c.disabled = true;
        c = c.dataset.cascadeChild ? document.querySelector(c.dataset.cascadeChild) : null;
    }
    if (!parent.value) return;
    child.innerHTML = '<option value="">Loading…</option>';
    try {
        const { data } = await axios.get(parent.dataset.cascadeUrl, { params: { parent: parent.value } });
        child.innerHTML = `<option value="">${placeholder}</option>` +
            data.map((o) => `<option value="${o.id}">${escapeHtml(o.name)}${o.code ? ' (' + escapeHtml(o.code) + ')' : ''}</option>`).join('');
        child.disabled = false;
    } catch {
        child.innerHTML = `<option value="">Could not load</option>`;
    }
}
window.siCascade = cascade;

export { escapeHtml }; // lives in util.js since 26-11-2026 (imported modules use './util', never './app')
window.escapeHtml = escapeHtml;

// Profile: change own photo (preview, type/size check, save or cancel)
document.querySelectorAll('[data-photo-form]').forEach((form) => {
    const input = form.querySelector('input[type=file]');
    const current = form.querySelector('[data-photo-current]');
    const preview = form.querySelector('[data-photo-preview]');
    const actions = form.querySelector('[data-photo-actions]');
    const error = form.querySelector('[data-photo-error]');
    const hint = form.querySelector('[data-photo-hint]');
    const maxKb = Number(form.dataset.maxKb || 1024);
    let url = null;
    const reset = (msg = '') => {
        input.value = '';
        if (url) URL.revokeObjectURL(url);
        url = null;
        preview.classList.add('d-none');
        current.classList.remove('d-none');
        actions.classList.add('d-none');
        hint?.classList.remove('d-none');
        error.textContent = msg;
        error.classList.toggle('d-none', !msg);
    };
    input.addEventListener('change', () => {
        const f = input.files[0];
        if (!f) return reset();
        if (!['jpg', 'jpeg', 'png'].includes(f.name.split('.').pop().toLowerCase())) return reset('Only JPG or PNG photos are allowed.');
        if (f.size > maxKb * 1024) return reset(`This photo is ${(f.size / 1048576).toFixed(1)} MB. Please choose one of 1 MB or less.`);
        if (url) URL.revokeObjectURL(url);
        url = URL.createObjectURL(f);
        preview.src = url;
        preview.classList.remove('d-none');
        current.classList.add('d-none');
        actions.classList.remove('d-none');
        hint?.classList.add('d-none');
        error.classList.add('d-none');
    });
    form.querySelector('[data-photo-cancel]').addEventListener('click', () => reset());
    form.addEventListener('submit', () => form.querySelector('[type=submit]').disabled = true);
});

/* ------------------------------------------------------------------
 * Enhanced dropdowns for <select class="form-select"> inside [data-enhance-selects].
 * The native <select> stays in the form (value, validation, FormData); this adds a styled
 * button + animated option panel (bottom sheet on phones), keyboard support and search for long lists.
 * ------------------------------------------------------------------ */
const xselOpen = { current: null };
function closeXsel() {
    const x = xselOpen.current;
    if (!x) return;
    x.wrap.classList.remove('open');
    x.btn.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('xsel-sheet-open');
    xselOpen.current = null;
}
function enhanceSelect(sel) {
    if (sel.dataset.enhanced || sel.multiple) return;
    sel.dataset.enhanced = '1';
    const wrap = document.createElement('div');
    wrap.className = 'xsel';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('xsel-native');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');

    const label = sel.id ? document.querySelector(`label[for="${sel.id}"]`) : null;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'xsel-btn form-select';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    if (label) { label.htmlFor = ''; label.addEventListener('click', () => btn.focus()); }
    const panel = document.createElement('div');
    panel.className = 'xsel-panel';
    panel.setAttribute('role', 'listbox');
    const title = (label?.textContent || sel.getAttribute('aria-label') || 'Select').replace('*', '').trim();
    const opts = [...sel.options].filter((o) => o.value !== '');
    panel.innerHTML = `<div class="xsel-sheet-head"><span>${escapeHtml(title)}</span><button type="button" class="btn-close" aria-label="Close"></button></div>`
        + (opts.length > 8 ? '<div class="xsel-search"><i class="bi bi-search"></i><input type="search" placeholder="Search…" aria-label="Search options"></div>' : '')
        + `<div class="xsel-list">${opts.map((o) => `<div class="xsel-opt" role="option" data-value="${escapeHtml(o.value)}">${escapeHtml(o.text)}<i class="bi bi-check2"></i></div>`).join('')}</div>`
        + '<div class="xsel-none d-none">No match</div>';
    wrap.append(btn, panel);

    const items = () => [...panel.querySelectorAll('.xsel-opt:not(.d-none)')];
    const sync = () => {
        const o = sel.selectedOptions[0];
        const has = o && o.value !== '';
        btn.textContent = has ? o.text : (sel.options[0]?.text || 'Select');
        btn.classList.toggle('is-placeholder', !has);
        panel.querySelectorAll('.xsel-opt').forEach((el) => {
            const on = el.dataset.value === sel.value;
            el.classList.toggle('selected', on);
            el.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        btn.disabled = sel.disabled;
    };
    const setActive = (el) => {
        panel.querySelectorAll('.xsel-opt.active').forEach((x) => x.classList.remove('active'));
        if (el) { el.classList.add('active'); el.scrollIntoView({ block: 'nearest' }); }
    };
    const open = () => {
        if (btn.disabled) return;
        closeXsel();
        const r = btn.getBoundingClientRect();
        wrap.classList.toggle('up', window.innerHeight - r.bottom < 260 && r.top > 260);
        wrap.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
        if (window.matchMedia('(max-width: 575.98px)').matches) document.body.classList.add('xsel-sheet-open');
        xselOpen.current = { wrap, btn };
        const s = panel.querySelector('.xsel-search input');
        if (s) { s.value = ''; filter(''); setTimeout(() => s.focus(), 30); }
        setActive(panel.querySelector('.xsel-opt.selected') || items()[0]);
    };
    const choose = (el) => {
        if (!el) return;
        sel.value = el.dataset.value;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        sync();
        closeXsel();
        btn.focus();
    };
    const filter = (q) => {
        q = q.toLowerCase();
        let n = 0;
        panel.querySelectorAll('.xsel-opt').forEach((el) => { const hit = el.textContent.toLowerCase().includes(q); el.classList.toggle('d-none', !hit); n += hit; });
        panel.querySelector('.xsel-none').classList.toggle('d-none', n > 0);
        setActive(items()[0]);
    };

    btn.addEventListener('click', () => (wrap.classList.contains('open') ? closeXsel() : open()));
    panel.addEventListener('click', (e) => {
        if (e.target.closest('.btn-close')) { closeXsel(); btn.focus(); return; }
        choose(e.target.closest('.xsel-opt'));
    });
    panel.querySelector('.xsel-search input')?.addEventListener('input', (e) => filter(e.target.value));
    const keys = (e) => {
        const list = items();
        const i = list.indexOf(panel.querySelector('.xsel-opt.active'));
        if (!wrap.classList.contains('open')) {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key) && e.target === btn) { e.preventDefault(); open(); }
            return;
        }
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(list[Math.min(list.length - 1, i + 1)]); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(list[Math.max(0, i - 1)]); }
        else if (e.key === 'Enter') { e.preventDefault(); choose(list[i]); }
        else if (e.key === 'Escape') { e.preventDefault(); closeXsel(); btn.focus(); }
        else if (e.key === 'Tab') closeXsel();
        else if (e.key.length === 1 && e.target === btn) {
            const hit = list.find((el) => el.textContent.trim().toLowerCase().startsWith(e.key.toLowerCase()));
            if (hit) setActive(hit);
        }
    };
    btn.addEventListener('keydown', keys);
    panel.addEventListener('keydown', keys);
    sel.addEventListener('change', sync);
    sel.form?.addEventListener('reset', () => setTimeout(sync));
    // keep the error state on the visible button
    new MutationObserver(() => btn.classList.toggle('is-invalid', sel.classList.contains('is-invalid')))
        .observe(sel, { attributes: true, attributeFilter: ['class', 'disabled'] });
    sel._xselSync = sync;
    sync();
}
document.addEventListener('click', (e) => {
    const x = xselOpen.current;
    if (x && !x.wrap.contains(e.target)) closeXsel();
});
window.addEventListener('resize', () => { if (xselOpen.current && !document.body.classList.contains('xsel-sheet-open')) closeXsel(); });
window.siSyncSelects = (root = document) => root.querySelectorAll('select.xsel-native').forEach((s) => s._xselSync?.());
document.querySelectorAll('[data-enhance-selects] select.form-select').forEach(enhanceSelect);

/* ---------- Idle sign-out warning (Modification V3: session ends after 20 minutes) ----------
 * The server session is refreshed by every request (page load, auto-save, AJAX). The last activity
 * time is shared between tabs through localStorage, so working in one tab keeps the others alive. */
(() => {
    const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content;
    const lifetimeMin = parseInt(meta('session-lifetime'), 10);
    const pingUrl = meta('session-ping');
    const expiredUrl = meta('session-expired-url');
    if (!lifetimeMin || !pingUrl) return; // guest pages
    const LIFE = lifetimeMin * 60 * 1000;
    const WARN_BEFORE = Math.min(2 * 60 * 1000, LIFE / 4);
    const KEY = 'si-last-activity';
    let box = null;

    const now = () => Date.now();
    const last = () => { try { return parseInt(localStorage.getItem(KEY), 10) || now(); } catch { return now(); } };
    const touch = () => { try { localStorage.setItem(KEY, String(now())); } catch { /* private mode */ } hide(); };
    const hide = () => { box?.remove(); box = null; };
    const show = (msLeft) => {
        if (!box) {
            box = document.createElement('div');
            box.className = 'idle-warning';
            box.setAttribute('role', 'alertdialog');
            box.setAttribute('aria-live', 'assertive');
            box.innerHTML = `<i class="bi bi-hourglass-split"></i>
                <div class="idle-text"><strong>Your session is about to end</strong><span data-idle-left></span></div>
                <button type="button" class="btn btn-sm btn-light" data-idle-stay>Stay signed in</button>`;
            document.body.appendChild(box);
            box.querySelector('[data-idle-stay]').addEventListener('click', () => {
                axios.get(pingUrl).then(touch).catch(() => { window.location.href = expiredUrl; });
            });
        }
        const s = Math.max(0, Math.ceil(msLeft / 1000));
        box.querySelector('[data-idle-left]').textContent = `You will be signed out in ${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')} because of no activity. Saved answers are kept.`;
    };

    touch(); // this page load refreshed the session
    axios.interceptors.response.use((r) => { touch(); return r; }, (e) => { if (e?.response && e.response.status < 500) touch(); return Promise.reject(e); });
    window.addEventListener('storage', (e) => { if (e.key === KEY) hide(); });

    setInterval(() => {
        const left = last() + LIFE - now();
        if (left <= 0) { window.location.href = expiredUrl; return; }
        if (left <= WARN_BEFORE) show(left); else hide();
    }, 1000);
})();

/* ---------------- Modification V11 item 1: bottom bars hide while scrolling down ----------------
 * The phone bottom nav and the wizard Back / Next bar slide away on scroll down and come back on
 * scroll up, after a short pause, or at the page bottom. Never while a modal is open or the bar has focus. */
(() => {
    if (!document.querySelector('.bottom-nav, .action-bar')) return;
    const body = document.body;
    let lastY = window.scrollY, ticking = false, idle = null;
    const show = () => body.classList.remove('bars-hidden');
    const update = () => {
        ticking = false;
        const y = window.scrollY;
        const atBottom = window.innerHeight + y >= document.documentElement.scrollHeight - 24;
        const keep = body.classList.contains('modal-open') || document.activeElement?.closest?.('.action-bar, .bottom-nav');
        if (keep || atBottom || y < 120) show();
        else if (y - lastY > 8) body.classList.add('bars-hidden');
        else if (lastY - y > 8) show();
        if (Math.abs(y - lastY) > 8) lastY = y;
        clearTimeout(idle);
        idle = setTimeout(show, 700); // stopped scrolling: bring them back
    };
    window.addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
})();

// "Start inspection" from a plan or from Today's schools (V14): capture the location first, like the school page
document.addEventListener('submit', (e) => {
    const f = e.target.closest?.('form[data-geo-start]');
    if (!f || f.dataset.located) return;
    e.preventDefault();
    const btn = f.querySelector('button');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Getting location…'; }
    const go = () => { f.dataset.located = 1; f.submit(); };
    if (!navigator.geolocation) { go(); return; }
    navigator.geolocation.getCurrentPosition((p) => {
        f.lat.value = p.coords.latitude; f.lng.value = p.coords.longitude; f.accuracy.value = Math.round(p.coords.accuracy);
        go();
    }, go, { enableHighAccuracy: true, timeout: 12000, maximumAge: 30000 });
});
