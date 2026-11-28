/**
 * 28-11-2026: every mandatory field shows a red * in its label.
 * The HTML `required` attribute is the single source: this adds <span class="req req-auto">*</span> to the label of each
 * required input / select / textarea (label[for=id], else a wrapping <label>, else the nearest .form-label before it in the
 * same box). Labels that already have a star (.req, .q-req, a red "*") are left alone. Radios / checkboxes, hidden fields,
 * wizard questions (.q-block has its own .q-req) and anything inside [data-no-star] are skipped.
 * Fields that become required (or stop being required) later, swapped filter sections and modals are followed by a
 * MutationObserver; an automatic star is removed when its field is no longer required. Set up once.
 */
const FIELDS = 'input[required], select[required], textarea[required]';
const owner = new WeakMap(); // automatic star -> its field
const SKIP_TYPES = new Set(['hidden', 'radio', 'checkbox', 'submit', 'button', 'reset', 'image']);

function hasStar(label) {
    if (label.querySelector('.req, .q-req')) return true;
    return [...label.querySelectorAll('.text-danger')].some((s) => s.textContent.trim() === '*');
}

function labelFor(field) {
    if (field.id) {
        const byFor = document.querySelector(`label[for="${CSS.escape(field.id)}"]`);
        if (byFor) return byFor;
    }
    const wrap = field.closest('label');
    if (wrap && !wrap.classList.contains('btn')) return wrap; // a file "button" label is not a field label
    // the label written just above the field without for= (Add forms): nearest .form-label before it in the same parent;
    // a field inside an input group / number stepper is looked up from that group
    const anchor = field.closest('.input-group, .num-stepper') || field;
    for (let el = anchor.previousElementSibling; el; el = el.previousElementSibling) {
        if (el.matches('label.form-label, .form-label')) return el;
        if (el.matches(FIELDS) || el.querySelector?.(FIELDS)) return null; // another field sits between: not ours
    }
    const box = anchor.parentElement;
    if (box && box !== document.body) {
        const first = box.querySelector(':scope > label.form-label, :scope > .form-label');
        if (first && box.querySelectorAll(FIELDS).length === 1) return first;
    }
    return null;
}

function skip(field) {
    // a date box made by the date picker (date-picker.js, flatpickr altInput) keeps the original input as type=hidden
    // with data-fp: that one still stands for the field; the visible copy has no label of its own
    const type = (field.type || '').toLowerCase();
    if (type === 'hidden' && field.dataset.fp) return !!field.closest('[data-no-star], .q-block');
    return SKIP_TYPES.has(type) || field.closest('[data-no-star], .q-block');
}

function markRequired(root = document) {
    const scope = root.querySelectorAll ? root : document;
    const fields = [...scope.querySelectorAll(FIELDS)];
    if (root.matches?.(FIELDS)) fields.push(root);
    for (const f of fields) {
        if (skip(f)) continue;
        const label = labelFor(f);
        if (!label || hasStar(label)) continue;
        const star = document.createElement('span');
        star.className = 'req req-auto';
        star.setAttribute('aria-hidden', 'true');
        star.textContent = '*';
        owner.set(star, f);
        label.append(' ', star);
    }
}

/** Remove automatic stars whose field is no longer required. */
function unmark() {
    document.querySelectorAll('.req-auto').forEach((star) => {
        const field = owner.get(star);
        if (field && field.isConnected && field.required && !skip(field)) return;
        const prev = star.previousSibling;
        if (prev && prev.nodeType === Node.TEXT_NODE && prev.textContent === ' ') prev.remove();
        star.remove();
    });
}

let queued = false;
function schedule() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; unmark(); markRequired(document); });
}

if (!window.__siRequiredStar) {
    window.__siRequiredStar = true;
    const start = () => {
        markRequired(document);
        new MutationObserver((records) => {
            if (records.some((r) => r.type === 'childList' ? r.addedNodes.length : true)) schedule();
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['required'] });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
}

export { markRequired };
