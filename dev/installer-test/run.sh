#!/usr/bin/env bash
# Installer test behind `bin/dev test-installer [--keep]`.
#
# Runs install-ctnlist as root in a stand-in server (dev/installer-test/Containerfile: a systemd
# container with PHP-FPM, PostgreSQL and nginx). The releases are throwaway Git tags in
# var/installer-test/release-repo, so the installer clones a tag exactly as it would from GitHub:
# the last released version as it was tagged, then this working tree as the next version, then
# the working tree again with a changed migration as the one after.
#
#   I  a new installation of the last release: every step, the layout and permissions, both
#      databases, the site through nginx and PHP-FPM, the timers and one worker run
#   R  a second run: everything is found and kept
#   U  the switch to this working tree: the database dumped, the data kept, the front
#      controller, the timers and the site on the new release; then back to the earlier
#      release by naming it, and forward again
#   S  a release with another schema: refused with nothing changed, then --recreate-database
#   C  a second installation for a host name nginx already serves: its site is left aside
#
# The stand-in is removed at the end unless --keep is given. The development stack is not touched.
set -euo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GEN="$REPO/var/installer-test"
RELEASE="$GEN/release-repo"
VPS=ctnlist_installer_test
IMAGE=localhost/ctnlist-installer-server:26.04
HOST=lists.ctnlist-installer.test
KEEP=false; [[ "${1:-}" == --keep ]] && KEEP=true
PASS=0 FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS  $*"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL  $*"; }
in_vps() { podman exec -i "$VPS" "$@"; }
installer() { in_vps bash /root/install-ctnlist --repo file:///src/ctnlist.git --yes "$@"; }
sql() { in_vps runuser -u postgres -- psql -X -At -d "${2:-ctnlist_lists}" -c "$1"; }
get() { in_vps curl -ksS --max-time 30 -o /dev/null -w '%{http_code}' --resolve "$HOST:443:127.0.0.1" "https://$HOST$1"; }
body() { in_vps curl -ksS --max-time 30 --resolve "$HOST:443:127.0.0.1" "https://$HOST$1"; }
release_of() { in_vps sed -n "s#^\$sharedDirectory = '/usr/local/lib/php/ctnlist/\([^/]*\)/';#\1#p" "/var/www/$1/public_html/index.php"; }
steps() { grep -cE '^\[[0-9]{2}/14\] .* (PASS|WARN)' "$1" || true; }

# snapshot <version> [git ref]: that ref (default: the working tree, tracked and new files) as release v<version>.
snapshot() {
  [[ -d "$RELEASE/.git" ]] || git init -q "$RELEASE"
  find "$RELEASE" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
  if [[ -n "${2:-}" ]]; then git -C "$REPO" archive "$2" | tar -C "$RELEASE" -xf -
  else (cd "$REPO" && git ls-files -co --exclude-standard -z | while IFS= read -r -d '' f; do [[ -f "$f" ]] && printf '%s\0' "$f"; done \
    | tar --null -T - -cf -) | tar -C "$RELEASE" -xf -; fi
  local was; was="$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$RELEASE/src/Config/SiteConfig.php")"
  sed -i "s/${was//./\\.}/$1/g" "$RELEASE/public_html/index.php" "$RELEASE/src/Config/SiteConfig.php"
  git -C "$RELEASE" add -A
  git -C "$RELEASE" -c user.name=installer-test -c user.email=installer-test@example.invalid commit -q --allow-empty -m "installer test $1"
  git -C "$RELEASE" tag -f "v$1" >/dev/null
}
cleanup() {
  [[ $KEEP == true ]] && { echo "installer-test: kept $VPS (podman rm -f $VPS when done)"; return; }
  podman rm -f --time 20 "$VPS" >/dev/null 2>&1 || true
  rm -rf "$GEN"
}

LAST="$(git -C "$REPO" describe --tags --abbrev=0)"; LAST="${LAST#v}"
NEXT="${LAST%.*}.$(( ${LAST##*.} + 1 ))"; AFTER="${LAST%.*}.$(( ${LAST##*.} + 2 ))"
podman rm -f --time 20 "$VPS" >/dev/null 2>&1 || true
rm -rf "$GEN"; mkdir -p "$GEN"
trap cleanup EXIT
podman build -q -t "$IMAGE" "$REPO/dev/installer-test" >/dev/null
snapshot "$LAST" "v$LAST"
podman run -d --name "$VPS" --hostname ctnlist-server --systemd=always \
  -v "$RELEASE:/src/ctnlist.git:ro" -v "$REPO/install-ctnlist:/root/install-ctnlist:ro" "$IMAGE" >/dev/null
in_vps systemctl is-system-running --wait >/dev/null 2>&1 || true
for _ in $(seq 1 30); do in_vps runuser -u postgres -- psql -Atc 'SELECT 1' >/dev/null 2>&1 && break; sleep 2; done
NEW_OPTIONS=(--host "$HOST" --admin-email "admin@ctnlist-installer.test" --list-name "Installer Test List" --mailer-dsn "smtp://127.0.0.1:1025" --create-banlist
  --mail-service "https://mail.ctnlist-installer.test")

echo "== I a new installation of $LAST"
if installer --installation lists --version "v$LAST" "${NEW_OPTIONS[@]}" > "$GEN/install-1.log" 2>&1; then pass "the installer finished (exit 0)"; else fail "installer exit: $(tail -n 20 "$GEN/install-1.log")"; fi
[[ "$(steps "$GEN/install-1.log")" == 14 ]] && pass "14 numbered steps, each PASS or WARN" || fail "steps: $(grep -E '^\[[0-9]{2}/14\]' "$GEN/install-1.log" | grep -v PASS)"
if [[ $FAIL -gt 0 ]]; then echo "installer-test: the installation failed, nothing further to test: $PASS passed, $FAIL failed"; exit 1; fi
[[ "$(in_vps stat -c '%a %U:%G' /var/www/lists/.env)" == "640 root:www-data" && "$(in_vps stat -c '%a %U' /var/www/lists/logs)" == "750 www-data" ]] \
  && ! in_vps runuser -u www-data -- test -w "/usr/local/lib/php/ctnlist/$LAST/src" \
  && pass ".env is 640 root:www-data, logs/ is private, and the code is read-only to PHP" || fail "permissions"
[[ "$(in_vps sed -n 's/^CATTOMAIL_API_BASE_URL=//p' /var/www/lists/.env)" == "https://mail.ctnlist-installer.test" ]] \
  && pass "the mail service's address (--mail-service) is in .env" || fail "CATTOMAIL_API_BASE_URL: $(in_vps sed -n 's/^CATTOMAIL_API_BASE_URL=//p' /var/www/lists/.env)"
secret="$(in_vps sed -n 's/^APP_SECRET=//p' /var/www/lists/.env)"; dbpass="$(in_vps sed -n 's/^DB_PASS=//p' /var/www/lists/.env)"
[[ ${#secret} == 64 && ${#dbpass} == 48 ]] && ! in_vps grep -qF -e "$secret" -e "$dbpass" /var/log/ctnlist-install.log && ! grep -qF -e "$secret" -e "$dbpass" "$GEN/install-1.log" \
  && pass "secrets are generated, and neither the log nor the output holds them" || fail "secrets"
[[ "$(sql "SELECT count(*) FROM lists WHERE l_shortcode = 'ALL'")" == 1 && "$(sql "SELECT to_regclass('public.phinxlog') IS NOT NULL" banlist)" == t \
   && "$(in_vps stat -c '%a %U:%G' /etc/ctnlist/ban.env)" == "640 root:www-data" ]] \
  && pass "the database has the schema, and the suppression database and its ban.env exist" || fail "databases"
[[ "$(body /health)" == OK && "$(get /login)" == 200 && "$(body /login)" == *"Installer Test List"* ]] \
  && pass "the site answers through nginx and PHP-FPM: /health and the sign-in page" || fail "site: health '$(body /health)', login $(get /login)"
css="$(body /login | sed -n 's/.*href="\(\/assets\/[^"]*\.css\)".*/\1/p' | head -n1)"
[[ -n "$css" && "$(get "$css")" == 200 ]] && pass "compiled assets are served" || fail "assets: '$css'"
[[ "$(in_vps systemctl is-active "ctnlist-worker@lists.timer" "ctnlist-maintenance@lists.timer" | tr '\n' ' ')" == "active active " ]] \
  && in_vps systemctl start "ctnlist-worker@lists.service" "ctnlist-maintenance@lists.service" \
  && pass "both timers are active, and the worker and maintenance run as their units" || fail "timers: $(in_vps systemctl status "ctnlist-worker@lists.service" 2>&1 | tail -n 5)"

echo "== R second run"
if installer --installation lists --version "v$LAST" "${NEW_OPTIONS[@]}" > "$GEN/install-2.log" 2>&1; then pass "a second run finishes (exit 0)"; else fail "second run: $(tail -n 20 "$GEN/install-2.log")"; fi
grep -q "Installing the code .* PASS  (already there)" "$GEN/install-2.log" && grep -q "Writing the configuration .* \(PASS  (kept)\|WARN  kept\)" "$GEN/install-2.log" \
  && grep -q "is reachable" "$GEN/install-2.log" && [[ "$(in_vps sed -n 's/^APP_SECRET=//p' /var/www/lists/.env)" == "$secret" && "$(body /health)" == OK ]] \
  && pass "the code, the configuration and the database are found and kept" || fail "idempotence: $(grep -E '^\[' "$GEN/install-2.log")"

echo "== U the switch to this working tree, as $NEXT"
sql "INSERT INTO subscribers (s_email, s_fname, s_lname) VALUES ('kept@example.org', 'Kept', 'Subscriber')" >/dev/null
snapshot "$NEXT"
if installer --installation lists > "$GEN/upgrade-1.log" 2>&1; then pass "the installer finished (exit 0), taking the newest release"; else fail "upgrade exit: $(tail -n 20 "$GEN/upgrade-1.log")"; fi
[[ "$(steps "$GEN/upgrade-1.log")" == 14 ]] && pass "14 numbered steps, each PASS or WARN" || fail "steps: $(grep -E '^\[[0-9]{2}/14\]' "$GEN/upgrade-1.log" | grep -v PASS)"
dump="$(sed -n 's/.*(dump before the switch: \(.*\))$/\1/p' "$GEN/upgrade-1.log")"
[[ "$(release_of lists)" == "$NEXT" && -n "$dump" && "$(in_vps stat -c '%a' "$dump")" == 600 && "$(sql "SELECT count(*) FROM subscribers WHERE s_email = 'kept@example.org'")" == 1 \
   && "$(in_vps sed -n 's/^APP_SECRET=//p' /var/www/lists/.env)" == "$secret" ]] \
  && pass "the installation runs $NEXT; the database was dumped first, and the data and .env are kept" || fail "switch: release $(release_of lists), dump '$dump'"
[[ "$(body /health)" == OK && "$(get /login)" == 200 && "$(get /acceptable-use-policy)" == 200 ]] \
  && in_vps grep -q "/usr/local/lib/php/ctnlist/$NEXT/bin/console ctnlist:cattomail:work" "/etc/systemd/system/ctnlist-worker@lists.service.d/release.conf" \
  && in_vps systemctl start "ctnlist-worker@lists.service" && [[ "$(in_vps systemctl is-active "ctnlist-worker@lists.timer")" == active ]] \
  && pass "the site, the timers and the worker run the new release" || fail "after the switch: health '$(body /health)'"
# The settings this working tree's .env.example adds to the last release's, which the switch must name (or none).
keys() { sed -n 's/^\([A-Z][A-Z0-9_]*\)=.*/\1/p' | sort -u; }
added="$(comm -13 <(git -C "$REPO" show "v$LAST:.env.example" | keys) <(keys < "$REPO/.env.example") | paste -sd' ' -)"
if [[ -n "$added" ]]; then named() { grep -q "does not name.*${added##* }" "$GEN/upgrade-1.log"; }; else named() { grep -q "Writing the configuration .* PASS  (kept)" "$GEN/upgrade-1.log"; }; fi
in_vps test -d "/usr/local/lib/php/ctnlist/$LAST" && named \
  && pass "the old release is kept, and the settings the new one adds are named (${added:-none})" || fail "old release, new settings (${added:-none}): $(grep -E '^\[07' "$GEN/upgrade-1.log")"
if installer --installation lists --version "v$LAST" > "$GEN/back-1.log" 2>&1 && [[ "$(release_of lists)" == "$LAST" && "$(body /health)" == OK \
     && "$(sql "SELECT count(*) FROM subscribers WHERE s_email = 'kept@example.org'")" == 1 ]] \
   && installer --installation lists > "$GEN/back-2.log" 2>&1 && [[ "$(release_of lists)" == "$NEXT" && "$(body /health)" == OK ]]; then
  pass "naming the earlier release switches back to it with the data kept, and forward again"
else fail "switching back: release $(release_of lists): $(tail -n 12 "$GEN/back-1.log")"; fi

echo "== S a release with another schema, as $AFTER"
snapshot "$AFTER"
echo "// installer test: another schema" >> "$RELEASE/database/migrations/domain/20260716120000_create_phase3a_schema.php"
git -C "$RELEASE" -c user.name=installer-test -c user.email=installer-test@example.invalid commit -qam "installer test $AFTER, schema" && git -C "$RELEASE" tag -f "v$AFTER" >/dev/null
if installer --installation lists > "$GEN/schema-1.log" 2>&1; then fail "the switch went ahead over a changed schema"; else
  grep -q -- "--recreate-database" "$GEN/schema-1.log" && [[ "$(release_of lists)" == "$NEXT" && "$(body /health)" == OK \
     && "$(sql "SELECT count(*) FROM subscribers WHERE s_email = 'kept@example.org'")" == 1 ]] \
    && pass "refused, naming --recreate-database; the installation still runs $NEXT with its data" || fail "schema refusal: $(tail -n 12 "$GEN/schema-1.log")"
fi
if installer --installation lists --recreate-database > "$GEN/schema-2.log" 2>&1; then
  [[ "$(release_of lists)" == "$AFTER" && "$(sql "SELECT count(*) FROM subscribers")" == 0 && "$(sql "SELECT count(*) FROM lists WHERE l_shortcode = 'ALL'")" == 1 && "$(body /health)" == OK ]] \
    && in_vps test -s "$(sed -n 's/.*(dump before the switch: \(.*\))$/\1/p' "$GEN/schema-2.log")" \
    && pass "--recreate-database: dumped, emptied, the schema created again, the site on $AFTER" || fail "recreate: release $(release_of lists)"
else fail "recreate exit: $(tail -n 20 "$GEN/schema-2.log")"; fi

echo "== C a second installation for a host name nginx already serves"
if installer --installation other --host "$HOST" --admin-email "admin@ctnlist-installer.test" --mailer-dsn "smtp://127.0.0.1:1025" > "$GEN/other-1.log" 2>&1; then
  in_vps test -f /etc/nginx/conf.d/ctnlist-other.conf.new && ! in_vps test -e /etc/nginx/conf.d/ctnlist-other.conf && in_vps nginx -t >/dev/null 2>&1 \
    && grep -q "nginx already serves $HOST" "$GEN/other-1.log" && [[ "$(body /health)" == OK && "$(release_of lists)" == "$AFTER" ]] \
    && pass "its site is left aside (.conf.new) and said so; the first site is untouched" || fail "second site: $(grep -E '^\[12' "$GEN/other-1.log")"
  [[ "$(in_vps sed -n 's/^GDB_ENV_DIRECTORY=//p' /var/www/other/.env)" == /etc/ctnlist && "$(sql 'SELECT 1' ctnlist_other)" == 1 \
     && "$(in_vps systemctl is-active "ctnlist-worker@other.timer")" == active ]] \
    && pass "it shares the suppression database and has its own database and timers" || fail "second installation"
else fail "second installation exit: $(tail -n 20 "$GEN/other-1.log")"; fi

echo "installer-test: $PASS passed, $FAIL failed"
[[ $FAIL == 0 ]]
