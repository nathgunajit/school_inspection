/**
 * V6 Module 5: password fields are encrypted in the browser before any form is submitted, so the plain
 * password never travels in the request body (and never shows in the browser's network tab).
 *
 * The page carries <meta name="pw-key" content="<base64 SPKI>" data-nonce="<session nonce>">
 * (partials/password-key.blade.php). Each non-empty password, password_confirmation and current_password
 * field is sent as "enc:" + base64(RSA-OAEP-SHA1(JSON {p: password, n: nonce})); the server
 * (DecryptPasswordFields) decrypts it before validation. The visible inputs keep the typed value, without a
 * name, so the browser's password manager still works.
 *
 * WebCrypto is used where available. It exists only in a secure context (HTTPS or localhost); on plain HTTP
 * over a LAN address the node-forge fallback is loaded on demand.
 */
const FIELDS = ['password', 'password_confirmation', 'current_password'];
const MAX_BYTES = 214; // RSA-2048 with OAEP-SHA1: 256 - 2 × 20 - 2
const meta = document.querySelector('meta[name="pw-key"]');
let encryptor = null;

const b64ToBytes = (b64) => Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
const bytesToB64 = (bytes) => btoa(Array.from(bytes, (b) => String.fromCharCode(b)).join(''));

function getEncryptor() {
    if (encryptor) return encryptor;
    const spki = meta.content;
    if (window.isSecureContext && window.crypto?.subtle) {
        encryptor = crypto.subtle
            .importKey('spki', b64ToBytes(spki), { name: 'RSA-OAEP', hash: 'SHA-1' }, false, ['encrypt'])
            .then((key) => async (text) => bytesToB64(new Uint8Array(await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, key, new TextEncoder().encode(text)))));
    } else {
        encryptor = import('node-forge').then((mod) => {
            const forge = mod.default || mod;
            const pem = `-----BEGIN PUBLIC KEY-----\n${spki.match(/.{1,64}/g).join('\n')}\n-----END PUBLIC KEY-----`;
            const key = forge.pki.publicKeyFromPem(pem);
            return async (text) => forge.util.encode64(key.encrypt(forge.util.encodeUtf8(text), 'RSA-OAEP', {
                md: forge.md.sha1.create(), mgf1: { md: forge.md.sha1.create() },
            }));
        });
    }
    encryptor.catch(() => { encryptor = null; });
    return encryptor;
}

/** Puts the form back as it was: visible inputs named again, encrypted copies removed. */
function restore(form) {
    form.querySelectorAll('input[data-pw-encrypted]').forEach((h) => h.remove());
    form.querySelectorAll('input[data-pw-name]').forEach((el) => { el.name = el.dataset.pwName; delete el.dataset.pwName; });
}

function fail(form) {
    restore(form);
    window.SI_BUSY?.releaseAll();
    window.SI_LOADER?.hide();
    form.querySelectorAll('[type=submit]').forEach((b) => { b.disabled = false; });
    const msg = 'Your password could not be encrypted. Please reload the page and try again.';
    if (window.toast) window.toast(msg, 'danger'); else alert(msg);
}

if (meta?.content) {
    getEncryptor(); // warm up, so the first submit is not delayed

    // Bubble listener on the document: runs after the form's own handlers (confirm dialogs, client validation).
    document.addEventListener('submit', async (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || e.defaultPrevented) return;
        const inputs = [...form.elements].filter((el) => el instanceof HTMLInputElement && FIELDS.includes(el.name)
            && el.value !== '' && !el.value.startsWith('enc:'));
        if (!inputs.length) return;

        e.preventDefault();
        form.dispatchEvent(new CustomEvent('si-form-sending', { bubbles: true, detail: { submitter: e.submitter } })); // processing look at once
        try {
            const encrypt = await getEncryptor();
            const copies = [];
            for (const el of inputs) {
                const json = JSON.stringify({ p: el.value, n: meta.dataset.nonce });
                if (new TextEncoder().encode(json).length > MAX_BYTES) throw new Error('Password too long to encrypt');
                const h = Object.assign(document.createElement('input'), { type: 'hidden', name: el.name, value: 'enc:' + await encrypt(json) });
                h.dataset.pwEncrypted = '1';
                copies.push([el, h]);
            }
            for (const [el, h] of copies) {
                el.dataset.pwName = el.name;
                el.removeAttribute('name');
                form.appendChild(h);
            }
            if (e.submitter?.name) {
                const s = Object.assign(document.createElement('input'), { type: 'hidden', name: e.submitter.name, value: e.submitter.value });
                s.dataset.pwEncrypted = '1';
                form.appendChild(s);
            }
            HTMLFormElement.prototype.submit.call(form); // does not fire "submit" again
        } catch (err) {
            fail(form);
        }
    });

    // Back/forward cache: a page restored after submit must not keep the encrypted copies.
    window.addEventListener('pageshow', (e) => { if (e.persisted) document.querySelectorAll('form').forEach(restore); });
}
