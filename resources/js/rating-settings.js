// Rating Settings page: live point tints, band swatches / preview bar and an "unsaved changes" marker.
const root = document.querySelector('[data-rating-settings]');
if (root) {
    const form = root.querySelector('#rs-form');
    const preview = root.querySelector('[data-band-preview]');

    const tint = (input) => {
        const v = Math.min(1, Math.max(0, parseFloat(input.value) || 0));
        input.closest('.rs-chip')?.style.setProperty('--p', v);
    };

    const legend = root.querySelector('[data-band-legend]');
    const pct = (n) => `${Math.round(n * 100) / 100}`;

    // Colour-only bar, a readable legend below it, and "to N %" on each band row
    const drawBands = () => {
        const bands = [...root.querySelectorAll('[data-band]')].map((row) => ({
            row,
            min: Math.min(100, Math.max(0, parseFloat(row.querySelector('[data-band-min]').value) || 0)),
            label: row.querySelector('[data-band-label]').value.trim() || 'Untitled',
            color: row.querySelector('[data-band-color]').value,
        })).sort((a, b) => a.min - b.min);
        preview.innerHTML = '';
        legend.innerHTML = '';
        bands.forEach((b, i) => {
            const end = i + 1 < bands.length ? bands[i + 1].min : 100;
            const to = b.row.querySelector('[data-band-to]');
            if (to) to.textContent = end > b.min ? `to ${pct(end)} %` : 'no range';
            if (end <= b.min) return;
            const seg = document.createElement('span');
            seg.className = `rating-swatch rating-${b.color}`;
            seg.style.width = `${end - b.min}%`;
            seg.title = `${b.label}: ${pct(b.min)} – ${pct(end)} %`;
            preview.appendChild(seg);

            const li = document.createElement('li');
            li.title = seg.title;
            li.innerHTML = `<span class="rating-swatch rating-${b.color}"></span><span class="rs-legend-name"></span><span class="rs-legend-range">${pct(b.min)} – ${pct(end)} %</span>`;
            li.querySelector('.rs-legend-name').textContent = b.label;
            legend.prepend(li); // highest band first
        });
    };

    root.querySelectorAll('[data-point]').forEach(tint);
    drawBands();

    const dirty = () => form.classList.add('is-dirty');
    form.addEventListener('input', (e) => {
        if (e.target.matches('[data-point]')) tint(e.target);
        if (e.target.closest('[data-band]')) drawBands();
        dirty();
    });
    form.addEventListener('change', (e) => {
        if (e.target.matches('[data-band-color]')) {
            const sw = e.target.closest('[data-band]').querySelector('[data-swatch]');
            sw.className = `rating-swatch rs-band-swatch rating-${e.target.value}`;
            drawBands();
        }
        dirty();
    });
}
