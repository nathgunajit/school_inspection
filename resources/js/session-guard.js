/*
 * 25-11-2026, one user per browser. Tabs of one browser share the sign-in cookie, so when another
 * user signs in, the pages still open for the earlier user would act as the new one. Each signed-in
 * page carries its user token (<meta name="si-user">, App\Support\PageUser):
 *  - it is sent back with every same-origin save (`_si_user` field, `X-SI-User` header); the server
 *    refuses a save whose token is not the signed-in user's (EnsurePageUser: 409 / redirect);
 *  - localStorage `si-auth` names the user of the newest page; when another tab signs in or out, the
 *    other tabs show a message that cannot be dismissed and go to the sign-in page.
 */
import axios from 'axios';

const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';
const KEY = 'si-auth';
const FIELD = '_si_user';
const me = meta('si-user');
const myName = (() => { try { return new TextDecoder().decode(Uint8Array.from(atob(meta('si-user-name')), (c) => c.charCodeAt(0))); } catch { return ''; } })(); // base64 (UTF-8)
const loginUrl = meta('si-login-url') || '/login';
const isGuestPage = !!meta('si-guest');

const read = () => { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch { return null; } };
const write = (v) => { try { v ? localStorage.setItem(KEY, JSON.stringify(v)) : localStorage.removeItem(KEY); } catch { /* private mode */ } };
const sameOrigin = (url) => { try { return new URL(url, location.href).origin === location.origin; } catch { return false; } };

let shown = false;
function showChanged(message) {
    if (shown) return;
    shown = true;
    document.body.classList.remove('bars-hidden');
    const box = document.createElement('div');
    box.className = 'modal fade si-user-changed';
    box.tabIndex = -1;
    box.setAttribute('role', 'alertdialog');
    box.setAttribute('aria-modal', 'true');
    box.innerHTML = `<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-body text-center p-4">
            <span class="suc-icon"><i class="bi bi-person-exclamation"></i></span>
            <h2 class="h5 mt-3 mb-2">You have been signed out</h2>
            <p class="mb-0 text-secondary"></p>
        </div>
        <div class="modal-footer justify-content-center border-0 pt-0 pb-4">
            <a class="btn btn-primary" data-no-loader href="${loginUrl}"><i class="bi bi-box-arrow-in-right"></i>Go to sign-in</a>
        </div></div></div>`;
    box.querySelector('p').textContent = message;
    document.body.appendChild(box);
    const bs = window.bootstrap?.Modal;
    if (bs) new bs(box, { backdrop: 'static', keyboard: false }).show();
    else { box.style.display = 'block'; box.classList.add('show'); }
}
window.siUserChanged = (message) => showChanged(message || 'Another user has signed in on this browser.');

function check() {
    if (!me || shown) return;
    const now = read();
    if (now && now.u === me) return;
    if (now && now.u) {
        showChanged(`Another user (${now.n || 'another officer'}) has signed in on this browser, so ${myName || 'you'} have been signed out of this page. Only one user can be signed in at a time in one browser.`);
    } else if (now === null && localStorage.getItem(`${KEY}-out`)) {
        showChanged('You signed out in another tab of this browser. Please sign in again to continue.');
    }
}

if (me) {
    write({ u: me, n: myName });
    try { localStorage.removeItem(`${KEY}-out`); } catch { /* ignore */ }

    // every same-origin save carries the page's user
    document.addEventListener('submit', (e) => {
        const f = e.target;
        if (!(f instanceof HTMLFormElement) || (f.method || 'get').toLowerCase() !== 'post' || !sameOrigin(f.action || location.href)) return;
        let input = f.querySelector(`input[name="${FIELD}"]`);
        if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = FIELD; f.appendChild(input); }
        input.value = me;
    }, true);
    axios.defaults.headers.common['X-SI-User'] = me;
    const nativeFetch = window.fetch?.bind(window);
    if (nativeFetch) {
        window.fetch = (input, init = {}) => {
            const url = typeof input === 'string' ? input : input?.url;
            const method = (init.method || (typeof input === 'object' && input?.method) || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD' && sameOrigin(url)) {
                const headers = new Headers(init.headers || (typeof input === 'object' ? input.headers : undefined));
                headers.set('X-SI-User', me);
                init = { ...init, headers };
            }
            return nativeFetch(input, init);
        };
    }
    axios.interceptors.response.use(undefined, (err) => {
        if (err?.response?.status === 409 && err.response.data?.user_changed) window.siUserChanged(err.response.data.message);
        return Promise.reject(err);
    });
}

// signing out here tells the other tabs
document.addEventListener('submit', (e) => {
    if (e.target instanceof HTMLFormElement && e.target.matches('[data-si-signout]')) {
        write(null);
        try { localStorage.setItem(`${KEY}-out`, String(Date.now())); } catch { /* ignore */ }
    }
});

window.addEventListener('storage', (e) => {
    if (e.key !== KEY && e.key !== `${KEY}-out`) return;
    if (me) { check(); return; }
    // a guest page (sign-in) reloads when someone signs in elsewhere, so it shows who is signed in
    if (isGuestPage && read()?.u && location.pathname.replace(/\/+$/, '').endsWith('/login')) location.reload();
});
window.addEventListener('pageshow', check);
window.addEventListener('focus', check);
document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
if (isGuestPage && document.querySelector('form[action$="/login"]')) write(null); // the browser has no signed-in user now
