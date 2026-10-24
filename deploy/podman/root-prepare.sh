#!/usr/bin/env bash
#
# The root steps before a ctnlist installation runs on rootless Podman (deploy/podman/README.md), run once per site:
#
#   sudo bash root-prepare.sh <username> [--database] [--banlist-env FILE | --create-banlist]
#
# It checks Podman, gives <username> the user ids rootless containers need (/etc/subuid and /etc/subgid) when it has
# none, and turns on linger, so the site's pod starts at boot and keeps running when nobody is logged in. Then, if
# asked, what only root can do on this server's PostgreSQL:
#
#   --database          a role and an empty database for the site, both ctnlist_<username>, with a generated
#                       password, written to ~<username>/ctnlist/config/database.env for ctnlist-podman.sh
#   --banlist-env FILE  the ban.env of the suppression database the site shares, copied for the site's user to
#                       ~<username>/ctnlist/config/ban.env (ctnlist-podman.sh creates its schema if it has none)
#   --create-banlist    or: a new suppression database on this server (role and database banlist), its ban.env in
#                       /etc/ctnlist/ban.env for later sites, and the site's copy
#
# It changes nothing else: the web server's forwarding to 127.0.0.1:<port> is the next step in README.md. Running it
# again is safe; it never replaces a role, a database or a file that exists.
set -euo pipefail

fail() { printf '\n\033[31mStopped: %s\033[0m\n' "$*" >&2; exit 1; }
note() { printf '  %s\n' "$*"; }

[ "$(id -u)" -eq 0 ] || fail "run this as root."
USERNAME="${1:-}"; [ $# -gt 0 ] && shift
DATABASE=no; BANLIST_ENV=""; CREATE_BANLIST=no
while [ $# -gt 0 ]; do
    case "$1" in
        --database) DATABASE=yes; shift ;;
        --banlist-env) BANLIST_ENV="${2:-}"; shift 2 ;;
        --create-banlist) CREATE_BANLIST=yes; shift ;;
        *) fail "unknown option $1 (see the top of this script)" ;;
    esac
done
[ -n "$USERNAME" ] || fail "give the site's Linux account: root-prepare.sh <username>"
id "$USERNAME" >/dev/null 2>&1 || fail "there is no Linux account called $USERNAME."
HOME_DIR="$(getent passwd "$USERNAME" | cut -d: -f6)"
[ -d "$HOME_DIR" ] || fail "$USERNAME has no home directory ($HOME_DIR)."
[ -z "$BANLIST_ENV" ] || [ "$CREATE_BANLIST" = no ] || fail "give --banlist-env or --create-banlist, not both."
[ -z "$BANLIST_ENV" ] || [ -f "$BANLIST_ENV" ] || fail "$BANLIST_ENV does not exist."
command -v podman >/dev/null 2>&1 || fail "Podman is not installed: apt install podman (and slirp4netns)."
command -v slirp4netns >/dev/null 2>&1 || fail "slirp4netns is not installed: apt install slirp4netns."
# A pod's pause container is built from catatonit, which Podman only recommends.
command -v catatonit >/dev/null 2>&1 || [ -x /usr/libexec/podman/catatonit ] || fail "catatonit is not installed: apt install catatonit."
# Quadlet, which runs the pod as a systemd user service, arrived in Podman 4.4.
version="$(podman --version | sed 's/[^0-9.]*\([0-9.]*\).*/\1/')"
[ "$(printf '%s\n%s\n' 4.4 "$version" | sort -V | head -n1)" = 4.4 ] || fail "Podman $version is too old: 4.4 or later is needed (Quadlet)."

# 65,536 ids after the highest range already given, so no two accounts share any.
for file in /etc/subuid /etc/subgid; do
    if grep -q "^$USERNAME:" "$file"; then
        note "$USERNAME already has ids in $file."
    else
        start="$(awk -F: 'BEGIN { m = 100000 } { e = $2 + $3; if (e > m) m = e } END { print m }' "$file")"
        printf '%s:%s:65536\n' "$USERNAME" "$start" >> "$file"
        note "$USERNAME: ids $start to $((start + 65535)) in $file."
    fi
done

loginctl enable-linger "$USERNAME"
note "Linger is on for $USERNAME: the site's pod starts at boot."

[ "$DATABASE" = yes ] || [ -n "$BANLIST_ENV" ] || [ "$CREATE_BANLIST" = yes ] || {
    note "Next: the database and the web server's forwarding (README.md), then, as $USERNAME, ctnlist-podman.sh install."
    exit 0
}

CONFIG="$HOME_DIR/ctnlist/config"
install -d -o "$USERNAME" -g "$(id -gn "$USERNAME")" -m 700 "$HOME_DIR/ctnlist" "$CONFIG"
# give FILE: a file of the site's user, readable by nobody else.
give() { chown "$USERNAME:$(id -gn "$USERNAME")" "$1"; chmod 600 "$1"; }
pg() { (cd / && runuser -u postgres -- psql -X -At -v ON_ERROR_STOP=1 "$@"); }
command -v psql >/dev/null 2>&1 && pg -c 'SELECT 1' >/dev/null 2>&1 || fail "PostgreSQL is not running on this server (or has no postgres account)."
num="$(pg -c 'SHOW server_version_num')"
[ "$num" -ge 160000 ] || fail "this server's PostgreSQL is too old (version number $num): ctnlist needs 16 or later."
# create_database NAME: a role and its database, both NAME, with a generated password, which is printed for the
# caller only. The password reaches psql on its standard input, never on a command line.
create_database() {
    [ -z "$(pg -c "SELECT 1 FROM pg_roles WHERE rolname = '$1'")" ] || fail "a PostgreSQL role $1 exists already: remove it, or give the site its database by hand (README.md)."
    [ -z "$(pg -c "SELECT 1 FROM pg_database WHERE datname = '$1'")" ] || fail "a PostgreSQL database $1 exists already: remove it, or give the site its database by hand (README.md)."
    local password; password="$(od -An -N24 -tx1 /dev/urandom | tr -d ' \n')"
    pg >/dev/null <<SQL || fail "the role or the database $1 could not be created."
\set pw $password
CREATE ROLE "$1" LOGIN PASSWORD :'pw';
CREATE DATABASE "$1" OWNER "$1" ENCODING 'UTF8' TEMPLATE template0;
SQL
    printf '%s' "$password"
}

if [ "$DATABASE" = yes ]; then
    name="ctnlist_$(printf '%s' "$USERNAME" | tr -c 'a-z0-9_' '_')"
    if [ -f "$CONFIG/database.env" ]; then
        note "$CONFIG/database.env exists already: kept."
    else
        password="$(create_database "$name")"
        umask 077
        printf 'DB_HOST=127.0.0.1\nDB_PORT=5432\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n' "$name" "$name" "$password" > "$CONFIG/database.env"
        give "$CONFIG/database.env"
        note "The database $name and its role, for $USERNAME: $CONFIG/database.env."
    fi
fi

if [ -n "$BANLIST_ENV" ] || [ "$CREATE_BANLIST" = yes ]; then
    if [ -f "$CONFIG/ban.env" ]; then
        note "$CONFIG/ban.env exists already: kept."
    else
        if [ "$CREATE_BANLIST" = yes ]; then
            BANLIST_ENV=/etc/ctnlist/ban.env
            [ ! -e "$BANLIST_ENV" ] || fail "$BANLIST_ENV exists already: give it with --banlist-env instead."
            password="$(create_database banlist)"
            install -d -o root -g root -m 700 /etc/ctnlist
            umask 077
            printf 'GDB_DRIVER=pgsql\nGDB_HOST=127.0.0.1\nGDB_PORT=5432\nGDB_NAME=banlist\nGDB_USER=banlist\nGDB_PASS=%s\nGDB_SSLMODE=prefer\n' "$password" > "$BANLIST_ENV"
            chmod 600 "$BANLIST_ENV"
            note "The suppression database banlist: $BANLIST_ENV (root only). Give it to later sites with --banlist-env."
        fi
        install -m 600 "$BANLIST_ENV" "$CONFIG/ban.env"
        give "$CONFIG/ban.env"
        note "The suppression database's settings, for $USERNAME: $CONFIG/ban.env."
    fi
fi
note "Next: the web server's forwarding (README.md, step 3), then, as $USERNAME, ctnlist-podman.sh install."
