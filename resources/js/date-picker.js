// Clean, consistent calendar for every <input type="date"> (flatpickr).
// The original input keeps the Y-m-d value and still fires "change", so forms,
// data-autosubmit, the wizard and the map filters work as before.
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.css';

export function enhanceDates(root = document) {
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
            onReady: (_, __, inst) => {
                const alt = inst.altInput;
                alt.setAttribute('aria-label', el.getAttribute('aria-label') || el.labels?.[0]?.textContent?.trim() || 'Date');
                alt.placeholder = 'dd Mon yyyy';
                if (el.id) el.labels?.forEach((l) => l.addEventListener('click', (e) => { e.preventDefault(); alt.focus(); }));
                const wrap = document.createElement('div');
                wrap.className = 'fp-actions';
                wrap.innerHTML = '<button type="button" data-fp-today>Today</button><button type="button" data-fp-clear>Clear</button>';
                wrap.querySelector('[data-fp-today]').addEventListener('click', () => { inst.setDate(new Date(), true); inst.close(); });
                wrap.querySelector('[data-fp-clear]').addEventListener('click', () => { inst.clear(); inst.close(); });
                inst.calendarContainer.appendChild(wrap);
            },
        });
        el.addEventListener('fp-sync', () => fp.setDate(el.value, false));
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => enhanceDates());
else enhanceDates();
