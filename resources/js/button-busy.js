// Processing look for submit buttons: spinner + text + shimmer, fixed width, no double click.
// Text: data-loading-text on the button, else a default from its label.
const DEFAULTS = [
    [/log\s?in|sign\s?in/i, 'Signing in…'],
    [/regist/i, 'Submitting…'],
    [/submit|send/i, 'Submitting…'],
    [/save|update|change/i, 'Saving…'],
    [/approve/i, 'Approving…'],
    [/reject/i, 'Rejecting…'],
    [/upload|check|import/i, 'Checking…'],
    [/start/i, 'Starting…'],
    [/delete|remove|cancel/i, 'Processing…'],
    [/search|filter|apply|show/i, 'Loading…'],
];

const textFor = (btn) => {
    if (btn.dataset.loadingText) return btn.dataset.loadingText;
    const label = (btn.textContent || btn.value || '').trim();
    return (DEFAULTS.find(([re]) => re.test(label)) || [null, 'Please wait…'])[1];
};

export function setBusy(btn, text) {
    if (!btn || btn.hasAttribute('data-si-busy')) return;
    const isInput = btn.tagName === 'INPUT';
    btn.dataset.siBusy = isInput ? btn.value : btn.innerHTML;
    const w = btn.getBoundingClientRect().width;
    if (w) btn.style.minWidth = `${Math.ceil(w)}px`;
    const t = text || textFor(btn);
    if (isInput) btn.value = t;
    else btn.innerHTML = '<span class="btn-spin" aria-hidden="true"></span><span class="btn-busy-text"></span>';
    if (!isInput) btn.querySelector('.btn-busy-text').textContent = t;
    btn.classList.add('is-loading');
    btn.setAttribute('aria-busy', 'true');
    btn.setAttribute('aria-live', 'polite');
    btn.disabled = true;
}

export function clearBusy(btn) {
    if (!btn || !btn.hasAttribute('data-si-busy')) return;
    if (btn.tagName === 'INPUT') btn.value = btn.dataset.siBusy;
    else btn.innerHTML = btn.dataset.siBusy;
    btn.removeAttribute('data-si-busy');
    btn.classList.remove('is-loading');
    btn.removeAttribute('aria-busy');
    btn.removeAttribute('aria-live');
    btn.style.minWidth = '';
    btn.disabled = false;
}

export const releaseAll = () => document.querySelectorAll('[data-si-busy]').forEach(clearBusy);

/** The button that sent the form: the submitter, else the first submit button. */
export const submitButtonOf = (form, submitter) => submitter
    || form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');

window.SI_BUSY = { setBusy, clearBusy, releaseAll };
window.addEventListener('pageshow', (e) => { if (e.persisted) releaseAll(); });
