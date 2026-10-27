#!/usr/bin/env bash
# ctnlist-podman.sh — ctnlist on rootless Podman (deploy/podman/README.md): a new installation, or the installation
# moved to another release, run as the site's own Linux user, never as root.
#
# One installation per user, in a pod run by systemd as the user's service: the web server and PHP-FPM (and Mailpit
# for proofs, with --mailpit). The worker and maintenance are user timers that run in the pod. The server's own web
# server keeps ports 80 and 443 and the certificate and forwards the site to the pod on 127.0.0.1:<port>;
# PostgreSQL and the mail server stay on the server, which the pod reaches at 10.0.2.2.
#
#   ctnlist-podman.sh install --version vX.Y.Z --port 8082 [options]   a new installation, or the switch to that release
#   ctnlist-podman.sh status | start | stop | restart
#   ctnlist-podman.sh logs [php|web|mailpit|worker|maintenance]
#   ctnlist-podman.sh console <command and options>                   bin/console in the running pod
#
# Before the first install, root runs root-prepare.sh <user> once and makes the server's web server forward the site
# to 127.0.0.1:<port> (README.md). Running install again is safe: what is there is checked and kept. It never
# replaces an .env or a database, never installs into a database that holds tables, and moves to a release with
# another schema only with --recreate-database.
#
# Everything is in ~/ctnlist:
#   releases/<version>   each release's code with its Composer packages, read-only in every container
#   site/                the installation: .env (mode 600), public_html/, var/, logs/
#   config/              database.env and, with a banlist, ban.env (root-prepare.sh), Mailpit's password
#   pod/                 the pod's definition and its web server's configuration, written from the release's templates
#   backups/             a dump of the database before each release switch
#   mailpit/             Mailpit's messages (--mailpit)
set -uo pipefail
unset APP_ENV APP_DEBUG SHELL_VERBOSITY

TOTAL=13
REPO_URL="https://github.com/rrcatto/ctnlist.git"
WEB_IMAGE="docker.io/nginxinc/nginx-unprivileged:1.30.6-alpine"
MAILPIT_IMAGE="docker.io/axllent/mailpit:v1.31.0"
# What `composer install --no-dev` requires (ctnlist:diagnose REQUIRED_EXTENSIONS; tests/Unit/PhpExtensionsTest).
EXTENSIONS="pdo pdo_pgsql openssl gd mbstring fileinfo filter json session dom xml iconv"
CODE_ROOT="/usr/local/lib/php/ctnlist"
# The server's 127.0.0.1 as the pod sees it (slirp4netns with allow_host_loopback): PostgreSQL and the mail server.
HOST_LOOPBACK="10.0.2.2"
UNIT="ctnlist"
KIT="$HOME/ctnlist"
RELEASES="$KIT/releases" SITE="$KIT/site" CONFIG="$KIT/config" POD_DIR="$KIT/pod" BACKUPS="$KIT/backups"
QUADLET_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/containers/systemd"
USER_UNITS="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"
LOG="$KIT/install.log"

COMMAND="${1:-}"; [[ $# -gt 0 ]] && shift
NAME="" VERSION="" PORT="" HOST="" ADMIN_EMAIL="" LIST_NAME="" ORGANISATION="" MAIL_FROM="" MAILER_DSN="" MAIL_SERVICE=""
MAILPIT="" BANLIST="" ASSUME_YES=false RECREATE=false SAMPLES=false BACKUP=true
ARGS="$*"

usage() {
  cat <<'USAGE'
usage: ctnlist-podman.sh install [options]       (as the site's own user)
       ctnlist-podman.sh status | start | stop | restart | logs [container] | console <command>

  --version vX.Y.Z        the release to install (default: the newest release); an earlier one than the
                          installation runs switches it back
  --port N                the port on 127.0.0.1 the server's web server forwards to (needed for a new
                          installation; kept after, and changed when given again)
  --installation NAME     the installation's name inside the containers, /var/www/NAME (default: the user's name)

 a new installation (asked for when not given):
  --host NAME             the site's public host name, e.g. lists.example.org
  --admin-email ADDRESS   the first administrator; signs in with an emailed link
  --list-name TEXT        the list's name (default: the host name)
  --organisation TEXT     who runs the list (default: the list's name)
  --mail-from ADDRESS     sender of the site's mail (default: info@<host>)
  --mailer-dsn DSN        the SMTP server for sign-in links and notifications, e.g.
                          smtps://USER:PASS@mail.example.org:465 (nobody can sign in without one)
  The database is the one in ~/ctnlist/config/database.env (root-prepare.sh --database), or asked for. The
  suppression database is ~/ctnlist/config/ban.env when root gave one (root-prepare.sh --banlist-env or
  --mail-service URL      the address of the mail service (catto-mail) the site sends its campaigns
                          through, e.g. https://mail.example.org (default: none; Set up sending asks)
  --create-banlist); without it, global suppression is catto-mail's (SUPPRESSION_PROVIDER=cattomail).

 an existing installation:
  --recreate-database     when the release changed the schema: dump the database, then empty it and create
                          the schema again. ALL ITS DATA IS LOST. ctnlist has no upgrade migrations before go-live.
  --no-backup             do not dump the database before switching releases

 either:
  --mailpit, --no-mailpit proofs go to Mailpit, a third container, read at https://<host>/mailpit/ (kept after)
  --sample-templates      add the three sample email templates that are not there yet
  --yes                   do not ask before an existing installation is switched
  --repo URL              where to fetch the release (default: the ctnlist GitHub repository)
  -h, --help
USAGE
}

while [[ "$COMMAND" == install ]] && (($#)); do
  case "$1" in
    --version) VERSION="${2:-}"; shift 2 ;;
    --port) PORT="${2:-}"; shift 2 ;;
    --installation) NAME="${2:-}"; shift 2 ;;
    --host) HOST="${2:-}"; shift 2 ;;
    --admin-email) ADMIN_EMAIL="${2:-}"; shift 2 ;;
    --list-name) LIST_NAME="${2:-}"; shift 2 ;;
    --organisation) ORGANISATION="${2:-}"; shift 2 ;;
    --mail-from) MAIL_FROM="${2:-}"; shift 2 ;;
    --mailer-dsn) MAILER_DSN="${2:-}"; shift 2 ;;
    --mail-service) MAIL_SERVICE="${2:-}"; shift 2 ;;
    --mailpit) MAILPIT=yes; shift ;;
    --no-mailpit) MAILPIT=no; shift ;;
    --recreate-database) RECREATE=true; shift ;;
    --no-backup) BACKUP=false; shift ;;
    --sample-templates) SAMPLES=true; shift ;;
    --yes|--non-interactive) ASSUME_YES=true; shift ;;
    --repo) REPO_URL="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown option $1 (--help)" >&2; exit 64 ;;
  esac
done

# ------------------------------------------------------------------------- output
STEP=0
NOTES=()
step() {  # step "Description": announce; the caller ends it with pass/warn
  STEP=$((STEP + 1)); CURRENT="$1"
  printf '[%02d/%02d] %s ' "$STEP" "$TOTAL" "$1"
  local pad=$((58 - ${#1})); ((pad < 2)) && pad=2
  printf '%.*s' "$pad" '..........................................................'
  echo "== [$STEP/$TOTAL] $1" >>"$LOG"
}
pass() { echo " PASS${1:+  ($1)}"; }
warn() { echo " WARN  $1"; NOTES+=("$1"); }
note() { echo " WARN  $1"; }   # a warning the summary explains by itself
# fail <why> <how to fix>
fail() {
  echo " FAIL"
  echo
  echo "What failed:  step $STEP, $CURRENT"
  echo "Why:          $1"
  echo "How to fix:   $2"
  echo "Re-running:   safe. Fix the cause, then run this again with the same options; what is done is kept."
  echo "Full log:     $LOG (the last lines:)"
  tail -n 12 "$LOG" | sed 's/^/    /'
  exit 1
}
run() { "$@" >>"$LOG" 2>&1; }
ask() {  # ask VAR "Question" [default]
  local var="$1" q="$2" def="${3:-}" val
  [[ -n "${!var}" || "$ASSUME_YES" == true ]] && { [[ -n "${!var}" ]] || printf -v "$var" '%s' "$def"; return 0; }
  read -r -p "$q${def:+ [$def]}: " val </dev/tty || true
  printf -v "$var" '%s' "${val:-$def}"
}
valid_host() { [[ "$1" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]; }
valid_email() { [[ "$1" =~ ^[A-Za-z0-9._%+-]{1,64}@([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]; }
valid_service() { [[ "$1" =~ ^https://([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(:[0-9]{1,5})?/?$ ]]; }
newer() { [[ "$1" != "$2" && "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n1)" == "$1" ]]; }  # newer A B: A > B

# ------------------------------------------------------------------------- containers
# The release's code at its own path, writable: Composer.
code_run() {
  podman run --rm --userns=keep-id --network slirp4netns -e HOME=/tmp -e COMPOSER_HOME=/composer \
    -v "$TREE:$CODE_ROOT/$NEW" -v "$HOME/.cache/ctnlist-composer:/composer" -w "$CODE_ROOT/$NEW" "$IMAGE" "$@"
}
# The installation mounted as the pod mounts it, with nothing but CTNLIST_INSTANCE_DIR, as the pod's PHP runs: the
# console, migrations, the cache and the assets (public_html is writable here for asset-map:compile).
site_run() {
  local ban=(); [[ "$BANLIST" == yes ]] && ban=(-v "$CONFIG/ban.env:/etc/ctnlist/ban.env:ro")
  podman run --rm --userns=keep-id --network slirp4netns:allow_host_loopback=true -e CTNLIST_INSTANCE_DIR="$INST" \
    -v "$TREE:$CODE_ROOT/$NEW:ro" -v "$SITE/.env:$INST/.env:ro" -v "$SITE/public_html:$INST/public_html" \
    -v "$SITE/var:$INST/var" -v "$SITE/logs:$INST/logs" "${ban[@]}" \
    -w "$CODE_ROOT/$NEW" "$IMAGE" "$@"
}
console() { site_run php bin/console "$@" --no-interaction; }
phinx() { site_run php vendor/bin/phinx "$@"; }
# PHP on the kit's own files (same paths inside), without network: dotenv files, secrets, Mailpit's password hash.
kit_php() { podman run --rm -i --userns=keep-id --network none -v "$KIT:$KIT" -w "$KIT" "$IMAGE" php "$@"; }
# A value of a dotenv file, read with the application's own parser (quotes, escapes).
envget() {  # envget FILE KEY
  kit_php -r 'require $argv[1] . "/vendor/autoload.php"; $v = (new Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents($argv[2]));
    echo $v[$argv[3]] ?? "";' "$TREE" "$1" "$2" 2>>"$LOG"
}
# Set KEY=value lines of a dotenv file in place (a missing key is appended); values are quoted when needed. The
# pairs arrive as KEY\0VALUE\0 on standard input, so no value is ever on a command line.
envset() {  # printf '%s\0' KEY VALUE ... | envset FILE
  kit_php -r '$f = $argv[1]; $t = (string) file_get_contents($f); $in = explode("\0", (string) stream_get_contents(STDIN));
    for ($i = 0; $i + 1 < count($in); $i += 2) {
      [$k, $v] = [$in[$i], $in[$i + 1]];
      $q = preg_match("/^[A-Za-z0-9_.:\/@+=-]*$/", $v) === 1 ? $v : "\"" . addcslashes($v, "\\\"\$") . "\"";
      $n = 0; $t = (string) preg_replace_callback("/^" . preg_quote($k, "/") . "=.*$/m", static fn() => $k . "=" . $q, $t, 1, $n);
      if ($n === 0) { $t = rtrim($t, "\n") . "\n" . $k . "=" . $q . "\n"; }
    }
    file_put_contents($f, $t);' "$1" 2>>"$LOG"
}
secret() { kit_php -r "echo $1;" 2>>"$LOG"; }  # secret 'PHP expression': a generated value, e.g. bin2hex(random_bytes(32))
# dbq ENVFILE PREFIX SQL: "ok <first value>" or "error <why>", from inside the pod's network, with the settings of
# the installation's .env (PREFIX DB_) or of the suppression database's ban.env (PREFIX GDB_).
dbq() {
  site_run php -r '[$file, $p, $sql] = [$argv[1], $argv[2], $argv[3]];
    require "vendor/autoload.php"; $e = (new Symfony\Component\Dotenv\Dotenv())->parse((string) file_get_contents($file));
    try {
      $pdo = new PDO(sprintf("pgsql:host=%s;port=%s;dbname=%s;sslmode=%s", $e[$p . "HOST"] ?? "", $e[$p . "PORT"] ?? "5432", $e[$p . "NAME"] ?? "",
        $e[$p . "SSLMODE"] ?? "prefer"), $e[$p . "USER"] ?? "", $e[$p . "PASS"] ?? "", [PDO::ATTR_TIMEOUT => 10, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
      $v = $pdo->query($sql)->fetchColumn(); echo "ok ", is_bool($v) ? ($v ? "t" : "f") : $v;
    } catch (Throwable $x) { echo "error ", preg_replace("/\s+/", " ", $x->getMessage()); }' "$1" "$2" "$3" 2>>"$LOG"
}
TABLES="SELECT count(*) FROM pg_tables WHERE schemaname = current_schema()"
PHINXLOG="SELECT to_regclass('phinxlog') IS NOT NULL"
health() { curl -s --max-time 10 -H "Host: $HOST" -H 'X-Forwarded-Proto: https' "http://127.0.0.1:$PORT$1" 2>>"$LOG"; }
release_of_site() { sed -n "s#^\$sharedDirectory = '$CODE_ROOT/\([^/']*\)/';.*#\1#p" "$SITE/public_html/index.php" 2>/dev/null | head -n1; }
state() { sed -n "s/^$1=//p" "$POD_DIR/kit.env" 2>/dev/null | head -n1; }  # what an earlier run chose: PORT, INSTALLATION, MAILPIT, BANLIST

# The pod's files from the release's templates, the systemd user units, and what this run chose.
write_pod() {
  local kit="$TREE/deploy/podman" mp=(-e '/# mailpit:begin/d' -e '/# mailpit:end/d') podman_bin; podman_bin="$(command -v podman)"
  [[ "$MAILPIT" == yes ]] || mp=(-e '/# mailpit:begin/,/# mailpit:end/d')
  if [[ "$BANLIST" == yes ]]; then mp+=(-e '/# banlist:begin/d' -e '/# banlist:end/d'); else mp+=(-e '/# banlist:begin/,/# banlist:end/d'); fi
  mkdir -p "$POD_DIR" "$QUADLET_DIR" "$USER_UNITS" || return 1
  local subst=(-e "s#@POD@#$UNIT#g" -e "s#@KIT@#$KIT#g" -e "s#@VERSION@#$NEW#g" -e "s#@INSTALLATION@#$NAME#g" -e "s#@IMAGE@#$IMAGE#g"
    -e "s#@WEB_IMAGE@#$WEB_IMAGE#g" -e "s#@MAILPIT_IMAGE@#$MAILPIT_IMAGE#g" -e "s#@PORT@#$PORT#g" -e "s#@PODMAN@#$podman_bin#g")
  sed "${mp[@]}" "${subst[@]}" "$kit/ctnlist.yaml.in" > "$POD_DIR/ctnlist.yaml" \
    && sed "${mp[@]}" "${subst[@]}" "$kit/web.conf.in" > "$POD_DIR/web.conf" \
    && cp "$kit/fastcgi.conf" "$POD_DIR/fastcgi.conf" \
    && sed "${subst[@]}" "$kit/ctnlist.kube.in" > "$QUADLET_DIR/$UNIT.kube" || return 1
  for unit in worker maintenance; do
    sed "${subst[@]}" "$kit/ctnlist-$unit.service.in" > "$USER_UNITS/$UNIT-$unit.service" \
      && sed "${subst[@]}" "$kit/ctnlist-$unit.timer.in" > "$USER_UNITS/$UNIT-$unit.timer" || return 1
  done
  printf 'PORT=%s\nINSTALLATION=%s\nMAILPIT=%s\nBANLIST=%s\n' "$PORT" "$NAME" "$MAILPIT" "$BANLIST" > "$POD_DIR/kit.env"
}

install_main() {
  mkdir -p "$KIT" && chmod 700 "$KIT" && : >>"$LOG" || { echo "$KIT cannot be written." >&2; exit 1; }
  chmod 600 "$LOG"
  echo "==== $(date +%FT%T%:z) ctnlist-podman.sh install ${ARGS//--mailer-dsn [^ ]*/--mailer-dsn ***}" >>"$LOG"
  echo "ctnlist on rootless Podman — log: $LOG"

  step "Checking the user, Podman and the server's tools"
  [[ $EUID -ne 0 ]] || fail "this is running as root" "run it as the site's own user; root runs root-prepare.sh <user> once (README.md)"
  missing=()
  for tool in podman git curl systemctl slirp4netns; do command -v "$tool" >/dev/null || missing+=("$tool"); done
  # The pod's pause container is built from catatonit (a package Podman recommends).
  command -v catatonit >/dev/null || [[ -x /usr/libexec/podman/catatonit ]] || missing+=(catatonit)
  ((${#missing[@]} == 0)) || fail "this server lacks: ${missing[*]}" "root: apt-get install podman catatonit slirp4netns git curl"
  [[ "$(podman info --format '{{.Host.Security.Rootless}}' 2>>"$LOG")" == true ]] \
    || fail "Podman cannot run rootless containers for $(id -un)" "root runs root-prepare.sh $(id -un) (it gives the user the ids containers need), then run this again"
  pversion="$(podman --version | sed 's/[^0-9.]*\([0-9.]*\).*/\1/')"
  [[ "$(printf '%s\n%s\n' 4.4 "$pversion" | sort -V | head -n1)" == 4.4 ]] || fail "Podman $pversion is too old" "Podman 4.4 or later is needed (Quadlet runs the pod)"
  systemctl --user show-environment >/dev/null 2>&1 \
    || fail "there is no systemd user session for $(id -un)" "log in as $(id -un) directly (ssh), not through su or sudo, and check that root ran root-prepare.sh $(id -un) (linger)"
  lacks=()
  [[ "$(loginctl show-user "$(id -un)" -p Linger --value 2>/dev/null)" == yes ]] \
    || lacks+=("linger is off for $(id -un): the site stops when you log out and does not start at boot (root runs: loginctl enable-linger $(id -un))")
  command -v pg_dump >/dev/null \
    || lacks+=("pg_dump is not installed: a release switch cannot dump the database first (root: apt-get install postgresql-client, or pass --no-backup)")
  if ((${#lacks[@]})); then warn "$(printf '%s; ' "${lacks[@]}" | sed 's/; $//')"; else pass "$(id -un), Podman $pversion"; fi

  step "Finding the release"
  EXISTING=false; [[ -f "$SITE/.env" ]] && EXISTING=true
  HAVE="$(release_of_site)"
  # Not finished yet: no .env, or an .env from an earlier run that stopped before the front controller was copied.
  FRESH=false; [[ "$EXISTING" != true || -z "$HAVE" ]] && FRESH=true
  if [[ -z "$VERSION" ]]; then
    VERSION="$(git ls-remote --tags --refs "$REPO_URL" 'v[0-9]*' 2>>"$LOG" | sed 's#.*refs/tags/##' | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | tail -n1)"
    [[ -n "$VERSION" ]] || fail "no release was found at $REPO_URL" "check the server's network and DNS, or pass --version vX.Y.Z"
  fi
  [[ "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "$VERSION is not a release tag (vX.Y.Z)" "install a release tag, never a branch"
  NEW="${VERSION#v}"; TREE="$RELEASES/$NEW"; IMAGE="localhost/ctnlist:$NEW"
  BACK=false; [[ -n "$HAVE" ]] && newer "$HAVE" "$NEW" && BACK=true
  if [[ "$EXISTING" != true ]]; then pass "$VERSION, a new installation"
  elif [[ "$HAVE" == "$NEW" ]]; then pass "$VERSION, the release the installation runs: checking it over"
  elif [[ "$BACK" == true ]]; then pass "$VERSION, back from $HAVE"
  else pass "$VERSION, from ${HAVE:-an unfinished installation}"; fi

  step "Fetching the release into $TREE"
  if [[ -f "$TREE/bin/console" && -f "$TREE/deploy/podman/Containerfile" ]]; then
    pass "already there"
  else
    mkdir -p "$RELEASES" && tmp="$(mktemp -d "$RELEASES/.fetch.XXXXXX")" || fail "$RELEASES cannot be written" "check the free space and the owner of $KIT"
    run git clone --quiet --depth 1 --branch "$VERSION" "$REPO_URL" "$tmp/src" \
      || { rm -rf "$tmp"; fail "release $VERSION could not be fetched from $REPO_URL" "check that the tag exists: git ls-remote --tags $REPO_URL"; }
    grep -qF "\$sharedDirectory = '$CODE_ROOT/$NEW/';" "$tmp/src/public_html/index.php" \
      || { rm -rf "$tmp"; fail "release $VERSION does not install to $CODE_ROOT/$NEW (its public_html/index.php names another path)" "pass the --version this tag carries"; }
    [[ -f "$tmp/src/deploy/podman/Containerfile" ]] \
      || { rm -rf "$tmp"; fail "release $VERSION has no Podman kit (deploy/podman)" "install a release that has one, or install it with install-ctnlist instead"; }
    rm -rf "$tmp/src/.git" "$TREE" && mv "$tmp/src" "$TREE" && rm -rf "$tmp" || fail "the release could not be put in $TREE" "see the log"
    pass "$TREE"
  fi

  step "Building the image $IMAGE"
  if podman image exists "$IMAGE"; then
    built="already there"
  else
    run podman build -t "$IMAGE" -f "$TREE/deploy/podman/Containerfile" "$TREE/deploy" \
      || fail "the image could not be built" "see the log: usually the network (the base image and Debian's packages are fetched)"
    built="built"
  fi
  lacking="$(podman run --rm --network none "$IMAGE" php -r 'foreach (explode(" ", $argv[1]) as $x) { if (!extension_loaded($x)) echo $x, " "; }' "$EXTENSIONS" 2>>"$LOG")" \
    || fail "the image's PHP does not start" "see the log; remove the image (podman image rm $IMAGE) and run this again"
  [[ -z "$lacking" ]] || fail "the image's PHP lacks the extensions $lacking" "the release's deploy/podman/Containerfile must install them"
  [[ -n "$MAILPIT" ]] || MAILPIT="$(state MAILPIT)"; [[ "$MAILPIT" == yes ]] || MAILPIT=no
  for image in "$WEB_IMAGE" $([[ "$MAILPIT" == yes ]] && echo "$MAILPIT_IMAGE"); do
    podman image exists "$image" || run podman pull -q "$image" || fail "the image $image could not be fetched" "check the server's network"
  done
  pass "$built; PHP $(podman run --rm --network none "$IMAGE" php -r 'echo PHP_VERSION;' 2>>"$LOG")"

  step "Installing the release's Composer packages"
  if [[ -f "$TREE/vendor/autoload_runtime.php" ]]; then
    pass "already there"
  else
    mkdir -p "$HOME/.cache/ctnlist-composer"
    run code_run composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
      || fail "composer install failed" "see the log: usually the network"
    pass "read-only in the containers"
  fi

  step "Reading the installation's settings"
  [[ -n "$NAME" ]] || NAME="$(state INSTALLATION)"; [[ -n "$NAME" ]] || NAME="$(id -un)"
  [[ "$NAME" =~ ^[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?$ ]] || fail "no usable installation name ('$NAME')" \
    "pass --installation NAME: lower-case letters, digits, dots and hyphens"
  INST="/var/www/$NAME"
  [[ -n "$PORT" ]] || PORT="$(state PORT)"
  [[ "$PORT" =~ ^[0-9]{2,5}$ ]] && ((PORT >= 1024 && PORT <= 65535)) \
    || fail "no usable port ('$PORT')" "pass --port N: a free port from 1024 to 65535 on 127.0.0.1, the one the server's web server forwards to"
  if [[ "$PORT" != "$(state PORT)" ]] && (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then
    fail "something already listens on 127.0.0.1:$PORT" "choose another --port (one per site on this server)"
  fi
  if [[ "$EXISTING" == true ]]; then
    HOST="$(kit_php -r 'echo parse_url($argv[1], PHP_URL_HOST);' "$(envget "$SITE/.env" APP_BASE_URL)" 2>>"$LOG")"
    [[ -n "$HOST" ]] || fail "$SITE/.env has no usable APP_BASE_URL" "set APP_BASE_URL to the site's https address"
    provider="$(envget "$SITE/.env" SUPPRESSION_PROVIDER)"
    BANLIST=no; [[ "${provider:-banlist}" == banlist ]] && BANLIST=yes
    [[ "$BANLIST" == no || -f "$CONFIG/ban.env" ]] || fail "$SITE/.env uses the suppression database (SUPPRESSION_PROVIDER=banlist), but there is no $CONFIG/ban.env" \
      "root runs root-prepare.sh $(id -un) --banlist-env <the server's ban.env>; or, for catto-mail's suppression instead, set SUPPRESSION_PROVIDER=cattomail in $SITE/.env"
    pass "kept $SITE/.env ($HOST), 127.0.0.1:$PORT"
  else
    ask HOST "The site's public host name, e.g. lists.example.org"
    valid_host "$HOST" || fail "'$HOST' is not a host name" "pass --host with a fully qualified lower-case name, e.g. lists.example.org"
    ask ADMIN_EMAIL "The administrator's e-mail address (sign-in links go there)"
    valid_email "$ADMIN_EMAIL" || fail "'$ADMIN_EMAIL' is not an e-mail address" "pass --admin-email"
    ask LIST_NAME "The list's name" "$HOST"
    ask ORGANISATION "The organisation that runs it" "$LIST_NAME"
    ask MAIL_FROM "Sender of the site's mail" "info@$HOST"
    valid_email "$MAIL_FROM" || fail "'$MAIL_FROM' is not an e-mail address" "pass --mail-from"
    ask MAILER_DSN "SMTP server for sign-in links, e.g. smtps://USER:PASS@mail.example.org:465 (empty: set it in Settings later)"
    ask MAIL_SERVICE "The address of the mail service (catto-mail) for campaign mail, e.g. https://mail.example.org (empty: Set up sending asks)"
    MAIL_SERVICE="${MAIL_SERVICE%/}"
    [[ -z "$MAIL_SERVICE" ]] || valid_service "$MAIL_SERVICE" || fail "'$MAIL_SERVICE' is not the https address of a mail service" "pass --mail-service with an address such as https://mail.example.org, or leave it out"
    if [[ -f "$CONFIG/database.env" ]]; then
      DB_NAME="$(envget "$CONFIG/database.env" DB_NAME)"; DB_USER="$(envget "$CONFIG/database.env" DB_USER)"; DB_PASS="$(envget "$CONFIG/database.env" DB_PASS)"
      DB_HOST="$(envget "$CONFIG/database.env" DB_HOST)"; DB_PORT="$(envget "$CONFIG/database.env" DB_PORT)"
    else
      [[ "$ASSUME_YES" != true ]] || fail "there is no $CONFIG/database.env to take the database from" \
        "root runs root-prepare.sh $(id -un) --database, or run this without --yes and give the database's name, user and password"
      DB_NAME="" DB_USER="" DB_HOST="" DB_PORT=""
      ask DB_NAME "The site's PostgreSQL database (empty, on this server)"
      ask DB_USER "Its PostgreSQL role" "$DB_NAME"
      read -r -s -p "That role's password: " DB_PASS </dev/tty || true; echo
    fi
    [[ -n "$DB_NAME" && -n "$DB_USER" && -n "$DB_PASS" ]] || fail "the database's name, role or password is missing" "root-prepare.sh $(id -un) --database writes them to $CONFIG/database.env"
    # The suppression database when root gave the site one; otherwise catto-mail's suppression.
    BANLIST=no; [[ -f "$CONFIG/ban.env" ]] && BANLIST=yes
    pass "$HOST, administrator $ADMIN_EMAIL, 127.0.0.1:$PORT, $([[ "$BANLIST" == yes ]] && echo "the suppression database in $CONFIG/ban.env" || echo "catto-mail's suppression (no ban.env)")"
  fi

  step "Preparing the installation directory"
  # public_html is read by the web container's own user: world-readable, like any web root. The rest is the site's.
  mkdir -p "$SITE/public_html/assets" "$SITE/var" "$SITE/logs" "$BACKUPS" "$CONFIG" \
    && chmod 750 "$SITE" && chmod 755 "$SITE/public_html" "$SITE/public_html/assets" && chmod 700 "$SITE/var" "$BACKUPS" "$CONFIG" \
    && chmod 750 "$SITE/logs" || fail "the directories in $SITE could not be made" "check the free space and the owner of $KIT"
  if [[ "$MAILPIT" == yes ]]; then
    mkdir -p "$KIT/mailpit" && chmod 700 "$KIT/mailpit" || fail "$KIT/mailpit could not be made" "see the log"
    if [[ ! -f "$CONFIG/mailpit-users" ]]; then
      password="$(secret 'bin2hex(random_bytes(12))')"
      (umask 077; printf '%s\n' "$password" > "$CONFIG/mailpit-password" \
        && printf '%s' "$password" | kit_php -r 'echo "proofs:", password_hash(stream_get_contents(STDIN), PASSWORD_BCRYPT), "\n";' > "$CONFIG/mailpit-users") \
        || fail "Mailpit's password could not be written" "see the log"
    fi
  fi
  pass "$SITE"

  step "Writing the configuration (.env)"
  if [[ "$BANLIST" == yes ]]; then
    # The pod's copy of the suppression database's settings: the server's 127.0.0.1 is 10.0.2.2 from the pod.
    ghost="$(envget "$CONFIG/ban.env" GDB_HOST)"
    if [[ "$ghost" == 127.0.0.1 || "$ghost" == localhost || -z "$ghost" ]]; then
      printf '%s\0' GDB_HOST "$HOST_LOOPBACK" | envset "$CONFIG/ban.env" || fail "$CONFIG/ban.env could not be written" "see the log"
    fi
    chmod 600 "$CONFIG/ban.env"
  fi
  if [[ "$EXISTING" == true ]]; then
    lacking="$(comm -23 <(sed -n 's/^\([A-Z][A-Z0-9_]*\)=.*/\1/p' "$TREE/.env.example" | sort -u) <(sed -n 's/^\([A-Z][A-Z0-9_]*\)=.*/\1/p' "$SITE/.env" | sort -u) | tr '\n' ' ')"
    lacks=()
    [[ -z "$lacking" ]] || lacks+=("kept. This release has settings that $SITE/.env does not name, so their defaults apply (they are explained in $TREE/.env.example): ${lacking% }")
    [[ "$MAILPIT" != yes || -n "$(envget "$SITE/.env" MAIL_PROOF_SMTP)" ]] \
      || lacks+=("Mailpit runs, but proofs go through the mail service until $SITE/.env has MAIL_PROOF_SMTP=127.0.0.1:1025")
    if ((${#lacks[@]})); then warn "$(printf '%s; ' "${lacks[@]}" | sed 's/; $//')"; else pass "kept"; fi
  else
    [[ "$DB_HOST" == 127.0.0.1 || "$DB_HOST" == localhost || -z "$DB_HOST" ]] && DB_HOST="$HOST_LOOPBACK"
    proof=""; [[ "$MAILPIT" == yes ]] && proof="127.0.0.1:1025"
    suppression=(SUPPRESSION_PROVIDER cattomail); [[ "$BANLIST" == yes ]] && suppression=(SUPPRESSION_PROVIDER banlist GDB_ENV_DIRECTORY /etc/ctnlist GDB_ENV_FILE ban.env)
    (umask 077; cp "$TREE/.env.example" "$SITE/.env.new") || fail "the configuration could not be written" "see the log"
    printf '%s\0' APP_SECRET "$(secret 'bin2hex(random_bytes(32))')" APP_INSTANCE_ID "$(secret 'bin2hex(random_bytes(16))')" \
      APP_SETTINGS_KEY "$(secret 'base64_encode(random_bytes(32))')" APP_BASE_URL "https://$HOST/" APP_DOMAIN "$HOST" APP_LIST_NAME "$LIST_NAME" \
      APP_ORGANISATION "$ORGANISATION" APP_ADMIN_EMAIL "$ADMIN_EMAIL" APP_ABOUT_US "" TRUSTED_PROXIES "" \
      DB_HOST "$DB_HOST" DB_PORT "${DB_PORT:-5432}" DB_NAME "$DB_NAME" DB_USER "$DB_USER" DB_PASS "$DB_PASS" \
      MAILER_DSN "$MAILER_DSN" MAIL_FROM_ADDRESS "$MAIL_FROM" MAIL_FROM_NAME "$LIST_NAME" MAIL_ADMIN_ADDRESS "$ADMIN_EMAIL" \
      MAIL_BOUNCE_ADDRESS "$MAIL_FROM" MAIL_TEST_ADDRESS "$ADMIN_EMAIL" MAIL_PROOF_SMTP "$proof" CONTACT_MAIL_ADDRESS "$ADMIN_EMAIL" \
      CONTACT_MAIL_NAME "$ORGANISATION" CATTOMAIL_API_BASE_URL "$MAIL_SERVICE" "${suppression[@]}" \
      | envset "$SITE/.env.new" || fail "the configuration could not be written" "see the log"
    chmod 600 "$SITE/.env.new" && mv "$SITE/.env.new" "$SITE/.env" || fail "the configuration could not be written" "see the log"
    pass "secrets generated; mode 600"
  fi

  step "Checking the databases"
  probe="$(dbq "$INST/.env" DB_ "$TABLES")"
  [[ "$probe" == ok* ]] || fail "the database named in $SITE/.env cannot be reached from the pod: ${probe#error }" \
    "check DB_HOST ($HOST_LOOPBACK is this server), DB_PORT, DB_NAME, DB_USER and DB_PASS, that PostgreSQL listens on 127.0.0.1 and that pg_hba.conf allows password logins from 127.0.0.1"
  tables="${probe#ok }"
  # A new installation starts from an empty database, or from one an earlier run of this release began (an
  # interrupted install): never from another application's tables, or another release's.
  if [[ "$FRESH" == true && "$tables" != 0 ]]; then
    ours="$(ls "$TREE/database/migrations/domain" | sed -n 's/^\([0-9]\{14\}\)_.*\.php$/\1/p' | sort | paste -sd, -)"
    found=""; [[ "$(dbq "$INST/.env" DB_ "$PHINXLOG")" == "ok t" ]] && found="$(dbq "$INST/.env" DB_ "SELECT string_agg(version::text, ',' ORDER BY version) FROM phinxlog")"
    [[ "$found" == "ok $ours" ]] || fail "the database already holds $tables tables that are not this release's schema, and a new installation starts from an empty one" \
      "give this installation a database of its own (root-prepare.sh $(id -un) --database, or a new one in your control panel); nothing was changed in this one"
  fi
  gprobe=""; [[ "$BANLIST" == yes ]] && gprobe="$(dbq /etc/ctnlist/ban.env GDB_ "$PHINXLOG")"
  [[ "$BANLIST" == no || "$gprobe" == ok* ]] || fail "the suppression database named in $CONFIG/ban.env cannot be reached from the pod: ${gprobe#error }" \
    "check its GDB_ settings ($HOST_LOOPBACK is this server) and that the role may log in from 127.0.0.1"
  if [[ "$BANLIST" == no ]]; then
    pass "$tables tables; no suppression database (catto-mail's suppression)"
  elif [[ "$gprobe" == "ok f" ]]; then
    run site_run env GDB_ENV_DIRECTORY=/etc/ctnlist GDB_ENV_FILE=ban.env php vendor/bin/phinx migrate -c config/phinx/phinx-banlist.php -e production \
      || fail "the suppression database's schema could not be created" "see the log"
    pass "$tables tables; the suppression database's schema created"
  else
    pass "$tables tables; the suppression database has its schema"
  fi

  HAS_SCHEMA=false; [[ "$(dbq "$INST/.env" DB_ "$PHINXLOG")" == "ok t" ]] && HAS_SCHEMA=true
  SWITCH=false; [[ "$FRESH" != true && "$HAVE" != "$NEW" ]] && SWITCH=true
  if [[ "$SWITCH" == true ]]; then
    echo
    echo "  The installation will be switched from $HAVE $([[ "$BACK" == true ]] && echo "back ")to $NEW (https://$HOST/)."
    echo "  The site stops for a minute or two. Its database is dumped first; .env and the data are kept."
    if [[ "$ASSUME_YES" != true ]]; then
      read -r -p "  Continue? [y/N] " answer </dev/tty || answer=""
      [[ "$answer" =~ ^[Yy]([Ee][Ss])?$ ]] || { echo "Nothing of the installation was changed."; exit 0; }
    fi
    echo
  fi

  step "Backing up and migrating the database"
  DUMP="" schema_note=""
  if [[ "$HAS_SCHEMA" == true && "$SWITCH" == true && "$BACKUP" == true ]]; then
    command -v pg_dump >/dev/null || fail "pg_dump is not installed, so the database cannot be dumped before the switch" "root: apt-get install postgresql-client; or pass --no-backup"
    dh="$(envget "$SITE/.env" DB_HOST)"; [[ "$dh" == "$HOST_LOOPBACK" ]] && dh=127.0.0.1
    DUMP="$BACKUPS/$NAME-$HAVE-$(date +%Y%m%dT%H%M%S).dump"
    (umask 077; PGPASSWORD="$(envget "$SITE/.env" DB_PASS)" pg_dump -Fc -h "$dh" -p "$(envget "$SITE/.env" DB_PORT)" -U "$(envget "$SITE/.env" DB_USER)" \
      -d "$(envget "$SITE/.env" DB_NAME)" -f "$DUMP" 2>>"$LOG") \
      || { rm -f "$DUMP"; fail "the database could not be dumped" "see the log (pg_dump must be at least the server's version); --no-backup skips the dump"; }
  fi
  if [[ "$HAS_SCHEMA" == true && "$SWITCH" == true ]]; then
    if [[ -d "$RELEASES/$HAVE/database/migrations/domain" ]]; then
      if ! diff -rq "$RELEASES/$HAVE/database/migrations/domain" "$TREE/database/migrations/domain" >>"$LOG" 2>&1; then
        if [[ "$RECREATE" != true ]]; then
          fail "release $NEW has another database schema than $HAVE, and ctnlist has no upgrade migrations before go-live" \
            "an installation with nothing to keep: run this again with --recreate-database (the database is dumped${DUMP:+, to $DUMP}, then emptied and created again). Otherwise stay on $HAVE: nothing of the installation was changed."
        fi
        [[ -n "$DUMP" ]] || fail "--recreate-database needs the dump it is about to rely on" "run it without --no-backup"
      else
        RECREATE=false
      fi
    else
      RECREATE=false
      schema_note="the schema of $HAVE could not be compared with $NEW's (its code in $RELEASES is gone): if pages fail with database errors, the schema differs"
    fi
  else
    RECREATE=false
  fi
  # From here the site changes: the pod and its timers stop, so the old release never runs on the new schema or cache.
  if [[ "$SWITCH" == true ]] && systemctl --user is-active --quiet "$UNIT.service"; then
    run systemctl --user stop "$UNIT-worker.timer" "$UNIT-maintenance.timer"
    run systemctl --user stop "$UNIT.service" || fail "the pod could not be stopped" "$0 status"
  fi
  if [[ "$RECREATE" == true ]]; then
    [[ "$(dbq "$INST/.env" DB_ "DROP SCHEMA public CASCADE")" == ok* && "$(dbq "$INST/.env" DB_ "CREATE SCHEMA public AUTHORIZATION CURRENT_USER")" == ok* ]] \
      || fail "the database could not be emptied" "see the log; the dump is $DUMP; start the old release again with: $0 start"
    HAS_SCHEMA=false
  fi
  run phinx migrate -c config/phinx/phinx-paralegal.php -e production || fail "the schema could not be migrated" "see the log${DUMP:+; the dump is $DUMP}"
  if [[ -n "$schema_note" ]]; then warn "$schema_note"
  elif [[ "$HAS_SCHEMA" == true ]]; then pass "${DUMP:+dump $DUMP; }schema unchanged"
  else pass "${DUMP:+dump $DUMP; }schema created"; fi

  step "Switching the installation to release $NEW"
  rm -rf "$SITE/public_html/vendor.new"
  cp -a "$TREE/public_html/vendor" "$SITE/public_html/vendor.new" && rm -rf "$SITE/public_html/vendor" && mv "$SITE/public_html/vendor.new" "$SITE/public_html/vendor" \
    && install -m 644 "$TREE/public_html/index.php" "$SITE/public_html/index.php" \
    || fail "the front controller and the editor files could not be copied" "see the log"
  rm -rf "$SITE/var/cache"
  run console cache:clear || fail "the cache could not be built" "see the log; then: $0 console cache:clear"
  run console asset-map:compile || fail "the assets could not be compiled" "see the log"
  if [[ "$SAMPLES" == true ]]; then run console ctnlist:templates:samples || warn "the sample templates could not be added"; fi
  pass "front controller, cache and assets"

  step "Starting the pod and the timers"
  write_pod || fail "the pod's files could not be written" "see the log"
  run systemctl --user daemon-reload || fail "systemd did not read the pod's unit" "systemctl --user status; journalctl --user -n 50"
  run systemctl --user restart "$UNIT.service" || fail "the pod did not start" "journalctl --user -u $UNIT.service -n 50"
  answer=""
  for _ in $(seq 1 45); do answer="$(health /health)"; [[ "$answer" == OK ]] && break; sleep 2; done
  [[ "$answer" == OK ]] || fail "the pod started, but the site does not answer on 127.0.0.1:$PORT" "$0 logs web; $0 logs php"
  run systemctl --user enable --now "$UNIT-worker.timer" "$UNIT-maintenance.timer" || fail "the timers could not be started" "systemctl --user status $UNIT-worker.timer"
  # One run of each now, so the worker and maintenance have been seen running before the check below.
  run systemctl --user start "$UNIT-worker.service" "$UNIT-maintenance.service" || warn "a first run of the worker or maintenance failed: $0 logs worker"
  pass "$UNIT.service on 127.0.0.1:$PORT, release $NEW$([[ "$MAILPIT" == yes ]] && echo ", with Mailpit")"

  step "Checking the installation"
  REPORT="$(podman exec "$UNIT-php" php "$CODE_ROOT/$NEW/bin/console" ctnlist:diagnose --production --no-interaction 2>&1)"; echo "$REPORT" >>"$LOG"
  PROBLEMS="$(grep -E '^(ERROR|WARN) ' <<<"$REPORT" || true)"
  errors="$(grep -c '^ERROR ' <<<"$REPORT" || true)"
  login="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H "Host: $HOST" -H 'X-Forwarded-Proto: https' "http://127.0.0.1:$PORT/login" 2>>"$LOG")"
  if [[ "$login" != 200 ]]; then warn "the sign-in page answers $login on 127.0.0.1:$PORT: $0 logs php"
  elif ((errors > 0)); then note "ctnlist:diagnose reports $errors error(s), listed below"
  else pass "/health answers OK; no error from ctnlist:diagnose"; fi

  cat <<DONE

ctnlist $([[ "$EXISTING" == true ]] && echo "release switch" || echo installation) completed.

Site:           https://$HOST/   (the pod: 127.0.0.1:$PORT)
Installation:   $SITE   (configuration: $SITE/.env)
Release:        $NEW$([[ -n "$HAVE" && "$HAVE" != "$NEW" ]] && echo " (was $HAVE)")   in $TREE
DONE
  [[ -n "$DUMP" ]] && echo "Dump:           $DUMP"
  [[ "$MAILPIT" == yes ]] && echo "Mailpit:        https://$HOST/mailpit/, name proofs, password in $CONFIG/mailpit-password"
  if [[ -n "$PROBLEMS" ]]; then
    echo
    echo "ctnlist:diagnose --production says:"
    sed 's/^/  /' <<<"$PROBLEMS"
  fi
  if ((${#NOTES[@]})); then
    echo
    echo "Still to do, from the steps above:"
    printf '  - %s\n' "${NOTES[@]}"
  fi
  echo
  if [[ "$EXISTING" == true ]]; then
    [[ -n "$HAVE" && "$HAVE" != "$NEW" ]] && echo "Release $HAVE stays in $RELEASES/$HAVE. To switch back: $0 install --version v$HAVE"
    echo "What the release changed: $TREE/CHANGELOG.md"
  else
    cat <<NEXT
Next:
  1. Root makes the server's web server forward https://$HOST/ to 127.0.0.1:$PORT
     ($TREE/deploy/podman/host-nginx.conf.example), then nginx -t and a reload.
  2. Open https://$HOST/login and ask for a sign-in link as $ADMIN_EMAIL: that address
     becomes the first administrator. It needs the SMTP server (MAILER_DSN, or Settings).
  3. Set up sending (Settings > Set up sending): the mail service account, Connect, the
     sending domain's DNS records and a test message, one step at a time.
  4. Go through the README's release checklist ($TREE/README.md).
NEXT
  fi
}

running_release() { HAVE="$(release_of_site)"; [[ -n "$HAVE" ]] || { echo "nothing is installed in $SITE yet: $0 install" >&2; exit 1; }; }

case "$COMMAND" in
  install) install_main ;;
  status)
    systemctl --user status "$UNIT.service" --no-pager || true
    podman pod ps --filter "name=$UNIT"
    systemctl --user list-timers "$UNIT-*" --no-pager || true ;;
  start|stop|restart)
    if [[ "$COMMAND" == stop ]]; then systemctl --user stop "$UNIT-worker.timer" "$UNIT-maintenance.timer"; fi
    systemctl --user "$COMMAND" "$UNIT.service"
    if [[ "$COMMAND" != stop ]]; then systemctl --user start "$UNIT-worker.timer" "$UNIT-maintenance.timer"; fi ;;
  logs)
    case "${1:-php}" in
      worker|maintenance) journalctl --user -u "$UNIT-$1.service" -n 200 --no-pager ;;
      *) podman logs --tail 200 "$UNIT-${1:-php}" ;;
    esac ;;
  console)
    running_release
    tty=(); [[ -t 0 ]] && tty=(-it)
    podman exec "${tty[@]}" "$UNIT-php" php "$CODE_ROOT/$HAVE/bin/console" "$@" ;;
  -h|--help) usage ;;
  *) usage; exit 64 ;;
esac
