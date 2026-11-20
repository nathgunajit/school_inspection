// Excel Upload page: School / Location switch, district template link, drag & drop, block drill-down.
const root = document.querySelector('[data-imports]');
if (root) {
    const form = root.querySelector('#imp-form');
    const district = root.querySelector('#district_id');
    const tplLink = root.querySelector('[data-district-template]');
    const fileInput = root.querySelector('#file');
    const drop = root.querySelector('[data-drop]');
    const dropText = root.querySelector('[data-drop-text]');
    const MAX = 20 * 1024 * 1024;

    const isSchool = () => form.querySelector('input[name="type"]:checked')?.value === 'school';
    const syncType = () => {
        const school = isSchool();
        root.querySelectorAll('[data-school-only]').forEach((el) => el.classList.toggle('d-none', !school));
        root.querySelectorAll('[data-location-only]').forEach((el) => el.classList.toggle('d-none', school));
        district.required = school;
        form.action = school ? form.dataset.previewUrl : form.dataset.storeUrl;
    };
    const syncTemplate = () => {
        const id = district.value;
        tplLink.classList.toggle('disabled', !id);
        tplLink.href = id ? `${root.dataset.templateUrl}?district=${encodeURIComponent(id)}` : '#';
    };
    const size = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
    const showFile = () => {
        const f = fileInput.files[0];
        drop.classList.remove('is-invalid', 'has-file');
        if (!f) { dropText.innerHTML = '<b>Drag your Excel file here</b> or <u>browse</u>'; return; }
        const ok = /\.(xlsx|xls|csv)$/i.test(f.name) && f.size <= MAX;
        drop.classList.add(ok ? 'has-file' : 'is-invalid');
        dropText.textContent = ok ? `${f.name} · ${size(f.size)}` : `${f.name}: use an .xlsx, .xls or .csv file up to 20 MB`;
        fileInput.setCustomValidity(ok ? '' : 'Invalid file');
    };

    form.querySelectorAll('input[name="type"]').forEach((r) => r.addEventListener('change', syncType));
    district.addEventListener('change', syncTemplate);
    fileInput.addEventListener('change', showFile);
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', (e) => {
        const f = e.dataTransfer?.files?.[0];
        if (!f) return;
        const dt = new DataTransfer();
        dt.items.add(f);
        fileInput.files = dt.files;
        showFile();
    });
    form.addEventListener('submit', () => {
        const btn = form.querySelector('[data-submit]');
        btn.disabled = true;
        btn.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-1"></span>');
    });
    syncType();

    // District report: tap a district to load its blocks
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmt = (n) => Number(n).toLocaleString('en-IN');
    const toggle = async (row) => {
        const open = row.getAttribute('aria-expanded') === 'true';
        let next = row.nextElementSibling;
        const children = [];
        while (next?.classList.contains('imp-block')) { children.push(next); next = next.nextElementSibling; }
        row.setAttribute('aria-expanded', String(!open));
        if (open) { children.forEach((c) => c.remove()); return; }
        row.classList.add('is-loading');
        try {
            const res = await fetch(`${root.dataset.blocksUrl}?district=${row.dataset.district}`, { headers: { Accept: 'application/json' } });
            const { blocks } = await res.json();
            const html = blocks.length ? blocks.map((b) => `<tr class="imp-block"><td><span class="imp-indent"></span>${esc(b.name)} <span class="text-xs text-secondary">${esc(b.code)}</span></td><td></td>`
                + `<td class="text-end">${b.clusters}</td><td class="text-end fw-semibold">${fmt(b.schools)}</td><td class="text-end text-success">${fmt(b.active)}</td>`
                + `<td class="text-end text-secondary">${fmt(b.closed)}</td><td class="text-end">${fmt(b.gps)}</td><td></td></tr>`).join('')
                : '<tr class="imp-block"><td colspan="8" class="text-secondary text-sm"><span class="imp-indent"></span>No blocks</td></tr>';
            row.insertAdjacentHTML('afterend', html);
        } catch {
            row.setAttribute('aria-expanded', 'false');
        } finally {
            row.classList.remove('is-loading');
        }
    };
    root.querySelectorAll('.imp-district').forEach((row) => {
        row.addEventListener('click', () => toggle(row));
        row.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(row); } });
    });
}
