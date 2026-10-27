# ctnlist on rootless Podman

One ctnlist installation runs as its own Linux user in a Podman pod. The server's own web server keeps ports 80 and 443 and the site's certificate, and forwards the site to the pod on `127.0.0.1`. PostgreSQL and the mail server stay on the server. This suits a server that already hosts other sites, with nginx and a control panel such as Virtualmin, and is the other way to install ctnlist beside [`install-ctnlist`](../../README.md#the-installer), which runs it with the server's own PHP as `www-data` under `/var/www`.

| Container | Image | Job |
|---|---|---|
| `web` | `nginxinc/nginx-unprivileged` (pinned in `ctnlist-podman.sh`) | Serves `public_html`, passes the front controller to `php` |
| `php` | ctnlist's image from [`Containerfile`](Containerfile): PHP 8.5 (an exact release) with the extensions ctnlist needs, and ctnlist's own PHP-FPM pool ([`deploy/php-fpm`](../php-fpm/ctnlist.conf.example)) | Runs the site (PHP-FPM) |
| `mailpit` | `axllent/mailpit`, the version the development stack runs (only with `--mailpit`) | Keeps proofs ([Proofs to Mailpit](../../README.md#proofs-to-mailpit)), read at `https://<host>/mailpit/` |

The worker (every minute) and maintenance (hourly) are systemd user timers that run their commands in the `php` container, so they read the same `.env` as the web server.

Why: PHP runs in a container as the site's user, with the code, `.env` and the web root mounted read-only; it can write only `var/` and `logs/`. A break-in through PHP cannot change the code or the configuration, reach the user's SSH keys, mail or cron, or touch the server's other sites. The PHP version is pinned in the image, the same on every machine that builds it, and needs nothing from the server's PHP. After root's one-time steps, the site's user installs and switches releases without root.

## What the server needs

- Linux with systemd, Podman 4.4 or later (Quadlet), `slirp4netns`, `catatonit` (a pod's pause container; Podman recommends it, so a plain `apt install podman` brings it), Git and curl. Ubuntu 24.04: `apt install podman catatonit slirp4netns git curl`.
- PostgreSQL 16 or later on the server, listening on 127.0.0.1, with password logins from 127.0.0.1 (`host all all 127.0.0.1/32 scram-sha-256` in `pg_hba.conf`, the usual default), and `pg_dump` (`postgresql-client`) to dump the database before a release switch.
- A mail server the site can send its sign-in links through: the server's own (such as Postfix) or any SMTP service.
- nginx on 80 and 443 with the site's certificate.

The pod reaches the server's `127.0.0.1` at `10.0.2.2`, and the server sees those connections arrive from 127.0.0.1, so the usual PostgreSQL and Postfix rules accept them. ctnlist's first release with this kit is 6.1.0.

## 1. As root, once

```sh
curl -fsSLO https://raw.githubusercontent.com/rrcatto/ctnlist/vX.Y.Z/deploy/podman/root-prepare.sh
bash root-prepare.sh <username> --database
```

The user must exist already, with a home directory (a control panel's account for the domain will do). `root-prepare.sh`:
- gives the user the user ids rootless containers need (`/etc/subuid`, `/etc/subgid`) when it has none, and turns on linger, so the pod starts at boot and keeps running when nobody is logged in;
- with `--database`, creates a role and an empty database for the site, both `ctnlist_<username>`, with a generated password, and writes them to `~<username>/ctnlist/config/database.env` (mode 600) for the install. Without it, give the site an empty database another way (a control panel's database for the domain will do) and type its name, role and password when the install asks;
- only for an installation that shares a suppression database (the banlist): with `--banlist-env FILE`, copies that database's `ban.env` to `~<username>/ctnlist/config/ban.env`; with `--create-banlist` instead, creates that database (role and database `banlist`), keeps its `ban.env` in `/etc/ctnlist/ban.env` (root only) for later sites, and gives the site its copy. The install creates the schema of a suppression database that has none. Without either, the site has no suppression database: global suppression is catto-mail's (`SUPPRESSION_PROVIDER=cattomail`, README [Configuration](../../README.md#configuration)).

It changes nothing else, never replaces a role, database or file that exists, and can be run again.

## 2. As the site's user

Log in as that user directly (`ssh`), not through `su` or `sudo`, so the user's systemd session is there:

```sh
curl -fsSLO https://raw.githubusercontent.com/rrcatto/ctnlist/vX.Y.Z/deploy/podman/ctnlist-podman.sh
bash ctnlist-podman.sh install --version vX.Y.Z --port 8082
```

Choose a free port for each site on the server (8081, 8082, …). The install asks for the host name, the administrator's address, the list's name, the sender, the SMTP server and the mail service's address (optional: Set up sending asks for it otherwise; `--help` lists them as options, `--mail-service` for the last). In 13 steps, each PASS, WARN or FAIL (a FAIL says why and how to fix it, and running it again is safe), it:

1. checks Podman, the user's systemd session and linger;
2. fetches the release into `~/ctnlist/releases/<version>`, builds its image `localhost/ctnlist:<version>` and installs its Composer packages in a one-off container;
3. writes `~/ctnlist/site/.env` from the release's `.env.example` with generated secrets (mode 600), the database from `config/database.env` (at `10.0.2.2`), `TRUSTED_PROXIES` empty (the pod's web server is PHP's edge) and the suppression source: with `config/ban.env`, the suppression database, its `ban.env` mounted at `/etc/ctnlist/ban.env`; without it, `SUPPRESSION_PROVIDER=cattomail`;
4. checks the database (and the suppression database, when there is one) from the pod's network, and refuses a database that holds tables other than this release's schema: a new installation starts from an empty one;
5. creates the schema, copies the front controller and the editor files into `~/ctnlist/site/public_html`, builds the cache and compiles the assets;
6. writes the pod (`~/ctnlist/pod/`), its systemd user service (`~/.config/containers/systemd/ctnlist.kube`) and the two timers (`~/.config/systemd/user/`), starts them, runs the worker and maintenance once, and waits until the site answers on `127.0.0.1:<port>`;
7. runs `ctnlist:diagnose --production` in the pod and shows its errors and warnings. A new installation has some until the mail service is connected.

`--mailpit` adds the Mailpit container, sets `MAIL_PROOF_SMTP=127.0.0.1:1025` in a new `.env`, and keeps Mailpit's password in `~/ctnlist/config/mailpit-password` (the name is `proofs`); `--no-mailpit` takes it away again. `--sample-templates` adds the three sample email templates.

Nothing is served to the public yet: the site answers only on `127.0.0.1:<port>`. A site that already runs on the host name keeps running until step 3.

## 3. As root: forward the site to the pod

[`host-nginx.conf.example`](host-nginx.conf.example) has the three pieces, for the site's HTTPS server block (with Virtualmin: the domain's own file in `/etc/nginx/sites-available/`):

1. a log format without query strings, once in the http context (`/etc/nginx/conf.d/ctnlist-log.conf`): sign-in links carry a one-time token, and a combined-style log would keep it;
2. that format on the site's `access_log` line (keep the file it names; AWStats still reads it);
3. `location ^~ / { proxy_pass http://127.0.0.1:<port>; … }` in place of the site's own `location /`, with the visitor's address (`X-Forwarded-For $remote_addr`), the scheme, a 300-second timeout (queue runs and imports) and a 32 MB body limit. Leave `location ^~ /.well-known/` as it is, so certificate renewal keeps working. `^~` keeps a PHP location from taking `.php` addresses away from the pod; with Virtualmin you can also set the domain's PHP mode to none (`virtualmin modify-web --domain <domain> --mode none`), which removes its PHP-FPM pool. AWStats's page at `/awstats/` is then the pod's (a 404); to keep it, add `location ^~ /awstats/ { root <the user's home>/public_html; }`.

Then `nginx -t` and `systemctl reload nginx`. Keep a copy of the file as it was: putting it back and reloading is the way back to the old site.

The pod redirects plain HTTP to HTTPS itself, so a server block that answers both ports (Virtualmin's) needs nothing more for that. It believes the forwarded address only from Podman's own hop and takes only the last one, so a visitor cannot choose the address the sign-in rate limits and the Site Log see.

## 4. Then

Sign in at `https://<host>/login` as the administrator's address (it becomes the first administrator), follow **Admin → Settings → Set up sending** (the mail service, Connect, the sending domain, a test message), and go through the README's [release checklist](../../README.md#release-checklist).

Mail from the pod: the server's mail server is at `10.0.2.2`, but its certificate names the server's host name, so with TLS use that name (`smtps://USER:PASS@mail.example.org:465`); the pod reaches the server's public address too.

## Running it

```sh
bash ctnlist-podman.sh install --version vX.Y.Z        # the next release, or an earlier one to go back
bash ctnlist-podman.sh status                          # the service, the pod and the timers
bash ctnlist-podman.sh logs php|web|mailpit            # a container's log
bash ctnlist-podman.sh logs worker|maintenance         # the timers' runs
bash ctnlist-podman.sh console ctnlist:diagnose        # bin/console in the running pod
bash ctnlist-podman.sh restart|stop|start
```

**A release switch** fetches and builds the new release beside the old one, dumps the database to `~/ctnlist/backups/` (mode 600), asks, stops the pod for a minute or two, migrates, switches the front controller, cache and assets, and starts the pod on the new release. `.env` and the data are kept, and the settings the new release adds are named. Before go-live ctnlist has no upgrade migrations: a release with another schema is refused with nothing changed, and `--recreate-database` dumps the database, empties it and creates the schema again (all data lost; restore a dump with `pg_restore --clean --if-exists -d <database> <dump>` and the release it was taken from). Earlier releases and their images stay until you remove them (`rm -rf ~/ctnlist/releases/<version>`, `podman image rm localhost/ctnlist:<version>`); naming one with `--version` switches back the same way.

**A PHP security release:** a ctnlist release raises the PHP version in `Containerfile`; switching to it builds the new image. The web server's and Mailpit's images are pinned in `ctnlist-podman.sh`.

## Where everything is

| Path | What |
|---|---|
| `~/ctnlist/site/.env` | the installation's settings and secrets (mode 600) |
| `~/ctnlist/site/public_html`, `var/`, `logs/` | the web root (front controller, editor files, compiled assets), the cache, the contact, import and export files |
| `~/ctnlist/releases/<version>` | each release's code with its packages, read-only in the containers |
| `~/ctnlist/config/` | `database.env` and, with a suppression database, `ban.env` from root; Mailpit's password |
| `~/ctnlist/pod/` | the pod, its web server's configuration and what the install chose (`kit.env`: port, installation, Mailpit) |
| `~/ctnlist/backups/`, `~/ctnlist/install.log` | dumps before each switch; the install's log, which holds no secret |
| `~/.config/containers/systemd/ctnlist.kube`, `~/.config/systemd/user/ctnlist-*.{service,timer}` | the pod's service and the timers |

Inside the containers the layout is the README's ([Layout and permissions](../../README.md#layout-and-permissions)): the code at `/usr/local/lib/php/ctnlist/<version>`, the installation at `/var/www/<installation>` (`--installation`, default the user's name), so the front controller, `ctnlist:diagnose` and the README's commands apply unchanged.

Back up the database, the suppression database where this server hosts it, and `~/ctnlist/site/.env` (it holds `APP_SETTINGS_KEY` and `APP_SECRET`, which are not in the database: README [Backups and recovery](../../README.md#backups-and-recovery)). The code, the images and `public_html` come back with an install.

## Testing the kit

`bin/dev test-podman-kit` runs both scripts in a stand-in server (`dev/podman-kit-test/`: Ubuntu 24.04 with systemd, Podman, PostgreSQL and nginx configured from `host-nginx.conf.example`): a new installation with Mailpit, a sign-in with the emailed link, a second run, a release switch and back, and a changed schema. `tests/Unit/PodmanKitTest` holds the kit's security-relevant lines and its agreement with `deploy/php-fpm`, `deploy/nginx` and `deploy/systemd`.
