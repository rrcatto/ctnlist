/*
 * ctnlist site script, loaded on every page through the import map
 * ({{ importmap('app') }} in base.html.twig). Bootstrap 6's bundle is an ES
 * module with no global: importing it here starts its data-attribute API
 * (menus, the navigation drawers, tabs, dismissible alerts) and gives this
 * script the classes it calls. styles/app.css is linked from base.html.twig
 * rather than imported here: AssetMapper maps CSS imports to data: script
 * URLs, which the Content-Security-Policy (script-src 'self' + nonce) refuses.
 */
import { Alert, Tab } from './lib/bootstrap/bootstrap.bundle.min.js';
// The profile picture editor; it does nothing on pages without [data-profile-image].
import './profile_image.js';

// Flash messages close themselves after a few seconds, as they did in v5.
document.querySelectorAll('.alert[data-autoclose]').forEach((alert) => {
    window.setTimeout(() => Alert.getOrCreateInstance(alert).close(), 5000);
});

// Forms (or individual submit buttons) whose action needs confirming carry
// data-confirm="Question?"; the clicked button's question wins.
document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    const question = event.submitter?.dataset.confirm ?? form?.dataset.confirm;
    if (question && !window.confirm(question)) {
        event.preventDefault();
    }
});

// A form marked data-autosubmit is submitted as soon as the page has loaded: the step that
// finishes a mail service connection is a POST, and its page arrives by a GET from that service.
document.querySelectorAll('form[data-autosubmit]').forEach((form) => {
    form.requestSubmit();
});

// A menu of checkboxes (data-checklist) names the ticked choices on its toggle:
// the data-checklist-summary inside the element before the menu. With none
// ticked it shows data-checklist-empty.
document.querySelectorAll('[data-checklist]').forEach((menu) => {
    const summary = menu.previousElementSibling?.querySelector('[data-checklist-summary]');
    if (!summary) {
        return;
    }
    menu.addEventListener('change', () => {
        const names = [...menu.querySelectorAll('input[type="checkbox"]:checked')].map((box) => box.labels[0]?.textContent.trim() ?? '');
        summary.textContent = names.length > 0 ? names.join(', ') : (menu.dataset.checklistEmpty ?? '');
    });
});

// Tab sets marked data-remember-tab keep the active tab in the URL hash (the
// tab button's data-tab-hash; panes have other ids, so the browser does not
// jump), and opening the page at …#hash shows that tab with the tab bar just
// below the sticky header.
document.querySelectorAll('[data-remember-tab]').forEach((tabs) => {
    const hash = window.location.hash.slice(1);
    const button = hash ? tabs.querySelector(`[data-tab-hash="${CSS.escape(hash)}"]`) : null;
    if (button) {
        Tab.getOrCreateInstance(button).show();
        const header = document.querySelector('.app-header');
        const top = tabs.getBoundingClientRect().top + window.scrollY - (header?.offsetHeight ?? 0) - 16;
        window.scrollTo(0, Math.max(0, top));
    }
    tabs.addEventListener('shown.bs.tab', (event) => {
        window.history.replaceState(null, '', '#' + event.target.dataset.tabHash);
    });
});
