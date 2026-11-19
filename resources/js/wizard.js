// Inspection questionnaire (v2): auto-save after every answer, conditional rules, repeating sections,
// inline validation messages, progress
import { offlineStore, syncAll, mountBanner, isNetworkError } from './offline-queue';

const SI = window.SI;
const esc = window.escapeHtml;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

let pending = 0;
let chain = Promise.resolve();
SI.missingNow = (SI.missing || {})[SI.step] || [];

/* ---------------- Session expiry (401 / 419) ---------------- */
let redirecting = false;
export function handleAuthError(err) {
    const code = err?.response?.status;
    if ((code === 401 || code === 419) && !redirecting) {
        redirecting = true;
        window.toast('Your session has expired. Your answers are saved. Please sign in again.', 'warning');
        setTimeout(() => { window.location.href = SI.loginUrl; }, 2200);
        return true;
    }
    return redirecting;
}
window.siHandleAuthError = handleAuthError;

/* ---------------- Save indicator ---------------- */
function indicator(state, text) {
    const el = $('#save-indicator');
    if (!el) return;
    el.className = `save-indicator ${state}`;
    const icon = { saving: 'arrow-repeat', saved: 'cloud-check', error: 'exclamation-triangle', offline: 'phone' }[state];
    el.innerHTML = `<i class="bi bi-${icon}"></i>${esc(text)}`;
}

/**
 * Serialise requests so answers are saved in order.
 * onInvalid(errors, message) handles 422 responses (inline messages); everything else becomes a toast.
 */
function queue(fn, onInvalid, op) {
    pending++;
    indicator('saving', 'Saving…');
    // While earlier changes are still waiting on this device, new ones join the end of that queue,
    // so they reach the server in the order they were made.
    const direct = () => (op && offline.ok && offline.queued > 0 ? keepOnDevice(op) : fn());
    chain = chain.then(direct).then((res) => {
        if (res?.data?.ok) {
            applyProgress(res.data);
            indicator('saved', `Saved ${res.data.saved_at}`);
        }
        return res;
    }).catch(async (err) => {
        if (handleAuthError(err)) { indicator('error', 'Not saved'); return; }
        if (err.response?.status === 422 && onInvalid) {
            indicator('error', 'Not saved');
            onInvalid(err.response.data.errors || {}, err.response.data.message);
            return;
        }
        if (isNetworkError(err) && op && offline.ok) {
            try { await keepOnDevice(op); return; } catch { /* storage refused: fall through */ }
        }
        indicator('error', 'Not saved');
        const msg = err.response?.data?.message || (err.response ? 'Could not save. Please try again.'
            : (op ? 'No network, and this browser cannot keep data offline. Please try again when you are back online.'
                : 'Editing or removing a saved record needs the network. Please try again when you are back online.'));
        window.toast(msg, 'danger');
    }).finally(() => { pending--; });
    return chain;
}

/* ---------------- Working without network (T2): keep changes on this device, sync later ---------------- */
const offline = { ok: false, queued: 0, ops: [], warned: false, banner: null };
async function keepOnDevice(op) {
    const { onQueued, ...stored } = op;
    await offlineStore.addOp(SI.inspectionKey, stored);
    indicator('offline', 'Saved on this device');
    onQueued?.();
    offline.banner?.set(navigator.onLine ? 'syncing' : 'offline');
    if (!offline.warned) {
        offline.warned = true;
        window.toast('No network. Your answers are kept on this device and will be sent automatically.', 'warning');
    }
    return { offline: true };
}

/* ---------------- Progress UI ---------------- */
function applyProgress(d) {
    if (d.pct === undefined) return;
    $('#overall-pct') && ($('#overall-pct').textContent = `${d.pct}%`);
    $('#overall-bar') && ($('#overall-bar').style.width = `${d.pct}%`);
    for (const [n, s] of Object.entries(d.steps || {})) {
        const link = $(`[data-step-link="${n}"]`);
        if (!link) continue;
        link.classList.toggle('complete', s.complete);
        const dot = link.querySelector('.dot');
        const active = link.classList.contains('active');
        if (!active) dot.innerHTML = s.complete ? '<i class="bi bi-check-lg"></i>' : n;
        const st = link.querySelector('[data-step-status]');
        if (st && !active) {
            st.textContent = s.complete ? 'Done' : (s.pct > 0 ? `In progress · ${s.pct}%` : 'Not started');
            st.className = `st ${s.complete ? 'is-done' : (s.pct > 0 ? 'is-partial' : '')}`;
        }
    }
    const doneEl = $('#steps-done');
    if (doneEl) doneEl.textContent = Object.values(d.steps || {}).filter((s) => s.complete).length;
    const miss = (d.missing || {})[SI.step] || [];
    SI.missingNow = miss;
    const sm = $('#step-missing');
    if (sm) { sm.textContent = miss.length ? `${miss.length} left in this step` : 'Step complete'; sm.classList.toggle('is-done', !miss.length); }
    const warn = (d.warnings || {})[SI.step] || [];
    const box = $('#step-warnings');
    if (box) box.innerHTML = warn.map((w) => `<div class="alert alert-warning text-sm py-2 mt-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>${esc(w)}</div>`).join('');
    // clear "required" highlights that are now satisfied
    const still = new Set(miss.map((m) => m.code));
    $$('.q-block.is-missing').forEach((b) => { if (!still.has(b.dataset.code)) clearQuestionError(b); });
    document.dispatchEvent(new CustomEvent('si:progress', { detail: d }));
}
window.siRefreshProgress = () => axios.get(SI.progressUrl).then((r) => applyProgress(r.data)).catch((e) => handleAuthError(e));

/* ---------------- Question-level messages ---------------- */
function questionError(block, msg, missing = false) {
    block.classList.add('has-error');
    block.classList.toggle('is-missing', missing);
    let el = block.querySelector('.q-error');
    if (!el) {
        el = document.createElement('div');
        el.className = 'q-error';
        el.setAttribute('role', 'alert');
        block.querySelector('.q-input').after(el);
    }
    el.innerHTML = `<i class="bi bi-exclamation-circle"></i>${esc(msg)}`;
}
function clearQuestionError(block) {
    block.classList.remove('has-error', 'is-missing');
    block.querySelector('.q-error')?.remove();
}

/* ---------------- Answer helpers ---------------- */
function readValue(block) {
    const type = block.dataset.type;
    const code = block.dataset.code;
    if (type === 'radio') return $(`input[name="${code}"]:checked`, block)?.value ?? null;
    if (type === 'multi') return $$(`input[name="${code}[]"]:checked`, block).map((i) => i.value);
    const el = $('[data-answer]', block);
    return el.value === '' ? null : el.value;
}

const filled = (v) => !(v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0));
const matches = (v, list) => [].concat(v ?? []).some((x) => list.includes(String(x)));

function isVisible(q) { return !q.show_if || matches(SI.answers[q.show_if[0]], q.show_if[1]); }
function isEnabled(q) { return !q.enable_if || matches(SI.answers[q.enable_if[0]], q.enable_if[1]); }
function isRequired(q) {
    if (!isVisible(q) || !isEnabled(q)) return false;
    if (q.required) return true;
    if (q.required_if) return matches(SI.answers[q.required_if[0]], q.required_if[1]);
    if (q.required_unless) { const v = SI.answers[q.required_unless[0]]; return filled(v) && !matches(v, q.required_unless[1]); }
    return false;
}
function photoRequired(q, v) {
    if (q.photo === 'required') return true;
    return !!(q.photo && q.photo.required_if && matches(v, q.photo.required_if));
}

/** Client-side check before saving; returns an error message or null. */
function checkValue(block, v) {
    if (!filled(v)) return null;
    if (block.dataset.type === 'number') {
        if (!/^\d+$/.test(String(v))) return 'Enter a whole number (0, 1, 2 …) without decimals or minus sign.';
        if (Number(v) > 100000) return 'This number looks too large. Please check it.';
    }
    if (block.dataset.type === 'text' && String(v).trim().length < 2) return 'Please enter at least 2 characters.';
    return null;
}

/** Re-evaluate visibility, required stars, remarks and photo boxes for questions on this step. */
function refreshRules() {
    for (const q of Object.values(SI.questions)) {
        const block = document.getElementById(`q-${q.code}`);
        if (!block) continue;
        let v = SI.answers[q.code];
        block.classList.toggle('d-none', !isVisible(q));
        if (q.enable_if) {
            const on = isEnabled(q);
            block.classList.toggle('is-disabled', !on);
            block.toggleAttribute('aria-disabled', !on);
            const fs = block.querySelector('fieldset.q-input');
            if (fs) fs.disabled = !on;
            if (!on && filled(v)) {
                // parent changed to No: the server clears this answer; mirror it on screen
                SI.answers[q.code] = v = null;
                block.querySelectorAll('[data-answer]').forEach((el) => { if (el.type === 'radio' || el.type === 'checkbox') el.checked = false; else el.value = ''; });
                block.classList.remove('is-answered');
                clearQuestionError(block);
            }
        }
        block.classList.toggle('is-answered', filled(v) && !block.classList.contains('has-error'));
        block.querySelector('.q-req')?.classList.toggle('d-none', !isRequired(q));
        const rem = block.querySelector('.q-remarks');
        if (rem) rem.classList.toggle('d-none', !(filled(v) && matches(v, q.remarks_if || [])));
        const ph = block.querySelector('.q-photos');
        if (ph) {
            const req = filled(v) && photoRequired(q, v);
            ph.classList.toggle('d-none', !(q.photo === 'required' || q.photo === 'optional' || req));
            ph.querySelector('.photo-req')?.classList.toggle('d-none', !req);
            ph.querySelector('.photo-opt')?.classList.toggle('d-none', req);
        }
    }
}

/* ---------------- Wire up answers ---------------- */
const timers = {};
function saveAnswer(block, value) {
    const code = block.dataset.code;
    const problem = checkValue(block, value);
    if (problem) { questionError(block, problem); refreshRules(); return Promise.resolve(); }
    clearQuestionError(block);
    SI.answers[code] = value;
    refreshRules();
    return queue(() => axios.post(SI.saveUrl, { code, value }), (errors, message) => {
        questionError(block, (errors.value || [])[0] || message || 'Please check this answer.');
        refreshRules();
    }, { method: 'post', url: SI.saveUrl, body: { code, value }, key: `a:${code}` });
}

$$('.q-block').forEach((block) => {
    const code = block.dataset.code;
    const type = block.dataset.type;
    const instant = ['radio', 'multi', 'select', 'date'].includes(type);

    block.querySelectorAll('[data-answer]').forEach((el) => {
        if (instant) {
            el.addEventListener('change', () => saveAnswer(block, readValue(block)));
        } else {
            el.addEventListener('input', () => {
                clearTimeout(timers[code]);
                timers[code] = setTimeout(() => { timers[code] = null; saveAnswer(block, readValue(block)); }, 800);
            });
            el.addEventListener('blur', () => {
                if (timers[code]) { clearTimeout(timers[code]); timers[code] = null; saveAnswer(block, readValue(block)); }
            });
        }
    });

    const remarks = block.querySelector('[data-remarks]');
    if (remarks) {
        const k = `${code}__r`;
        const send = () => queue(() => axios.post(SI.saveUrl, { code, remarks: remarks.value }), null,
            { method: 'post', url: SI.saveUrl, body: { code, remarks: remarks.value }, key: `r:${code}` });
        remarks.addEventListener('input', () => { clearTimeout(timers[k]); timers[k] = setTimeout(send, 800); });
        remarks.addEventListener('blur', () => { if (timers[k]) { clearTimeout(timers[k]); timers[k] = null; send(); } });
    }
});

/* ---------------- Number steppers (− / +) ---------------- */
document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-num-step]');
    if (!b) return;
    const input = b.parentElement.querySelector('input[type=number]');
    const next = Math.max(0, Math.min(100000, (parseInt(input.value, 10) || 0) + Number(b.dataset.numStep)));
    input.value = next;
    input.dispatchEvent(new Event('input', { bubbles: true })); // debounced auto-save
});

/* ---------------- Inline form validation (repeating sections) ---------------- */
function fieldBox(form, name) {
    const group = form.querySelector(`[data-field="${name}"]`);
    if (group) return { el: group, box: group.parentElement };
    const el = form.elements[name];
    if (!el || el instanceof RadioNodeList) return null;
    return { el, box: el.closest('.fund-cell, [class*="col-"]') || el.parentElement };
}
function setFieldError(form, name, msg) {
    const f = fieldBox(form, name);
    if (!f) { formError(form, msg); return; }
    f.el.classList.add('is-invalid');
    let fb = f.box.querySelector(':scope > .field-error');
    if (!fb) { fb = document.createElement('div'); fb.className = 'field-error'; f.box.appendChild(fb); }
    fb.innerHTML = `<i class="bi bi-exclamation-circle"></i>${esc(msg)}`;
}
function clearFieldError(form, name) {
    const f = fieldBox(form, name);
    if (!f) return;
    f.el.classList.remove('is-invalid');
    f.box.querySelector(':scope > .field-error')?.remove();
}
function formError(form, msg) {
    const el = form.querySelector('[data-form-error]');
    if (!el) { window.toast(msg, 'danger'); return; }
    el.innerHTML = msg ? `<i class="bi bi-exclamation-octagon"></i>${esc(msg)}` : '';
    el.classList.toggle('d-none', !msg);
}
function clearFormErrors(form) {
    $$('.is-invalid', form).forEach((el) => el.classList.remove('is-invalid'));
    $$('.field-error', form).forEach((el) => el.remove());
    formError(form, '');
}
function showServerErrors(form, errors, message) {
    clearFormErrors(form);
    const names = Object.keys(errors);
    names.forEach((n) => setFieldError(form, n, errors[n][0]));
    if (!names.length) formError(form, message || 'Please check the form.');
    focusFirstError(form);
}
function focusFirstError(form) {
    let first = form.querySelector('.is-invalid');
    if (!first) return;
    if (first.classList.contains('xsel-native')) first = first.parentElement.querySelector('.xsel-btn'); // enhanced dropdown
    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    (first.matches('input,select,textarea,button') ? first : first.querySelector('input'))?.focus({ preventScroll: true });
}
/** Required fields / radio groups, with friendly messages taken from the field labels. */
function validateRequired(form) {
    clearFormErrors(form);
    let ok = true;
    const seen = new Set();
    $$('[required]', form).forEach((el) => {
        if (seen.has(el.name)) return;
        seen.add(el.name);
        const label = (form.querySelector(`label[for="${el.id}"]`) || el.closest('[class*="col-"]')?.querySelector('.form-label'))
            ?.textContent.replace('*', '').trim().toLowerCase() || 'this field';
        if (el.type === 'radio') {
            if (!form.querySelector(`input[name="${el.name}"]:checked`)) { setFieldError(form, el.name, `Please choose ${label}.`); ok = false; }
        } else if (!el.value.trim()) {
            setFieldError(form, el.name, el.tagName === 'SELECT' ? `Please select ${label}.` : `Please enter ${label}.`);
            ok = false;
        } else if (el.type === 'text' && el.value.trim().length < 2) {
            setFieldError(form, el.name, `The ${label} is too short.`);
            ok = false;
        }
    });
    if (!ok) focusFirstError(form);
    return ok;
}
// clear a field's message as soon as it is corrected
document.addEventListener('input', (e) => {
    const form = e.target.closest?.('form.row-form, form.fund-form');
    if (form && e.target.name) { clearFieldError(form, e.target.name); formError(form, ''); }
});
document.addEventListener('change', (e) => {
    const form = e.target.closest?.('form.row-form, form.fund-form');
    if (form && e.target.name) { clearFieldError(form, e.target.name); formError(form, ''); }
});

/* ---------------- Repeating sections (absentee, classroom, fund) ---------------- */
const fyShort = (fy) => String(fy).replace('FY-', '');
// Amounts in lakh, Indian digit grouping (display only; stored values are unchanged)
const fmt = (n) => {
    const v = Math.round((Number(n) || 0) * 100) / 100;
    return (v < 0 ? '−' : '') + Math.abs(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};
const toNum = (s) => { const v = parseFloat(String(s ?? '').replace(/,/g, '')); return Number.isFinite(v) ? v : 0; };
function renderYearTable() {
    const box = $('#fund-years');
    if (!box) return;
    const rows = [...(SI.funds || [])].sort((a, b) => String(b.financial_year).localeCompare(String(a.financial_year)));
    box.classList.toggle('d-none', rows.length === 0);
    const active = document.querySelector('#fund-form input[name="financial_year"]:checked')?.value;
    let r = 0, e = 0;
    $('[data-fy-rows]', box).innerHTML = rows.map((f) => {
        const fr = toNum(f.total_received), fe = toNum(f.total_expenditure), fb = toNum(f.balance);
        r += fr; e += fe;
        return `<button type="button" class="fy-tr ${f.financial_year === active ? 'is-active' : ''}" role="row" data-fy-open="${esc(f.financial_year)}" aria-label="Open FY ${esc(fyShort(f.financial_year))}">
            <span role="cell"><i class="bi bi-calendar3"></i>FY ${esc(fyShort(f.financial_year))}</span>
            <span role="cell">${fmt(fr)}</span><span role="cell">${fmt(fe)}</span><span role="cell" class="${fb < 0 ? 'neg' : 'pos'}">${fmt(fb)}</span></button>`;
    }).join('');
    $('[data-fy-total="r"]', box).textContent = fmt(r);
    $('[data-fy-total="e"]', box).textContent = fmt(e);
    const tb = $('[data-fy-total="b"]', box);
    tb.textContent = fmt(r - e);
    tb.className = r - e < 0 ? 'neg' : 'pos';
    $('.fy-tfoot', box).classList.toggle('d-none', rows.length < 2);
}
const initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
const rowActions = (r, editAttr = 'data-edit') => `<div class="rs-actions">
    <button type="button" class="rs-btn" ${editAttr}="${esc(r.id)}" aria-label="Edit"><i class="bi bi-pencil"></i><span>Edit</span></button>
    <button type="button" class="rs-btn rs-del" data-del="${esc(r.delete_url)}" aria-label="Remove"><i class="bi bi-trash"></i></button></div>`;
const chip = (text, tone = '') => `<span class="rs-chip ${tone}">${text}</span>`;
const SRC = { ssa: 'SSA', govt: 'Govt', other: 'Other' };

const sections = {
    teachers: {
        list: '#teacher-list', form: '#teacher-form', key: 'teachers', done: 'Teacher added', updated: 'Teacher updated',
        empty: '<i class="bi bi-person-check"></i><strong>No absent teachers</strong><span>Leave this empty if all teachers were present.</span>',
        row: (t, i) => `<div class="rs-item" data-id="${esc(t.id)}">
            <span class="rs-avatar tone-danger">${esc(initials(t.teacher_name))}</span>
            <div class="rs-body">
                <div class="rs-title"><span class="rs-no">${i + 1}.</span>${esc(t.teacher_name)}</div>
                <div class="rs-chips">${chip(esc(t.type))}${chip('<i class="bi bi-calendar-x"></i>' + esc(t.reason), 'warn')}</div>
                ${t.remarks ? `<div class="rs-note"><i class="bi bi-chat-left-text"></i>${esc(t.remarks)}</div>` : ''}
            </div>${rowActions(t)}</div>`,
    },
    classrooms: {
        list: '#classroom-list', form: '#classroom-form', key: 'classrooms', done: 'Classroom record added', updated: 'Classroom record updated',
        empty: '<i class="bi bi-easel"></i><strong>No classroom records yet</strong><span>Add at least one classroom observation below.</span>',
        row: (c, i) => `<div class="rs-item" data-id="${esc(c.id)}">
            <span class="rs-avatar tone-primary rs-class">${esc(String(c.values.class))}</span>
            <div class="rs-body">
                <div class="rs-title"><span class="rs-no">${i + 1}.</span>${esc(c.subject)} <span class="rs-muted">· ${esc(c.class)}</span></div>
                <div class="rs-sub"><i class="bi bi-person"></i>${esc(c.teacher_name)}</div>
                <div class="rs-chips">${chip('TLM: ' + esc(c.tlm), c.tlm_ok ? 'ok' : 'bad')}${chip('Participation: ' + esc(c.participation), c.participation_ok ? 'ok' : 'bad')}</div>
                ${c.remarks ? `<div class="rs-note"><i class="bi bi-chat-left-text"></i>${esc(c.remarks)}</div>` : ''}
            </div>${rowActions(c)}</div>`,
    },
    funds: {
        list: '#fund-list', form: '#fund-form', key: 'funds', done: 'Year saved',
        empty: '<i class="bi bi-cash-stack"></i><strong>No years saved yet</strong><span>This step is optional.</span>',
        row: (f) => `<div class="rs-item fund-saved">
            <span class="rs-avatar tone-success"><i class="bi bi-calendar3"></i></span>
            <div class="rs-body">
                <div class="rs-title">FY ${esc(fyShort(f.financial_year))}</div>
                <div class="rs-chips">${chip('Received ₹ ' + esc(fmt(toNum(f.total_received))) + ' L')}${chip('Spent ₹ ' + esc(fmt(toNum(f.total_expenditure))) + ' L')}
                    ${chip('Balance ₹ ' + esc(fmt(toNum(f.balance))) + ' L', toNum(f.balance) < 0 ? 'bad' : 'ok')}</div>
                <div class="fund-saved-heads">${f.heads.filter((h) => h.received !== '0.00' || h.expenditure !== '0.00')
                    .map((h) => `<span class="src-${esc(h.source)}"><b>${SRC[h.source] || ''}</b>${esc(h.label.replace(/\s*\(SSA\)$/, ''))}: ${esc(h.received)} / ${esc(h.expenditure)}</span>`).join('')}</div>
            </div>${rowActions({ id: f.financial_year, delete_url: f.delete_url }, 'data-edit-fund')}</div>`,
    },
};

/** A row added without network: shown until it reaches the server (no edit / remove yet). */
function pendingRow(key, b) {
    const title = key === 'teachers' ? esc(b.teacher_name || 'Teacher')
        : key === 'classrooms' ? `${esc(b.subject || 'Classroom')} <span class="rs-muted">· Class ${esc(b.class || '')}</span>`
            : `FY ${esc(fyShort(b.financial_year || ''))}`;
    return `<div class="rs-item is-pending"><span class="rs-avatar tone-warning"><i class="bi bi-cloud-arrow-up"></i></span>
        <div class="rs-body"><div class="rs-title">${title}</div>
        <div class="rs-chips">${chip('<i class="bi bi-phone"></i>Saved on this device · waiting for network', 'warn')}</div></div></div>`;
}

function renderSection(s) {
    const list = $(s.list);
    if (!list) return;
    const rows = SI[s.key] || [];
    const waiting = offline.ops.filter((o) => o.section === s.key);
    list.innerHTML = rows.length || waiting.length ? rows.map(s.row).join('') + waiting.map((o) => pendingRow(s.key, o.body)).join('') : `<div class="rs-empty">${s.empty}</div>`;
    const count = $(`[data-count="${s.key}"]`);
    if (count) { count.textContent = rows.length + waiting.length; count.classList.toggle('has', rows.length + waiting.length > 0); }
    if (s.editing) list.querySelector(`.rs-item[data-id="${s.editing}"]`)?.classList.add('is-editing');
    if (s.key === 'funds') { markSavedYears(); renderYearTable(); }
}

/** Add / edit mode for the absentee and classroom forms. */
function setEditMode(s, form, row) {
    s.editing = row ? String(row.id) : null;
    form.dataset.editUrl = row ? row.update_url : '';
    form.classList.toggle('is-editing', !!row);
    const title = $('[data-form-title]', form);
    title.textContent = row ? title.dataset.edit : title.dataset.add;
    const btn = $('.row-form-btn', form);
    btn.innerHTML = row ? `<i class="bi bi-check2"></i><span>${esc(btn.dataset.editLabel)}</span>` : `<i class="bi bi-plus-lg"></i><span>${esc(btn.dataset.addLabel)}</span>`;
    $('[data-cancel-edit]', form).classList.toggle('d-none', !row);
    form.querySelector('.rf-icon i').className = row ? 'bi bi-pencil-square' : (s.key === 'teachers' ? 'bi bi-person-plus' : 'bi bi-journal-plus');
    clearFormErrors(form);
    form.reset();
    if (row) {
        for (const [name, value] of Object.entries(row.values || {})) {
            const el = form.elements[name];
            if (!el) continue;
            if (el instanceof RadioNodeList) { for (const r of el) r.checked = r.value === String(value); } else { el.value = value ?? ''; }
        }
    }
    window.siSyncSelects?.(form);
    renderSection(s);
}

for (const s of Object.values(sections)) {
    const form = $(s.form);
    if (!form) continue;
    renderSection(s);
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (s.key === 'funds' ? !validateFund() : !validateRequired(form)) {
            window.toast('Please correct the highlighted fields.', 'warning');
            return;
        }
        const btn = form.querySelector('button:not([type=button])');
        btn.disabled = true;
        const data = Object.fromEntries(new FormData(form));
        const editing = s.key !== 'funds' && form.dataset.editUrl;
        if (!editing && s.key !== 'funds') data.row_uid = newUid(); // a replayed add never creates a duplicate
        // Only new rows and fund years can wait on this device; editing or removing needs the network.
        const op = editing ? null : {
            method: 'post', url: form.dataset.url, body: data, section: s.key,
            key: s.key === 'funds' ? `fund:${data.financial_year}` : null,
            onQueued: () => {
                if (s.key !== 'funds') { form.reset(); window.siSyncSelects?.(form); }
                window.toast(`${s.key === 'funds' ? `FY ${fyShort(data.financial_year)}` : 'Record'} saved on this device; it will be sent when the network is back.`, 'warning');
            },
        };
        queue(() => (editing ? axios.put(form.dataset.editUrl, data) : axios.post(form.dataset.url, data)).then((r) => {
            SI[s.key] = r.data[s.key];
            if (s.key === 'funds') {
                renderSection(s);
                fundSaved(data.financial_year);
                window.toast(`FY ${fyShort(data.financial_year)} saved`, 'success');
            } else {
                setEditMode(s, form, null);
                window.toast(editing ? s.updated : s.done, 'success');
                form.querySelector('input,select')?.focus({ preventScroll: !!editing });
            }
            return r;
        }), (errors, message) => showServerErrors(form, errors, message), op).finally(() => { btn.disabled = false; });
    });
    $('[data-cancel-edit]', form)?.addEventListener('click', () => setEditMode(s, form, null));
    $(s.list).addEventListener('click', (e) => {
        const b = e.target.closest('[data-del]');
        if (b && confirm('Remove this record?')) {
            queue(() => axios.delete(b.dataset.del).then((r) => {
                SI[s.key] = r.data[s.key];
                if (s.editing && !(SI[s.key] || []).some((x) => String(x.id) === s.editing)) setEditMode(s, form, null);
                renderSection(s);
                window.toast('Removed', 'success');
                return r;
            }));
        }
        const ed = e.target.closest('[data-edit]');
        if (ed) {
            const row = (SI[s.key] || []).find((x) => String(x.id) === ed.dataset.edit);
            if (row) { setEditMode(s, form, row); form.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        }
        const edf = e.target.closest('[data-edit-fund]');
        if (edf) {
            selectYear(edf.dataset.editFund);
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
}

/* ---------------- Step 5: fund by year ---------------- */
const fundForm = $('#fund-form');
const num = (el) => { const v = parseFloat(el?.value); return Number.isFinite(v) ? v : 0; };
let fundIsDirty = false;
let currentFy = null;

function markSavedYears() {
    const saved = new Set((SI.funds || []).map((f) => f.financial_year));
    $$('.fy-chips label').forEach((l) => l.classList.toggle('has-data', saved.has(l.dataset.fy)));
}
function selectYear(fy) {
    const radio = fundForm.querySelector(`input[name="financial_year"][value="${fy}"]`);
    if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
}
function fundTotals() {
    let r = 0, e = 0;
    const over = [];
    $$('.fund-row[data-head]', fundForm).forEach((row) => {
        const [ri, ei] = $$('input', row);
        const bal = num(ri) - num(ei);
        r += num(ri); e += num(ei);
        const out = $('[data-bal]', row);
        out.textContent = fmt(bal);
        out.classList.toggle('neg', bal < 0);
        out.closest('.fund-bal')?.classList.toggle('is-neg', bal < 0); // fallback for :has() in older browsers
        if (bal < 0) over.push(row.querySelector('.fund-head').textContent.trim());
    });
    $('[data-total="r"]', fundForm).textContent = fmt(r);
    $('[data-total="e"]', fundForm).textContent = fmt(e);
    const tb = $('[data-total="b"]', fundForm);
    tb.textContent = fmt(r - e);
    tb.classList.toggle('neg', r - e < 0);
    const card = $('#fund-balance-card');
    if (card) {
        $('[data-fbc-year]', card).textContent = currentFy ? `FY ${fyShort(currentFy)}` : 'FY';
        $('[data-fbc="r"]', card).textContent = fmt(r);
        $('[data-fbc="e"]', card).textContent = fmt(e);
        $('[data-fbc="b"]', card).textContent = fmt(r - e);
        card.classList.toggle('is-neg', r - e < 0);
    }
    const warn = $('#fund-warn');
    warn.classList.toggle('d-none', !over.length);
    if (over.length) warn.querySelector('span').textContent = `Expenditure is more than the amount received for ${over.join(', ')}. Please check the figures (you can still save).`;
}
function fundDirty(dirty) {
    const btn = $('#fund-save');
    const fy = fundForm.querySelector('input[name="financial_year"]:checked')?.value;
    const exists = (SI.funds || []).some((f) => f.financial_year === fy);
    fundIsDirty = dirty;
    btn.classList.toggle('btn-success', !dirty);
    btn.classList.toggle('btn-primary', dirty);
    btn.innerHTML = dirty
        ? `<i class="bi bi-check2"></i><span>${exists ? 'Update' : 'Save'} FY ${esc(fyShort(fy || ''))}</span>`
        : `<i class="bi bi-check2-all"></i><span>Saved</span>`;
}
function fundSaved() { fundDirty(false); }
function validateFund() {
    clearFormErrors(fundForm);
    if (!fundForm.querySelector('input[name="financial_year"]:checked')) { setFieldError(fundForm, 'financial_year', 'Select the financial year first.'); focusFirstError(fundForm); return false; }
    let ok = true, any = false;
    $$('#fund-amounts input', fundForm).forEach((el) => {
        if (el.value === '') return;
        any = true;
        if (!/^\d+(\.\d{1,2})?$/.test(el.value)) { setFieldError(fundForm, el.name, 'Use a number with up to 2 decimals, e.g. 1.25'); ok = false; }
        else if (parseFloat(el.value) > 99999) { setFieldError(fundForm, el.name, 'Too large. Enter the amount in lakh.'); ok = false; }
    });
    if (ok && !any) { formError(fundForm, 'Enter at least one amount for this year.'); ok = false; }
    if (!ok) focusFirstError(fundForm);
    return ok;
}
if (fundForm) {
    markSavedYears();
    fundForm.addEventListener('change', (e) => {
        if (e.target.name !== 'financial_year') return;
        const fy = e.target.value;
        // Each year is kept separately: warn before leaving a year with amounts that are not saved yet
        const hasInput = $$('#fund-amounts input').some((el) => el.value !== '');
        if (currentFy && currentFy !== fy && fundIsDirty && hasInput
            && !confirm(`FY ${fyShort(currentFy)} has amounts that are not saved. Switch to FY ${fyShort(fy)} and discard them?`)) {
            const back = fundForm.querySelector(`input[name="financial_year"][value="${currentFy}"]`);
            if (back) back.checked = true;
            return;
        }
        currentFy = fy;
        const existing = (SI.funds || []).find((x) => x.financial_year === fy);
        $('#fund-amounts').disabled = false;
        $('#fund-amounts').classList.remove('d-none');
        $('#fund-empty-hint').classList.add('d-none');
        for (const el of $$('#fund-amounts input')) {
            const v = existing ? existing.values[el.name] : null;
            el.value = v ? v : '';
        }
        fundTotals();
        fundDirty(!existing);
        renderYearTable();
        $('#fund-amounts input')?.focus({ preventScroll: true });
    });
    $('#fund-years')?.addEventListener('click', (e) => {
        const row = e.target.closest('[data-fy-open]');
        if (!row) return;
        selectYear(row.dataset.fyOpen);
        fundForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    fundForm.addEventListener('input', (e) => { if (e.target.closest('#fund-amounts')) { fundTotals(); fundDirty(true); } });
    $('[data-fund-clear]')?.addEventListener('click', () => {
        $$('#fund-amounts input').forEach((el) => { el.value = ''; });
        clearFormErrors(fundForm);
        fundTotals();
        fundDirty(true);
    });
}

/* ---------------- Step 6: suggestions ---------------- */
const sugBox = $('#suggestions');
if (sugBox) {
    let t;
    const send = () => {
        const body = { suggestions: $$('[data-suggestion]', sugBox).map((i) => i.value) };
        return queue(() => axios.post(sugBox.dataset.url, body), null, { method: 'post', url: sugBox.dataset.url, body, key: 'suggestions' });
    };
    sugBox.addEventListener('input', () => { clearTimeout(t); t = setTimeout(send, 900); });
    sugBox.addEventListener('focusout', () => { if (t) { clearTimeout(t); t = null; send(); } });
}

/* ---------------- Leaving the step: show what is still missing ---------------- */
window.siPendingWork = () => pending + (window.siPendingUploads?.() ?? 0);
window.addEventListener('beforeunload', (e) => {
    if (!redirecting && window.siPendingWork() > 0) { e.preventDefault(); e.returnValue = ''; }
});

function highlightMissing() {
    let first = null;
    for (const m of SI.missingNow) {
        const block = m.code && document.getElementById(`q-${m.code}`);
        if (block && !block.classList.contains('d-none')) {
            // a blank answer gets the short text; a consistency rule (e.g. teacher totals) shows its own message
            questionError(block, /: answer required$/.test(m.message) ? 'This question needs an answer.' : m.message, true);
            first = first || block;
        }
    }
    return first;
}
const missModal = document.getElementById('missingModal');
let leaveTo = null;
$$('[data-next]').forEach((a) => a.addEventListener('click', async (e) => {
    const active = document.activeElement;
    if (active && active.matches('[data-answer], [data-remarks], [data-suggestion]')) active.blur();
    e.preventDefault();
    await chain;
    if (SI.missingNow.length && missModal && !a.dataset.force) {
        leaveTo = a.href;
        $('#missing-count').textContent = SI.missingNow.length;
        $('#missing-list').innerHTML = SI.missingNow.slice(0, 6).map((m) => `<li><i class="bi bi-dot"></i>${esc(m.message)}</li>`).join('')
            + (SI.missingNow.length > 6 ? `<li class="text-secondary">… and ${SI.missingNow.length - 6} more</li>` : '');
        bootstrap.Modal.getOrCreateInstance(missModal).show();
        return;
    }
    window.location = a.href;
}));
$('[data-missing-show]')?.addEventListener('click', () => {
    bootstrap.Modal.getOrCreateInstance(missModal).hide();
    const first = highlightMissing();
    if (first) setTimeout(() => first.scrollIntoView({ behavior: 'smooth', block: 'center' }), 250);
    else if ($('.row-form, .photo-slot:not(.done)')) setTimeout(() => $('.row-form, .photo-slot:not(.done)').scrollIntoView({ behavior: 'smooth', block: 'center' }), 250);
});
$('[data-missing-continue]')?.addEventListener('click', () => { if (leaveTo) window.location = leaveTo; });
$$('[data-step-link], .action-bar a:not([data-next])').forEach((a) => a.addEventListener('click', async (e) => {
    const active = document.activeElement;
    if (active && active.matches('[data-answer], [data-remarks], [data-suggestion]')) active.blur();
    if (pending > 0) { e.preventDefault(); await chain; window.location = a.href; }
}));

// Arriving from the review page with #q-CODE: highlight that question
if (location.hash.startsWith('#q-')) {
    const b = document.getElementById(location.hash.slice(1));
    if (b) { highlightMissing(); setTimeout(() => b.scrollIntoView({ behavior: 'smooth', block: 'center' }), 300); }
}

refreshRules();

/* ---------------- Step 2: automatic totals (display only) ---------------- */
const autoTotals = $$('[data-auto-total]');
if (autoTotals.length) {
    const val = (code) => {
        const el = document.querySelector(`input[name="${code}"]`);
        if (!el || el.value === '') return null;
        const n = parseInt(el.value, 10);
        return Number.isFinite(n) ? n : null;
    };
    const fmtInt = (n) => (n === null ? '–' : n.toLocaleString('en-IN'));
    const TEACHER_CODES = ['TSI_04', 'TSI_05', 'TSI_06', 'TSI_07', 'TSI_08'];
    const paint = (box, { total, present, absent, extra = '', bad = false }) => {
        $('[data-at="total"]', box).textContent = fmtInt(total);
        $('[data-at="present"]', box).textContent = fmtInt(present);
        $('[data-at="absent"]', box).textContent = absent === null ? '–' : (absent < 0 ? '−' + Math.abs(absent).toLocaleString('en-IN') : fmtInt(absent));
        $('[data-at-extra]', box).textContent = extra;
        box.classList.toggle('is-bad', bad);
        box.classList.toggle('is-ready', total !== null && !bad);
    };
    const recalc = () => {
        const s = $('[data-auto-total="students"]');
        if (s) {
            const p = val('TSI_01'), a = val('TSI_02');
            const total = p !== null && a !== null ? p + a : null;
            paint(s, { total, present: p, absent: a, extra: total ? `Attendance ${Math.round((p / total) * 100)}%` : '' });
        }
        const t = $('[data-auto-total="teachers"]');
        if (t) {
            const parts = TEACHER_CODES.map(val);
            const total = parts.includes(null) ? null : parts.reduce((x, y) => x + y, 0);
            const present = val('TSI_09');
            const absent = total !== null && present !== null ? total - present : null;
            const bad = absent !== null && absent < 0;
            paint(t, { total, present, absent, bad, extra: bad ? 'Present is more than the total. Please check.' : '' });
        }
        // Trained/untrained and male/female (TSI_10..13) must each add up to Total teachers
        const bd = $('[data-auto-total="breakdown"]');
        if (bd) {
            const [mt, mu, ft, fu] = ['TSI_10', 'TSI_11', 'TSI_12', 'TSI_13'].map(val);
            const parts = TEACHER_CODES.map(val);
            const total = parts.includes(null) ? null : parts.reduce((x, y) => x + y, 0);
            const all = [mt, mu, ft, fu].every((x) => x !== null);
            const sum = (...xs) => (xs.every((x) => x !== null) ? xs.reduce((a, b) => a + b, 0) : null);
            $('[data-at="trained"]', bd).textContent = fmtInt(sum(mt, ft));
            $('[data-at="untrained"]', bd).textContent = fmtInt(sum(mu, fu));
            $('[data-at="male"]', bd).textContent = fmtInt(sum(mt, mu));
            $('[data-at="female"]', bd).textContent = fmtInt(sum(ft, fu));
            const s = all ? mt + mu + ft + fu : null;
            $('[data-at="sum"]', bd).textContent = fmtInt(s);
            const checked = all && total !== null;
            const bad = checked && s !== total;
            $('[data-at-extra]', bd).innerHTML = !checked ? (total === null ? 'Enter the teacher numbers above first.' : '')
                : bad ? `<i class="bi bi-x-circle-fill"></i> Does not match Total teachers (${fmtInt(total)})`
                    : `<i class="bi bi-check-circle-fill"></i> Matches Total teachers (${fmtInt(total)})`;
            bd.classList.toggle('is-bad', bad);
            bd.classList.toggle('is-ready', checked && !bad);
        }
    };
    window.siRecalcTotals = recalc;
    document.addEventListener('input', (e) => { if (e.target.matches?.('input[name^="TSI_"]')) recalc(); });
    recalc();
}

/* ---------------- Step 4: subject autocomplete (AJAX, free text still allowed) ---------------- */
$$('[data-ac]').forEach((wrap) => {
    const input = $('input', wrap);
    const list = $('.ac-list', wrap);
    const cache = new Map();
    let items = [], active = -1, timer = null, seq = 0;

    const close = () => { list.classList.add('d-none'); input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; };
    const mark = (label, q) => {
        const i = q ? label.toLowerCase().indexOf(q.toLowerCase()) : -1;
        return i < 0 ? esc(label) : `${esc(label.slice(0, i))}<b>${esc(label.slice(i, i + q.length))}</b>${esc(label.slice(i + q.length))}`;
    };
    const render = (q) => {
        const typed = input.value.trim();
        const exact = items.some((s) => s.toLowerCase() === typed.toLowerCase());
        if (!items.length && !typed) { close(); return; }
        list.innerHTML = items.map((s, i) => `<div class="ac-item" role="option" id="${input.id}_opt${i}" data-i="${i}" aria-selected="false"><i class="bi bi-book"></i><span>${mark(s, q)}</span></div>`).join('')
            + (typed && !exact ? `<div class="ac-free"><i class="bi bi-pencil"></i>Not in the list? Keep “${esc(typed)}” as typed.</div>` : '');
        list.classList.remove('d-none');
        input.setAttribute('aria-expanded', 'true');
        active = -1;
    };
    const highlight = (i) => {
        const opts = $$('.ac-item', list);
        if (!opts.length) return;
        active = (i + opts.length) % opts.length;
        opts.forEach((o, k) => { o.classList.toggle('is-active', k === active); o.setAttribute('aria-selected', k === active ? 'true' : 'false'); });
        input.setAttribute('aria-activedescendant', opts[active].id);
        opts[active].scrollIntoView({ block: 'nearest' });
    };
    const choose = (i) => {
        if (items[i] === undefined) return;
        input.value = items[i];
        input.dispatchEvent(new Event('input', { bubbles: true })); // clears a shown validation message
        close();
    };
    const fetchFor = (q) => {
        const key = q.toLowerCase();
        if (cache.has(key)) { items = cache.get(key); render(q); return; }
        const my = ++seq;
        axios.get(wrap.dataset.url, { params: { q } }).then((r) => {
            cache.set(key, r.data.subjects || []);
            if (my !== seq) return; // a newer request is on its way
            items = cache.get(key);
            render(q);
        }).catch((err) => { handleAuthError(err); });
    };

    input.addEventListener('input', (e) => {
        if (!e.isTrusted) return; // set by choose()
        clearTimeout(timer);
        const q = input.value.trim();
        timer = setTimeout(() => fetchFor(q), 250);
    });
    input.addEventListener('focus', () => { if (!input.value.trim()) fetchFor(''); });
    input.addEventListener('keydown', (e) => {
        const open = !list.classList.contains('d-none');
        if (e.key === 'ArrowDown') { e.preventDefault(); if (!open) fetchFor(input.value.trim()); else highlight(active + 1); }
        else if (e.key === 'ArrowUp' && open) { e.preventDefault(); highlight(active - 1); }
        else if (e.key === 'Enter' && open && active >= 0) { e.preventDefault(); choose(active); }
        else if (e.key === 'Escape' && open) { e.preventDefault(); close(); }
        else if (e.key === 'Tab') close();
    });
    // pointerdown so the choice happens before the input loses focus
    list.addEventListener('pointerdown', (e) => {
        const opt = e.target.closest('.ac-item');
        e.preventDefault();
        if (opt) choose(Number(opt.dataset.i));
        else if (e.target.closest('.ac-free')) close();
    });
    input.addEventListener('blur', () => setTimeout(close, 120));
    // the add/edit form resets the field: hide the list too
    input.form?.addEventListener('reset', close);
});

/* ---------------- Working without network (T2): restore and sync ---------------- */
function newUid() {
    return crypto.randomUUID ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16); });
}

/** Put an answer / remark / suggestions kept on this device back on screen (the page shows server data). */
function restoreOp(op) {
    const b = op.body || {};
    if (op.key === 'suggestions') {
        $$('[data-suggestion]').forEach((el, i) => { el.value = (b.suggestions || [])[i] ?? ''; });
        return;
    }
    if (!b.code) return;
    const block = document.getElementById(`q-${b.code}`);
    if ('remarks' in b) { const r = block?.querySelector('[data-remarks]'); if (r) r.value = b.remarks ?? ''; return; }
    SI.answers[b.code] = b.value;
    if (!block) return;
    const type = block.dataset.type;
    if (type === 'radio') $$(`input[name="${b.code}"]`, block).forEach((i) => { i.checked = i.value === String(b.value); });
    else if (type === 'multi') $$(`input[name="${b.code}[]"]`, block).forEach((i) => { i.checked = [].concat(b.value ?? []).map(String).includes(i.value); });
    else { const el = $('[data-answer]', block); if (el) el.value = b.value ?? ''; }
}

const syncHooks = {
    onOp: (op, res) => {
        if (res?.data?.ok) { applyProgress(res.data); indicator('saved', `Saved ${res.data.saved_at}`); }
        for (const s of Object.values(sections)) {
            if (res?.data?.[s.key]) { SI[s.key] = res.data[s.key]; }
        }
    },
    onInvalid: (op, err) => {
        const what = op.section ? 'A record' : (op.body?.code ? `The answer to ${SI.questions[op.body.code]?.label || op.body.code}` : 'A change');
        window.toast(`${what} saved offline could not be accepted: ${err.response?.data?.message || 'please enter it again.'}`, 'danger');
    },
    onAuth: (err) => handleAuthError(err),
};

async function refreshOffline() {
    try { offline.ops = await offlineStore.ops(SI.inspectionKey); } catch { offline.ops = []; }
    offline.queued = offline.ops.length;
    Object.values(sections).forEach((s) => renderSection(s));
}

async function trySync() {
    if (!offline.ok || !navigator.onLine) return;
    const c = await offlineStore.counts(SI.inspectionKey);
    if (!c.ops) return; // photos are sent by camera.js
    offline.banner?.set('syncing');
    const r = await syncAll(SI.inspectionKey, { hooks: syncHooks });
    await refreshOffline();
    offline.banner?.set(r.left ? (navigator.onLine ? 'syncing' : 'offline') : 'synced');
    if (!r.left) { indicator('saved', 'All changes saved'); window.siRefreshProgress?.(); }
}
window.siSyncOffline = trySync;

(async () => {
    offline.ok = !!SI.inspectionKey && await offlineStore.available();
    if (!offline.ok) return;
    offline.banner = mountBanner(SI.inspectionKey, document.querySelector('.wizard-card')?.parentElement);
    document.addEventListener('si:offline-counts', (e) => {
        if (e.detail.insp !== SI.inspectionKey) return;
        if (e.detail.ops !== offline.queued) refreshOffline();
    });
    await refreshOffline();
    if (offline.ops.length) {
        offline.ops.forEach(restoreOp);
        refreshRules();
        window.siSyncSelects?.();
        window.siRecalcTotals?.();
        indicator('offline', `${offline.ops.length} change${offline.ops.length === 1 ? '' : 's'} saved on this device`);
    }
    window.addEventListener('online', () => trySync());
    setInterval(trySync, 20000);
    trySync();
})();
