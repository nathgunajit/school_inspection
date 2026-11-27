// 27-11-2026: employee photo as a selfie (front camera) on the registration form.
// The picture becomes a JPEG File in the same <input type=file>, so the upload zone handler in register.js
// shows the preview and the server checks it like any chosen file.
import { Modal } from './bootstrap-lite';

const SIZE = 600; // square output, px (server needs at least 100 x 100)

const stamp = () => {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}`;
};
const toBlob = (canvas, q) => new Promise((res) => canvas.toBlob(res, 'image/jpeg', q));

/** Hand a File to the zone's input and let its change handler do the rest. */
function putFile(input, file) {
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

export function initSelfie(input) {
    const col = input?.closest('.upload-col');
    const open = col?.querySelector('[data-selfie-open]');
    const modalEl = document.getElementById('selfie-modal');
    if (!open || !modalEl) return;
    const fallback = col.querySelector('[data-selfie-fallback]');
    const maxBytes = Number(input.closest('.upload-zone')?.dataset.maxKb || 2048) * 1024;

    // No camera API (old browser, or http on a phone): the phone's own camera app through capture="user"
    if (!navigator.mediaDevices?.getUserMedia || typeof DataTransfer === 'undefined') {
        if (!fallback || typeof DataTransfer === 'undefined') { open.classList.add('d-none'); return; }
        open.addEventListener('click', () => fallback.click());
        fallback.addEventListener('change', () => { const f = fallback.files[0]; fallback.value = ''; if (f) putFile(input, f); });
        return;
    }

    const modal = Modal.getOrCreateInstance(modalEl);
    const $ = (s) => modalEl.querySelector(s);
    const video = $('[data-selfie-video]');
    const shot = $('[data-selfie-shot]');
    const wait = $('[data-selfie-wait]');
    const error = $('[data-selfie-error]');
    const btn = { capture: $('[data-selfie-capture]'), retake: $('[data-selfie-retake]'), use: $('[data-selfie-use]') };
    let stream = null;
    let blob = null;

    const stop = () => { stream?.getTracks().forEach((t) => t.stop()); stream = null; video.srcObject = null; };
    const mode = (m) => { // live | taken | error
        video.classList.toggle('d-none', m !== 'live');
        shot.classList.toggle('d-none', m !== 'taken');
        btn.capture.classList.toggle('d-none', m === 'taken');
        btn.retake.classList.toggle('d-none', m !== 'taken');
        btn.use.classList.toggle('d-none', m !== 'taken');
        btn.capture.disabled = m !== 'live' || !stream;
    };
    const fail = (err) => {
        stop();
        wait.classList.add('d-none');
        const name = err?.name;
        error.textContent = !window.isSecureContext ? 'The camera needs a secure (https) connection. Please choose a photo file instead.'
            : name === 'NotAllowedError' ? 'Camera permission was denied. Allow the camera for this site in the browser settings, or choose a photo file instead.'
                : name === 'NotFoundError' || name === 'OverconstrainedError' ? 'No camera was found on this device. Please choose a photo file instead.'
                    : name === 'NotReadableError' ? 'The camera is being used by another app. Close it and try again, or choose a photo file.'
                        : 'The camera could not be started. Please choose a photo file instead.';
        error.classList.remove('d-none');
        mode('error');
    };
    const start = async () => {
        stop();
        blob = null;
        error.classList.add('d-none');
        wait.classList.remove('d-none');
        mode('live');
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 1280 } }, audio: false });
            if (!modalEl.classList.contains('show') && !modalEl.classList.contains('showing')) return stop(); // closed meanwhile
            video.srcObject = stream;
            await video.play().catch(() => {});
            wait.classList.add('d-none');
            mode('live');
        } catch (err) { fail(err); }
    };

    btn.capture.addEventListener('click', async () => {
        const w = video.videoWidth;
        const h = video.videoHeight;
        if (!w || !h) return;
        const side = Math.min(w, h);
        shot.width = SIZE;
        shot.height = SIZE;
        const ctx = shot.getContext('2d');
        ctx.save();
        ctx.translate(SIZE, 0);
        ctx.scale(-1, 1); // mirrored, as the officer saw it
        ctx.drawImage(video, (w - side) / 2, (h - side) / 2, side, side, 0, 0, SIZE, SIZE);
        ctx.restore();
        let q = 0.9;
        blob = await toBlob(shot, q);
        while (blob && blob.size > maxBytes && q > 0.55) { q -= 0.1; blob = await toBlob(shot, q); }
        stop();
        mode('taken');
    });
    btn.retake.addEventListener('click', start);
    btn.use.addEventListener('click', () => {
        if (!blob) return;
        putFile(input, new File([blob], `selfie-${stamp()}.jpg`, { type: 'image/jpeg', lastModified: Date.now() }));
        modal.hide();
    });

    open.addEventListener('click', () => modal.show());
    modalEl.addEventListener('shown.bs.modal', start);
    modalEl.addEventListener('hidden.bs.modal', () => { stop(); blob = null; mode('live'); });
    window.addEventListener('pagehide', stop);
}
