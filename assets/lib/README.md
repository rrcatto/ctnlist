# Vendored frontend libraries

Served from this site through AssetMapper (no runtime CDN, so the
Content-Security-Policy allows only the site's own origin). Files are the
unmodified distribution files. Bootstrap's come from the npm package
`bootstrap@6.0.0-alpha.1`, whose tarball was verified against the registry's
integrity value
(`sha512-snAWSjKDVdQAyPOtR69Upy247tZq13gw86SMDMZS4wNfBXGNdzbHdIP6GAyaAVJwTX4LjWowY8YYaJkSDv2wGQ==`)
when vendored; the Bootstrap Icons stylesheet was verified against its
jsDelivr Subresource Integrity hash.

| File | Source | SHA-384 (SRI) |
|---|---|---|
| bootstrap/bootstrap.min.css | `dist/css/bootstrap.min.css` in bootstrap@6.0.0-alpha.1 | sha384-B/GM4XqrwHnWXNOWMbloTmrYXZg10cakYGmpfsR/bbzQ6JAJI4ihuyADKLnBgrCe |
| bootstrap/bootstrap.bundle.min.js | `dist/js/bootstrap.bundle.min.js` in bootstrap@6.0.0-alpha.1 (an ES module that includes Floating UI and Vanilla Calendar Pro) | sha384-1a/pXj49ZQ1aHEmrJ+gMw1otqoVsYwlEnlD8mIfY2TV03r20Y0CN7uqx1tQogjPL |
| bootstrap-icons/bootstrap-icons.min.css | https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css | sha384-CK2SzKma4jA5H/MXDUU7i1TqZlCFaD4T01vtyDFvPlD97JQyS+IsSh1nI2EFbpyk |
| bootstrap-icons/fonts/bootstrap-icons.woff2, .woff | https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/fonts/ | see the SHA-256 sums below |
| htmx/htmx.esm.min.js | `dist/htmx.esm.min.js` in htmx.org@4.0.0 (an ES module; `assets/app.js` imports it) | sha384-lIFlfAj002Phzxvrea858KMM3/dXelBFF5rq+Oh5lChznUWuVI0mabAEUnsRa/2W |

htmx comes from the npm package `htmx.org@4.0.0`, whose tarball was verified
against the registry's integrity value
(`sha512-T/171FUY93Kdfp8t+DnHdk45QvKRiBhVhhrwSzrXgUi4pHKvhp77dUA/qg8FAjsFWPIHNbmUuIdCrcVHuiZWng==`)
when vendored. None of its extensions is used. `hx-csp` would gate htmx
behind per-element nonces; ctnlist relies instead on its Content-Security-Policy,
which allows no `eval` (so `hx-on`, `js:` values and trigger filters cannot
run, and no template uses them), on autoescaping, on the `data-hx-` prefix
being switched off (`htmx-config` in `templates/base.html.twig`) and on
`hx-ignore` around authored message HTML.

Bootstrap and Bootstrap Icons are MIT licensed, htmx is under the Zero-Clause
BSD licence (LICENSE in each directory).
To upgrade, replace the files with a newer release's, update this table and
check every page in a browser (for htmx, every search field, page turn and
in-place action: `templates/CLAUDE.md`, "htmx"). Bootstrap 6 is an alpha: read its migration
guide for each new pre-release, since class names and markup can still change.
    f55513b7b591cb84a3b87ff0e34ea24d4831d6fedc22e54b911ca64b5b544a15  bootstrap-icons/fonts/bootstrap-icons.woff
    6c75710364a1ca5604267716f6d28997b26319fdb078cf11e0b42ab66ff2ea61  bootstrap-icons/fonts/bootstrap-icons.woff2
