// Registration form: dynamic location fields (PRD 5.2) and password strength.
import { initSelfie } from './selfie';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('register-form');
    if (!form) return;

    const fields = {
        district: form.querySelector('[data-loc="district"]'),
        block: form.querySelector('[data-loc="block"]'),
        cluster: form.querySelector('[data-loc="cluster"]'),
    };

    function applyLevel() {
        const r = form.querySelector('input[name="level"]:checked');
        const need = {
            district: r?.dataset.needsDistrict === '1',
            block: r?.dataset.needsBlock === '1',
            cluster: r?.dataset.needsCluster === '1',
        };
        for (const [k, el] of Object.entries(fields)) {
            el.classList.toggle('d-none', !need[k]);
            el.querySelector('select').required = need[k];
        }
    }
    form.querySelectorAll('input[name="level"]').forEach((r) => r.addEventListener('change', applyLevel));
    applyLevel();

    // Restore old() selections after a validation error, or prefill when there is a single state.
    // 27-11-2026: run last and not awaited, so the password, upload and consent handlers below never wait on the network.
    const restoreLocations = async () => {
        const chain = ['#state_id', '#district_id', '#block_id', '#cluster_id'].map((s) => form.querySelector(s));
        for (let i = 0; i < chain.length - 1; i++) {
            const parent = chain[i];
            const child = chain[i + 1];
            if (!parent.value) break;
            await window.siCascade(parent);
            if (child.dataset.old) child.value = child.dataset.old;
            else break;
        }
    };

    // Password strength meter
    const pw = form.querySelector('#password');
    const meter = document.getElementById('pw-meter');
    const confirmPw = form.querySelector('#password_confirmation');
    const match = document.getElementById('pw-match');
    const RULES = { len: (v) => v.length >= 8 && v.length <= 64, lower: (v) => /[a-z]/.test(v), upper: (v) => /[A-Z]/.test(v), digit: (v) => /\d/.test(v), symbol: (v) => /[^A-Za-z0-9]/.test(v) };
    const checkRules = () => {
        const v = pw.value;
        let score = 0;
        form.querySelectorAll('#pw-rules [data-rule]').forEach((li) => {
            const ok = RULES[li.dataset.rule]?.(v) ?? false;
            score += ok ? 1 : 0;
            li.classList.toggle('is-ok', ok);
            li.querySelector('i').className = ok ? 'bi bi-check-circle-fill' : 'bi bi-circle';
        });
        meter.style.width = `${score * 20}%`;
        meter.style.background = score < 3 ? '#dc2626' : score < 5 ? '#d97706' : '#059669';
    };
    // 26-11-2026: tell the officer at once whether the two passwords match
    const checkMatch = () => {
        if (!match || !confirmPw) return;
        const c = confirmPw.value;
        if (!c) {
            match.classList.add('d-none');
            confirmPw.setCustomValidity('');
            return;
        }
        const ok = c === pw.value;
        match.className = `pw-match ${ok ? 'is-ok' : 'is-bad'}`;
        match.innerHTML = `<i class="bi ${ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill'}"></i>${ok ? 'Passwords match' : 'Passwords do not match'}`;
        confirmPw.setCustomValidity(ok ? '' : 'The two passwords do not match.');
        confirmPw.classList.toggle('is-invalid', !ok);
    };
    pw.addEventListener('input', () => { checkRules(); checkMatch(); });
    confirmPw?.addEventListener('input', checkMatch);
    // the form is novalidate: stop a mismatch here (runs before the password encryption listener on the document)
    form.addEventListener('submit', (e) => {
        if (!confirmPw || !confirmPw.value || confirmPw.value === pw.value) return;
        checkMatch();
        if (!e.defaultPrevented) confirmPw.focus();
        e.preventDefault();
    });
    checkRules();
    checkMatch();

    // Upload zones (employee photo, identity proof): preview, size/type check, remove
    form.querySelectorAll('.upload-zone').forEach((zone) => {
        const input = zone.querySelector('input[type=file]');
        const empty = zone.querySelector('.upload-empty');
        const done = zone.querySelector('.upload-done');
        const img = done.querySelector('img');
        const pdfIcon = done.querySelector('.bi-file-earmark-pdf-fill');
        const err = zone.parentElement.querySelector('.upload-error');
        const exts = (zone.dataset.exts || 'pdf,jpg,jpeg,png').split(',');
        const maxKb = Number(zone.dataset.maxKb || 2048);
        const MAX = maxKb * 1024;
        const limit = maxKb >= 1024 ? `${maxKb / 1024} MB` : `${maxKb} KB`;
        let url = null;
        const fmt = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
        // Modification V7: a file kept on the server from the last attempt (the form came back with errors)
        const keepFlag = zone.querySelector('input.keep-flag');
        const kept = () => !!zone.dataset.keptUrl && keepFlag?.value === '1';
        const showDone = (name, meta, src, isPdf) => {
            img.classList.toggle('d-none', isPdf);
            pdfIcon.classList.toggle('d-none', !isPdf);
            if (src) img.src = src;
            done.querySelector('.upload-name').textContent = name;
            done.querySelector('.upload-meta span').textContent = meta;
            empty.classList.add('d-none');
            done.classList.remove('d-none');
            zone.classList.add('has-file');
        };
        const showKept = () => {
            const isPdf = zone.dataset.keptPdf === '1';
            showDone(zone.dataset.keptName, `${isPdf ? 'PDF document' : 'Image'} · ${fmt(Number(zone.dataset.keptSize))} · kept from your last attempt`,
                isPdf ? null : zone.dataset.keptUrl, isPdf);
            zone.classList.add('is-kept');
        };
        const reset = (msg = '') => {
            input.value = '';
            if (url) URL.revokeObjectURL(url);
            url = null;
            if (keepFlag) keepFlag.value = '0'; // removed or replaced: the kept file is dropped on the next submit
            if (zone.dataset.keptUrl && input.id === 'photo') input.required = true;
            empty.classList.remove('d-none');
            done.classList.add('d-none');
            zone.classList.remove('has-file', 'is-kept');
            err.textContent = msg;
            err.classList.toggle('d-none', !msg);
            zone.classList.toggle('is-invalid', !!msg);
        };
        if (kept()) showKept();
        input.addEventListener('change', () => {
            const f = input.files[0];
            if (!f) return kept() ? showKept() : reset(); // picker cancelled: keep what was there
            zone.classList.remove('is-kept');
            const ext = f.name.split('.').pop().toLowerCase();
            if (!exts.includes(ext)) return reset(`Only ${exts.filter((e) => e !== 'jpeg').map((e) => e.toUpperCase()).join(', ')} files are allowed.`);
            if (f.size > MAX) return reset(`This file is ${fmt(f.size)}. Please choose a file of ${limit} or less.`);
            err.classList.add('d-none');
            zone.classList.remove('is-invalid');
            const isPdf = ext === 'pdf';
            if (url) URL.revokeObjectURL(url);
            url = isPdf ? null : URL.createObjectURL(f);
            showDone(f.name, `${isPdf ? 'PDF document' : 'Image'} · ${fmt(f.size)} · ready to upload`, url, isPdf);
        });
        done.querySelector('.upload-remove').addEventListener('click', (e) => { e.preventDefault(); reset(); });
        ['dragenter', 'dragover'].forEach((ev) => zone.addEventListener(ev, () => zone.classList.add('is-drag')));
        ['dragleave', 'drop'].forEach((ev) => zone.addEventListener(ev, () => zone.classList.remove('is-drag')));
    });

    // 27-11-2026: employee photo by selfie (front camera), into the same photo input
    initSelfie(form.querySelector('#photo'));

    // Consent panel (Modification V7): green when ticked; an unticked submit is stopped and the panel flagged
    const consent = document.getElementById('reg-consent');
    const privacy = form.querySelector('#privacy');
    if (consent && privacy) {
        const syncConsent = () => {
            consent.classList.toggle('is-checked', privacy.checked);
            if (privacy.checked) consent.classList.remove('is-invalid');
        };
        privacy.addEventListener('change', syncConsent);
        syncConsent();
        form.addEventListener('submit', (e) => {
            if (privacy.checked) return;
            const first = !e.defaultPrevented; // another field (e.g. mobile) may already have stopped the submit and taken focus
            e.preventDefault();
            consent.classList.add('is-invalid');
            consent.classList.remove('shake');
            void consent.offsetWidth; // restart the animation
            consent.classList.add('shake');
            consent.querySelector('.reg-consent-hint').textContent = 'Please tick this box to accept the privacy notice.';
            if (first) {
                consent.scrollIntoView({ behavior: 'smooth', block: 'center' });
                privacy.focus({ preventScroll: true });
            }
        });
    }

    restoreLocations();
});
