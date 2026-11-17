// Inspection questionnaire (v2): auto-save after every answer, conditional rules, repeating sections,
// inline validation messages, progress
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
    const icon = { saving: 'arrow-repeat', saved: 'cloud-check', error: 'exclamation-triangle' }[state];
    el.innerHTML = `<i class="bi bi-${icon}"></i>${esc(text)}`;
}

/**
 * Serialise requests so answers are saved in order.
 * onInvalid(errors, message) handles 422 responses (inline messages); everything else becomes a toast.
 */
function queue(fn, onInvalid) {
    pending++;
    indicator('saving', 'Saving…');
    chain = chain.then(fn).then((res) => {
        if (res?.data?.ok) {
            applyProgress(res.data);
            indicator('saved', `Saved ${res.data.saved_at}`);
        }
        return res;
    }).catch((err) => {
        indicator('error', 'Not saved');
        if (handleAuthError(err)) return;
        if (err.response?.status === 422 && onInvalid) {
            onInvalid(err.response.data.errors || {}, err.response.data.message);
            return;
        }
        const msg = err.response?.data?.message || (err.response ? 'Could not save. Please try again.' : 'No connection. Your change will be saved when you edit it again.');
        window.toast(msg, 'danger');
    }).finally(() => { pending--; });
    return chain;
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
        if (!link.classList.contains('active')) dot.innerHTML = s.complete ? '<i class="bi bi-check-lg"></i>' : n;
    }
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
    });
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
        const send = () => queue(() => axios.post(SI.saveUrl, { code, remarks: remarks.value }));
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
                <div class="rs-chips">${chip('Received ' + esc(f.total_received) + ' L')}${chip('Spent ' + esc(f.total_expenditure) + ' L')}
                    ${chip('Balance ' + esc(f.balance) + ' L', parseFloat(String(f.balance).replace(/,/g, '')) < 0 ? 'bad' : 'ok')}</div>
                <div class="fund-saved-heads">${f.heads.filter((h) => h.received !== '0.00' || h.expenditure !== '0.00')
                    .map((h) => `<span class="src-${esc(h.source)}"><b>${SRC[h.source] || ''}</b>${esc(h.label.replace(/\s*\(SSA\)$/, ''))}: ${esc(h.received)} / ${esc(h.expenditure)}</span>`).join('')}</div>
            </div>${rowActions({ id: f.financial_year, delete_url: f.delete_url }, 'data-edit-fund')}</div>`,
    },
};

function renderSection(s) {
    const list = $(s.list);
    if (!list) return;
    const rows = SI[s.key] || [];
    list.innerHTML = rows.length ? rows.map(s.row).join('') : `<div class="rs-empty">${s.empty}</div>`;
    const count = $(`[data-count="${s.key}"]`);
    if (count) { count.textContent = rows.length; count.classList.toggle('has', rows.length > 0); }
    if (s.editing) list.querySelector(`.rs-item[data-id="${s.editing}"]`)?.classList.add('is-editing');
    if (s.key === 'funds') markSavedYears();
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
        }), (errors, message) => showServerErrors(form, errors, message)).finally(() => { btn.disabled = false; });
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
const fmt = (n) => (Math.round(n * 100) / 100).toFixed(2);
const num = (el) => { const v = parseFloat(el?.value); return Number.isFinite(v) ? v : 0; };

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
        if (bal < 0) over.push(row.querySelector('.fund-head').textContent.trim());
    });
    $('[data-total="r"]', fundForm).textContent = fmt(r);
    $('[data-total="e"]', fundForm).textContent = fmt(e);
    const tb = $('[data-total="b"]', fundForm);
    tb.textContent = fmt(r - e);
    tb.classList.toggle('neg', r - e < 0);
    const warn = $('#fund-warn');
    warn.classList.toggle('d-none', !over.length);
    if (over.length) warn.querySelector('span').textContent = `Expenditure is more than the amount received for ${over.join(', ')}. Please check the figures (you can still save).`;
}
function fundDirty(dirty) {
    const btn = $('#fund-save');
    const fy = fundForm.querySelector('input[name="financial_year"]:checked')?.value;
    const exists = (SI.funds || []).some((f) => f.financial_year === fy);
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
        $('#fund-amounts input')?.focus({ preventScroll: true });
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
    const send = () => queue(() => axios.post(sugBox.dataset.url, { suggestions: $$('[data-suggestion]', sugBox).map((i) => i.value) }));
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
            questionError(block, 'This question needs an answer.', true);
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
