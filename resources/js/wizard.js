// Inspection questionnaire: auto-save after every answer, conditional rules, progress (PRD 8.2, 8.3, 10.1)
const SI = window.SI;
const esc = window.escapeHtml;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

let pending = 0;
let chain = Promise.resolve();

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
        const msg = err.response?.data?.message || (err.response ? 'Could not save' : 'No connection. Will retry when you change an answer.');
        indicator('error', 'Not saved');
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
    document.dispatchEvent(new CustomEvent('si:progress', { detail: d }));
}
window.siRefreshProgress = () => axios.get(SI.progressUrl).then((r) => applyProgress(r.data)).catch(() => {});

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

/** Re-evaluate visibility, required stars, remarks and photo boxes for all questions on this step. */
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
            const show = q.photo === 'required' || q.photo === 'optional' || req;
            ph.classList.toggle('d-none', !show);
            ph.querySelector('.photo-req')?.classList.toggle('d-none', !req);
            ph.querySelector('.photo-opt')?.classList.toggle('d-none', req);
        }
    }
    attendanceCheck();
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
                if (type === 'number') attendanceCheck();
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

/* ---------------- Step 4: attendance ---------------- */
function attendanceCheck() {
    const box = $('#att-check');
    if (!box) return;
    const n = (c) => (filled(SI.answers[c]) ? parseInt(SI.answers[c], 10) : null);
    const [s, w, p, a, e, sp] = ['ATT_SANCTIONED', 'ATT_WORKING', 'ATT_PRESENT', 'ATT_ABSENT', 'ATT_ENROLLED', 'ATT_STUDENTS_PRESENT'].map(n);
    const msgs = [];
    if (w !== null && p !== null && a !== null && p + a !== w) msgs.push(`Present (${p}) + Absent (${a}) = ${p + a}, but Working is ${w}.`);
    if (s !== null && w !== null && w > s) msgs.push('Working teachers cannot exceed sanctioned posts.');
    if (e !== null && sp !== null && sp > e) msgs.push('Students present cannot exceed enrolment.');
    const tc = SI.teachers?.length ?? 0;
    if (a !== null && a !== tc) msgs.push(`Add details for each absent teacher (${tc} of ${a} added).`);
    box.className = `alert text-sm py-2 ${msgs.length ? 'alert-warning' : (w !== null && p !== null && a !== null ? 'alert-success' : 'd-none')}`;
    box.innerHTML = msgs.length ? msgs.map((m) => `<div><i class="bi bi-exclamation-triangle me-1"></i>${esc(m)}</div>`).join('') : '<i class="bi bi-check-circle me-1"></i>Attendance figures are consistent.';
}

function renderTeachers() {
    const list = $('#teacher-list');
    if (!list) return;
    $('#teacher-count').textContent = SI.teachers.length;
    list.innerHTML = SI.teachers.length ? SI.teachers.map((t) => `
        <div class="list-item py-2">
            <i class="bi bi-person text-secondary"></i>
            <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-sm">${esc(t.teacher_name)}</div>
                <div class="text-xs text-secondary">${esc(t.status)}${t.remarks ? ' · ' + esc(t.remarks) : ''}</div></div>
            <button type="button" class="btn btn-sm btn-light btn-icon" data-del="${esc(t.delete_url)}" aria-label="Remove"><i class="bi bi-trash text-danger"></i></button>
        </div>`).join('') : '<div class="text-sm text-secondary p-2">No absent teachers added.</div>';
    attendanceCheck();
}

const tForm = $('#teacher-form');
if (tForm) {
    renderTeachers();
    tForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(tForm));
        queue(() => axios.post(tForm.dataset.url, data).then((r) => { SI.teachers = r.data.teachers; renderTeachers(); tForm.reset(); tForm.teacher_name.focus(); return r; }));
    });
    $('#teacher-list').addEventListener('click', (e) => {
        const b = e.target.closest('[data-del]');
        if (b) queue(() => axios.delete(b.dataset.del).then((r) => { SI.teachers = r.data.teachers; renderTeachers(); return r; }));
    });
}

/* ---------------- Step 5: funds ---------------- */
function renderFunds() {
    const body = $('#fund-list');
    if (!body) return;
    body.innerHTML = SI.funds.length ? SI.funds.map((f) => `
        <tr><td class="text-sm">${esc(f.financial_year)}</td><td class="text-sm">${esc(f.category)}</td>
        <td class="text-end text-sm">₹${esc(f.received)}</td><td class="text-end text-sm">₹${esc(f.utilised)}</td>
        <td class="text-end text-sm fw-semibold">₹${esc(f.balance)}</td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-light btn-icon" data-del="${esc(f.delete_url)}" aria-label="Remove"><i class="bi bi-trash text-danger"></i></button></td></tr>`).join('')
        : '<tr><td colspan="6" class="text-sm text-secondary">No fund entries.</td></tr>';
}
const fForm = $('#fund-form');
if (fForm) {
    renderFunds();
    fForm.addEventListener('submit', (e) => {
        e.preventDefault();
        queue(() => axios.post(fForm.dataset.url, Object.fromEntries(new FormData(fForm))).then((r) => {
            SI.funds = r.data.funds; renderFunds();
            fForm.amount_received.value = ''; fForm.amount_utilised.value = '';
            return r;
        }));
    });
    $('#fund-list').addEventListener('click', (e) => {
        const b = e.target.closest('[data-del]');
        if (b) queue(() => axios.delete(b.dataset.del).then((r) => { SI.funds = r.data.funds; renderFunds(); return r; }));
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
    if (window.siPendingWork() > 0) { e.preventDefault(); e.returnValue = ''; }
});
// Flush pending debounced text before navigating with the step links
$$('[data-next], [data-step-link], .action-bar a').forEach((a) => a.addEventListener('click', async (e) => {
    const active = document.activeElement;
    if (active && active.matches('[data-answer], [data-remarks], [data-suggestion]')) active.blur();
    if (pending > 0) { e.preventDefault(); await chain; window.location = a.href; }
}));

refreshRules();
