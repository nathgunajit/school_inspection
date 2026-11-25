/**
 * Working without network (T2). The inspection pages are a normal responsive web app; when the network drops,
 * unsent work is kept in the browser's own storage (IndexedDB, available in mobile and desktop browsers) and
 * sent automatically when the network returns.
 *
 *  - ops:    saves that could not reach the server (answers, remarks, rows, fund years, suggestions), replayed in
 *            the order they were made. Replays are safe: answers / funds / suggestions are upserts, rows carry a
 *            row_uid, photos an evidence_uid.
 *  - photos: photographs (blob + stamp data) kept until the server confirms the upload.
 *
 * Everything is stored per inspection (SI.inspectionKey) and removed after it is sent. Starting or submitting an
 * inspection still needs the network. If the browser refuses storage (private mode, quota) the pages behave as
 * before and say so.
 */
const DB_NAME = 'si-offline';
const PHOTO_FIELDS = ['evidence_uid', 'kind', 'source', 'category', 'question_code', 'caption', 'taken_at', 'latitude', 'longitude', 'accuracy', 'no_location_reason', 'sha256'];

let dbPromise = null;
function db() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
        if (!('indexedDB' in window)) { reject(new Error('IndexedDB not available')); return; }
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => {
            const d = req.result;
            const ops = d.createObjectStore('ops', { keyPath: 'id', autoIncrement: true });
            ops.createIndex('insp', 'insp');
            const ph = d.createObjectStore('photos', { keyPath: 'evidence_uid' });
            ph.createIndex('insp', 'insp');
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
    dbPromise.catch(() => { dbPromise = null; });
    return dbPromise;
}

function tx(store, mode, fn) {
    return db().then((d) => new Promise((resolve, reject) => {
        const t = d.transaction(store, mode);
        const s = t.objectStore(store);
        let out;
        Promise.resolve(fn(s)).then((v) => { out = v; });
        t.oncomplete = () => resolve(out);
        t.onerror = () => reject(t.error);
        t.onabort = () => reject(t.error);
    }));
}
const reqP = (r) => new Promise((resolve, reject) => { r.onsuccess = () => resolve(r.result); r.onerror = () => reject(r.error); });
const byInsp = (store, insp) => tx(store, 'readonly', (s) => reqP(s.index('insp').getAll(insp)));

export const isNetworkError = (err) => !!err && !err.response && (err.code === 'ERR_NETWORK' || err.code === 'ECONNABORTED' || err.message === 'Network Error' || !navigator.onLine);

export const offlineStore = {
    async available() { try { await db(); return true; } catch { return false; } },

    /** Queue a request. An op with the same `key` (e.g. the same question) replaces the older one. */
    async addOp(insp, op) {
        await tx('ops', 'readwrite', async (s) => {
            if (op.key) {
                const old = await reqP(s.index('insp').getAll(insp));
                old.filter((o) => o.key === op.key).forEach((o) => s.delete(o.id));
            }
            s.add({ ...op, insp, createdAt: Date.now() });
        });
        notify(insp);
    },
    ops: (insp) => byInsp('ops', insp).then((a) => a.sort((x, y) => x.id - y.id)),
    removeOp: (id) => tx('ops', 'readwrite', (s) => s.delete(id)),

    async putPhoto(insp, item) {
        const rec = { insp, blob: item.blob, filename: item.filename, category_label: item.category_label ?? null, has_location: item.has_location ?? null };
        PHOTO_FIELDS.forEach((k) => { if (item[k] !== undefined) rec[k] = item[k]; });
        await tx('photos', 'readwrite', (s) => s.put(rec));
        notify(insp);
    },
    photos: (insp) => byInsp('photos', insp),
    async removePhoto(insp, uid) { await tx('photos', 'readwrite', (s) => s.delete(uid)); notify(insp); },

    async counts(insp) {
        try {
            const [o, p] = await Promise.all([this.ops(insp), this.photos(insp)]);
            return { ops: o.length, photos: p.length };
        } catch { return { ops: 0, photos: 0 }; }
    },
    async clear(insp) {
        try {
            const [o, p] = await Promise.all([this.ops(insp), this.photos(insp)]);
            await tx('ops', 'readwrite', (s) => o.forEach((x) => s.delete(x.id)));
            await tx('photos', 'readwrite', (s) => p.forEach((x) => s.delete(x.evidence_uid)));
        } catch { /* nothing stored */ }
        notify(insp);
    },
};

function notify(insp) {
    offlineStore.counts(insp).then((c) => document.dispatchEvent(new CustomEvent('si:offline-counts', { detail: { insp, ...c } })));
}

/** Uploads one stored photo (same fields as camera.js). */
export function sendPhoto(url, rec) {
    const fd = new FormData();
    fd.append('file', rec.blob, rec.filename);
    PHOTO_FIELDS.forEach((k) => { if (rec[k] !== null && rec[k] !== undefined && rec[k] !== '') fd.append(k, rec[k]); });
    return axios.post(url, fd, { timeout: 120000 });
}

/**
 * Sends everything stored for the inspection: ops in order, then photos.
 * hooks.onOp(op, res) / hooks.onPhoto(rec, res) after each success; hooks.onInvalid(op, err) for a 422;
 * hooks.skipPhoto(uid) lets camera.js keep its own upload of a photo in progress.
 * Stops at the first network error (still offline). Returns { done, left }.
 */
let syncing = null;
export function syncAll(insp, { evidenceUrl, hooks = {} } = {}) {
    if (syncing) return syncing;
    syncing = (async () => {
        let done = 0;
        try {
            for (const op of await offlineStore.ops(insp)) {
                try {
                    const res = await axios({ method: op.method, url: op.url, data: op.body });
                    await offlineStore.removeOp(op.id);
                    done++;
                    hooks.onOp?.(op, res);
                } catch (err) {
                    const code = err.response?.status;
                    if (!err.response) return { done, left: true };                    // still offline
                    if (code === 401 || code === 419 || code === 409) { hooks.onAuth?.(err); return { done, left: true }; }
                    if (code >= 500 || code === 429) return { done, left: true };      // try again later
                    // 422 / 403 / 404: the change can no longer be applied (invalid, inspection locked or removed)
                    await offlineStore.removeOp(op.id);
                    hooks.onInvalid?.(op, err);
                }
            }
            if (evidenceUrl) {
                for (const rec of await offlineStore.photos(insp)) {
                    if (hooks.skipPhoto?.(rec.evidence_uid)) continue;
                    try {
                        const res = await sendPhoto(evidenceUrl, rec);
                        await offlineStore.removePhoto(insp, rec.evidence_uid);
                        done++;
                        hooks.onPhoto?.(rec, res);
                    } catch (err) {
                        const code = err.response?.status;
                        if (!err.response || code >= 500 || code === 429) return { done, left: true };
                        if (code === 401 || code === 419 || code === 409) { hooks.onAuth?.(err); return { done, left: true }; }
                        await offlineStore.removePhoto(insp, rec.evidence_uid);
                        hooks.onInvalid?.({ photo: rec }, err);
                    }
                }
            }
            const c = await offlineStore.counts(insp);
            return { done, left: c.ops + c.photos > 0 };
        } finally {
            notify(insp);
            syncing = null;
        }
    })();
    return syncing;
}

/**
 * Banner at the top of the page: offline / saved on this device / syncing / all synced.
 * Returns { set(state) } for callers that know the real outcome of a request (navigator.onLine can be wrong).
 */
export function mountBanner(insp, anchor) {
    const bar = document.createElement('div');
    bar.className = 'offline-bar d-none';
    bar.setAttribute('role', 'status');
    bar.setAttribute('aria-live', 'polite');
    (anchor || document.querySelector('main') || document.body).prepend(bar);
    let counts = { ops: 0, photos: 0 };
    let state = navigator.onLine ? 'online' : 'offline';
    let hideTimer = null;
    const items = () => {
        const parts = [];
        if (counts.ops) parts.push(`${counts.ops} change${counts.ops === 1 ? '' : 's'}`);
        if (counts.photos) parts.push(`${counts.photos} photo${counts.photos === 1 ? '' : 's'}`);
        return parts.join(' and ');
    };
    const paint = () => {
        clearTimeout(hideTimer);
        const n = counts.ops + counts.photos;
        let cls = '', html = '';
        if (state === 'offline') {
            cls = 'is-offline';
            html = `<i class="bi bi-wifi-off"></i><span><b>No network.</b> ${n ? `${items()} saved on this device; ${n === 1 ? 'it' : 'they'} will be sent automatically.` : 'You can keep working; your answers are kept on this device.'}</span>`;
        } else if (state === 'syncing' || n) {
            cls = 'is-syncing';
            html = `<span class="spinner-border spinner-border-sm"></span><span>Back online: sending ${items() || 'saved changes'}…</span>`;
        } else if (state === 'synced') {
            cls = 'is-synced';
            html = '<i class="bi bi-cloud-check"></i><span>All changes saved to the server.</span>';
            hideTimer = setTimeout(() => { state = 'online'; paint(); }, 4000);
        }
        bar.className = `offline-bar ${cls}${cls ? '' : ' d-none'}`;
        bar.innerHTML = html;
    };
    document.addEventListener('si:offline-counts', (e) => { if (e.detail.insp === insp) { counts = e.detail; paint(); } });
    window.addEventListener('offline', () => { state = 'offline'; paint(); });
    window.addEventListener('online', () => { state = 'syncing'; paint(); });
    notify(insp);
    paint();
    return {
        set(s) { if (s === 'synced' && counts.ops + counts.photos > 0) s = 'syncing'; state = s; paint(); },
        get state() { return state; },
    };
}
