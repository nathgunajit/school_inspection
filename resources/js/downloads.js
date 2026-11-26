/**
 * 26-11-2026 performance, module B: large downloads are made in the background (App\Support\QueuedExports).
 *
 * After an export link is clicked the server answers at once and the page comes back with
 * body[data-export-job]. This file remembers that job (sessionStorage, so moving to another page keeps it),
 * asks body[data-downloads-url] for its status every few seconds, and when it is ready starts the download by
 * itself. A small box at the bottom of the screen shows what is being prepared. On the Downloads page
 * ([data-downloads-page]) the list reloads when a job there finishes.
 */
import { escapeHtml } from './util'; // never from './app' (that bundles app.js twice)

const STORE = 'si-export-jobs';
const POLL_MS = 3000;
const MAX_AGE_MS = 3 * 60 * 60 * 1000; // stop following a job after 3 hours

const body = document.body;
const statusUrl = body.dataset.downloadsUrl;

function load() {
    try {
        const now = Date.now();
        return (JSON.parse(sessionStorage.getItem(STORE) || '[]') || []).filter((j) => j && j.key && now - j.at < MAX_AGE_MS);
    } catch {
        return [];
    }
}
function save(list) {
    try { sessionStorage.setItem(STORE, JSON.stringify(list)); } catch { /* private mode: follow on this page only */ }
}

let jobs = load();
if (body.dataset.exportJob && !jobs.some((j) => j.key === body.dataset.exportJob)) {
    jobs.push({ key: body.dataset.exportJob, at: Date.now() });
    save(jobs);
}

let box;
function render(items) {
    const active = items.filter((i) => i.status === 'queued' || i.status === 'running');
    if (!active.length) {
        box?.remove();
        box = null;
        return;
    }
    if (!box) {
        box = document.createElement('div');
        box.className = 'si-export-box';
        box.setAttribute('role', 'status');
        box.setAttribute('aria-live', 'polite');
        document.body.appendChild(box);
    }
    box.innerHTML = active.map((i) => `<div class="si-export-item"><span class="spinner-border spinner-border-sm"></span>
        <span class="min-w-0"><b class="d-block text-truncate">${escapeHtml(i.title)}</b><span class="text-xs">${i.stalled ? 'Still waiting to start: the background worker may not be running. Please tell your administrator.' : 'Preparing your file…'}</span></span></div>`).join('')
        + `<a class="si-export-link" href="${escapeHtml(statusUrl.replace(/\/status$/, ''))}">Downloads</a>`;
}

function notify(text, kind) {
    const el = document.createElement('div');
    el.className = `si-export-note is-${kind}`;
    el.setAttribute('role', 'status');
    el.textContent = text;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 6000);
}

let timer;
async function poll() {
    clearTimeout(timer);
    const page = document.querySelector('[data-downloads-page]');
    const pageActive = page ? [...page.querySelectorAll('[data-download-row]')].filter((r) => ['queued', 'running'].includes(r.dataset.status)) : [];
    if (!statusUrl || (!jobs.length && !pageActive.length)) {
        render([]);
        return;
    }
    const keys = [...new Set([...jobs.map((j) => j.key), ...pageActive.map((r) => r.dataset.downloadRow)])];
    try {
        const res = await fetch(`${statusUrl}?keys=${encodeURIComponent(keys.join(','))}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) throw new Error(String(res.status));
        const items = (await res.json()).items || [];
        const byKey = Object.fromEntries(items.map((i) => [i.key, i]));
        let reloadPage = false;

        jobs = jobs.filter((j) => {
            const i = byKey[j.key];
            if (!i) return false; // removed, or older than the keep time
            if (i.status === 'done') {
                notify(`${i.title} is ready.`, 'ok');
                if (i.url) window.location.href = i.url; // the file downloads; the page stays
                return false;
            }
            if (i.status === 'failed') {
                notify(i.error || `${i.title} could not be made.`, 'error');
                return false;
            }
            return true;
        });
        save(jobs);
        pageActive.forEach((r) => {
            const i = byKey[r.dataset.downloadRow];
            if (!i || i.status !== r.dataset.status) reloadPage = true;
        });
        render(items.filter((i) => jobs.some((j) => j.key === i.key)));
        if (reloadPage) {
            setTimeout(() => window.location.reload(), 800); // after the download has started
            return;
        }
    } catch {
        // network trouble: try again later
    }
    timer = setTimeout(poll, POLL_MS);
}

if (statusUrl) {
    poll();
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
}
