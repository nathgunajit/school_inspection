/**
 * Modification V7 (item 2): Indian mobile numbers are 10 digits starting with 6, 7, 8 or 9.
 * Every <input data-mobile> (register, login, forgot/reset password, profile mobile change):
 *  - keeps digits only, at most 10
 *  - refuses a first digit other than 6–9 as it is typed, with a message under the field
 *  - blocks the submit (before password encryption runs) while the number is not valid.
 * The server applies the same rule (regex ^[6-9]\d{9}$); this is only for quick feedback.
 */
export const MOBILE_RE = /^[6-9]\d{9}$/;

function message(v) {
    if (v === '') return 'Enter your 10-digit mobile number.';
    if (!/^[6-9]/.test(v)) return 'Mobile number must start with 6, 7, 8 or 9.';
    if (v.length < 10) return `Mobile number must be 10 digits (${v.length} entered).`;
    return '';
}

function feedback(input) {
    let el = input.parentElement.querySelector('.mobile-msg');
    if (!el) {
        el = Object.assign(document.createElement('div'), { className: 'invalid-feedback mobile-msg' });
        el.setAttribute('aria-live', 'polite');
        input.after(el);
    }
    return el;
}

function show(input, msg) {
    const el = feedback(input);
    el.textContent = msg;
    el.classList.toggle('d-block', !!msg);
    // a server error shown under the field is replaced by the live message while typing
    input.parentElement.querySelectorAll('.invalid-feedback:not(.mobile-msg)').forEach((x) => x.classList.toggle('d-none', !!msg || input.dataset.edited === '1'));
    input.classList.toggle('is-invalid', !!msg);
    input.setCustomValidity(msg);
}

export function initMobileInputs(root = document) {
    root.querySelectorAll('input[data-mobile]').forEach((input) => {
        if (input.dataset.mobileReady) return;
        input.dataset.mobileReady = '1';
        input.setAttribute('pattern', '[6-9][0-9]{9}');
        input.setAttribute('maxlength', '10');
        input.setAttribute('inputmode', 'numeric');

        input.addEventListener('input', () => {
            input.dataset.edited = '1';
            const raw = input.value.replace(/\D/g, '');
            let v = raw.slice(0, 10);
            let msg = '';
            if (v !== '' && !/^[6-9]/.test(v)) { msg = message(v); v = ''; } // a wrong first digit is not kept
            input.value = v;
            if (msg) return show(input, msg);
            // while typing, only complain once the length is complete or the field is left
            show(input, v.length === 10 ? message(v) : '');
        });
        input.addEventListener('blur', () => { if (input.value !== '') show(input, message(input.value)); });

        input.form?.addEventListener('submit', (e) => {
            const v = input.value;
            if (v === '' && !input.required) return;
            const msg = message(v);
            if (!msg) return;
            e.preventDefault();
            show(input, msg);
            input.focus();
        });
    });
}

document.addEventListener('DOMContentLoaded', () => initMobileInputs());
