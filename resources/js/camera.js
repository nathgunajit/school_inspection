// In-app camera, stamping, fingerprint and background upload with retry (PRD 9.2 – 9.6)
const SI = window.SI;
const esc = window.escapeHtml;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];

const modalEl = $('#cameraModal');
const modal = modalEl ? new window.bootstrap.Modal(modalEl) : null;
const video = $('#cam-video');
const preview = $('#cam-preview');
const catSel = $('#cam-category');

let stream = null;
let facing = 'environment';
let geo = null;          // latest position
let geoWatch = null;
let shot = null;         // { blob, url, takenAt, geo }
let target = {};         // { category, question, caption }
let uploading = 0;

window.siPendingUploads = () => SI.evidence.filter((e) => e.status === 'pending').length;

/* ---------------- Utils ---------------- */
const uuid = () => (crypto.randomUUID ? crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16); }));

async function sha256(blob) {
    if (!crypto.subtle) return null; // non-secure context: server still computes its own fingerprint
    const buf = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
    return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

const pad = (n) => String(n).padStart(2, '0');
const fmtDate = (d) => `${pad(d.getDate())}-${pad(d.getMonth() + 1)}-${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
const toBlob = (canvas, q) => new Promise((res) => canvas.toBlob(res, 'image/jpeg', q));

/* ---------------- Rendering ---------------- */
function tile(e) {
    const statusBadge = {
        pending: '<span class="badge text-bg-warning tile-status"><span class="spinner-border spinner-border-sm me-1" style="width:.6rem;height:.6rem"></span>Uploading</span>',
        failed: '<span class="badge text-bg-danger tile-status">Failed</span>',
        uploaded: e.has_location === false ? '<span class="badge text-bg-dark tile-status">No location</span>' : '',
    }[e.status] || '';
    const retry = e.status === 'failed' ? `<button type="button" class="btn" data-retry="${e.evidence_uid}" title="Retry" aria-label="Retry upload"><i class="bi bi-arrow-clockwise text-primary"></i></button>` : '';
    const src = e.local_url || e.url;
    return `<div class="photo-tile" data-uid="${e.evidence_uid}">
        <a href="${esc(e.url || src)}" target="_blank" rel="noopener"><img src="${esc(src)}" alt="${esc(e.category_label || 'Photo')}" loading="lazy"></a>
        ${statusBadge}
        <div class="tile-actions">${retry}<button type="button" class="btn" data-remove="${e.evidence_uid}" title="Delete" aria-label="Delete photo"><i class="bi bi-trash text-danger"></i></button></div>
        <div class="tile-cap">${esc(e.caption || e.category_label || '')}</div>
    </div>`;
}

function render() {
    const photos = SI.evidence.filter((e) => e.kind === 'photo');
    $$('[data-photo-grid]').forEach((grid) => {
        let list;
        if (grid.dataset.question) list = photos.filter((e) => e.question_code === grid.dataset.question);
        else if (grid.dataset.category) list = photos.filter((e) => e.category === grid.dataset.category && !e.question_code);
        else list = photos;
        const add = grid.querySelector('.photo-add');
        grid.innerHTML = list.map(tile).join('');
        if (add) grid.prepend(add);
    });

    // Mandatory chips (step 6)
    $$('[data-req-cat]').forEach((chip) => {
        const n = photos.filter((e) => e.category === chip.dataset.reqCat && e.status === 'uploaded').length;
        const ok = n >= parseInt(chip.dataset.min, 10);
        chip.classList.toggle('done', ok);
        chip.querySelector('i').className = `bi bi-${ok ? 'check-circle-fill' : 'circle'}`;
    });

    // Documents
    const docs = $('#doc-list');
    if (docs) {
        const list = SI.evidence.filter((e) => e.kind === 'document');
        docs.innerHTML = list.length ? list.map((d) => `<div class="list-item py-2" data-uid="${d.evidence_uid}">
            <i class="bi bi-file-earmark-${d.original_name?.toLowerCase().endsWith('.pdf') ? 'pdf text-danger' : 'image text-primary'} fs-5"></i>
            <div class="flex-grow-1 min-w-0 text-sm text-truncate">${d.url ? `<a href="${esc(d.url)}" target="_blank" rel="noopener">${esc(d.original_name)}</a>` : esc(d.original_name)}</div>
            ${d.status === 'pending' ? '<span class="spinner-border spinner-border-sm text-warning"></span>' : ''}
            ${d.status === 'failed' ? `<button type="button" class="btn btn-sm btn-soft-primary" data-retry="${d.evidence_uid}">Retry</button>` : ''}
            <button type="button" class="btn btn-sm btn-light btn-icon" data-remove="${d.evidence_uid}" aria-label="Remove document"><i class="bi bi-trash text-danger"></i></button>
        </div>`).join('') : '<div class="text-sm text-secondary p-3">No documents attached.</div>';
    }
}

/* ---------------- Upload with retry ---------------- */
async function upload(item, attempt = 1) {
    item.status = 'pending';
    item.error = null;
    render();
    uploading++;
    const fd = new FormData();
    fd.append('file', item.blob, item.filename);
    for (const k of ['evidence_uid', 'kind', 'category', 'question_code', 'caption', 'taken_at', 'latitude', 'longitude', 'accuracy', 'no_location_reason', 'sha256']) {
        if (item[k] !== null && item[k] !== undefined && item[k] !== '') fd.append(k, item[k]);
    }
    try {
        const { data } = await axios.post(SI.evidenceUrl, fd, { timeout: 120000 });
        Object.assign(item, data, { status: 'uploaded' });
        delete item.blob;
        window.siRefreshProgress?.();
    } catch (err) {
        const code = err.response?.status;
        const permanent = code && code >= 400 && code < 500 && code !== 408 && code !== 429;
        if (!permanent && attempt < 4) {
            // Automatic retry on weak network: 3 s, 8 s, 20 s
            uploading--;
            setTimeout(() => upload(item, attempt + 1), [3000, 8000, 20000][attempt - 1]);
            return;
        }
        item.status = 'failed';
        item.error = err.response?.data?.message || 'Upload failed';
        window.toast(item.error, 'danger');
    }
    uploading--;
    render();
}

document.addEventListener('click', async (e) => {
    const retry = e.target.closest('[data-retry]');
    if (retry) {
        const item = SI.evidence.find((x) => x.evidence_uid === retry.dataset.retry);
        if (item?.blob) upload(item);
        return;
    }
    const rm = e.target.closest('[data-remove]');
    if (rm) {
        const item = SI.evidence.find((x) => x.evidence_uid === rm.dataset.remove);
        if (!item || !confirm('Delete this file?')) return;
        if (item.status === 'uploaded' && item.delete_url) {
            try { await axios.delete(item.delete_url); } catch { window.toast('Could not delete', 'danger'); return; }
        }
        SI.evidence = SI.evidence.filter((x) => x !== item);
        render();
        window.siRefreshProgress?.();
    }
});

/* ---------------- Documents (may be chosen from the device, PRD 9.2) ---------------- */
$('#doc-input')?.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) return window.toast('Document must be 5 MB or smaller.', 'danger');
    if (!/\.(pdf|jpe?g|png)$/i.test(file.name)) return window.toast('Only PDF, JPG or PNG documents are allowed.', 'danger');
    const item = { evidence_uid: uuid(), kind: 'document', blob: file, filename: file.name, original_name: file.name, sha256: await sha256(file), status: 'pending' };
    SI.evidence.push(item);
    upload(item);
});

/* ---------------- Camera ---------------- */
async function startCamera() {
    stopCamera();
    $('#cam-error').classList.add('d-none');
    video.classList.remove('d-none');
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: facing }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false });
        video.srcObject = stream;
    } catch (err) {
        video.classList.add('d-none');
        $('#cam-error').classList.remove('d-none');
        $('#cam-error-text').textContent = err.name === 'NotAllowedError' ? 'Camera permission was denied.' : 'Camera not available on this device.';
    }
}
function stopCamera() {
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
}

function startGeo() {
    const el = $('#cam-gps');
    if (!navigator.geolocation) { el.innerHTML = '<i class="bi bi-geo-alt"></i> No location'; return; }
    geoWatch = navigator.geolocation.watchPosition((p) => {
        geo = { lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.round(p.coords.accuracy) };
        el.innerHTML = `<i class="bi bi-geo-alt-fill text-success"></i> ±${geo.acc} m`;
    }, () => { geo = null; el.innerHTML = '<i class="bi bi-geo-alt text-warning"></i> Location unavailable'; },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 });
}
function stopGeo() { if (geoWatch !== null) navigator.geolocation.clearWatch(geoWatch); geoWatch = null; }

function showMode(mode) {
    const capture = mode === 'capture';
    video.classList.toggle('d-none', !capture);
    preview.classList.toggle('d-none', capture);
    $('#cam-shutter').classList.toggle('d-none', !capture);
    $('#cam-switch').classList.toggle('d-none', !capture);
    $('#cam-spacer').classList.toggle('d-none', !capture);
    $('#cam-retake').classList.toggle('d-none', capture);
    $('#cam-use').classList.toggle('d-none', capture);
    $('#cam-review-fields').classList.toggle('d-none', capture);
}

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-camera]');
    if (!btn || !modal) return;
    if (SI.evidence.filter((x) => x.kind === 'photo').length >= SI.maxPhotos) {
        return window.toast(`A maximum of ${SI.maxPhotos} photographs is allowed.`, 'warning');
    }
    target = { category: btn.dataset.category || null, question: btn.dataset.question || null, caption: btn.dataset.caption || '' };
    const picker = btn.hasAttribute('data-category-picker');
    catSel.disabled = !picker;
    if (target.category) catSel.value = target.category;
    else if (picker) {
        // suggest the first missing mandatory category
        const missing = Object.keys(SI.mandatory).find((c) => !SI.evidence.some((x) => x.category === c && x.status !== 'failed'));
        catSel.value = missing || 'other';
    }
    $('#cam-caption').value = target.question ? target.caption : '';
    modal.show();
});

modalEl?.addEventListener('shown.bs.modal', () => { showMode('capture'); startCamera(); startGeo(); });
modalEl?.addEventListener('hidden.bs.modal', () => { stopCamera(); stopGeo(); if (shot?.url) URL.revokeObjectURL(shot.url); shot = null; });

$('#cam-switch')?.addEventListener('click', () => { facing = facing === 'environment' ? 'user' : 'environment'; startCamera(); });

function updateMeta() {
    const now = new Date();
    $('#cam-meta').textContent = `${SI.stamp.udise} · ${fmtDate(now)} · ${geo ? `${geo.lat.toFixed(5)}, ${geo.lng.toFixed(5)}` : 'No location'}`;
}
setInterval(() => { if (modalEl?.classList.contains('show')) updateMeta(); }, 1000);

$('#cam-shutter')?.addEventListener('click', async () => {
    if (!stream || !video.videoWidth) return;
    const takenAt = new Date();
    const g = geo ? { ...geo } : null;

    // Resize so the longer side is at most maxSide (PRD 9.4)
    const scale = Math.min(1, SI.maxSide / Math.max(video.videoWidth, video.videoHeight));
    const w = Math.round(video.videoWidth * scale);
    const h = Math.round(video.videoHeight * scale);
    const canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, w, h);

    // Stamp strip: UDISE, school, date/time, lat/lng, officer
    const fs = Math.max(14, Math.round(w / 55));
    const lines = [
        `UDISE ${SI.stamp.udise} · ${SI.stamp.school}`,
        `${fmtDate(takenAt)} · ${g ? `Lat ${g.lat.toFixed(6)}, Lng ${g.lng.toFixed(6)} (±${g.acc} m)` : 'No location'}`,
        `Officer: ${SI.stamp.officer}`,
    ];
    const stripH = Math.round(fs * 1.45 * lines.length + fs * 0.9);
    ctx.fillStyle = 'rgba(0,0,0,0.62)';
    ctx.fillRect(0, h - stripH, w, stripH);
    ctx.fillStyle = '#ffffff';
    ctx.font = `600 ${fs}px Inter, system-ui, sans-serif`;
    ctx.textBaseline = 'top';
    lines.forEach((t, i) => ctx.fillText(t, Math.round(fs * 0.7), h - stripH + Math.round(fs * 0.45) + i * Math.round(fs * 1.45), w - fs * 1.4));

    // JPEG ~500 KB, never above 1 MB
    let q = 0.82;
    let blob = await toBlob(canvas, q);
    while (blob.size > 950 * 1024 && q > 0.4) { q -= 0.12; blob = await toBlob(canvas, q); }

    shot = { blob, url: URL.createObjectURL(blob), takenAt, geo: g };
    preview.src = shot.url;
    $('#cam-noloc').classList.toggle('d-none', !!g);
    $('#cam-noloc').value = '';
    showMode('review');
});

$('#cam-retake')?.addEventListener('click', () => { if (shot?.url) URL.revokeObjectURL(shot.url); shot = null; showMode('capture'); });

$('#cam-use')?.addEventListener('click', async () => {
    if (!shot) return;
    const reason = $('#cam-noloc').value.trim();
    if (!shot.geo && !reason) { $('#cam-noloc').focus(); return window.toast('Location is not available. Enter a reason to continue.', 'warning'); }
    const category = catSel.value;
    const item = {
        evidence_uid: uuid(),
        kind: 'photo',
        blob: shot.blob,
        filename: `photo-${Date.now()}.jpg`,
        local_url: shot.url,
        category,
        category_label: catSel.selectedOptions[0]?.textContent,
        question_code: target.question,
        caption: $('#cam-caption').value.trim().slice(0, 150) || null,
        taken_at: shot.takenAt.toISOString(),
        latitude: shot.geo?.lat ?? null,
        longitude: shot.geo?.lng ?? null,
        accuracy: shot.geo?.acc ?? null,
        no_location_reason: shot.geo ? null : reason,
        has_location: !!shot.geo,
        sha256: await sha256(shot.blob),
        status: 'pending',
    };
    shot = null; // keep object URL alive for the thumbnail
    SI.evidence.push(item);
    modal.hide();
    upload(item);
});

render();
