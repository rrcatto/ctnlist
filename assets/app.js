/*
 * ctnlist site script, loaded on every page through the import map
 * ({{ importmap('app') }} in base.html.twig). Bootstrap 6's bundle is an ES
 * module with no global: importing it here starts its data-attribute API
 * (menus, the navigation drawers, tabs, dismissible alerts) and gives this
 * script the classes it calls. styles/app.css is linked from base.html.twig
 * rather than imported here: AssetMapper maps CSS imports to data: script
 * URLs, which the Content-Security-Policy (script-src 'self' + nonce) refuses.
 *
 * htmx 4 (lib/htmx, configured by the htmx-config meta tag in base.html.twig)
 * updates page regions in place: search fields that search as you type, page
 * turns, buttons that change one row or card, and regions that refresh
 * themselves while something is in progress (templates/CLAUDE.md, "htmx").
 * Every page works without it.
 */
import { Alert, Tab } from './lib/bootstrap/bootstrap.bundle.min.js';
import htmx from './lib/htmx/htmx.esm.min.js';
// The profile picture editor; it does nothing on pages without [data-profile-image].
import './profile_image.js';

// Forms (or individual submit buttons) whose action needs confirming carry
// data-confirm="Question?"; the clicked button's question wins. Listening in the
// capture phase answers before htmx sends a form it has taken over.
document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    const question = event.submitter?.dataset.confirm ?? form?.dataset.confirm;
    if (question && !window.confirm(question)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

// What the page needs on content that arrives, the first page included (htmx
// calls this for every element it processes, after each swap as well).
htmx.onLoad((root) => {
    // Flash messages close themselves after a few seconds, as they did in v5.
    root.querySelectorAll('.alert[data-autoclose]:not([data-closing])').forEach((alert) => {
        alert.dataset.closing = '';
        window.setTimeout(() => Alert.getOrCreateInstance(alert).close(), 5000);
    });

    // A menu of checkboxes (data-checklist) names the ticked choices on its toggle:
    // the data-checklist-summary inside the element before the menu. With none
    // ticked it shows data-checklist-empty.
    root.querySelectorAll('[data-checklist]:not([data-checklist-ready])').forEach((menu) => {
        menu.dataset.checklistReady = '';
        const summary = menu.previousElementSibling?.querySelector('[data-checklist-summary]');
        if (!summary) {
            return;
        }
        menu.addEventListener('change', () => {
            const names = [...menu.querySelectorAll('input[type="checkbox"]:checked')].map((box) => box.labels[0]?.textContent.trim() ?? '');
            summary.textContent = names.length > 0 ? names.join(', ') : (menu.dataset.checklistEmpty ?? '');
        });
    });
});

// A form marked data-autosubmit is submitted as soon as the page has loaded: the step that
// finishes a mail service connection is a POST, and its page arrives by a GET from the mail service.
document.querySelectorAll('form[data-autosubmit]').forEach((form) => {
    form.requestSubmit();
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

// htmx swaps every answer into the page, errors included. A region belongs there, and so does
// a refused form (422, drawn again with its errors); anything else is a whole page. A page that
// was asked for is then opened as a page (the sign-in prompt when the session has ended, an error
// page); after an action the message area says it failed.
document.addEventListener('htmx:before:swap', (event) => {
    const ctx = event.detail.ctx;
    const status = ctx.response?.status ?? 200;
    const wholePage = /^\s*<!doctype html/i.test(ctx.text ?? '');
    if ((status < 400 || status === 422) && !wholePage) {
        return;
    }
    event.preventDefault();
    if (ctx.request.method === 'GET' || (wholePage && status < 400)) {
        window.location.assign(ctx.response?.raw?.url || ctx.request.action);
    } else {
        showProblem(`That did not work (the site answered ${status}). Reload the page to see where things stand, and try again.`);
    }
});

// No answer at all: the network. A request that was cancelled (a newer search replaced it) is not a problem.
document.addEventListener('htmx:error', (event) => {
    if (event.detail?.ctx && !event.detail.ctx.response && event.detail.error?.name !== 'AbortError') {
        showProblem('The site could not be reached. Check your connection, then reload the page.');
    }
});

function showProblem(text) {
    const area = document.getElementById('app-flash');
    if (!area) {
        return;
    }
    const alert = document.createElement('div');
    alert.className = 'alert theme-danger';
    alert.setAttribute('role', 'alert');
    const message = document.createElement('div');
    message.textContent = text;
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close ms-auto';
    close.dataset.bsDismiss = 'alert';
    close.setAttribute('aria-label', 'Close');
    alert.append(message, close);
    area.replaceChildren(alert);
    area.scrollIntoView({ block: 'nearest' });
}
