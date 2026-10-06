/*
 * ctnlist site script, loaded on every page through the import map
 * ({{ importmap('app') }} in base.html.twig). Bootstrap's bundle, loaded
 * before this module runs, provides dropdowns, the collapsing navbar and
 * dismissible alerts.
 */
import './styles/app.css';

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
