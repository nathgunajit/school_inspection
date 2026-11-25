// Clean, consistent calendar for every <input type="date"> (flatpickr).
// The original input keeps the Y-m-d value and still fires "change", so forms,
// data-autosubmit, the wizard and the map filters work as before.
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';

// 25-11-2026: From / To pairs are linked: To cannot be before From, From cannot be after To.
const PAIRS = [['from', 'to'], ['custom_from', 'custom_to']];
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

export function enhanceDates(root = document) {
    const made = [];
    root.querySelectorAll('input[type="date"]:not([data-fp])').forEach((el) => {
        el.dataset.fp = '1';
        const fp = flatpickr(el, {
            altInput: true,
            altFormat: 'd M Y',
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
