// 26-11-2026: PDF / Excel download links get the same processing look as submit buttons and cannot be clicked
// again until the file arrives. The link is sent with `_dl=<token>`; App\Http\Middleware\MarkDownloadDone answers a
// file with the cookie si_dl=<token>, which ends the busy state. A queued export redirects back, so the page reloads.
import { setBusy, clearBusy } from './button-busy';

const PARAM = '_dl';
const COOKIE = 'si_dl';
const MAX_MS = 90000;
const isDownload = (url) => /\/(export|export-pdf|pdf|blank-form|download|template)\b|\.(xlsx|csv|pdf|zip)(\?|$)/i.test(url || '')
    && !/\/(evidence|photo|kept)\b/i.test(url || '');

const keyOf = (a) => {
    const u = new URL(a.href, location.href);
    u.searchParams.delete(PARAM);
    return u.href;
};
const linksFor = (key) => [...document.querySelectorAll('a[href]')].filter((l) => {
    try { return keyOf(l) === key; } catch { return false; }
});
const readCookie = () => (document.cookie.match(new RegExp(`(?:^|;\\s*)${COOKIE}=([^;]*)`)) || [])[1];
const dropCookie = () => { document.cookie = `${COOKIE}=; Max-Age=0; path=/`; };

const textFor = (a) => {
    if (a.dataset.loadingText) return a.dataset.loadingText;
    if (a.querySelector('.bi-file-earmark-pdf, .bi-file-earmark-pdf-fill') || /pdf/i.test(a.href)) return 'Preparing PDF…';
    if (a.querySelector('[class*="bi-file-earmark-excel"], [class*="bi-file-earmark-spreadsheet"]')) return 'Preparing Excel…';
    return 'Preparing file…';
};

function release(links) {
    links.forEach((l) => {
        clearBusy(l);
        l.removeAttribute('aria-disabled');
        delete l.dataset.siDownloading;
    });
}

document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href]');
    if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (a.target === '_blank' || a.closest('[data-no-busy]') || a.origin !== location.origin) return;
    if (!a.hasAttribute('download') && !isDownload(a.href)) return;

    e.preventDefault();
    const key = keyOf(a);
    const links = linksFor(key);
    if (links.some((l) => l.dataset.siDownloading)) return; // already being prepared: ignore the repeat click

    const token = Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => (b % 36).toString(36)).join('');
    const text = textFor(a);
    links.forEach((l) => {
        l.dataset.siDownloading = token;
        l.setAttribute('aria-disabled', 'true');
        setBusy(l, text);
    });
    dropCookie();

    const started = Date.now();
    const timer = setInterval(() => {
        if (readCookie() === token || Date.now() - started > MAX_MS) {
            clearInterval(timer);
            dropCookie();
            release(linksFor(key));
        }
    }, 400);

    const url = new URL(key);
    url.searchParams.set(PARAM, token);
    location.href = url.href; // a file keeps the page; a queued export comes back to it
});

window.addEventListener('pageshow', (e) => {
    if (e.persisted) release([...document.querySelectorAll('a[data-si-downloading]')]);
});
