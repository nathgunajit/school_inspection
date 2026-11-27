// Clean, consistent calendar for every <input type="date"> (flatpickr).
// The original input keeps the Y-m-d value and still fires "change", so forms,
// data-autosubmit, the wizard and the map filters work as before.
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';

// 25-11-2026: From / To pairs are linked: To cannot be before From, From cannot be after To.
const PAIRS = [['from', 'to'], ['custom_from', 'custom_to'], ['date', 'date_to'], ['w_from', 'w_to']]; // 27-11-2026: holiday From – To, withdraw From – To
const ID_PAIRS = [['fFrom', 'fTo']];

function partnerOf(el) {
    const scope = el.form || el.closest('.filter-bar, form, section') || document;
    for (const [a, b] of PAIRS) {
        if (el.name === a) return { other: scope.querySelector(`input[type="date"][name="${b}"]`), role: 'from' };
        if (el.name === b) return { other: scope.querySelector(`input[type="date"][name="${a}"]`), role: 'to' };
    }
    for (const [a, b] of ID_PAIRS) {
        if (el.id === a) return { other: document.getElementById(b), role: 'from' };
        if (el.id === b) return { other: document.getElementById(a), role: 'to' };
    }
    return null;
}

/** Set the partner's limit from this input's value (null when cleared), within the partner's own min / max. */
function link(el) {
    const p = partnerOf(el);
    const fp = p?.other?._flatpickr;
    if (!fp) return;
    const v = el.value || null;
    if (p.role === 'from') {
        const own = p.other.min || null;
        fp.set('minDate', v && (!own || v > own) ? v : own);
    } else {
        const own = p.other.max || null;
        fp.set('maxDate', v && (!own || v < own) ? v : own);
    }
}

// 27-11-2026: date of birth boxes (data-date-typing) also take a typed date, formatted as dd-mm-yyyy while typing.
const pad = (n) => String(n).padStart(2, '0');
const dmy = (d) => `${pad(d.getDate())}-${pad(d.getMonth() + 1)}-${d.getFullYear()}`;
const ymdToDmy = (s) => (s ? s.split('-').reverse().join('-') : '');

/** Strict parser for Y-m-d (values, min / max) and d-m-Y (typed): an impossible date such as 31-02 gives undefined. */
function strictParse(str) {
    if (str instanceof Date) return str;
    const s = String(str ?? '').trim();
    let m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    let y; let mo; let d;
    if (m) [, y, mo, d] = m.map(Number);
    else if ((m = s.match(/^(\d{2})[-/.](\d{2})[-/.](\d{4})$/))) [, d, mo, y] = m.map(Number);
    else return undefined;
    const date = new Date(y, mo - 1, d);
    return date.getFullYear() === y && date.getMonth() === mo - 1 && date.getDate() === d ? date : undefined;
}

/** Digits → "dd-mm-yyyy" (dashes after the day and the month); separators typed by the officer are allowed. */
function maskDigits(text, deleting) {
    const digits = text.replace(/\D/g, '').slice(0, 8);
    let out = digits.slice(0, 2);
    if (digits.length > 2 || (digits.length === 2 && !deleting)) out += '-';
    out += digits.slice(2, 4);
    if (digits.length > 4 || (digits.length === 4 && !deleting)) out += '-';
    out += digits.slice(4);
    return { out, digits };
}

function bindTyping(inst, el) {
    const alt = inst.altInput;
    alt.readOnly = false;
    alt.setAttribute('inputmode', 'numeric');
    alt.setAttribute('maxlength', '10');
    alt.setAttribute('autocomplete', el.getAttribute('autocomplete') || 'off');
    alt.placeholder = 'dd-mm-yyyy';

    const msg = document.createElement('div');
    msg.className = 'invalid-feedback d-block';
    msg.dataset.dateMsg = '1';
    msg.hidden = true;
    alt.insertAdjacentElement('afterend', msg);
    // 27-11-2026: the hint under the box shows only while it helps (focus without a full date), never beside an error
    const hint = el.closest('[class*="col"], .mb-3, form')?.querySelector('[data-date-hint]');
    const setHint = (on) => { if (hint) hint.hidden = !on; };
    const show = (text) => { msg.textContent = text; msg.hidden = !text; alt.classList.toggle('is-invalid', !!text); if (text) setHint(false); };
    inst.config.onChange.push(() => { if (el.value) { show(''); setHint(false); } });
    alt.addEventListener('focus', () => { if (!el.value && msg.hidden) setHint(true); });

    const problem = (text) => {
        const date = strictParse(text);
        if (!date) return `“${text}” is not a real date. Enter it as dd-mm-yyyy.`;
        const min = strictParse(el.min); const max = strictParse(el.max);
        if ((min && date < min) || (max && date > max)) {
            return `Date must be between ${ymdToDmy(el.min) || '…'} and ${ymdToDmy(el.max) || '…'}.`;
        }
        return null;
    };

    alt.addEventListener('input', (e) => {
        const deleting = (e.inputType || '').startsWith('delete');
        const { out, digits } = maskDigits(alt.value, deleting);
        if (alt.value !== out) alt.value = out;
        if (digits.length < 8) { show(''); setHint(true); return; }
        const p = problem(out);
        if (p) { show(p); return; }
        inst.setDate(strictParse(out), true);
        inst.jumpToDate(strictParse(out));
        show('');
    });
    alt.addEventListener('blur', () => {
        const text = alt.value.trim();
        if (!text) { if (el.value) inst.clear(); show(''); return; }
        if (el.value && text === dmy(strictParse(el.value))) return;
        const p = text.replace(/\D/g, '').length < 8 ? `“${text}” is not complete. Enter the date as dd-mm-yyyy.` : problem(text);
        // flatpickr's own blur parse (strict) clears a bad entry, so the message keeps what was typed
        setTimeout(() => show(p || ''), 0);
    });
}

export function enhanceDates(root = document) {
    const made = [];
    root.querySelectorAll('input[type="date"]:not([data-fp])').forEach((el) => {
        el.dataset.fp = '1';
        const typing = el.hasAttribute('data-date-typing');
        const fp = flatpickr(el, {
            altInput: true,
            altFormat: typing ? 'd-m-Y' : 'd M Y',
            allowInput: typing,
            ...(typing ? { parseDate: strictParse } : {}),
            dateFormat: 'Y-m-d',
            minDate: el.min || null,
            maxDate: el.max || null,
            disableMobile: true,
            monthSelectorType: 'static',
            prevArrow: '<i class="bi bi-chevron-left"></i>',
            nextArrow: '<i class="bi bi-chevron-right"></i>',
            onChange: () => link(el),
            onReady: (_, __, inst) => {
                const alt = inst.altInput;
                alt.setAttribute('aria-label', el.getAttribute('aria-label') || el.labels?.[0]?.textContent?.trim() || 'Date');
                alt.placeholder = 'dd Mon yyyy';
                if (typing) bindTyping(inst, el);
                if (el.id) el.labels?.forEach((l) => l.addEventListener('click', (e) => { e.preventDefault(); alt.focus(); }));
                const wrap = document.createElement('div');
                wrap.className = 'fp-actions';
                wrap.innerHTML = '<button type="button" data-fp-today>Today</button><button type="button" data-fp-clear>Clear</button>';
                wrap.querySelector('[data-fp-today]').addEventListener('click', () => { inst.setDate(new Date(), true); inst.close(); });
                wrap.querySelector('[data-fp-clear]').addEventListener('click', () => { inst.clear(); inst.close(); link(el); });
                inst.calendarContainer.appendChild(wrap);
            },
        });
        // a value set from code (map reset): drop the partner limit first, so the new value is not refused
        el.addEventListener('fp-sync', () => {
            fp.set('minDate', el.min || null);
            fp.set('maxDate', el.max || null);
            fp.setDate(el.value, false);
            link(el);
            const other = partnerOf(el)?.other;
            if (other) link(other);
        });
        made.push(el);
    });
    // limits from the values already in the boxes (both partners exist by now)
    made.forEach(link);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => enhanceDates());
else enhanceDates();
