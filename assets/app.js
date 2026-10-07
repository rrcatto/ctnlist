/*
 * ctnlist site script, loaded on every page through the import map
 * ({{ importmap('app') }} in base.html.twig). Bootstrap's bundle, loaded
 * before this module runs, provides dropdowns, the collapsing navbar and
 * dismissible alerts. styles/app.css is linked from base.html.twig rather
 * than imported here: AssetMapper maps CSS imports to data: script URLs,
 * which the Content-Security-Policy (script-src 'self' + nonce) refuses.
 */
// The profile picture editor; it does nothing on pages without [data-profile-image].
import './profile_image.js';

// Flash messages close themselves after a few seconds, as they did in v5.
document.querySelectorAll('.alert[data-autoclose]').forEach((alert) => {
    window.setTimeout(() => window.bootstrap?.Alert.getOrCreateInstance(alert).close(), 5000);
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

// Tab sets marked data-remember-tab keep the active tab in the URL hash (the
// tab button's data-tab-hash; panes have other ids, so the browser does not
// jump), and opening the page at …#hash shows that tab with the tab bar just
// below the sticky header.
document.querySelectorAll('[data-remember-tab]').forEach((tabs) => {
    const hash = window.location.hash.slice(1);
    const button = hash ? tabs.querySelector(`[data-tab-hash="${CSS.escape(hash)}"]`) : null;
    if (button) {
        window.bootstrap?.Tab.getOrCreateInstance(button).show();
        const header = document.querySelector('.app-header');
        const top = tabs.getBoundingClientRect().top + window.scrollY - (header?.offsetHeight ?? 0) - 16;
        window.scrollTo(0, Math.max(0, top));
    }
    tabs.addEventListener('shown.bs.tab', (event) => {
        window.history.replaceState(null, '', '#' + event.target.dataset.tabHash);
    });
});
