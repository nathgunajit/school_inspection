// Slow / offline internet banner and the branded loader between page loads (see components/page-loader.blade.php).
const banner = document.getElementById('si-net-banner');
const loader = window.SI_LOADER;

if (banner) {
    const KEY = 'si-net-dismissed';
    const MSG = {
        slow: ['warning', 'bi-wifi-1', 'Slow internet connection. Pages may take longer to load; your answers are still saved.'],
        offline: ['danger', 'bi-wifi-off', 'You are offline. Answers are kept on this device and sent when you reconnect.'],
        online: ['success', 'bi-wifi', 'Back online.'],
    };
    let state = null;
    let hideTimer;

    const render = (s) => {
        clearTimeout(hideTimer);
        state = s;
        if (!s || (s === 'slow' && sessionStorage.getItem(KEY))) { banner.hidden = true; return; }
        const [tone, icon, text] = MSG[s];
        banner.className = `si-net-banner is-${tone}`;
        banner.innerHTML = `<i class="bi ${icon}" aria-hidden="true"></i><span></span>`
            + (s === 'offline' ? '' : '<button type="button" class="si-net-close" aria-label="Dismiss">&times;</button>');
        banner.querySelector('span').textContent = text;
        banner.hidden = false;
        if (s === 'online') hideTimer = setTimeout(() => render(isSlow() ? 'slow' : null), 3000);
    };

    const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    let slowLoad = !!loader?.slowLoad;
    const isSlow = () => slowLoad || (!!conn && (['slow-2g', '2g'].includes(conn.effectiveType)
        || (conn.downlink > 0 && conn.downlink < 0.5) || conn.rtt > 1500));
    const evaluate = () => render(!navigator.onLine ? 'offline' : (isSlow() ? 'slow' : null));

    banner.addEventListener('click', (e) => {
        if (!e.target.closest('.si-net-close')) return;
        if (state === 'slow') sessionStorage.setItem(KEY, '1');
        render(null);
    });
    window.addEventListener('offline', () => render('offline'));
    window.addEventListener('online', () => render('online'));
    conn?.addEventListener?.('change', () => { if (navigator.onLine && state !== 'online') evaluate(); });
    document.addEventListener('si-slow-load', () => { slowLoad = true; if (navigator.onLine) evaluate(); });
    evaluate();
}

// Show the loader again when leaving the page by a normal link or form submit
if (loader) {
    const skip = (url) => /\/(export|pdf|download|template|report\/export|evidence|photo)\b|\.(xlsx|csv|pdf|zip)(\?|$)/i.test(url);
    const leave = () => {
        if (!navigator.onLine) return;
        loader.show();
        setTimeout(loader.hide, 15000); // never stuck, e.g. a file download that keeps the page
    };
    document.addEventListener('click', (e) => {
        const a = e.target.closest('a[href]');
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const href = a.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:') || a.target === '_blank' || a.hasAttribute('download')
            || a.hasAttribute('data-bs-toggle') || a.closest('[data-no-loader]') || a.origin !== location.origin || skip(a.href)) return;
        if (a.pathname === location.pathname && a.search === location.search && a.hash) return;
        setTimeout(() => { if (!e.defaultPrevented) leave(); }, 0);
    });
    document.addEventListener('submit', (e) => {
        const f = e.target;
        if (f.target === '_blank' || f.closest('[data-no-loader]') || skip(f.action || '')) return;
        setTimeout(() => { if (!e.defaultPrevented) leave(); }, 0);
    });
}
