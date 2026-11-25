/**
 * 25-11-2026: click (or Enter / Space on) a calendar date [data-holiday-day] to open the day popup #holiday-day
 * (holidays/partials/day-modal): the day's holidays, a Remove button for those the user may manage, and the
 * "Add a holiday on this date" form for holiday managers. Used by the Holiday Calendar and the dashboard calendar.
 */
import * as bootstrap from 'bootstrap';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function open(cell) {
    const modal = document.getElementById('holiday-day');
    if (!modal) return;
    let day;
    try { day = JSON.parse(cell.dataset.holidayDay); } catch (_) { return; }

    modal.querySelector('[data-day-title]').textContent = day.label;
    modal.querySelector('[data-day-off]').hidden = !day.off;
    const list = modal.querySelector('[data-day-list]');
    list.innerHTML = (day.holidays || []).map((h, i) => `
        <div class="holiday-day-row">
            <span class="holiday-chip ${esc(h.chip)}">${esc(h.type)}</span>
            <span class="flex-grow-1 min-w-0"><span class="d-block fw-semibold">${esc(h.name)}</span><span class="d-block text-xs text-secondary">${esc(h.area)}</span></span>
            ${h.remove ? `<button type="button" class="btn btn-sm btn-outline-danger" data-day-remove-btn="${i}"><i class="bi bi-trash"></i>Remove</button>` : ''}
        </div>`).join('');
    modal.querySelector('[data-day-empty]').hidden = (day.holidays || []).length > 0;

    list.querySelectorAll('[data-day-remove-btn]').forEach((btn) => btn.addEventListener('click', () => {
        const h = day.holidays[Number(btn.dataset.dayRemoveBtn)];
        const form = modal.querySelector('[data-day-remove]');
        if (!form || !h?.remove) return;
        form.action = h.remove;
        form.dataset.confirm = `Remove ${h.name} (${day.label}) from the calendar?`;
        form.requestSubmit(); // submit event: data-confirm and the busy state still apply
    }));

    const add = modal.querySelector('[data-day-add]');
    if (add) {
        const date = add.querySelector('[data-day-date]');
        if (date.value !== day.date) {
            add.querySelector('[name="name"]').value = ''; // a different day: start the form fresh
            add.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
            add.querySelector('.alert')?.remove();
        }
        date.value = day.date;
    }
    bootstrap.Modal.getOrCreateInstance(modal).show();
}

document.addEventListener('click', (e) => {
    const cell = e.target.closest('[data-holiday-day]');
    if (cell) open(cell);
});
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const cell = e.target.closest?.('[data-holiday-day]');
    if (!cell) return;
    e.preventDefault();
    open(cell);
});
