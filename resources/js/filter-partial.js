// 23-11-2026: filtered lists and reports load inside their results section, not the whole page.
//
//  <form method="GET" data-filter data-loader-text="Calculating coverage…" id="…" data-filter-region>
//  <div id="…" data-filter-region data-filter-results> … </div>
//
// A filter submit (or a same-page link inside a region: pagination, sort, drill-down) fetches the page,
// swaps every [data-filter-region][id] with the one in the response and shows a spinner over the
// [data-filter-results] boxes only. The URL is updated, Back / Forward work. Anything unexpected
// (error, redirect such as an expired session, a missing region) falls back to a normal page load.
import { enhanceDates } from './date-picker';
import { setBusy, clearBusy } from './button-busy';

const regions = () => [...document.querySelectorAll('[data-filter-region][id]')];
let current = null;

function showLoading(text) {
    document.querySelectorAll('[data-filter-results]').forEach((box) => {
        box.classList.add('is-section-loading');
        box.setAttribute('aria-busy', 'true');
        if (!box.querySelector(':scope > .section-loader')) {
            const l = document.createElement('div');
            l.className = 'section-loader';
            l.innerHTML = '<div class="sl-box" role="status"><span class="sl-spin" aria-hidden="true"></span><span class="sl-text"></span></div>';
            l.querySelector('.sl-text').textContent = text || 'Loading…';
            box.appendChild(l);
        }
    });
}

function hideLoading() {
    document.querySelectorAll('[data-filter-results]').forEach((box) => {
        box.classList.remove('is-section-loading');
        box.removeAttribute('aria-busy');
        box.querySelector(':scope > .section-loader')?.remove();
    });
}

async function load(url, { push = true, text } = {}) {
    current?.abort();
    const ctl = new AbortController();
    current = ctl;
    showLoading(text);
    try {
        const res = await fetch(url, { headers: { 'X-Filter-Partial': '1', Accept: 'text/html' }, cache: 'no-store', credentials: 'same-origin', signal: ctl.signal });
        const finalUrl = new URL(res.url);
        if (!res.ok || res.redirected && finalUrl.pathname !== new URL(url, location.href).pathname) throw new Error('fallback');
        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        const mine = regions();
        if (!mine.length || mine.some((r) => !doc.getElementById(r.id))) throw new Error('fallback');
        mine.forEach((r) => {
            const fresh = document.importNode(doc.getElementById(r.id), true);
            r.replaceWith(fresh);
        });
        if (doc.title) document.title = doc.title;
        if (push) history.pushState({ siFilter: true }, '', res.url);
        regions().forEach((r) => init(r));
        document.dispatchEvent(new CustomEvent('si:content', { detail: { url: res.url } }));
    } catch (e) {
        if (e.name === 'AbortError') return;
        location.href = url;
    } finally {
        if (current === ctl) {
            current = null;
            hideLoading();
            document.querySelectorAll('form[data-filter] [data-si-busy]').forEach(clearBusy);
        }
    }
}

function init(root) {
    enhanceDates(root);
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => window.bootstrap?.Tooltip.getOrCreateInstance(el));
}

const sameList = (a) => a.origin === location.origin && a.pathname === location.pathname;

if (document.querySelector('form[data-filter]')) {
    history.replaceState({ siFilter: true }, '', location.href);

    document.addEventListener('submit', (e) => {
        const f = e.target.closest('form[data-filter]');
        if (!f || (f.method || 'get').toLowerCase() !== 'get' || e.defaultPrevented) return;
        e.preventDefault();
        const url = new URL(f.action || location.href, location.href);
        url.search = new URLSearchParams(new FormData(f)).toString();
        const btn = e.submitter || f.querySelector('.btn-filter');
        if (btn && !btn.matches('[data-no-busy]')) setBusy(btn);
        load(url.toString(), { text: f.dataset.loaderText });
    });

    document.addEventListener('click', (e) => {
        const a = e.target.closest('[data-filter-region] a[href]');
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (a.target || a.hasAttribute('download') || a.closest('[data-no-loader], [data-no-partial]') || a.hasAttribute('data-bs-toggle')) return;
        const href = a.getAttribute('href') || '';
        if (href.startsWith('#') || href.startsWith('javascript:') || !sameList(a)) return;
        e.preventDefault();
        load(a.href, { text: document.querySelector('form[data-filter]')?.dataset.loaderText });
    });

    window.addEventListener('popstate', (e) => {
        if (e.state?.siFilter) load(location.href, { push: false });
    });
}
