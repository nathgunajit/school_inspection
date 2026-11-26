/**
 * V15 (26-11-2026): the bell in the top bar ([data-alerts]).
 * - The unread dot ([data-alert-count]) is fetched again every minute while the tab is visible, on focus and on Back.
 * - Opening the menu loads the latest alerts (alerts.count?items=1); a click opens the alert (marked read by the server).
 * Text is set with textContent only.
 */
const box = document.querySelector('[data-alerts]');
const url = box?.dataset.alertsUrl;

function setCount(n) {
    n = Math.max(0, Number(n) || 0);
    document.querySelectorAll('[data-alert-count]').forEach((el) => {
        el.textContent = n > 9 ? '9+' : String(n);
        el.hidden = n === 0;
    });
}

function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
}

function render(items) {
    const list = box.querySelector('[data-alert-items]');
    list.replaceChildren();
    if (!items || !items.length) {
        list.appendChild(el('div', 'alert-menu-empty', 'No alerts yet.'));
        return;
    }
    items.forEach((a) => {
        const row = el('a', `alert-menu-item${a.unread ? ' is-unread' : ''}`);
        row.href = a.url;
        const ico = el('span', 'alert-ico');
        ico.appendChild(el('i', `bi ${/^bi-[a-z0-9-]+$/.test(a.icon) ? a.icon : 'bi-bell'}`));
        const text = el('span', 'min-w-0 flex-grow-1');
        text.appendChild(el('span', 't', a.title));
        if (a.body) text.appendChild(el('span', 'b', a.body));
        text.appendChild(el('span', 'w', a.when || ''));
        row.append(ico, text);
        list.appendChild(row);
    });
}

let busy = false;
async function load(withItems = false) {
    if (!url || busy || !navigator.onLine) return;
    busy = true;
    try {
        const res = await fetch(withItems ? `${url}?items=1` : url, { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (res.ok && (res.headers.get('content-type') || '').includes('json')) {
            const data = await res.json();
            if (typeof data.count === 'number') setCount(data.count);
            if (withItems) render(data.items);
        }
    } catch (_) {
        /* offline or signed out: keep what is shown */
    } finally {
        busy = false;
    }
}

if (box && url) {
    box.addEventListener('show.bs.dropdown', () => load(true));
    window.addEventListener('pageshow', (e) => { if (e.persisted) load(); });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') load(); });
    setInterval(() => { if (document.visibilityState === 'visible') load(); }, 60000);
}
