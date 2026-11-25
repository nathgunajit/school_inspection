/**
 * 25-11-2026: live unread counters for Notices & Orders.
 * - Opening a notice ([data-notice-unread] link) marks it read at once on the page: its "New" tags go and every
 *   counter drops by one. The server marks it read when the notice opens; Back (bfcache) keeps this page state.
 * - The counters ([data-notice-count], their [data-notice-count-wrap]) are fetched again on Back / Forward,
 *   when the tab is shown again and every minute while it is visible (body[data-notice-count-url]).
 */
const url = document.body?.dataset.noticeCountUrl;
let current = null;

function counters() {
    return document.querySelectorAll('[data-notice-count]');
}

function setCount(n) {
    n = Math.max(0, Number(n) || 0);
    current = n;
    counters().forEach((el) => {
        const max = Number(el.dataset.max || 0);
        el.textContent = max && n > max ? `${max}+` : String(n);
        el.hidden = n === 0;
        const wrap = el.closest('[data-notice-count-wrap]');
        if (wrap) {
            wrap.hidden = n === 0;
            wrap.title = n ? `${n} unread notice${n > 1 ? 's' : ''}` : 'Unread notices';
        }
    });
}

function shownCount() {
    if (current !== null) return current;
    const el = [...counters()].find((c) => !c.hidden && !c.dataset.max);
    return el ? parseInt(el.textContent, 10) || 0 : 0;
}

// "auto read": the clicked notice (and every other link to it on the page, e.g. the ticker copies) loses its New tags
document.addEventListener('click', (e) => {
    const link = e.target.closest('a[data-notice-unread]');
    if (!link || e.defaultPrevented) return;
    const href = link.getAttribute('href');
    document.querySelectorAll('a[data-notice-unread]').forEach((a) => {
        if (a.getAttribute('href') !== href) return;
        a.removeAttribute('data-notice-unread');
        a.classList.remove('is-unread');
        a.querySelectorAll('[data-notice-new]').forEach((t) => t.remove());
    });
    setCount(shownCount() - 1);
});

let busy = false;
async function refresh() {
    if (!url || busy || !navigator.onLine) return;
    busy = true;
    try {
        const res = await fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (res.ok && (res.headers.get('content-type') || '').includes('json')) {
            const data = await res.json();
            if (typeof data.count === 'number') setCount(data.count);
        }
    } catch (_) {
        /* offline or signed out: keep what is shown */
    } finally {
        busy = false;
    }
}

if (url) {
    window.addEventListener('pageshow', (e) => { if (e.persisted) refresh(); });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refresh(); });
    window.addEventListener('focus', refresh);
    setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, 60000);
}
