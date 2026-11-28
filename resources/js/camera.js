// In-app camera, stamping, fingerprint and background upload with retry (PRD 9.2 – 9.6)
import { offlineStore } from './offline-queue';
import { fitSize, encodeUnder, checkPhotoFile } from './image-shrink';

const SI = window.SI;
const esc = window.escapeHtml;
const INSP = SI.inspectionKey;
let storeOk = false; // photos are kept in this browser until the server has them (T2: working without network)
let warnedOffline = false;
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
let geoState = 'locating'; // 23-11-2026: 'locating' | 'ok' | 'failed'; Use photo waits while 'locating'
let shot = null;         // { blob, url, takenAt, geo, frame } (frame: the raw picture, stamped again when the location arrives)
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

/* ---------------- Rendering ---------------- */
function tile(e) {
    const statusBadge = {
        pending: '<span class="badge text-bg-warning tile-status"><span class="spinner-border spinner-border-sm me-1" style="width:.6rem;height:.6rem"></span>Uploading</span>',
        failed: e.refused ? '<span class="badge text-bg-danger tile-status">Not accepted</span>' : '<span class="badge text-bg-danger tile-status">Failed</span>',
        waiting: '<span class="badge text-bg-warning tile-status"><i class="bi bi-phone me-1"></i>On this device · waiting for network</span>',
        uploaded: (e.source === 'gallery' ? '<span class="badge text-bg-info tile-status">Gallery</span>' : '')
            + (e.has_location === false ? `<span class="badge text-bg-dark tile-status" style="${e.source === 'gallery' ? 'top:28px' : ''}">No location</span>` : ''),
    }[e.status] || '';
    const retry = e.status === 'failed' && !e.refused ? `<button type="button" class="btn" data-retry="${e.evidence_uid}" title="Retry" aria-label="Retry upload"><i class="bi bi-arrow-clockwise text-primary"></i></button>` : '';
    const src = e.local_url || e.thumb_url || e.url; // 360 px thumbnail in the grid; the link opens the full photo
    return `<div class="photo-tile" data-uid="${e.evidence_uid}">
        <a href="${esc(e.url || src)}" target="_blank" rel="noopener"><img src="${esc(src)}" alt="${esc(e.category_label || 'Photo')}" loading="lazy"></a>
        ${statusBadge}
        <div class="tile-actions">${retry}<button type="button" class="btn" data-remove="${e.evidence_uid}" title="Delete" aria-label="Delete photo"><i class="bi bi-trash text-danger"></i></button></div>
        <div class="tile-cap">${e.refused ? `<span class="text-danger">${esc(e.error)} Delete it and take the photo again.</span>` : esc(e.caption || e.category_label || '')}</div>
    </div>`;
}

function render() {
    const photos = SI.evidence.filter((e) => e.kind === 'photo');
    const mandatoryCats = Object.keys(SI.mandatory || {});
    $$('[data-photo-grid]').forEach((grid) => {
        let list;
        if (grid.dataset.question) list = photos.filter((e) => e.question_code === grid.dataset.question);
        else if (grid.dataset.category) list = photos.filter((e) => e.category === grid.dataset.category);
        else if (grid.hasAttribute('data-extra')) list = photos.filter((e) => !mandatoryCats.includes(e.category) && !e.question_code);
        else list = photos;
        const adds = [...grid.querySelectorAll('.photo-add')];
        grid.innerHTML = list.map(tile).join('');
        adds.reverse().forEach((a) => grid.prepend(a));
    });

    // Mandatory photo slots / chips
    $$('[data-req-cat]').forEach((slot) => {
        const n = photos.filter((e) => e.category === slot.dataset.reqCat && e.status === 'uploaded').length;
        const ok = n >= parseInt(slot.dataset.min, 10);
        slot.classList.toggle('done', ok);
        const icon = slot.querySelector('i');
        if (icon) icon.className = `bi bi-${ok ? 'check-circle-fill text-success' : 'circle'} me-1`;
    });

    // Documents
    // 28-11-2026: documents attached to a question (NET_02 signed declaration) are listed under that question only
    const docRow = (d) => `<div class="list-item py-2" data-uid="${d.evidence_uid}">
            <i class="bi bi-file-earmark-${d.original_name?.toLowerCase().endsWith('.pdf') ? 'pdf text-danger' : 'image text-primary'} fs-5"></i>
            <div class="flex-grow-1 min-w-0 text-sm text-truncate">${d.url ? `<a href="${esc(d.url)}" target="_blank" rel="noopener">${esc(d.original_name)}</a>` : esc(d.original_name)}</div>
            ${d.status === 'pending' ? '<span class="spinner-border spinner-border-sm text-warning"></span>' : ''}
            ${d.status === 'failed' && d.refused ? `<span class="text-xs text-danger">${esc(d.error)} Remove it and attach the file again.</span>` : ''}
            ${d.status === 'failed' && !d.refused ? `<button type="button" class="btn btn-sm btn-soft-primary" data-retry="${d.evidence_uid}">Retry</button>` : ''}
            <button type="button" class="btn btn-sm btn-light btn-icon" data-remove="${d.evidence_uid}" aria-label="Remove document"><i class="bi bi-trash text-danger"></i></button>
        </div>`;
    const allDocs = SI.evidence.filter((e) => e.kind === 'document');
    const docs = $('#doc-list');
    if (docs) {
        const list = allDocs.filter((e) => !e.question_code);
        docs.innerHTML = list.length ? list.map(docRow).join('') : '<div class="text-sm text-secondary p-3">No documents attached.</div>';
    }
    $$('[data-doc-list][data-question]').forEach((box) => {
        const list = allDocs.filter((e) => e.question_code === box.dataset.question);
        box.innerHTML = list.length ? list.map(docRow).join('') : '<div class="text-sm text-secondary p-3">Not attached yet.</div>';
    });
}

/* ---------------- Upload with retry ---------------- */
async function upload(item, attempt = 1) {
    item.status = 'pending';
    item.error = null;
    item.refused = false;
    render();
    // Keep the photo in this browser first, so it survives no network, a reload or a closed tab
    if (storeOk && item.blob && !item.stored) {
        try { await offlineStore.putPhoto(INSP, item); item.stored = true; } catch { /* storage full or refused: upload as before */ }
    }
    uploading++;
    const fd = new FormData();
    fd.append('file', item.blob, item.filename);
    for (const k of ['evidence_uid', 'kind', 'source', 'category', 'question_code', 'caption', 'taken_at', 'latitude', 'longitude', 'accuracy', 'no_location_reason', 'sha256']) {
        if (item[k] !== null && item[k] !== undefined && item[k] !== '') fd.append(k, item[k]);
    }
    try {
        const { data } = await axios.post(SI.evidenceUrl, fd, { timeout: 120000 });
        Object.assign(item, data, { status: 'uploaded' });
        delete item.blob;
        if (item.stored) { offlineStore.removePhoto(INSP, item.evidence_uid).catch(() => {}); item.stored = false; }
        window.siRefreshProgress?.();
    } catch (err) {
        const code = err.response?.status;
        if (window.siHandleAuthError?.(err)) { uploading--; item.status = 'failed'; render(); return; }
        const permanent = code && code >= 400 && code < 500 && code !== 408 && code !== 429;
        if (!err.response && item.stored) {
            // no network: no point retrying now; the 'online' event and a 20 s check upload it later
            uploading--;
            item.status = 'waiting';
            if (!warnedOffline) { warnedOffline = true; window.toast('No network. Photos are kept on this device and will upload automatically.', 'warning'); }
            render();
            return;
        }
        if (!permanent && attempt < 4) {
            // Automatic retry on weak network: 3 s, 8 s, 20 s
            uploading--;
            setTimeout(() => upload(item, attempt + 1), [3000, 8000, 20000][attempt - 1]);
            return;
        }
        if (!err.response && item.stored) {
            // No network: the photo is safe on this device and is sent when the network returns
            item.status = 'waiting';
            if (!warnedOffline) { warnedOffline = true; window.toast('No network. Photos are kept on this device and will upload automatically.', 'warning'); }
        } else {
            item.status = 'failed';
            // 26-11-2026: the first field message says what to do (e.g. "Choose what the photo shows…")
            item.error = Object.values(err.response?.data?.errors || {})[0]?.[0] || err.response?.data?.message || 'Upload failed. Check the network and press Retry.';
            item.refused = !!permanent;
            if (item.stored && permanent) { offlineStore.removePhoto(INSP, item.evidence_uid).catch(() => {}); item.stored = false; }
            window.toast(item.error, 'danger');
        }
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
        if (item.stored) offlineStore.removePhoto(INSP, item.evidence_uid).catch(() => {});
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
    if (SI.evidence.filter((x) => x.kind === 'document' && !x.refused).length >= (SI.maxDocuments || 5)) {
        return window.toast(`You already added ${SI.maxDocuments || 5} documents, the most allowed. Remove one to add another.`, 'warning');
    }
    if (file.size > 5 * 1024 * 1024) return window.toast('The document is larger than 5 MB. Choose a smaller file, or scan it at a lower quality.', 'danger');
    if (!/\.(pdf|jpe?g|png)$/i.test(file.name)) return window.toast('Only PDF, JPG or PNG documents are allowed. Save the file in one of these formats.', 'danger');
    const item = { evidence_uid: uuid(), kind: 'document', blob: file, filename: file.name, original_name: file.name, sha256: await sha256(file), status: 'pending' };
    SI.evidence.push(item);
    upload(item);
});

// 28-11-2026: a document attached to a question (NET_02: the declaration signed by the Block / District officer, PDF only)
document.addEventListener('change', async (e) => {
    const input = e.target.closest?.('input[data-doc-question]');
    if (!input) return;
    const file = input.files[0];
    input.value = '';
    if (!file) return;
    if (SI.evidence.filter((x) => x.kind === 'document' && !x.refused).length >= (SI.maxDocuments || 5)) {
        return window.toast(`You already added ${SI.maxDocuments || 5} documents, the most allowed. Remove one to add another.`, 'warning');
    }
    const pdfOnly = input.dataset.pdfOnly === '1';
    if (pdfOnly && !/\.pdf$/i.test(file.name)) return window.toast('Attach the signed declaration as a PDF file. Scan the signed paper as PDF, then attach it again.', 'danger');
    if (!/\.(pdf|jpe?g|png)$/i.test(file.name)) return window.toast('Only PDF, JPG or PNG documents are allowed. Save the file in one of these formats.', 'danger');
    if (file.size > 5 * 1024 * 1024) return window.toast('The file is larger than 5 MB. Scan it at a lower quality and attach it again.', 'danger');
    const item = { evidence_uid: uuid(), kind: 'document', question_code: input.dataset.docQuestion, blob: file, filename: file.name,
        original_name: file.name, sha256: await sha256(file), status: 'pending' };
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

const LOCATING_HTML = '<span class="spinner-border spinner-border-sm me-1" style="width:.75rem;height:.75rem" aria-hidden="true"></span>Getting location…';
function setGeoState(state) {
    geoState = state;
    const el = $('#cam-gps');
    if (el) {
        el.innerHTML = state === 'ok' ? `<i class="bi bi-geo-alt-fill text-success"></i> ±${geo.acc} m`
            : state === 'locating' ? LOCATING_HTML
                : '<i class="bi bi-geo-alt text-warning"></i> Location unavailable';
        el.classList.toggle('is-locating', state === 'locating');
    }
    updateMeta();
    syncUseButton();
}
function startGeo() {
    geo = null;
    if (!navigator.geolocation) { setGeoState('failed'); return; }
    setGeoState('locating');
    geoWatch = navigator.geolocation.watchPosition((p) => {
        geo = { lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.round(p.coords.accuracy) };
        setGeoState('ok');
        restampShot();
    }, () => { geo = null; setGeoState('failed'); },
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 });
}
function stopGeo() { if (geoWatch !== null) navigator.geolocation.clearWatch(geoWatch); geoWatch = null; }

/**
 * 23-11-2026: Use photo is disabled while the location is still loading (a spinner shows on the button).
 * When the location arrives the waiting photo is stamped again with it; if it fails, Use photo asks for a reason as before.
 */
function syncUseButton() {
    const btn = $('#cam-use');
    if (!btn) return;
    btn.dataset.html ??= btn.innerHTML;
    const waiting = !!shot && !shot.geo && geoState === 'locating';
    btn.disabled = waiting;
    btn.setAttribute('aria-busy', waiting ? 'true' : 'false');
    btn.innerHTML = waiting ? LOCATING_HTML : btn.dataset.html;
    $('#cam-noloc')?.classList.toggle('d-none', !shot || !!shot.geo || waiting);
}
async function restampShot() {
    if (!shot || shot.geo || !shot.frame || !geo) return;
    const g = { ...geo };
    const s = shot;
    const blob = await processImage(s.frame, s.frame.width, s.frame.height, s.takenAt, g);
    if (shot !== s) return; // retaken or closed meanwhile
    URL.revokeObjectURL(s.url);
    Object.assign(s, { blob, url: URL.createObjectURL(blob), geo: g });
    preview.src = s.url;
    syncUseButton();
}

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
modalEl?.addEventListener('hidden.bs.modal', () => { stopCamera(); stopGeo(); if (shot?.url) URL.revokeObjectURL(shot.url); shot = null; syncUseButton(); });

$('#cam-switch')?.addEventListener('click', () => { facing = facing === 'environment' ? 'user' : 'environment'; startCamera(); });

function updateMeta() {
    const now = new Date();
    const m = $('#cam-meta');
    if (m) m.textContent = `${SI.stamp.udise} · ${fmtDate(now)} · ${geo ? `${geo.lat.toFixed(5)}, ${geo.lng.toFixed(5)}` : geoState === 'locating' ? 'Getting location…' : 'No location'}`;
}
setInterval(() => { if (modalEl?.classList.contains('show')) updateMeta(); }, 1000);

/**
 * Standard size (27-11-2026): fit inside SI.maxSide × SI.shortSide (1600 × 1200 landscape, 1200 × 1600 portrait),
 * aspect kept, never enlarged; print the stamp strip and encode as JPEG under SI.photoMaxKb (2 MB since 27-11-2026).
 * When quality 0.5 is still too big the photo is made smaller until it fits (image-shrink.js), so it is never refused.
 * Used for camera shots and for gallery uploads.
 */
async function processImage(source, srcW, srcH, takenAt, g, fromGallery = false) {
    const { w, h } = fitSize(srcW, srcH, SI.maxSide || 1600, SI.shortSide || 1200);
    const lines = [
        `UDISE ${SI.stamp.udise} · ${SI.stamp.school}`,
        `${fmtDate(takenAt)} · ${g ? `Lat ${g.lat.toFixed(6)}, Lng ${g.lng.toFixed(6)} (±${g.acc} m)` : 'No location'}`,
        `Officer: ${SI.stamp.officer}${fromGallery ? ' · From gallery' : ''}`,
    ];
    const draw = (ctx, cw, ch) => {
        ctx.drawImage(source, 0, 0, cw, ch);
        const fs = Math.max(14, Math.round(cw / 55));
        const stripH = Math.round(fs * 1.45 * lines.length + fs * 0.9);
        ctx.fillStyle = 'rgba(0,0,0,0.62)';
        ctx.fillRect(0, ch - stripH, cw, stripH);
        ctx.fillStyle = '#ffffff';
        ctx.font = `600 ${fs}px Inter, system-ui, sans-serif`;
        ctx.textBaseline = 'top';
        lines.forEach((t, i) => ctx.fillText(t, Math.round(fs * 0.7), ch - stripH + Math.round(fs * 0.45) + i * Math.round(fs * 1.45), cw - fs * 1.4));
    };
    const maxBytes = Math.floor((SI.photoMaxKb || 2048) * 1024 * 0.95);
    const { blob } = await encodeUnder(draw, w, h, maxBytes);
    return blob;
}

$('#cam-shutter')?.addEventListener('click', async () => {
    if (!stream || !video.videoWidth) return;
    const takenAt = new Date();
    const g = geo ? { ...geo } : null;
    // keep the raw picture while the location is loading, so it can be stamped again when it arrives
    let frame = null;
    if (!g) {
        frame = document.createElement('canvas');
        frame.width = video.videoWidth; frame.height = video.videoHeight;
        frame.getContext('2d').drawImage(video, 0, 0);
    }
    const blob = await processImage(frame || video, video.videoWidth, video.videoHeight, takenAt, g);

    shot = { blob, url: URL.createObjectURL(blob), takenAt, geo: g, frame };
    preview.src = shot.url;
    $('#cam-noloc').value = '';
    showMode('review');
    syncUseButton();
    restampShot(); // the location may have arrived while the photo was processed
});

/** 26-11-2026: inline message under the no-location reason box. */
function nolocError(on) {
    const box = $('#cam-noloc');
    if (!box) return;
    box.classList.toggle('is-invalid', on);
    box.toggleAttribute('aria-invalid', on);
    let el = document.getElementById('cam-noloc-error');
    if (!on) { el?.remove(); return; }
    if (!el) { el = document.createElement('div'); el.id = 'cam-noloc-error'; el.className = 'field-error text-warning'; el.setAttribute('role', 'alert'); box.after(el); }
    el.innerHTML = '<i class="bi bi-exclamation-circle"></i>The location could not be found. Write why (e.g. "No GPS signal inside the building") to use this photo.';
    box.setAttribute('aria-describedby', el.id);
}
$('#cam-noloc')?.addEventListener('input', () => { if ($('#cam-noloc').value.trim()) nolocError(false); });

/**
 * 26-11-2026: the reason for a gallery photo without a location, asked in a small window with an inline
 * message (was window.prompt). Resolves with the reason, or null when cancelled.
 */
function askNoLocationReason() {
    return new Promise((resolve) => {
        let m = document.getElementById('noloc-modal');
        if (!m) {
            m = document.createElement('div');
            m.id = 'noloc-modal';
            m.className = 'modal fade';
            m.tabIndex = -1;
            m.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-geo-alt me-1"></i>Location not available</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body"><label class="form-label text-sm" for="noloc-reason">Why is the location not available? <span class="text-danger">*</span></label>
                    <input id="noloc-reason" class="form-control" maxlength="255" placeholder="e.g. No GPS signal inside the building">
                    <div class="field-error d-none" id="noloc-reason-error" role="alert"><i class="bi bi-exclamation-circle"></i>Write a reason (at least 3 characters) to use this photo.</div></div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" data-noloc-ok><i class="bi bi-check2"></i>Use photo</button></div>
            </div></div>`;
            document.body.appendChild(m);
        }
        const input = m.querySelector('#noloc-reason');
        const err = m.querySelector('#noloc-reason-error');
        input.value = '';
        input.classList.remove('is-invalid');
        err.classList.add('d-none');
        let done = false;
        const modalObj = window.bootstrap.Modal.getOrCreateInstance(m);
        const ok = () => {
            const v = input.value.trim();
            if (v.length < 3) { input.classList.add('is-invalid'); err.classList.remove('d-none'); input.focus(); return; }
            done = true;
            modalObj.hide();
            resolve(v);
        };
        m.querySelector('[data-noloc-ok]').onclick = ok;
        input.onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); ok(); } };
        m.addEventListener('hidden.bs.modal', () => { if (!done) resolve(null); }, { once: true });
        m.addEventListener('shown.bs.modal', () => input.focus(), { once: true });
        modalObj.show();
    });
}

$('#cam-retake')?.addEventListener('click', () => { if (shot?.url) URL.revokeObjectURL(shot.url); shot = null; showMode('capture'); syncUseButton(); });

$('#cam-use')?.addEventListener('click', async () => {
    if (!shot || (!shot.geo && geoState === 'locating')) return; // still getting the location
    const reason = $('#cam-noloc').value.trim();
    if (!shot.geo && !reason) { nolocError(true); $('#cam-noloc').focus(); return; }
    nolocError(false);
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
    item.source = 'camera';
    shot = null; // keep object URL alive for the thumbnail
    syncUseButton();
    SI.evidence.push(item);
    modal.hide();
    upload(item);
});

/* ---------------- Gallery upload (Modification V1: camera + gallery) ---------------- */
let galleryCategory = null;
const currentPosition = () => new Promise((res) => {
    if (geo) return res({ ...geo });
    if (!navigator.geolocation) return res(null);
    navigator.geolocation.getCurrentPosition(
        (p) => res({ lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.round(p.coords.accuracy) }),
        () => res(null), { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 });
});

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-gallery]');
    if (!btn) return;
    if (SI.evidence.filter((x) => x.kind === 'photo').length >= SI.maxPhotos) {
        return window.toast(`You already added ${SI.maxPhotos} photographs, the most allowed. Delete one to add another.`, 'warning');
    }
    galleryCategory = btn.dataset.category || 'other';
    $('#gallery-input')?.click();
});

$('#gallery-input')?.addEventListener('change', async (e) => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    // 27-11-2026 photo rule: school photos from the gallery are JPG / JPEG / PNG of at most 2 MB, else a warning
    const problem = checkPhotoFile(file, SI.photoMaxKb || 2048);
    if (problem) return window.toast(problem, 'warning');

    let bitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch {
        return window.toast('This image format cannot be read. Please choose a JPG or PNG photo.', 'danger');
    }
    window.toast('Processing photo…', 'info');
    const takenAt = new Date();
    const g = await currentPosition();
    let reason = null;
    if (!g) {
        reason = await askNoLocationReason();
        if (!reason) return window.toast('Upload cancelled: a reason is required when the location is not available.', 'warning');
    }
    const blob = await processImage(bitmap, bitmap.width, bitmap.height, takenAt, g, true);
    bitmap.close?.();

    const label = catSel?.querySelector(`option[value="${galleryCategory}"]`)?.textContent || galleryCategory;
    const item = {
        evidence_uid: uuid(),
        kind: 'photo',
        source: 'gallery',
        blob,
        filename: `gallery-${Date.now()}.jpg`,
        local_url: URL.createObjectURL(blob),
        category: galleryCategory,
        category_label: label,
        question_code: null,
        caption: null,
        taken_at: takenAt.toISOString(),
        latitude: g?.lat ?? null,
        longitude: g?.lng ?? null,
        accuracy: g?.acc ?? null,
        no_location_reason: g ? null : reason,
        has_location: !!g,
        sha256: await sha256(blob),
        status: 'pending',
    };
    SI.evidence.push(item);
    upload(item);
});


/* ---------------- Working without network (T2): restore kept photos and upload them when online ---------------- */
const retryWaiting = () => {
    if (!navigator.onLine) return;
    SI.evidence.filter((e) => e.status === 'waiting' && e.blob).forEach((e) => upload(e));
};
(async () => {
    storeOk = !!INSP && await offlineStore.available();
    if (storeOk) {
        try {
            const known = new Set(SI.evidence.map((e) => e.evidence_uid));
            for (const rec of await offlineStore.photos(INSP)) {
                if (known.has(rec.evidence_uid)) { offlineStore.removePhoto(INSP, rec.evidence_uid).catch(() => {}); continue; } // already on the server
                SI.evidence.push({ ...rec, local_url: URL.createObjectURL(rec.blob), stored: true, status: navigator.onLine ? 'pending' : 'waiting' });
            }
        } catch { /* nothing kept */ }
        window.addEventListener('online', retryWaiting);
        setInterval(retryWaiting, 20000);
    }
    render();
    SI.evidence.filter((e) => e.stored && e.status === 'pending').forEach((e) => upload(e));
})();
