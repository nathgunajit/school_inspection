// Inspection questionnaire (v2): auto-save after every answer, conditional rules, repeating sections, progress
const SI = window.SI;
const esc = window.escapeHtml;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

let pending = 0;
let chain = Promise.resolve();

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

/** Serialise requests so answers are saved in order. */
function queue(fn) {
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
        const msg = err.response?.data?.message || (err.response ? 'Could not save' : 'No connection. Will retry when you change an answer.');
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
    $('#step-missing') && ($('#step-missing').textContent = miss.length ? `${miss.length} item(s) pending in this step` : 'Step complete');
    const warn = (d.warnings || {})[SI.step] || [];
    const box = $('#step-warnings');
    if (box) box.innerHTML = warn.map((w) => `<div class="alert alert-warning text-sm py-2 mt-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>${esc(w)}</div>`).join('');
    document.dispatchEvent(new CustomEvent('si:progress', { detail: d }));
}
window.siRefreshProgress = () => axios.get(SI.progressUrl).then((r) => applyProgress(r.data)).catch((e) => handleAuthError(e));

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
function isRequired(q) {
    if (!isVisible(q)) return false;
    if (q.required) return true;
    if (q.required_if) return matches(SI.answers[q.required_if[0]], q.required_if[1]);
    if (q.required_unless) { const v = SI.answers[q.required_unless[0]]; return filled(v) && !matches(v, q.required_unless[1]); }
    return false;
}
function photoRequired(q, v) {
    if (q.photo === 'required') return true;
    return !!(q.photo && q.photo.required_if && matches(v, q.photo.required_if));
}

/** Re-evaluate visibility, required stars, remarks and photo boxes for questions on this step. */
function refreshRules() {
    for (const q of Object.values(SI.questions)) {
        const block = document.getElementById(`q-${q.code}`);
        if (!block) continue;
        const v = SI.answers[q.code];
        block.classList.toggle('d-none', !isVisible(q));
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
function saveAnswer(code, value) {
    SI.answers[code] = value;
    refreshRules();
    return queue(() => axios.post(SI.saveUrl, { code, value }));
}

$$('.q-block').forEach((block) => {
    const code = block.dataset.code;
    const type = block.dataset.type;
    const instant = ['radio', 'multi', 'select', 'date'].includes(type);

    block.querySelectorAll('[data-answer]').forEach((el) => {
        if (instant) {
            el.addEventListener('change', () => saveAnswer(code, readValue(block)));
        } else {
            el.addEventListener('input', () => {
                SI.answers[code] = readValue(block);
                clearTimeout(timers[code]);
                timers[code] = setTimeout(() => saveAnswer(code, readValue(block)), 800);
            });
            el.addEventListener('blur', () => {
                if (timers[code]) { clearTimeout(timers[code]); timers[code] = null; saveAnswer(code, readValue(block)); }
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

/* ---------------- Repeating sections (absentee, classroom, fund) ---------------- */
const delBtn = (url) => `<button type="button" class="btn btn-sm btn-light btn-icon" data-del="${esc(url)}" aria-label="Remove"><i class="bi bi-trash text-danger"></i></button>`;

const sections = {
    teachers: {
        list: '#teacher-list', form: '#teacher-form', key: 'teachers',
        empty: 'No absent teachers added.',
        row: (t) => `<div class="row-item"><span class="row-icon tone-danger"><i class="bi bi-person-x"></i></span>
            <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-sm">${esc(t.teacher_name)}</div>
            <div class="text-xs text-secondary">${esc(t.type)} · ${esc(t.reason)}${t.remarks ? ' · ' + esc(t.remarks) : ''}</div></div>${delBtn(t.delete_url)}</div>`,
    },
    classrooms: {
        list: '#classroom-list', form: '#classroom-form', key: 'classrooms',
        empty: 'No classroom records yet. Add at least one.',
        row: (c) => `<div class="row-item"><span class="row-icon tone-primary"><i class="bi bi-easel"></i></span>
            <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-sm">${esc(c.class)} · ${esc(c.subject)}</div>
            <div class="text-xs text-secondary">${esc(c.teacher_name)} · TLM: ${esc(c.tlm)} · Participation: ${esc(c.participation)}${c.remarks ? ' · ' + esc(c.remarks) : ''}</div></div>${delBtn(c.delete_url)}</div>`,
    },
    funds: {
        list: '#fund-list', form: '#fund-form', key: 'funds',
        empty: 'No fund details entered.',
        row: (f) => `<div class="row-item align-items-start"><span class="row-icon tone-success"><i class="bi bi-cash-coin"></i></span>
            <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-sm">${esc(f.financial_year)}
                <span class="text-xs text-secondary fw-normal ms-1">Received ₹${esc(f.total_received)} L · Spent ₹${esc(f.total_expenditure)} L · Balance ₹${esc(f.balance)} L</span></div>
            <div class="table-responsive"><table class="table table-sm text-xs mb-0 mt-1"><thead><tr><th>Head</th><th class="text-end">Received</th><th class="text-end">Expenditure</th><th class="text-end">Balance</th></tr></thead><tbody>
            ${f.heads.map((h) => `<tr><td>${esc(h.label)}</td><td class="text-end">${esc(h.received)}</td><td class="text-end">${esc(h.expenditure)}</td><td class="text-end">${esc(h.balance)}</td></tr>`).join('')}
            </tbody></table></div></div>
            <div class="d-flex flex-column gap-1"><button type="button" class="btn btn-sm btn-light btn-icon" data-edit-fund="${esc(f.financial_year)}" aria-label="Edit"><i class="bi bi-pencil"></i></button>${delBtn(f.delete_url)}</div></div>`,
    },
};

function renderSection(s) {
    const list = $(s.list);
    if (!list) return;
    const rows = SI[s.key] || [];
    list.innerHTML = rows.length ? rows.map(s.row).join('') : `<div class="text-sm text-secondary p-3 text-center">${s.empty}</div>`;
}

for (const s of Object.values(sections)) {
    const form = $(s.form);
    if (!form) continue;
    renderSection(s);
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(form));
        queue(() => axios.post(form.dataset.url, data).then((r) => {
            SI[s.key] = r.data[s.key];
            renderSection(s);
            form.reset();
            if (s.key === 'funds') $('#fund-amounts').disabled = true;
            form.querySelector('input,select')?.focus();
            window.toast('Saved', 'success');
            return r;
        }));
    });
    $(s.list).addEventListener('click', (e) => {
        const b = e.target.closest('[data-del]');
        if (b && confirm('Remove this record?')) {
            queue(() => axios.delete(b.dataset.del).then((r) => { SI[s.key] = r.data[s.key]; renderSection(s); return r; }));
        }
        const ed = e.target.closest('[data-edit-fund]');
        if (ed) {
            const f = SI.funds.find((x) => x.financial_year === ed.dataset.editFund);
            form.financial_year.value = f.financial_year;
            $('#fund-amounts').disabled = false;
            for (const [k, v] of Object.entries(f.values)) if (form[k]) form[k].value = v || '';
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
}

// Fund: select the year first, then the amounts unlock (prefilled if the year exists)
const fundForm = $('#fund-form');
if (fundForm) {
    fundForm.financial_year.addEventListener('change', () => {
        const fy = fundForm.financial_year.value;
        $('#fund-amounts').disabled = !fy;
        const existing = (SI.funds || []).find((x) => x.financial_year === fy);
        for (const el of $$('#fund-amounts input')) el.value = existing ? (existing.values[el.name] || '') : '';
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

/* ---------------- Leaving the page ---------------- */
window.siPendingWork = () => pending + (window.siPendingUploads?.() ?? 0);
window.addEventListener('beforeunload', (e) => {
    if (!redirecting && window.siPendingWork() > 0) { e.preventDefault(); e.returnValue = ''; }
});
$$('[data-next], [data-step-link], .action-bar a').forEach((a) => a.addEventListener('click', async (e) => {
    const active = document.activeElement;
    if (active && active.matches('[data-answer], [data-remarks], [data-suggestion]')) active.blur();
    if (pending > 0) { e.preventDefault(); await chain; window.location = a.href; }
}));

refreshRules();
