/*
 * 25-11-2026, faster loading: only the Bootstrap plugins the app uses, imported from their sources so
 * the bundle leaves out Carousel, Popover and ScrollSpy (the package entry is never tree-shaken).
 * Importing a plugin also sets up its data-bs-* attributes, as the full bundle does.
 */
export { default as Alert } from 'bootstrap/js/src/alert.js';
export { default as Button } from 'bootstrap/js/src/button.js';
export { default as Collapse } from 'bootstrap/js/src/collapse.js';
export { default as Dropdown } from 'bootstrap/js/src/dropdown.js';
export { default as Modal } from 'bootstrap/js/src/modal.js';
export { default as Offcanvas } from 'bootstrap/js/src/offcanvas.js';
export { default as Tab } from 'bootstrap/js/src/tab.js';
export { default as Toast } from 'bootstrap/js/src/toast.js';
export { default as Tooltip } from 'bootstrap/js/src/tooltip.js';
