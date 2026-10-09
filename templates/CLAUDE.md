# Templates, screens and assets

Every page extends `base.html.twig` (blocks `title`, `description`, `content`, `stylesheets`, `javascripts`), which includes `layout/_navbar`, `_admin_menu`, `_flash` and `_footer`. `App\Twig\AppExtension` provides the globals `site` (`SiteConfig`: use it instead of hard-coded site details) and `counters` (lazily queried admin menu counts) and the functions `csp_nonce()` and `profile_image_url()`. Link with `path('route')`, never hard-coded URLs. Controllers flash with `addFlash('info'|'danger', …)`; `_flash` reads flashes only from an existing session, so anonymous pages without a form start none and error pages render while the database is down.

- The administration bar (`layout/_admin_menu.html.twig`) is data-driven: each item names the permission its controller's `#[IsGranted]` requires and shows only when `is_granted()` allows it. Likewise show actions and links to protected pages only with `is_granted()` of the target's permission; this is a navigation aid, the controller is the boundary.
- Styling: Bootstrap 5.3 classes first; `assets/styles/app.css` is a small application layer (`app-narrow` for single-purpose forms). Icons are Bootstrap Icons (`bi bi-*`) only. No inline `style=`, `<script>` or event handlers: the CSP refuses them.
- Message HTML is never printed `|raw`; the public archive uses the `app.message_html` sanitizer.
- Client errors render the site's error templates (`bundles/TwigBundle/Exception/`) even with `APP_DEBUG=true` (`App\Twig\ErrorPageDebug`); only 5xx errors show Symfony's exception page in debug mode.

**Screen conventions** (administration pages follow them; see `admin/messages.html.twig`):
- header: `{% embed 'layout/_page_header.html.twig' with {title, description} %}` with an `actions` block (primary action first, `btn-primary`; secondary `btn-outline-secondary`);
- filters: a `form.card.card-body.bg-body-tertiary` (`role="search"`) with Search and Clear;
- tables: `.table-responsive > table.table.table-hover.align-middle`, `thead.table-light`, `scope="col"`, numbers `text-end`, row actions in a right-aligned `btn-group-sm`;
- empty results: `{% include 'layout/_empty_state.html.twig' with {message, icon, hint} %}` instead of an empty table;
- status badges: the macros in `admin/_macros.html.twig`. Data reaches Twig structured (e.g. `SubscriberRepository::reportPage()` returns each row's `memberships` as `{name, state}` pairs); never parse display strings in templates;
- forms: grouped in cards with the submit row in the footer (or a bordered row at the end), help text in `form-text` linked by `aria-describedby`, required fields marked `*`;
- buttons: starting delivery is `btn-success`, stopping `btn-warning`, removing or suppressing `btn-danger`/`btn-outline-danger`. Forms that delete, clear or suppress carry `data-confirm="Question?"` (handled in `assets/app.js`) and stay POST + CSRF;
- POST outcome pages: `page/result.html.twig` (`title`, `message`, optional `links` of `{label, route, permission}`);
- pagination: `_pagination.html.twig` with `App\Http\Pagination` (v5 `?r=` rows per page), keeping the filters in `query`.

**Forms.** The form theme `form/theme.html.twig` (on `bootstrap_5_layout.html.twig`, set in `config/packages/twig.yaml`) gives every widget `aria-describedby` (help and errors) and `aria-invalid`, gives forms `novalidate` (validation is server-side only) and marks required labels with the asterisk. Lay fields out with `form_row()` in the screen's grid (see `admin/message_form.html.twig`); render choices by hand only to add descriptions (subscribe page, role permissions), keeping `form_errors()`. Password fields always render empty.

**Assets.** AssetMapper maps `assets/` (`config/packages/asset_mapper.yaml`, `importmap.php`), and `{{ importmap('app', {nonce: csp_nonce()}) }}` loads `assets/app.js`. Stylesheets, including `styles/app.css` and the vendored `lib/bootstrap*`, are `<link>`ed with `asset()`: a CSS import from JavaScript becomes a `data:` script URL that the CSP refuses. Bootstrap and Bootstrap Icons are unmodified distribution files in `assets/lib/` (sources and hashes in `assets/lib/README.md`). No CDN, Node/npm or jQuery. In debug mode PHP serves `/assets/*`.

**CKEditor 5** is a prebuilt bundle in `public_html/vendor/ckeditor5/` (with its emoji list, so nothing comes from CKEditor's CDN). It loads only on pages extending `admin/_editor_page.html.twig`; `assets/ckeditor/editor.js` attaches it to the textarea marked `data-html-editor` (the HTML part of `MessageType`/`TemplateType`). Editor routes set the `_csp` default `ContentSecurityPolicy::EDITOR`.
