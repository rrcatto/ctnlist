# Vendored frontend libraries

Served from this site through AssetMapper (no runtime CDN, so the
Content-Security-Policy allows only the site's own origin). Files are the
unmodified distribution files; the three that were previously loaded from
jsDelivr with Subresource Integrity were verified against those hashes when
vendored.

| File | Source | SHA-384 (SRI) |
|---|---|---|
| bootstrap/bootstrap.min.css | https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css | sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB |
| bootstrap/bootstrap.bundle.min.js | https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js | sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI |
| bootstrap-icons/bootstrap-icons.min.css | https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css | sha384-CK2SzKma4jA5H/MXDUU7i1TqZlCFaD4T01vtyDFvPlD97JQyS+IsSh1nI2EFbpyk |
| bootstrap-icons/fonts/bootstrap-icons.woff2, .woff | https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/fonts/ | see the SHA-256 sums below |

Bootstrap and Bootstrap Icons are MIT licensed (LICENSE in each directory).
To upgrade, replace the files with a newer release's, update this table and
check every page in a browser.
    f55513b7b591cb84a3b87ff0e34ea24d4831d6fedc22e54b911ca64b5b544a15  bootstrap-icons/fonts/bootstrap-icons.woff
    6c75710364a1ca5604267716f6d28997b26319fdb078cf11e0b42ab66ff2ea61  bootstrap-icons/fonts/bootstrap-icons.woff2
