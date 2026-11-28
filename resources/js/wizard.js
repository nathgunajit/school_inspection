// Inspection questionnaire (v2): auto-save after every answer, conditional rules, repeating sections,
// inline validation messages, progress
import { offlineStore, syncAll, mountBanner, isNetworkError, rejectedItem, addRejected, rejectedList, clearRejected } from './offline-queue';

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
    if (code === 409 && err.response?.data?.user_changed) { // 25-11-2026: another user signed in on this browser (session-guard.js)
        redirecting = true;
        window.siUserChanged?.(err.response.data.message);
        return true;
    }
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
    let el = block.querySelector('.q-error:not(.q-remark-error)');
    if (!el) {
        el = document.createElement('div');
        el.className = 'q-error';
        el.id = `q-err-${block.dataset.code}`;
        el.setAttribute('role', 'alert');
        block.querySelector('.q-input').after(el);
    }
    el.innerHTML = `<i class="bi bi-exclamation-circle"></i>${esc(msg)}`;
    // 26-11-2026: the field says it is wrong and points at the message (screen readers)
    block.querySelectorAll('.q-input [data-answer]').forEach((i) => { i.setAttribute('aria-invalid', 'true'); i.setAttribute('aria-describedby', el.id); });
}
function clearQuestionError(block) {
    block.classList.remove('has-error', 'is-missing');
    block.querySelector('.q-error:not(.q-remark-error)')?.remove();
    block.querySelectorAll('.q-input [data-answer]').forEach((i) => { i.removeAttribute('aria-invalid'); i.removeAttribute('aria-describedby'); });
}
/** 26-11-2026: a message under the remarks box of a question (not under the answer). */
function remarkError(block, msg) {
    const box = block.querySelector('[data-remarks]');
    if (!box) return;
    let el = block.querySelector('.q-remark-error');
    if (!msg) { el?.remove(); box.classList.remove('is-invalid'); box.removeAttribute('aria-invalid'); return; }
    if (!el) {
        el = document.createElement('div');
        el.className = 'q-error q-remark-error';
        el.id = `q-rerr-${block.dataset.code}`;
        el.setAttribute('role', 'alert');
        box.after(el);
    }
    el.innerHTML = `<i class="bi bi-exclamation-circle"></i>${esc(msg)}`;
    box.classList.add('is-invalid');
    box.setAttribute('aria-invalid', 'true');
    box.setAttribute('aria-describedby', el.id);
}
/** Answers refused on this screen or by the server, not saved yet: { code: message } (listed when leaving the step). */
SI.unsaved = {};
function markUnsaved(block, msg) {
    SI.unsaved[block.dataset.code] = msg;
    indicator('error', 'Not saved: fix the red box');
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
    const type = block.dataset.type;
    const el = block.querySelector('[data-answer]');
    // 26-11-2026: letters in a number box read as blank in the browser; say so instead of clearing the answer
    if (type === 'number' && el?.validity?.badInput) return 'Numbers only, e.g. 12. Remove letters, spaces or signs.';
    if (!filled(v)) return null;
    if (type === 'number') {
        if (!/^\d+$/.test(String(v))) return 'Enter a whole number (0, 1, 2 …) without decimals or a minus sign.';
        if (Number(v) > 100000) return 'This number looks too large (more than 1,00,000). Please check it.';
    }
    if ((type === 'text' || type === 'textarea') && String(v).trim().length < 2) return 'Please write at least 2 characters.';
    const max = el?.maxLength > 0 ? el.maxLength : 0;
    if (max && String(v).length > max) return `Too long: at most ${max} characters. Shorten the answer.`;
    return null;
}

/** Re-evaluate visibility, required stars, remarks and photo boxes for questions on this step. */
function refreshRules() {
    for (const q of Object.values(SI.questions)) {
        const block = document.getElementById(`q-${q.code}`);
        if (!block) continue;
        let v = SI.answers[q.code];
        block.classList.toggle('d-none', !isVisible(q));
        if (q.show_if && !isVisible(q) && filled(v)) {
            // 26-11-2026: the server clears a hidden (show_if) answer when its parent changes; mirror it on screen
            SI.answers[q.code] = v = null;
            block.querySelectorAll('[data-answer]').forEach((el) => { if (el.type === 'radio' || el.type === 'checkbox') el.checked = false; else el.value = ''; });
            clearQuestionError(block);
            delete SI.unsaved[q.code];
        }
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
        // 28-11-2026: the signed-document box (NET_02) follows its answer
        const dc = block.querySelector('.q-docs');
        if (dc) dc.classList.toggle('d-none', !(filled(v) && q.document?.required_if && matches(v, q.document.required_if)));
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
    if (problem) { questionError(block, problem); markUnsaved(block, problem); refreshRules(); return Promise.resolve(); }
    clearQuestionError(block);
    delete SI.unsaved[code];
    SI.answers[code] = value;
    refreshRules();
    return queue(() => axios.post(SI.saveUrl, { code, value }), (errors, message) => {
        const msg = (errors.value || [])[0] || message || 'Please check this answer.';
        questionError(block, msg);
        markUnsaved(block, msg);
        refreshRules();
    }, { method: 'post', url: SI.saveUrl, body: { code, value }, key: `a:${code}` }).then((res) => {
        // V12 item 2: all teachers present -> the server removed the absentee list
        const n = res?.data?.absent_removed;
        if (n) {
            SI.teachers = [];
            window.toast?.(`All teachers present: the absentee list (${n} row${n === 1 ? '' : 's'}) was removed.`, 'info');
        }
        return res;
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
        const q = SI.questions[code] || {};
        const send = () => {
            if (remarks.value.length > 2000) { remarkError(block, 'Too long: at most 2000 characters. Shorten the remarks.'); return; }
            remarkError(block, '');
            return queue(() => axios.post(SI.saveUrl, { code, remarks: remarks.value }), (errors, message) => remarkError(block, (errors.remarks || [])[0] || message),
                { method: 'post', url: SI.saveUrl, body: { code, remarks: remarks.value }, key: `r:${code}` });
        };
        remarks.addEventListener('input', () => { clearTimeout(timers[k]); timers[k] = setTimeout(send, 800); });
        remarks.addEventListener('blur', () => {
            if (timers[k]) { clearTimeout(timers[k]); timers[k] = null; send(); }
            // 26-11-2026: a mandatory remark left empty is pointed out at once, under the remarks box
            if (!q.remarks_optional && !remarks.closest('.q-remarks')?.classList.contains('d-none') && !remarks.value.trim()) {
                remarkError(block, 'Please write the remarks for this answer (what is the problem?).');
            }
        });
    }
});

/* ---------------- Number boxes: keys that are not part of a number (26-11-2026) ---------------- */
document.addEventListener('keydown', (e) => {
    const el = e.target;
    if (!(el instanceof HTMLInputElement) || el.type !== 'number' || e.ctrlKey || e.metaKey) return;
    const whole = !!el.closest('.q-block[data-type="number"]'); // counts: no decimals; fund amounts in lakh may have them
    if (['e', 'E', '+', '-'].includes(e.key) || (whole && ['.', ','].includes(e.key))) e.preventDefault();
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
    names.forEach((n) => setFieldError(form, n.startsWith('tlm_items') ? 'tlm_items' : n, errors[n][0]));
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
/** A person's name: letters (any script), spaces, dot, apostrophe, brackets, hyphen; same rule as the server. */
const NAME_RE = /^[\p{L}\p{M}][\p{L}\p{M} .'()\-]*$/u;
const sameName = (n) => String(n ?? '').replace(/\s+/g, ' ').trim().toLowerCase();
/** Names on the absent list of this inspection (SI.absentNames comes with the step 4 page). */
const absentNames = () => new Set((SI.absentNames || []).map(sameName));
/** Required fields / radio groups, with friendly messages taken from the field labels. */
function validateRequired(form) {
    clearFormErrors(form);
    let ok = true;
    const seen = new Set();
    $$('[required]', form).forEach((el) => {
        if (seen.has(el.name)) return;
        seen.add(el.name);
        // a radio's own label is an option ("Yes"): use the question's label for a radio group (26-11-2026)
        const labelEl = el.type === 'radio' ? el.closest('[class*="col-"]')?.querySelector('.form-label')
            : (form.querySelector(`label[for="${el.id}"]`) || el.closest('[class*="col-"]')?.querySelector('.form-label'));
        const label = labelEl?.textContent.replace('*', '').trim().toLowerCase() || 'this field';
        if (el.type === 'radio') {
            if (!form.querySelector(`input[name="${el.name}"]:checked`)) { setFieldError(form, el.name, el.name === 'tlm_used' ? 'Please choose Yes or No for use of TLM.' : `Please choose the ${label}.`); ok = false; }
        } else if (!el.value.trim()) {
            setFieldError(form, el.name, el.tagName === 'SELECT' ? `Please select ${label}.` : `Please enter ${label}.`);
            ok = false;
        } else if (el.type === 'text' && el.value.trim().length < 2) {
            setFieldError(form, el.name, `The ${label} is too short: write at least 2 characters.`);
            ok = false;
        } else if (el.name === 'teacher_name' && !NAME_RE.test(el.value.trim())) {
            setFieldError(form, el.name, "Use letters for the teacher's name (spaces and . ' - are allowed).");
            ok = false;
        } else if (form.id === 'classroom-form' && el.name === 'teacher_name' && absentNames().has(sameName(el.value))) {
            setFieldError(form, el.name, `${el.value.trim()} is marked absent today on step 3. Choose a teacher who is present, or remove them from the absent list.`);
            ok = false;
        }
    });
    if (form.id === 'classroom-form' && !tlmPicked()) { setFieldError(form, 'tlm_items', 'Tick at least one TLM item used.'); ok = false; }
    if (!ok) focusFirstError(form);
    return ok;
}

/* ---------------- FLN / ECCE TLM items (step 4, shown when Use of TLM = Yes) ---------------- */
const tlmBlock = document.getElementById('tlm-items');
/**
 * Rebuilds the TLM ticks: every active item, in two lists (Modification V10): FLN grouped by class
 * (Class I, Class II, …, All classes), then ECCE grouped by its group (TLM for students / schools). Keeps the ticks given or current.
 */
function renderTlm(keep) {
    const form = document.getElementById('classroom-form');
    if (!form || !tlmBlock) return;
    const list = tlmBlock.querySelector('[data-tlm-list]');
    const ticked = (keep ?? [...list.querySelectorAll('input:checked')].map((i) => i.value)).map(String);
    const yes = form.querySelector('input[name="tlm_used"]:checked')?.value === 'yes';
    const all = SI.tlmItems || [];
    tlmBlock.classList.toggle('d-none', !yes || !all.length);
    const labels = SI.tlmClasses || {};
    const lists = SI.tlmLists || { fln: 'FLN TLM' };
    const chip = (t) => `<input type="checkbox" class="btn-check" name="tlm_items[]" id="tlm_i_${esc(t.id)}" value="${esc(t.id)}"${ticked.includes(String(t.id)) ? ' checked' : ''}>`
        + `<label for="tlm_i_${esc(t.id)}"><i class="bi bi-check-lg"></i><span>${esc(t.name)}${t.code ? `<small>${esc(t.code)}</small>` : ''}</span></label>`;
    const group = (title, items) => {
        if (!items.length) return '';
        const n = items.filter((t) => ticked.includes(String(t.id))).length;
        return `<div class="tlm-group"><div class="tlm-group-head"><span class="tlm-class">${esc(title)}</span>`
            + `<span class="tlm-count" data-tlm-count>${n ? `${n} ticked` : `${items.length} items`}</span></div><div class="tlm-chips">${items.map(chip).join('')}</div></div>`;
    };
    list.innerHTML = Object.keys(lists).map((key) => {
        const mine = all.filter((t) => (t.list || 'fln') === key);
        if (!mine.length) return '';
        let groups;
        if (key === 'fln') {
            groups = [...Object.keys(labels), null].map((cls) => group(cls === null ? 'All classes' : (labels[cls] || cls), mine.filter((t) => t.class === cls)));
        } else {
            const names = [...new Set(mine.map((t) => t.section || ''))];
            groups = names.map((s) => group(s || 'Items', mine.filter((t) => (t.section || '') === s)));
        }
        const n = mine.filter((t) => ticked.includes(String(t.id))).length;
        const icon = { fln: 'bi-book-half', ecce: 'bi-balloon-heart' }[key] || 'bi-box-seam';
        return `<div class="tlm-list is-${esc(key)}"><div class="tlm-list-head"><span class="tlm-list-icon"><i class="bi ${icon}"></i></span>`
            + `<span class="tlm-list-name"><b>${esc(key.toUpperCase())}</b>${esc(lists[key])}</span>`
            + `<span class="tlm-list-count${n ? ' has' : ''}" data-tlm-list-count>${n ? `${n} ticked` : `${mine.length} items`}</span></div>`
            + `<div class="tlm-list-body">${groups.join('')}</div></div>`;
    }).join('');
}
// keep each group's and each list's "n ticked" count live
tlmBlock?.addEventListener('change', (e) => {
    const g = e.target.closest('.tlm-group');
    if (!g) return;
    const n = g.querySelectorAll('input:checked').length;
    g.querySelector('[data-tlm-count]').textContent = n ? `${n} ticked` : `${g.querySelectorAll('input').length} items`;
    const l = g.closest('.tlm-list');
    const lc = l?.querySelector('[data-tlm-list-count]');
    if (lc) {
        const ln = l.querySelectorAll('input:checked').length;
        lc.textContent = ln ? `${ln} ticked` : `${l.querySelectorAll('input').length} items`;
        lc.classList.toggle('has', ln > 0);
    }
});
/** True when no tick is needed (block hidden, or no items) or at least one item is ticked. */
function tlmPicked() {
    if (!tlmBlock || tlmBlock.classList.contains('d-none')) return true;
    const boxes = [...tlmBlock.querySelectorAll('input[name="tlm_items[]"]')];
    return !boxes.length || boxes.some((b) => b.checked);
}
document.getElementById('classroom-form')?.addEventListener('change', (e) => {
    if (e.target.name === 'tlm_used') renderTlm();
});

// clear a field's message as soon as it is corrected
document.addEventListener('input', (e) => {
    const form = e.target.closest?.('form.row-form, form.fund-form');
    if (form && e.target.name) { clearFieldError(form, e.target.name); formError(form, ''); }
});
document.addEventListener('change', (e) => {
    const form = e.target.closest?.('form.row-form, form.fund-form');
    if (form && e.target.name) { clearFieldError(form, e.target.name.replace('[]', '')); formError(form, ''); }
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
// TLM ticks as coloured groups: FLN (blue) and ECCE (orange). Older payloads have only tlm_items strings ("ECCE · name").
const TLM_ICON = { fln: 'bi-book-half', ecce: 'bi-balloon-heart' };
function tlmGroupsOf(c) {
    if (Array.isArray(c.tlm_groups)) return c.tlm_groups;
    const items = c.tlm_items || [];
    const ecce = items.filter((n) => String(n).startsWith('ECCE · ')).map((n) => String(n).slice(7));
    const fln = items.filter((n) => !String(n).startsWith('ECCE · '));
    return [['fln', 'FLN', fln], ['ecce', 'ECCE', ecce]].filter((g) => g[2].length).map(([list, short, its]) => ({ list, short, label: (SI.tlmLists || {})[list] || short, items: its }));
}
const tlmBadges = (c) => tlmGroupsOf(c).map((g) => `<span class="tlm-badge is-${esc(g.list)}">${esc(g.short)} <b>${g.items.length}</b></span>`).join('');
const tlmGroupHtml = (g) => `<div class="tlm-grp is-${esc(g.list)}"><div class="tlm-grp-head"><i class="bi ${TLM_ICON[g.list] || 'bi-box-seam'}"></i><span>${esc(g.label)}</span>`
    + `<span class="tlm-grp-n">${g.items.length}</span></div><div class="tlm-grp-items">${g.items.map((n) => `<span class="tlm-grp-chip">${esc(n)}</span>`).join('')}</div></div>`;

const sections = {
    teachers: {
        list: '#teacher-list', form: '#teacher-form', key: 'teachers', done: 'Teacher added', updated: 'Teacher updated',
        empty: '<i class="bi bi-person-x"></i><strong>No absent teachers added</strong><span>Add each absent teacher below (the number comes from step 2).</span>',
        // table on wide screens (header below), card on phones; cells carry data-label for the phone layout
        head: ['Sl.', 'Teacher', 'Type', 'Reason for absence', 'Remarks', ''],
        row: (t, i) => {
            const type = String(t.values?.teacher_type || ''); // regular / contractual / tutor: avatar ring colour
            const ul = t.values?.reason === 'UL'; // unauthorised leave stands out in red
            return `<div class="rs-item rs-grid rs-teacher at-${esc(type)}" data-id="${esc(t.id)}">
            <span class="rs-sl">${i + 1}</span>
            <div class="rs-who"><span class="rs-avatar at-avatar">${esc(initials(t.teacher_name))}</span>
                <div><span class="rs-title">${esc(t.teacher_name)}</span></div></div>
            <div class="rs-cell rs-nowrap" data-label="Type">${chip('<i class="bi bi-person-badge"></i>' + esc(t.type), 'type')}</div>
            <div class="rs-cell rs-nowrap" data-label="Reason">${chip(`<i class="bi ${ul ? 'bi-exclamation-octagon' : 'bi-calendar-x'}"></i>` + esc(t.reason), ul ? 'bad' : 'warn')}</div>
            <div class="rs-cell rs-rem" data-label="Remarks">${t.remarks ? `<span class="rs-quote" title="${esc(t.remarks)}">${esc(t.remarks)}</span>` : '<span class="rs-dash">—</span>'}</div>
            ${rowActions(t)}</div>`;
        },
    },
    classrooms: {
        list: '#classroom-list', form: '#classroom-form', key: 'classrooms', done: 'Classroom record added', updated: 'Classroom record updated',
        empty: '<i class="bi bi-easel"></i><strong>No classroom records yet</strong><span>Add at least one classroom observation below.</span>',
        head: ['Sl.', 'Class', 'Subject & teacher', 'Use of TLM', 'Participation', ''],
        row: (c, i) => `<div class="rs-item rs-grid rs-class-row" data-id="${esc(c.id)}">
            <span class="rs-sl">${i + 1}</span>
            <span class="rs-classbadge" title="${esc(c.class)}">${esc(String(c.values.class))}</span>
            <div class="rs-who"><div><span class="rs-title">${esc(c.subject)}</span><span class="rs-sub"><i class="bi bi-person"></i>${esc(c.teacher_name)}</span></div></div>
            <div class="rs-cell" data-label="Use of TLM"><div class="tlm-use">${chip(`<i class="bi ${c.tlm_ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill'}"></i>` + (c.tlm_ok ? 'Yes' : 'No'), c.tlm_ok ? 'ok' : 'bad')}${tlmBadges(c)}</div></div>
            <div class="rs-cell" data-label="Participation">${chip(`<i class="bi ${c.participation_ok ? 'bi-emoji-smile' : 'bi-emoji-frown'}"></i>` + esc(c.participation), c.participation_ok ? 'ok' : 'bad')}</div>
            ${rowActions(c)}
            ${tlmGroupsOf(c).length || c.remarks ? `<div class="rs-extra">
                ${tlmGroupsOf(c).length ? `<div class="tlm-grps">${tlmGroupsOf(c).map(tlmGroupHtml).join('')}</div>` : ''}
                ${c.remarks ? `<div class="rs-note"><i class="bi bi-chat-left-text"></i>${esc(c.remarks)}</div>` : ''}</div>` : ''}
            </div>`,
    },
    funds: {
        list: '#fund-list', form: '#fund-form', key: 'funds', done: 'Year saved',
        empty: '<i class="bi bi-cash-stack"></i><strong>No years saved yet</strong><span>Optional: save the fund details of any financial year.</span>',
        head: ['Financial year', 'Received', 'Expenditure', 'Balance', ''],
        row: (f) => {
            const bal = toNum(f.balance);
            const heads = f.heads.filter((h) => h.received !== '0.00' || h.expenditure !== '0.00');
            return `<div class="rs-item rs-grid rs-fund fund-saved">
            <div class="rs-who"><span class="rs-fy"><i class="bi bi-calendar3"></i>FY ${esc(fyShort(f.financial_year))}</span></div>
            <div class="rs-cell rs-amt" data-label="Received">₹ ${esc(fmt(toNum(f.total_received)))} <em>L</em></div>
            <div class="rs-cell rs-amt" data-label="Expenditure">₹ ${esc(fmt(toNum(f.total_expenditure)))} <em>L</em></div>
            <div class="rs-cell rs-amt ${bal < 0 ? 'neg' : 'pos'}" data-label="Balance">₹ ${esc(fmt(bal))} <em>L</em></div>
            ${rowActions({ id: f.financial_year, delete_url: f.delete_url }, 'data-edit-fund')}
            ${heads.length ? `<div class="rs-extra"><div class="fund-saved-heads">${heads
                .map((h) => `<span class="src-${esc(h.source)}"><b>${SRC[h.source] || ''}</b>${esc(h.label.replace(/\s*\(SSA\)$/, ''))}<i>${esc(h.received)} / ${esc(h.expenditure)}</i></span>`).join('')}</div></div>` : ''}
            </div>`;
        },
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
    const any = rows.length || waiting.length;
    // a table on wide screens: header strip + rows in one rounded box; on phones each row is a card
    const head = any && s.head ? `<div class="rs-thead rs-grid rs-${s.key === 'teachers' ? 'teacher' : s.key === 'classrooms' ? 'class-row' : 'fund'}" aria-hidden="true">${s.head.map((h) => `<span>${esc(h)}</span>`).join('')}</div>` : '';
    list.classList.toggle('rs-table', !!any && !!s.head);
    list.innerHTML = any ? head + rows.map(s.row).join('') + waiting.map((o) => pendingRow(s.key, o.body)).join('') : `<div class="rs-empty">${s.empty}</div>`;
    const count = $(`[data-count="${s.key}"]`);
    if (count) {
        const n = rows.length + waiting.length;
        // Modification V9: absent teachers show "added / allowed" (Total teachers - Present on step 2)
        const limit = s.key === 'teachers' && Number.isInteger(SI.absentLimit) ? SI.absentLimit : null;
        count.textContent = limit === null ? n : `${n} / ${limit}`;
        count.classList.toggle('has', n > 0);
        count.classList.toggle('is-full', limit !== null && n >= limit);
    }
    if (s.editing) list.querySelector(`.rs-item[data-id="${s.editing}"]`)?.classList.add('is-editing');
    if (s.key === 'funds') { markSavedYears(); renderYearTable(); }
    if (s.key === 'teachers') { syncAllPresent(); renderAbsentTally(rows); }
}

/** Step 3: counts by leave reason under the absent list, e.g. "2 · Casual Leave (CL)  1 · Unauthorised Leave". */
function renderAbsentTally(rows) {
    const box = $('[data-absent-tally]');
    if (!box) return;
    const by = new Map();
    rows.forEach((t) => {
        const k = t.values?.reason || t.reason;
        by.set(k, { label: t.reason, ul: k === 'UL', n: (by.get(k)?.n || 0) + 1 });
    });
    box.classList.toggle('d-none', rows.length === 0);
    box.innerHTML = rows.length ? `<span class="tally-total"><i class="bi bi-people"></i>${rows.length} absent</span>`
        + [...by.values()].map((r) => `<span class="tally-chip ${r.ul ? 'bad' : ''}"><b>${r.n}</b>${esc(r.label)}</span>`).join('') : '';
}

/**
 * Step 3 follows step 2 (21-11-2026): SI.absentLimit = Total teachers - Present.
 * 0: nothing to fill (form hidden). N: exactly N absent rows; the add button rests once N are added (edit still works).
 */
function syncAllPresent() {
    const form = $('#teacher-form');
    if (!form) return;
    const limit = Number.isInteger(SI.absentLimit) ? SI.absentLimit : null;
    const rows = (SI.teachers || []).length + offline.ops.filter((o) => o.section === 'teachers').length;
    const editing = !!sections.teachers.editing;
    form.classList.toggle('d-none', !limit && !editing);
    $('[data-absent-head]')?.classList.toggle('d-none', !limit && rows === 0);
    const full = limit !== null && rows >= limit && !editing;
    const btn = $('.row-form-btn', form);
    if (btn) btn.disabled = full;
    form.classList.toggle('is-full', full);
    const pill = $('[data-absent-progress]');
    if (pill && limit) {
        pill.textContent = `${Math.min(rows, limit)} / ${limit} added`;
        pill.classList.toggle('is-done', rows === limit);
        $('[data-absent-state]')?.classList.toggle('is-done', rows === limit);
    }
    const list = $('#teacher-list');
    if (list && rows === 0) list.innerHTML = limit ? `<div class="rs-empty">${sections.teachers.empty}</div>` : '';
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
    if (s.key === 'classrooms') renderTlm(row?.values?.tlm_items || []);
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
        const fd = new FormData(form);
        const data = Object.fromEntries(fd);
        if (s.key === 'classrooms') { data.tlm_items = fd.getAll('tlm_items[]'); delete data['tlm_items[]']; } // several ticks
        const editing = s.key !== 'funds' && form.dataset.editUrl;
        if (!editing && s.key !== 'funds') data.row_uid = newUid(); // a replayed add never creates a duplicate
        // Only new rows and fund years can wait on this device; editing or removing needs the network.
        const op = editing ? null : {
            method: 'post', url: form.dataset.url, body: data, section: s.key,
            key: s.key === 'funds' ? `fund:${data.financial_year}` : null,
            onQueued: () => {
                if (s.key !== 'funds') { form.reset(); window.siSyncSelects?.(form); if (s.key === 'classrooms') renderTlm([]); }
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
/**
 * Modification V9: amounts in lakh are written with 2 decimals: ".2" -> "0.20", "5" -> "5.00", "1.5" -> "1.50".
 * Anything that is not a plain number with up to 2 decimals is left as typed, so validateFund() can point at it.
 */
function formatLakh(raw) {
    const v = String(raw ?? '').trim().replace(/,/g, '');
    if (v === '') return '';
    if (!/^\d*(\.\d{0,2})?$/.test(v) || v === '.') return v;
    const n = Number(v);
    return Number.isFinite(n) ? n.toFixed(2) : v;
}
function formatFundInput(el) {
    const f = formatLakh(el.value);
    if (f !== el.value) { el.value = f; return true; }
    return false;
}
/** What is wrong with one amount box, with the fix (26-11-2026); null when it is fine or empty. */
function fundProblem(el) {
    if (el.validity?.badInput) return 'Numbers only, e.g. 1.25 (amount in lakh). Remove letters, commas or spaces.';
    if (el.value === '') return null;
    if (el.value.trim().startsWith('-')) return 'The amount cannot be negative. Enter 0 or more.';
    if (!/^\d+(\.\d{1,2})?$/.test(el.value)) return 'Use a number with up to 2 decimals, e.g. 1.25 or .2 (= 0.20)';
    if (parseFloat(el.value) > 99999) return 'Too large. Enter the amount in lakh (e.g. 2.50 for 2,50,000).';
    return null;
}
function validateFund() {
    clearFormErrors(fundForm);
    $$('#fund-amounts input', fundForm).forEach(formatFundInput);
    if (!fundForm.querySelector('input[name="financial_year"]:checked')) { setFieldError(fundForm, 'financial_year', 'Select the financial year first.'); focusFirstError(fundForm); return false; }
    let ok = true, any = false;
    $$('#fund-amounts input', fundForm).forEach((el) => {
        const problem = fundProblem(el);
        if (el.value === '' && !problem) return;
        any = true;
        if (problem) { setFieldError(fundForm, el.name, problem); ok = false; }
    });
    if (ok && !any) { formError(fundForm, 'Enter at least one amount for this year.'); ok = false; }
    if (!ok) focusFirstError(fundForm);
    return ok;
}
if (fundForm) {
    markSavedYears();
    // V9: format each amount when the officer leaves the box, and check it at once
    fundForm.addEventListener('focusout', (e) => {
        const el = e.target;
        if (!el.matches?.('#fund-amounts input')) return;
        if (formatFundInput(el)) fundTotals();
        const problem = fundProblem(el);
        if (problem) setFieldError(fundForm, el.name, problem); else clearFieldError(fundForm, el.name);
    });
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
        const inputs = $$('[data-suggestion]', sugBox);
        const show = (errors) => inputs.forEach((el, n) => {
            const msg = (errors[`suggestions.${n}`] || [])[0];
            el.classList.toggle('is-invalid', !!msg);
            let fb = el.parentElement.querySelector('.field-error');
            if (!msg) { fb?.remove(); el.removeAttribute('aria-invalid'); return; }
            if (!fb) { fb = document.createElement('div'); fb.className = 'field-error'; fb.id = `sug-err-${n}`; el.parentElement.appendChild(fb); }
            fb.innerHTML = `<i class="bi bi-exclamation-circle"></i>${esc(msg)}`;
            el.setAttribute('aria-invalid', 'true');
            el.setAttribute('aria-describedby', fb.id);
        });
        // 26-11-2026: checked here first, with the number of the suggestion and the fix
        const local = {};
        inputs.forEach((el, n) => {
            const v = el.value.trim();
            if (v.length === 1) local[`suggestions.${n}`] = [`Suggestion ${n + 1} is too short: write at least 2 characters, or clear the box.`];
            else if (el.value.length > 500) local[`suggestions.${n}`] = [`Suggestion ${n + 1} is too long: at most 500 characters. Shorten it.`];
        });
        show(local);
        if (Object.keys(local).length) { indicator('error', 'Not saved: fix the red box'); return; }
        const body = { suggestions: inputs.map((i) => i.value) };
        return queue(() => axios.post(sugBox.dataset.url, body), (errors, message) => {
            show(errors);
            if (!Object.keys(errors).some((k) => k.startsWith('suggestions.'))) window.toast(message || 'The suggestions were not saved. Please check them.', 'danger');
        }, { method: 'post', url: sugBox.dataset.url, body, key: 'suggestions' });
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
    // 26-11-2026: answers refused (red boxes) are not saved: they come first in the list, with their message
    const unsaved = Object.entries(SI.unsaved).filter(([c]) => document.getElementById(`q-${c}`))
        .map(([c, m]) => ({ code: c, message: `Not saved: ${SI.questions[c]?.label || c}. ${m}` }));
    const items = [...unsaved, ...SI.missingNow.filter((m) => !SI.unsaved[m.code])];
    if (items.length && missModal && !a.dataset.force) {
        leaveTo = a.href;
        $('#missing-count').textContent = items.length;
        $('#missing-list').innerHTML = items.slice(0, 6).map((m) => `<li class="${m.message.startsWith('Not saved') ? 'text-danger fw-semibold' : ''}"><i class="bi bi-dot"></i>${esc(m.message)}</li>`).join('')
            + (items.length > 6 ? `<li class="text-secondary">… and ${items.length - 6} more</li>` : '');
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
        // a blank optional count (question with a default, e.g. TSI_05..13) counts as its default (0)
        const def = SI.questions?.[code]?.default;
        if (el && el.value === '' && def !== undefined) return Number(def);
        if (!el || el.value === '') return null;
        const n = parseInt(el.value, 10);
        return Number.isFinite(n) ? n : null;
    };
    const fmtInt = (n) => (n === null ? '–' : n.toLocaleString('en-IN'));
    const TEACHER_CODES = ['TSI_04', 'TSI_05', 'TSI_06', 'TSI_07', 'TSI_08'];
    const paint = (box, { total, present, absent, extra = '', bad = false }) => {
        $('[data-at="total"]', box).textContent = fmtInt(total);
        const pe = $('[data-at="present"]', box), ae = $('[data-at="absent"]', box); // V9: the teachers box shows the total only
        if (pe) pe.textContent = fmtInt(present);
        if (ae) ae.textContent = absent === null ? '–' : (absent < 0 ? '−' + Math.abs(absent).toLocaleString('en-IN') : fmtInt(absent));
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
            const bad = total !== null && present !== null && present > total;
            paint(t, { total, present, absent: null, bad, extra: bad ? `Teachers present (${fmtInt(present)}) is more than the total. Please check.` : '' });
        }
        // 21-11-2026: absent = Total - Present decides step 3; with none absent, Next skips step 3
        const ab = $('[data-auto-total="absent"]');
        if (ab) {
            const parts = TEACHER_CODES.map(val);
            const total = parts.includes(null) ? null : parts.reduce((x, y) => x + y, 0);
            const el = document.querySelector('input[name="TSI_09"]');
            const present = el && el.value !== '' ? parseInt(el.value, 10) : null; // required: no default
            const known = total !== null && Number.isFinite(present);
            const absent = known ? total - present : null;
            const bad = known && absent < 0;
            $('[data-at="ttotal"]', ab).textContent = fmtInt(total);
            $('[data-at="present"]', ab).textContent = fmtInt(Number.isFinite(present) ? present : null);
            $('[data-at="total"]', ab).textContent = known && !bad ? fmtInt(absent) : '–';
            $('[data-at-extra]', ab).innerHTML = !known ? 'Enter the teacher numbers and Teachers present.'
                : bad ? '<i class="bi bi-x-circle-fill"></i> Present is more than Total teachers. Please check.'
                    : absent === 0 ? '<i class="bi bi-check-circle-fill"></i> All teachers present: step 3 is not needed.'
                        : `<i class="bi bi-arrow-right-circle-fill"></i> Add the details of ${absent} absent teacher${absent === 1 ? '' : 's'} in step 3.`;
            ab.classList.toggle('is-bad', bad);
            ab.classList.toggle('is-ready', known && !bad && absent === 0);
            ab.classList.toggle('is-need', known && !bad && absent > 0);
            const next = $('[data-next][data-step4-href]');
            if (next) next.href = known && absent === 0 ? next.dataset.step4Href : next.dataset.step3Href;
        }
        // Totals of trained / untrained and male / female (TSI_10..13); Trained + Untrained must be equal to Total teachers
        const bd = $('[data-auto-total="breakdown"]');
        if (bd) {
            const [mt, mu, ft, fu] = ['TSI_10', 'TSI_11', 'TSI_12', 'TSI_13'].map(val);
            const parts = TEACHER_CODES.map(val);
            const total = parts.includes(null) ? null : parts.reduce((x, y) => x + y, 0);
            const all = [mt, mu, ft, fu].every((x) => x !== null);
            const sum = (...xs) => (xs.every((x) => x !== null) ? xs.reduce((a, b) => a + b, 0) : null);
            $('[data-at="trained"]', bd).textContent = fmtInt(sum(mt, ft));
            $('[data-at="untrained"]', bd).textContent = fmtInt(sum(mu, fu));
            // 23-11-2026: male / female totals as well
            const me = $('[data-at="male"]', bd), fe = $('[data-at="female"]', bd);
            if (me) me.textContent = fmtInt(sum(mt, mu));
            if (fe) fe.textContent = fmtInt(sum(ft, fu));
            const s = all ? mt + mu + ft + fu : null;
            $('[data-at="sum"]', bd).textContent = fmtInt(s);
            const checked = all && total !== null;
            const bad = checked && s !== total; // must be exactly equal to Total teachers
            $('[data-at-extra]', bd).innerHTML = !checked ? (total === null ? 'Enter the teacher numbers above first.' : '')
                : s > total ? `<i class="bi bi-x-circle-fill"></i> More than Total teachers (${fmtInt(total)})`
                    : s < total ? `<i class="bi bi-x-circle-fill"></i> Less than Total teachers (${fmtInt(total)}): ${fmtInt(total - s)} more to count`
                        : `<i class="bi bi-check-circle-fill"></i> Equal to Total teachers (${fmtInt(total)})`;
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
    const icon = wrap.dataset.acIcon || 'bi-book'; // V11: teacher names use bi-person
    const freeHint = wrap.dataset.acFree !== 'off'; // names: no "keep as typed" line, the list just stays closed

    const close = () => { list.classList.add('d-none'); input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; };
    const mark = (label, q) => {
        const i = q ? label.toLowerCase().indexOf(q.toLowerCase()) : -1;
        return i < 0 ? esc(label) : `${esc(label.slice(0, i))}<b>${esc(label.slice(i, i + q.length))}</b>${esc(label.slice(i + q.length))}`;
    };
    const render = (q) => {
        const typed = input.value.trim();
        const exact = items.some((s) => s.toLowerCase() === typed.toLowerCase());
        if (!items.length && (!typed || !freeHint)) { close(); return; }
        list.innerHTML = items.map((s, i) => `<div class="ac-item" role="option" id="${input.id}_opt${i}" data-i="${i}" aria-selected="false"><i class="bi ${icon}"></i><span>${mark(s, q)}</span></div>`).join('')
            + (typed && !exact && freeHint ? `<div class="ac-free"><i class="bi bi-pencil"></i>Not in the list? Keep “${esc(typed)}” as typed.</div>` : '');
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
            cache.set(key, r.data.items || r.data.subjects || []);
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
        const r = rejectedItem(op, err, (c) => SI.questions[c]?.label);
        addRejected(SI.inspectionKey, r);
        renderRejected();
        window.toast(`${r.what} could not be saved: ${r.message}`, 'danger');
    },
    onAuth: (err) => handleAuthError(err),
};

/**
 * 26-11-2026: changes kept on this device that the server refused are listed above the form (sessionStorage),
 * each with what it was, why, and what to do; an answer on this step is also marked red with the message.
 */
function renderRejected() {
    const list = rejectedList(SI.inspectionKey);
    let box = document.getElementById('si-rejected');
    if (!list.length) { box?.remove(); return; }
    if (!box) {
        box = document.createElement('div');
        box.id = 'si-rejected';
        box.className = 'alert alert-danger text-sm';
        box.setAttribute('role', 'alert');
        document.querySelector('.wizard-card')?.before(box);
    }
    box.innerHTML = `<div class="fw-semibold mb-1"><i class="bi bi-exclamation-octagon me-1"></i>${list.length} change${list.length === 1 ? '' : 's'} made without network could not be saved. Please enter ${list.length === 1 ? 'it' : 'them'} again:</div>`
        + `<ul class="mb-2 ps-3">${list.map((r) => `<li><b>${esc(r.what)}</b>: ${esc(r.message)}</li>`).join('')}</ul>`
        + '<button type="button" class="btn btn-sm btn-light" data-rejected-clear><i class="bi bi-check2"></i>I have entered them again</button>';
    box.querySelector('[data-rejected-clear]').addEventListener('click', () => { clearRejected(SI.inspectionKey); renderRejected(); });
    list.forEach((r) => { const b = r.code && document.getElementById(`q-${r.code}`); if (b) questionError(b, r.message); });
}

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
    renderRejected();
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
