/*
 * Sidebar: phone menu (☰) and the desktop icon rail (<< / >>), remembered per browser.
 * 23-11-2026: collapsed (icon rail) by default, see the inline script in layouts/app; 'full' keeps it open.
 * In the rail every icon shows a styled name popup (.si-nav-tip) instead of the plain browser title.
 * 26-11-2026: moved out of app.js. Delegated click handlers, set up once (window.__siSidebar), so the
 * button can never be bound twice (a second binding folds and unfolds it on one click) or not at all.
 */
const html = document.documentElement;

function sidebarEl() { return document.querySelector('.app-sidebar'); }
const isRail = () => html.classList.contains('sidebar-mini') && window.matchMedia('(min-width: 992px)').matches;

function syncSidebarState() {
    const mini = html.classList.contains('sidebar-mini');
    document.querySelectorAll('[data-sidebar-mini]').forEach((b) => {
        const label = mini ? 'Expand menu' : 'Collapse menu';
        b.setAttribute('aria-label', label);
        b.setAttribute('aria-expanded', mini ? 'false' : 'true');
        b.dataset.tipTitle = label;
    });
    // the native title would show a second, plain tooltip next to ours
    document.querySelectorAll('.app-sidebar [data-mini-tip]').forEach((el) => {
        if (el.hasAttribute('title')) { el.dataset.tipTitle ??= el.getAttribute('title'); }
        if (mini) el.removeAttribute('title'); else if (el.dataset.tipTitle) el.setAttribute('title', el.dataset.tipTitle);
    });
}

let tip = null;
const hideTip = () => tip?.classList.remove('show');
function showTip(el) {
    if (!isRail()) return;
    if (!tip) {
        tip = document.createElement('div');
        tip.className = 'si-nav-tip';
        tip.setAttribute('role', 'tooltip');
        tip.id = 'si-nav-tip';
        document.body.appendChild(tip);
    }
    const label = el.matches('[data-sidebar-mini]') ? el.dataset.tipTitle
        : (el.querySelector('span:not(.badge)')?.textContent || el.dataset.tipTitle || '').trim();
    const badge = el.querySelector('.badge')?.textContent.trim();
    tip.replaceChildren();
    if (el.classList.contains('active')) { const d = document.createElement('i'); d.className = 'tip-dot'; d.title = 'Current page'; tip.appendChild(d); }
    tip.appendChild(document.createTextNode(label));
    if (badge) { const b = document.createElement('span'); b.className = 'tip-badge'; b.textContent = badge; tip.appendChild(b); }
    const r = el.getBoundingClientRect();
    const rail = sidebarEl().getBoundingClientRect();
    tip.style.left = `${rail.right + 12}px`;
    tip.style.top = `${r.top + r.height / 2}px`;
    el.setAttribute('aria-describedby', tip.id);
    tip.classList.remove('show');
    void tip.offsetWidth; // restart the slide-in
    tip.classList.add('show');
}

if (!window.__siSidebar) {
    window.__siSidebar = true;

    document.addEventListener('click', (e) => {
        const sidebar = sidebarEl();
        // phone menu
        if (e.target.closest?.('[data-toggle-sidebar]')) { sidebar?.classList.toggle('show'); return; }
        // desktop rail
        if (e.target.closest?.('[data-sidebar-mini]')) {
            const mini = html.classList.toggle('sidebar-mini');
            try { localStorage.setItem('si-sidebar', mini ? 'mini' : 'full'); } catch { /* private mode */ }
            hideTip();
            syncSidebarState();
            return;
        }
        if (sidebar?.classList.contains('show') && !sidebar.contains(e.target)) sidebar.classList.remove('show');
    });

    // name popups in the rail (a failure here never stops the buttons above)
    const tipTarget = (e) => (e.target instanceof Element ? e.target.closest('.app-sidebar [data-mini-tip]') : null);
    document.addEventListener('mouseover', (e) => { const el = tipTarget(e); if (el && !el.contains(e.relatedTarget)) { try { showTip(el); } catch { /* ignore */ } } });
    document.addEventListener('mouseout', (e) => { const el = tipTarget(e); if (el && !el.contains(e.relatedTarget)) hideTip(); });
    document.addEventListener('focusin', (e) => { const el = tipTarget(e); if (el) { try { showTip(el); } catch { /* ignore */ } } });
    document.addEventListener('focusout', (e) => { if (tipTarget(e)) hideTip(); });
    document.addEventListener('scroll', (e) => { if (e.target instanceof Element && e.target.closest('.app-sidebar')) hideTip(); }, { capture: true, passive: true });
    window.addEventListener('resize', hideTip);

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', syncSidebarState);
    else syncSidebarState();
}
