/**
 * 27-11-2026: the eye button beside a password box (partials/pw-toggle.blade.php, [data-pw-toggle]) shows or hides
 * the password in the same .input-group. Delegated, so every page and swapped form works. Before a form is sent
 * every shown password goes back to type="password", so the browser never stores it as plain text. The password
 * encryption (password-encrypt.js) works on field names, so it is not affected.
 */
function inputOf(btn) {
    return btn.closest('.input-group')?.querySelector('input[type="password"], input[data-pw-shown]');
}

function set(btn, input, show) {
    input.type = show ? 'text' : 'password';
    if (show) input.dataset.pwShown = '1'; else delete input.dataset.pwShown;
    const label = show ? 'Hide password' : 'Show password';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.setAttribute('aria-label', label);
    btn.title = label;
    const icon = btn.querySelector('i');
    icon?.classList.toggle('bi-eye', !show);
    icon?.classList.toggle('bi-eye-slash', show);
}

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-pw-toggle]');
    if (!btn) return;
    const input = inputOf(btn);
    if (!input) return;
    e.preventDefault();
    const pos = input.selectionStart;
    set(btn, input, input.type === 'password');
    input.focus();
    try { if (pos !== null) input.setSelectionRange(pos, pos); } catch { /* not supported on this type */ }
});

// capture phase: runs before the encryption listener and the browser's own password saving
document.addEventListener('submit', (e) => {
    e.target.querySelectorAll?.('input[data-pw-shown]').forEach((input) => {
        const btn = input.closest('.input-group')?.querySelector('[data-pw-toggle]');
        if (btn) set(btn, input, false); else input.type = 'password';
    });
}, true);
