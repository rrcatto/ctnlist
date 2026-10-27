#!/usr/bin/env bash
# Podman kit test behind `bin/dev test-podman-kit [--keep]`.
#
# Runs deploy/podman as a server would, in a stand-in (dev/podman-kit-test/Containerfile: Ubuntu 24.04 with systemd,
# Podman, PostgreSQL and nginx): root-prepare.sh as root, then ctnlist-podman.sh as a site user whose pod runs in the
# stand-in under that user's own systemd, behind the stand-in's nginx configured from host-nginx.conf.example. The
# releases are throwaway Git tags in var/podman-kit-test/release-repo, so the script clones a tag exactly as it would
# from GitHub: this working tree as the next version, then as the one after, then with a changed migration.
#
#   P  root-prepare.sh: the user's ids, linger and the site's database; for a second user also a new suppression
#      database; a database holding another application's tables is refused with nothing changed
#   I  a new installation, with Mailpit and without a suppression database (catto-mail's suppression): every step,
#      the layout and permissions, the site through nginx and the pod, HTTPS and the visitor's address as PHP sees
#      them, a sign-in with the emailed link, the address the mail service's Connect page is given, the timers and
#      their runs, a restart
#   R  a second run: everything is found and kept
#   U  the switch to the next release: the database dumped, the data and .env kept, the pod and the timers on the
#      new release; then back to the earlier release by naming it, and forward again
#   S  a release with another schema: refused with nothing changed, then --recreate-database
#   B  the second user's installation, with the suppression database: its ban.env read-only in the pod, its schema
#
# Rootless Podman inside the stand-in needs it --privileged (which stays within this user's own user namespace).
# The stand-in is removed at the end unless --keep is given. The development stack is not touched.
set -euo pipefail
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GEN="$REPO/var/podman-kit-test"
RELEASE="$GEN/release-repo"
VPS=ctnlist_podman_kit_test
IMAGE=localhost/ctnlist-podman-kit-server:24.04
HOST=lists.ctnlist-podman.test
ADMIN=admin@ctnlist-podman.test
SITE_USER=site
PORT=8082
# The second site, which shares a suppression database (the banlist).
SITE2=site2
PORT2=8083
KIT=/home/$SITE_USER/ctnlist
# The images the kit uses, given to the stand-in from this machine's store so that each run need not fetch them.
IMAGES=(docker.io/library/php:8.5.12-fpm-bookworm docker.io/library/composer:2 docker.io/nginxinc/nginx-unprivileged:1.30.6-alpine docker.io/axllent/mailpit:v1.31.0)
KEEP=false; [[ "${1:-}" == --keep ]] && KEEP=true
PASS=0 FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS  $*"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL  $*"; }
in_vps() { podman exec -i "$VPS" "$@"; }
as_user() {  # as_user USER COMMAND...: in that user's systemd session
  local user="$1" uid; uid="$(in_vps id -u "$1" </dev/null)"; shift  # standard input is the command's (an image stream)
  in_vps runuser -u "$user" -- env HOME="/home/$user" XDG_RUNTIME_DIR="/run/user/$uid" \
    DBUS_SESSION_BUS_ADDRESS="unix:path=/run/user/$uid/bus" PATH=/usr/local/bin:/usr/bin:/bin "$@"
}
as_site() { as_user "$SITE_USER" "$@"; }
kit() { as_site bash /opt/kit/ctnlist-podman.sh install --repo file:///src/ctnlist.git --yes "$@"; }
kit2() { as_user "$SITE2" bash /opt/kit/ctnlist-podman.sh install --repo file:///src/ctnlist.git --yes "$@"; }
sql() { in_vps runuser -u postgres -- psql -X -At -d "${2:-ctnlist_site}" -c "$1"; }
# Through the stand-in's nginx, as a visitor reaches the site.
get() { in_vps curl -ksS --max-time 30 -o /dev/null -w '%{http_code}' --resolve "$HOST:443:127.0.0.1" "https://$HOST$1"; }
body() { in_vps curl -ksS --max-time 30 --resolve "$HOST:443:127.0.0.1" "https://$HOST$1"; }
release_of() { in_vps sed -n "s#^\$sharedDirectory = '/usr/local/lib/php/ctnlist/\([^/]*\)/';#\1#p" "$KIT/site/public_html/index.php"; }
steps() { grep -cE '^\[[0-9]{2}/13\] .* (PASS|WARN)' "$1" || true; }
# hidden FIELD < HTML: the value of a form's input named FIELD, whatever the order of its attributes.
hidden() { { grep -o "<input[^>]*name=\"${1//[/\\[}\"[^>]*>" || true; } | head -n1 | sed -n 's/.*value="\([^"]*\)".*/\1/p'; }

# snapshot <version>: the working tree (tracked and new files) as release v<version>.
snapshot() {
  [[ -d "$RELEASE/.git" ]] || git init -q "$RELEASE"
  find "$RELEASE" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
  (cd "$REPO" && git ls-files -co --exclude-standard -z | while IFS= read -r -d '' f; do [[ -f "$f" ]] && printf '%s\0' "$f"; done \
    | tar --null -T - -cf -) | tar -C "$RELEASE" -xf -
  local was; was="$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$RELEASE/src/Config/SiteConfig.php")"
  sed -i "s/${was//./\\.}/$1/g" "$RELEASE/public_html/index.php" "$RELEASE/src/Config/SiteConfig.php"
  git -C "$RELEASE" add -A
  git -C "$RELEASE" -c user.name=podman-kit-test -c user.email=podman-kit-test@example.invalid commit -q --allow-empty -m "podman kit test $1"
  git -C "$RELEASE" tag -f "v$1" >/dev/null
}
cleanup() {
  [[ $KEEP == true ]] && { echo "podman-kit-test: kept $VPS (podman rm -f -v $VPS when done)"; return; }
  podman rm -f -v --time 20 "$VPS" >/dev/null 2>&1 || true
  rm -rf "$GEN"
}

LAST="$(git -C "$REPO" describe --tags --abbrev=0)"; LAST="${LAST#v}"
NEXT="${LAST%.*}.$(( ${LAST##*.} + 1 ))"; AFTER="${LAST%.*}.$(( ${LAST##*.} + 2 ))"; THIRD="${LAST%.*}.$(( ${LAST##*.} + 3 ))"
podman rm -f -v --time 20 "$VPS" >/dev/null 2>&1 || true
rm -rf "$GEN"; mkdir -p "$GEN"
trap cleanup EXIT
podman build -q -t "$IMAGE" "$REPO/dev/podman-kit-test" >/dev/null
for image in "${IMAGES[@]}"; do podman image exists "$image" || podman pull -q "$image" >/dev/null; done
snapshot "$NEXT"
podman run -d --name "$VPS" --hostname ctnlist-server --systemd=always --privileged -v /home \
  -v "$RELEASE:/src/ctnlist.git:ro" -v "$REPO/deploy/podman:/opt/kit:ro" "$IMAGE" >/dev/null
in_vps systemctl is-system-running --wait >/dev/null 2>&1 || true
for _ in $(seq 1 30); do in_vps runuser -u postgres -- psql -Atc 'SELECT 1' >/dev/null 2>&1 && break; sleep 2; done

# The server's own nginx, as root sets it up (host-nginx.conf.example) in a control panel's style: one server block
# for both ports, its own certificate and access log.
in_vps install -d -m 755 /etc/ssl/ctnlist-test
in_vps openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=$HOST" -addext "subjectAltName=DNS:$HOST" \
  -keyout /etc/ssl/ctnlist-test/key.pem -out /etc/ssl/ctnlist-test/cert.pem >/dev/null 2>&1
grep '^log_format ' "$REPO/deploy/podman/host-nginx.conf.example" | in_vps tee /etc/nginx/conf.d/ctnlist-log.conf >/dev/null
{ printf 'server {\n    listen 80;\n    listen 443 ssl;\n    server_name %s;\n    root /home/%s/public_html;\n' "$HOST" "$SITE_USER"
  printf '    ssl_certificate /etc/ssl/ctnlist-test/cert.pem;\n    ssl_certificate_key /etc/ssl/ctnlist-test/key.pem;\n'
  printf '    access_log /var/log/nginx/site.access.log ctnlist_combined;\n    location ^~ /.well-known/ {\n        try_files $uri =404;\n    }\n'
  sed -n '/^location \^~ \/ {/,/^}/p' "$REPO/deploy/podman/host-nginx.conf.example" | sed -e "s/<port>/$PORT/" -e 's/^/    /'
  printf '}\n'; } | in_vps tee /etc/nginx/sites-enabled/site.conf >/dev/null
in_vps unlink /etc/nginx/sites-enabled/default
in_vps nginx -t >/dev/null 2>&1 && in_vps systemctl reload nginx && pass "the stand-in's nginx takes host-nginx.conf.example" || fail "nginx: $(in_vps nginx -t 2>&1 | tail -n 3)"

echo "== P root-prepare.sh"
# Control panel accounts: no subordinate ids yet, as useradd gives them here.
for user in "$SITE_USER" "$SITE2"; do
  in_vps useradd -m -s /bin/bash "$user"
  in_vps sed -i "/^$user:/d" /etc/subuid /etc/subgid
done
if in_vps bash /opt/kit/root-prepare.sh "$SITE_USER" --database > "$GEN/prepare.log" 2>&1; then pass "root-prepare.sh finished (exit 0)"; else fail "root-prepare.sh: $(tail -n 8 "$GEN/prepare.log")"; fi
[[ "$(in_vps grep -c "^$SITE_USER:" /etc/subuid)" == 1 && "$(in_vps loginctl show-user "$SITE_USER" -p Linger --value)" == yes \
   && "$(in_vps stat -c '%a %U' "$KIT/config/database.env")" == "600 $SITE_USER" && "$(sql 'SELECT 1' ctnlist_site)" == 1 ]] \
  && ! in_vps test -e "$KIT/config/ban.env" && pass "ids, linger and the site's database, its settings readable by the site's user only; no suppression database" || fail "root-prepare.sh's result: $(cat "$GEN/prepare.log")"
in_vps bash /opt/kit/root-prepare.sh "$SITE_USER" --database > "$GEN/prepare-2.log" 2>&1 \
  && grep -q "already has ids" "$GEN/prepare-2.log" && grep -q "database.env exists already: kept" "$GEN/prepare-2.log" \
  && pass "a second run keeps what is there" || fail "second root-prepare.sh: $(cat "$GEN/prepare-2.log")"
if in_vps bash /opt/kit/root-prepare.sh "$SITE2" --database --create-banlist > "$GEN/prepare-3.log" 2>&1 \
   && [[ "$(in_vps stat -c '%a %U' "/home/$SITE2/ctnlist/config/database.env" "/home/$SITE2/ctnlist/config/ban.env" /etc/ctnlist/ban.env | tr '\n' ' ')" == "600 $SITE2 600 $SITE2 600 root " \
   && "$(sql 'SELECT 1' ctnlist_site2)" == 1 && "$(sql 'SELECT 1' banlist)" == 1 ]]; then
  pass "--create-banlist: a new suppression database, its settings in /etc/ctnlist for root and a copy for the second site's user"
else fail "root-prepare.sh --create-banlist: $(cat "$GEN/prepare-3.log")"; fi
# The test's release repository is mounted owned by root; Git clones another user's repository only when told to.
for user in "$SITE_USER" "$SITE2"; do as_user "$user" git config --global --add safe.directory /src/ctnlist.git/.git; done
# The kit's images, so that the stand-in need not fetch them (it still builds ctnlist's own).
podman save -q -m "${IMAGES[@]}" | as_site podman load -q >/dev/null

# The refused run below already writes .env (before it checks the database), and the installation keeps it.
OPTIONS=(--port "$PORT" --host "$HOST" --admin-email "$ADMIN" --list-name "Podman Kit Test List" --mailer-dsn "smtp://127.0.0.1:1025" --mailpit --sample-templates
  --mail-service "https://mail.ctnlist-kit.test")
sql "CREATE TABLE another_application (id int)" >/dev/null
if kit --version "v$NEXT" "${OPTIONS[@]}" > "$GEN/refused.log" 2>&1; then
  fail "the kit installed into a database holding another application's table"
else
  grep -q "not this release's schema" "$GEN/refused.log" && [[ "$(sql "SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")" == 1 ]] \
    && ! as_site podman pod exists ctnlist && pass "a database holding another application's table is refused, and left as it was" \
    || fail "refusal: $(grep -E '^(\[|What|Why)' "$GEN/refused.log" | tail -n 4)"
fi
sql "DROP TABLE another_application" >/dev/null

echo "== I a new installation of $NEXT"
if kit --version "v$NEXT" "${OPTIONS[@]}" > "$GEN/install-1.log" 2>&1; then pass "the kit finished (exit 0)"; else fail "kit exit: $(tail -n 25 "$GEN/install-1.log")"; fi
[[ "$(steps "$GEN/install-1.log")" == 13 ]] && pass "13 numbered steps, each PASS or WARN" || fail "steps: $(grep -E '^\[[0-9]{2}/13\]' "$GEN/install-1.log" | grep -v PASS)"
if [[ $FAIL -gt 0 ]]; then echo "podman-kit-test: the installation failed, nothing further to test: $PASS passed, $FAIL failed"; exit 1; fi
secret="$(in_vps sed -n 's/^APP_SECRET=//p' "$KIT/site/.env")"; dbpass="$(in_vps sed -n 's/^DB_PASS=//p' "$KIT/site/.env")"
[[ "$(in_vps stat -c '%a %U' "$KIT/site/.env")" == "600 $SITE_USER" && ${#secret} == 64 && ${#dbpass} == 48 ]] \
  && ! in_vps grep -qF -e "$secret" -e "$dbpass" "$KIT/install.log" && ! grep -qF -e "$secret" -e "$dbpass" "$GEN/install-1.log" "$GEN/refused.log" \
  && pass ".env is the site user's alone, its secrets are generated, and neither the log nor the output holds them" || fail "secrets"
[[ "$(in_vps sed -n 's/^CATTOMAIL_API_BASE_URL=//p' "$KIT/site/.env")" == "https://mail.ctnlist-kit.test" ]] \
  && pass "the mail service's address (--mail-service) is in .env" || fail "CATTOMAIL_API_BASE_URL: $(in_vps sed -n 's/^CATTOMAIL_API_BASE_URL=//p' "$KIT/site/.env")"
php_writes() { as_site podman exec ctnlist-php sh -c "test -w '$1'"; }
! php_writes "/usr/local/lib/php/ctnlist/$NEXT/src" && ! php_writes /var/www/site/.env && ! php_writes /var/www/site/public_html \
  && php_writes /var/www/site/var && php_writes /var/www/site/logs \
  && pass "in the pod, PHP writes var/ and logs/ only: the code, .env and public_html are read-only" || fail "permissions in the pod"
[[ "$(in_vps sed -n 's/^SUPPRESSION_PROVIDER=//p' "$KIT/site/.env")" == cattomail ]] && ! in_vps grep -q 'ban\.env$' "$KIT/pod/ctnlist.yaml" \
  && ! as_site podman exec ctnlist-php test -e /etc/ctnlist/ban.env \
  && pass "without a ban.env from root: SUPPRESSION_PROVIDER=cattomail, and the pod mounts none" || fail "suppression: $(in_vps grep -n '^SUPPRESSION\|ban\.env$' "$KIT/site/.env" "$KIT/pod/ctnlist.yaml")"
[[ "$(as_site podman ps --format '{{.Names}}' | grep -cE '^ctnlist-(php|web|mailpit)$')" == 3 \
   && "$(in_vps ss -Hltn "sport = :$PORT" | awk '{print $4}')" == "127.0.0.1:$PORT" ]] \
  && pass "the pod runs php, web and mailpit, published on 127.0.0.1:$PORT only" || fail "pod: $(as_site podman ps --format '{{.Names}} {{.Status}}')"
[[ "$(body /health)" == OK && "$(get /login)" == 200 && "$(body /login)" == *"Podman Kit Test List"* ]] \
  && pass "the site answers through nginx and the pod: /health and the sign-in page" || fail "site: health '$(body /health)', login $(get /login)"
css="$(body /login | sed -n 's/.*href="\(\/assets\/[^"]*\.css\)".*/\1/p' | head -n1)"
[[ -n "$css" && "$(get "$css")" == 200 ]] && pass "compiled assets are served" || fail "assets: '$css'"
redirect="$(in_vps curl -sS -o /dev/null -w '%{http_code} %{redirect_url}' --resolve "$HOST:80:127.0.0.1" "http://$HOST/login?x=1")"
[[ "$redirect" == "301 https://$HOST/login?x=1" ]] && pass "plain HTTP moves to HTTPS" || fail "http: $redirect"
in_vps curl -ksSI --resolve "$HOST:443:127.0.0.1" "https://$HOST/login" | grep -qi '^strict-transport-security:' \
  && pass "PHP sees HTTPS (the application sends HSTS)" || fail "no HSTS from the application"
in_vps curl -ksS -o /dev/null -A kit-test-through-nginx -H 'X-Forwarded-For: 6.6.6.6' --resolve "$HOST:443:127.0.0.1" "https://$HOST/login" || true
in_vps curl -sS -o /dev/null -A kit-test-direct -H "Host: $HOST" -H 'X-Forwarded-Proto: https' -H 'X-Forwarded-For: 6.6.6.6, 203.0.113.9' "http://127.0.0.1:$PORT/login" || true
[[ "$(sql "SELECT stl_ip || ' ' || coalesce(stl_xfwdfor, '') FROM sitelog WHERE stl_agent = 'kit-test-through-nginx' ORDER BY stl_id DESC LIMIT 1")" == "127.0.0.1 " \
   && "$(sql "SELECT stl_ip FROM sitelog WHERE stl_agent = 'kit-test-direct' ORDER BY stl_id DESC LIMIT 1")" == 203.0.113.9 ]] \
  && pass "PHP sees the visitor's address (the one nginx forwarded), never one the visitor wrote in X-Forwarded-For" \
  || fail "addresses: $(sql "SELECT stl_agent || ' ' || stl_ip || ' ' || coalesce(stl_xfwdfor, '-') FROM sitelog WHERE stl_agent LIKE 'kit-test-%'")"

# A sign-in as a browser does it: ask for a link, read it in Mailpit (the pod's transactional mail goes there in this
# test), open it and press Sign in. The first administrator is made by that sign-in.
mailpit_password="$(in_vps cat "$KIT/config/mailpit-password")"
[[ "$(get /mailpit/)" == 401 && "$(in_vps curl -ksS -o /dev/null -w '%{http_code}' -u "proofs:$mailpit_password" --resolve "$HOST:443:127.0.0.1" "https://$HOST/mailpit/")" == 200 ]] \
  && pass "Mailpit's page is at /mailpit/, behind its password" || fail "mailpit page: $(get /mailpit/)"
jar=/tmp/kit-test-cookies
page="$(in_vps curl -ksS -c "$jar" -b "$jar" --resolve "$HOST:443:127.0.0.1" "https://$HOST/login" || true)"
in_vps curl -ksS -o /dev/null -c "$jar" -b "$jar" --resolve "$HOST:443:127.0.0.1" --data-urlencode "signin[email]=$ADMIN" \
  --data-urlencode "signin[csrf]=$(hidden 'signin[csrf]' <<<"$page")" "https://$HOST/login" || true
link=""
for _ in $(seq 1 15); do
  id="$(in_vps curl -ksS -u "proofs:$mailpit_password" --resolve "$HOST:443:127.0.0.1" "https://$HOST/mailpit/api/v1/search?query=to:$ADMIN" | in_vps jq -r '.messages[0].ID // empty' || true)"
  [[ -n "$id" ]] && link="$(in_vps curl -ksS -u "proofs:$mailpit_password" --resolve "$HOST:443:127.0.0.1" "https://$HOST/mailpit/api/v1/message/$id" \
    | in_vps jq -r .Text | grep -o "https://$HOST/auth/verify?token=[A-Za-z0-9_-]*" | head -n1 || true)"
  [[ -n "$link" ]] && break; sleep 2
done
signin=""
if [[ -n "$link" ]]; then
  page="$(in_vps curl -ksS -c "$jar" -b "$jar" --resolve "$HOST:443:127.0.0.1" "$link" || true)"
  signin="$(in_vps curl -ksS -o /dev/null -w '%{http_code}' -c "$jar" -b "$jar" --resolve "$HOST:443:127.0.0.1" \
    --data-urlencode "csrf=$(hidden csrf <<<"$page")" --data-urlencode "token=$(hidden token <<<"$page")" "https://$HOST/auth/verify" || true)"
fi
[[ -n "$link" && "$signin" == 302 && "$(in_vps curl -ksS -o /dev/null -w '%{http_code}' -b "$jar" --resolve "$HOST:443:127.0.0.1" "https://$HOST/delivery")" == 200 ]] \
  && pass "the sign-in link is mailed through the pod's SMTP setting, and signs the first administrator in" || fail "sign-in: link '${link:0:40}', sign in $signin"
# The form's CSRF token belongs to the PHP session this page starts: keep its cookie.
page="$(in_vps curl -ksS -c "$jar" -b "$jar" --resolve "$HOST:443:127.0.0.1" "https://$HOST/delivery" || true)"
# The address is the one --mail-service gave (.env), so Connect asks for none.
answer="$(in_vps curl -ksS -c "$jar" -b "$jar" --resolve "$HOST:443:127.0.0.1" \
  --data-urlencode "mail_service[csrf]=$(hidden 'mail_service[csrf]' <<<"$page")" "https://$HOST/delivery/connect" || true)"
# param NAME: a parameter of the address the Connect page sends the browser to, decoded.
param() { { grep -o "$1=[^&\"]*" <<<"$answer" || true; } | head -n1 | sed "s/^$1=//; s/%3A/:/gi; s/%2F/\\//gi"; }
[[ "$(param redirect_uri)" == "https://$HOST/delivery/connect/callback" && "$(param webhook_url)" == "https://$HOST/cattomail/webhook" ]] \
  && grep -q 'content="0;url=https://mail.ctnlist-kit.test/dashboard/connect?' <<<"$answer" \
  && pass "Connect goes to the configured mail service with the site's own callback and webhook addresses (https, not the pod's port)" \
  || fail "Connect: callback '$(param redirect_uri)', webhook '$(param webhook_url)'"

[[ "$(as_site systemctl --user is-active ctnlist-worker.timer ctnlist-maintenance.timer | tr '\n' ' ')" == "active active " ]] \
  && as_site systemctl --user start ctnlist-worker.service ctnlist-maintenance.service \
  && pass "both timers are active, and the worker and maintenance run in the pod" || fail "timers: $(as_site journalctl --user -u ctnlist-worker.service -n 5 --no-pager 2>&1)"
report="$(as_site podman exec ctnlist-php php "/usr/local/lib/php/ctnlist/$NEXT/bin/console" ctnlist:diagnose --production 2>&1 || true)"
bad="$(grep -E '^(ERROR|WARN) ' <<<"$report" | grep -iE 'extension|writable|readable|front controller|CKEditor|assets|different configuration|differs|suppression|schema|worker|maintenance' || true)"
[[ -z "$bad" && "$report" == *"proofs go to Mailpit at 127.0.0.1:1025"* && "$report" == *"OK    suppression provider: cattomail"* ]] \
  && pass "ctnlist:diagnose finds the extensions, permissions, assets, schema, catto-mail's suppression, worker and maintenance in order" || fail "diagnose: $bad"
echo "        (diagnose's other findings: $(grep -cE '^(ERROR|WARN) ' <<<"$report") — $(grep -E '^(ERROR|WARN) ' <<<"$report" | cut -c1-70 | paste -sd'|' -))"
as_site systemctl --user restart ctnlist.service || true
for _ in $(seq 1 30); do [[ "$(body /health)" == OK ]] && break; sleep 2; done
[[ "$(body /health)" == OK ]] && pass "the pod comes back after a restart of its service" || fail "after restart: '$(body /health)'"

echo "== R second run"
if kit "${OPTIONS[@]}" --version "v$NEXT" > "$GEN/install-2.log" 2>&1; then pass "a second run finishes (exit 0)"; else fail "second run: $(tail -n 20 "$GEN/install-2.log")"; fi
grep -q "Fetching the release .* PASS  (already there)" "$GEN/install-2.log" && grep -q "Building the image .* PASS  (already there" "$GEN/install-2.log" \
  && grep -q "Writing the configuration .* \(PASS  (kept)\|WARN  kept\)" "$GEN/install-2.log" \
  && [[ "$(in_vps sed -n 's/^APP_SECRET=//p' "$KIT/site/.env")" == "$secret" && "$(body /health)" == OK ]] \
  && pass "the code, the image, the configuration and the database are found and kept" || fail "idempotence: $(grep -E '^\[' "$GEN/install-2.log")"

echo "== U the switch to $AFTER"
sql "INSERT INTO subscribers (s_email, s_fname, s_lname) VALUES ('kept@example.org', 'Kept', 'Subscriber')" >/dev/null
snapshot "$AFTER"
if kit > "$GEN/upgrade-1.log" 2>&1; then pass "the kit finished (exit 0), taking the newest release"; else fail "upgrade exit: $(tail -n 20 "$GEN/upgrade-1.log")"; fi
[[ "$(steps "$GEN/upgrade-1.log")" == 13 ]] && pass "13 numbered steps, each PASS or WARN" || fail "steps: $(grep -E '^\[[0-9]{2}/13\]' "$GEN/upgrade-1.log" | grep -v PASS)"
dump="$(sed -n 's/^Dump: *//p' "$GEN/upgrade-1.log")"
[[ "$(release_of)" == "$AFTER" && -n "$dump" && "$(in_vps stat -c '%a' "$dump")" == 600 && "$(sql "SELECT count(*) FROM subscribers WHERE s_email = 'kept@example.org'")" == 1 \
   && "$(in_vps sed -n 's/^APP_SECRET=//p' "$KIT/site/.env")" == "$secret" ]] \
  && pass "the installation runs $AFTER; the database was dumped first, and the data and .env are kept" || fail "switch: release $(release_of), dump '$dump'"
[[ "$(body /health)" == OK && "$(get /acceptable-use-policy)" == 200 ]] \
  && in_vps grep -q "localhost/ctnlist:$AFTER" "$KIT/pod/ctnlist.yaml" \
  && in_vps grep -q "/usr/local/lib/php/ctnlist/$AFTER/bin/console ctnlist:cattomail:work" "/home/$SITE_USER/.config/systemd/user/ctnlist-worker.service" \
  && as_site systemctl --user start ctnlist-worker.service && [[ "$(as_site systemctl --user is-active ctnlist-worker.timer)" == active ]] \
  && pass "the site, the pod's image and the timers run the new release" || fail "after the switch: health '$(body /health)'"
in_vps test -d "$KIT/releases/$NEXT" && grep -q "Release $NEXT stays in" "$GEN/upgrade-1.log" \
  && pass "the old release is kept, and the way back is named" || fail "old release: $(tail -n 5 "$GEN/upgrade-1.log")"
if kit --version "v$NEXT" > "$GEN/back-1.log" 2>&1 && [[ "$(release_of)" == "$NEXT" && "$(body /health)" == OK \
     && "$(sql "SELECT count(*) FROM subscribers WHERE s_email = 'kept@example.org'")" == 1 ]] \
   && kit > "$GEN/back-2.log" 2>&1 && [[ "$(release_of)" == "$AFTER" && "$(body /health)" == OK ]]; then
  pass "naming the earlier release switches back to it with the data kept, and forward again"
else fail "switching back: release $(release_of): $(tail -n 12 "$GEN/back-1.log")"; fi

echo "== S a release with another schema, as $THIRD"
snapshot "$THIRD"
echo "// podman kit test: another schema" >> "$RELEASE/database/migrations/domain/20260716120000_create_phase3a_schema.php"
git -C "$RELEASE" -c user.name=podman-kit-test -c user.email=podman-kit-test@example.invalid commit -qam "podman kit test $THIRD, schema" && git -C "$RELEASE" tag -f "v$THIRD" >/dev/null
if kit > "$GEN/schema-1.log" 2>&1; then fail "the switch went ahead over a changed schema"; else
  grep -q -- "--recreate-database" "$GEN/schema-1.log" && [[ "$(release_of)" == "$AFTER" && "$(body /health)" == OK \
     && "$(sql "SELECT count(*) FROM subscribers WHERE s_email = 'kept@example.org'")" == 1 ]] \
    && pass "refused, naming --recreate-database; the site still runs $AFTER with its data" || fail "schema refusal: $(tail -n 12 "$GEN/schema-1.log")"
fi
if kit --recreate-database > "$GEN/schema-2.log" 2>&1; then
  [[ "$(release_of)" == "$THIRD" && "$(sql "SELECT count(*) FROM subscribers")" == 0 && "$(sql "SELECT count(*) FROM lists WHERE l_shortcode = 'ALL'")" == 1 && "$(body /health)" == OK ]] \
    && in_vps test -s "$(sed -n 's/^Dump: *//p' "$GEN/schema-2.log")" \
    && pass "--recreate-database: dumped, emptied, the schema created again, the site on $THIRD" || fail "recreate: release $(release_of)"
else fail "recreate exit: $(tail -n 20 "$GEN/schema-2.log")"; fi

echo "== B the second site, with the suppression database"
# ctnlist's image as the first site built it, so that it is not built twice.
as_site podman save -q -m "localhost/ctnlist:$NEXT" "${IMAGES[@]}" | as_user "$SITE2" podman load -q >/dev/null
health2() { in_vps curl -sS --max-time 10 -H "Host: lists2.ctnlist-podman.test" -H 'X-Forwarded-Proto: https' "http://127.0.0.1:$PORT2$1" || true; }
if kit2 --version "v$NEXT" --port "$PORT2" --host lists2.ctnlist-podman.test --admin-email "$ADMIN" --list-name "Second Site" > "$GEN/banlist-1.log" 2>&1; then
  pass "the kit finished (exit 0)"; else fail "second site: $(tail -n 25 "$GEN/banlist-1.log")"; fi
ban_readable="$(as_user "$SITE2" podman exec ctnlist-php sh -c 'test -r /etc/ctnlist/ban.env && ! test -w /etc/ctnlist/ban.env && echo yes' || true)"
[[ "$(in_vps sed -n 's/^SUPPRESSION_PROVIDER=//p' "/home/$SITE2/ctnlist/site/.env")" == banlist && "$ban_readable" == yes \
   && "$(sql "SELECT to_regclass('globalunsubscribe') IS NOT NULL" banlist)" == t \
   && "$(in_vps sed -n 's/^GDB_HOST=//p' "/home/$SITE2/ctnlist/config/ban.env")" == 10.0.2.2 && "$(health2 /health)" == OK ]] \
  && pass "with a ban.env from root: SUPPRESSION_PROVIDER=banlist, ban.env read-only in the pod (at 10.0.2.2), the suppression database's schema created" \
  || fail "banlist: provider $(in_vps sed -n 's/^SUPPRESSION_PROVIDER=//p' "/home/$SITE2/ctnlist/site/.env"), ban.env '$ban_readable', health '$(health2 /health)'"
report2="$(as_user "$SITE2" podman exec ctnlist-php php "/usr/local/lib/php/ctnlist/$NEXT/bin/console" ctnlist:diagnose --production 2>&1 || true)"
[[ "$report2" == *"OK    suppression database (banlist) reachable"* && -z "$(grep -E '^(ERROR|WARN) ' <<<"$report2" | grep -i suppression || true)" ]] \
  && pass "ctnlist:diagnose reaches the suppression database from the pod" || fail "diagnose: $(grep -iE 'suppression|banlist' <<<"$report2")"

echo "podman-kit-test: $PASS passed, $FAIL failed"
[[ $FAIL == 0 ]]
