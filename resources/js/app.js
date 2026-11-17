import * as bootstrap from 'bootstrap';
import axios from 'axios';

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
    /* ---------- Sidebar toggle (mobile) ---------- */
    const sidebar = document.querySelector('.app-sidebar');
    document.querySelectorAll('[data-toggle-sidebar]').forEach((b) =>
        b.addEventListener('click', (e) => { e.stopPropagation(); sidebar?.classList.toggle('show'); }));
    document.addEventListener('click', (e) => {
        if (sidebar?.classList.contains('show') && !sidebar.contains(e.target)) sidebar.classList.remove('show');
    });

    /* ---------- Collapsible sidebar (desktop), remembered per browser ---------- */
    document.querySelectorAll('[data-sidebar-mini]').forEach((b) => b.addEventListener('click', () => {
        const mini = document.documentElement.classList.toggle('sidebar-mini');
        try { localStorage.setItem('si-sidebar', mini ? 'mini' : 'full'); } catch { /* private mode */ }
    }));

    /* ---------- Flash messages -> toasts ---------- */
    document.querySelectorAll('[data-flash]').forEach((f) => toast(f.dataset.flash, f.dataset.type || 'success'));

    /* ---------- Tooltips ---------- */
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new bootstrap.Tooltip(el));

    /* ---------- Confirm before submit ---------- */
    document.querySelectorAll('form[data-confirm]').forEach((form) =>
        form.addEventListener('submit', (e) => { if (!confirm(form.dataset.confirm)) e.preventDefault(); }));

    /* ---------- Location cascade: select[data-cascade-child="#id"][data-cascade-url="/api/..?parent="] ---------- */
    document.querySelectorAll('select[data-cascade-child]').forEach((parent) => {
        parent.addEventListener('change', () => cascade(parent));
    });

    /* ---------- Auto-submit filter selects ---------- */
    document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));
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

export function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
window.escapeHtml = escapeHtml;
