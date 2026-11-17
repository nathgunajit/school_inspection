// Registration form: dynamic location fields (PRD 5.2) and password strength.
document.addEventListener('DOMContentLoaded', async () => {
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

    // Restore old() selections after a validation error, or prefill when there is a single state
    const chain = ['#state_id', '#district_id', '#block_id', '#cluster_id'].map((s) => form.querySelector(s));
    for (let i = 0; i < chain.length - 1; i++) {
        const parent = chain[i];
        const child = chain[i + 1];
        if (!parent.value) break;
        await window.siCascade(parent);
        if (child.dataset.old) child.value = child.dataset.old;
        else break;
    }

    // Password strength meter
    const pw = form.querySelector('#password');
    const meter = document.getElementById('pw-meter');
    pw.addEventListener('input', () => {
        const v = pw.value;
        const score = [v.length >= 8, /[a-z]/.test(v), /[A-Z]/.test(v), /\d/.test(v), /[^A-Za-z0-9]/.test(v)].filter(Boolean).length;
        meter.style.width = `${score * 20}%`;
        meter.style.background = score < 3 ? '#dc2626' : score < 5 ? '#d97706' : '#059669';
    });

    // Mobile: digits only
    form.querySelector('#mobile').addEventListener('input', (e) => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 10); });
});
