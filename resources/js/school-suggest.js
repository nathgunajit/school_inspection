/*
 * 23-11-2026 (V14): school name / UDISE auto-suggestions for search boxes.
 * Add data-school-suggest="{{ route('schools.suggest') }}" to any school search input inside a GET form.
 * Picking a suggestion puts its UDISE Code in the box and submits the form (filter-partial.js then loads the
 * results in their section). Listeners are delegated, so boxes swapped in by filter-partial.js keep working.
 */
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const SEL = 'input[data-school-suggest]';
const state = new WeakMap(); // input → { list, items, active, timer, seq, cache }
let uid = 0;

function setup(input) {
    if (state.has(input)) return state.get(input);
    if (!input.id) input.id = `ss-input-${++uid}`;
    // the parent becomes the positioning box (moving the focused input would blur it); the list opens under the input
    const wrap = input.parentElement;
    wrap.classList.add('ac-wrap');
    const list = document.createElement('div');
    list.className = 'ac-list ss-list d-none';
    list.id = `${input.id}-list`;
    list.setAttribute('role', 'listbox');
    wrap.appendChild(list);
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-expanded', 'false');

    const s = { list, items: [], active: -1, timer: null, seq: 0, cache: new Map() };
    state.set(input, s);
    list.addEventListener('pointerdown', (e) => {
        const opt = e.target.closest('.ac-item');
        e.preventDefault(); // keep the focus in the box
        if (opt) choose(input, Number(opt.dataset.i));
    });
    input.addEventListener('blur', () => setTimeout(() => close(input), 120));
    return s;
}

function close(input) {
    const s = state.get(input);
    if (!s) return;
    s.list.classList.add('d-none');
    s.active = -1;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
}

function mark(text, q) {
    const i = q ? text.toLowerCase().indexOf(q.toLowerCase()) : -1;
    return i < 0 ? esc(text) : `${esc(text.slice(0, i))}<b>${esc(text.slice(i, i + q.length))}</b>${esc(text.slice(i + q.length))}`;
}

function render(input, q) {
    const s = state.get(input);
    if (!s.items.length) {
        s.list.innerHTML = `<div class="ss-empty"><i class="bi bi-search"></i>No school matches “${esc(q)}”.</div>`;
    } else {
        s.list.innerHTML = s.items.map((it, i) => `<div class="ac-item ss-item" role="option" id="${input.id}-opt${i}" data-i="${i}" aria-selected="false">
            <i class="bi bi-building"></i><span class="min-w-0"><span class="ss-name">${mark(it.name, q)}</span>
            <span class="ss-meta"><span class="font-monospace">${mark(it.udise, q)}</span>${it.area ? ' · ' + esc(it.area) : ''}${it.status !== 'active' ? ` · <span class="text-danger">${esc(it.status)}</span>` : ''}</span></span></div>`).join('');
    }
    s.active = -1;
    s.list.classList.remove('d-none');
    input.setAttribute('aria-expanded', 'true');
}

function highlight(input, i) {
    const s = state.get(input);
    const opts = [...s.list.querySelectorAll('.ac-item')];
    if (!opts.length) return;
    s.active = (i + opts.length) % opts.length;
    opts.forEach((o, k) => { o.classList.toggle('is-active', k === s.active); o.setAttribute('aria-selected', k === s.active ? 'true' : 'false'); });
    input.setAttribute('aria-activedescendant', opts[s.active].id);
    opts[s.active].scrollIntoView({ block: 'nearest' });
}

function choose(input, i) {
    const s = state.get(input);
    const it = s.items[i];
    if (!it) return;
    input.value = it.udise;
    close(input);
    const form = input.form;
    if (form) (form.requestSubmit ? form.requestSubmit() : form.submit());
}

async function lookup(input, q) {
    const s = setup(input);
    if (q.length < 2) { close(input); return; }
    const key = q.toLowerCase();
    if (s.cache.has(key)) { s.items = s.cache.get(key); render(input, q); return; }
    const my = ++s.seq;
    try {
        const url = new URL(input.dataset.schoolSuggest, window.location.origin);
        url.searchParams.set('q', q);
        const r = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!r.ok) return;
        const items = (await r.json()).items || [];
        s.cache.set(key, items);
        if (my !== s.seq || document.activeElement !== input) return; // a newer request, or the user moved on
        s.items = items;
        render(input, q);
    } catch { /* offline: no suggestions */ }
}

document.addEventListener('focusin', (e) => { if (e.target.matches?.(SEL)) setup(e.target); });
document.addEventListener('input', (e) => {
    const input = e.target;
    if (!input.matches?.(SEL)) return;
    const s = setup(input);
    clearTimeout(s.timer);
    s.timer = setTimeout(() => lookup(input, input.value.trim()), 250);
});
document.addEventListener('keydown', (e) => {
    const input = e.target;
    if (!input.matches?.(SEL)) return;
    const s = state.get(input);
    const open = s && !s.list.classList.contains('d-none');
    if (e.key === 'ArrowDown') { e.preventDefault(); if (!open) lookup(input, input.value.trim()); else highlight(input, s.active + 1); }
    else if (e.key === 'ArrowUp' && open) { e.preventDefault(); highlight(input, s.active - 1); }
    else if (e.key === 'Enter' && open && s.active >= 0) { e.preventDefault(); choose(input, s.active); }
    else if (e.key === 'Escape' && open) { e.preventDefault(); close(input); }
    else if (e.key === 'Tab' || e.key === 'Enter') close(input);
});
