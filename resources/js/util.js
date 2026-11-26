/*
 * 26-11-2026: small helpers shared by app.js and the modules it imports.
 * A module imported by app.js must never import from './app': Vite then bundles app.js a second
 * time, and every handler in it runs twice (the sidebar button folded and unfolded on one click).
 */
export function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
