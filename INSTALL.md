# Installing Kiption

A step-by-step self-host guide: requirements, install, permissions, web
server, email, cron, first run, troubleshooting, and upgrades. Every claim
here matches what the shipped code does; where something is unvalidated, it
says so. `php bin/kip doctor` checks most of this automatically, and its
check names are the troubleshooting keys in section 8.

Commands assume the repo lives at `/path/to/kiption` and that `php` is
PHP 8.3 or newer. On macOS with Homebrew PHP, use
`PATH="/opt/homebrew/bin:$PATH" php ...` if the default `php` is older.

## Shared hosting: install from the release ZIP (no Composer, no SSH)

This path is for a compatible shared host and needs only a file manager
(or FTP), a browser, and the host's own tools: no Composer, no Git, no SSH,
no database server. The plan that hosted an eFiction 3.5 archive usually
qualifies once its PHP selector is set to 8.3. Before uploading, confirm
the host against
docs/hosting-matrix.md: PHP 8.3+ with `pdo_sqlite`, writable local storage
where SQLite WAL works, a private directory outside the web root, HTTPS,
and outbound mail. Kiption makes no universal-host claim: the matrix is
the support boundary.

**1. Download and verify the ZIP.** Take `kiption-install.zip` from the
release page and check it before uploading, on a device you trust:

    shasum -a 256 kiption-install.zip

The printed digest must equal the whole-install-ZIP SHA-256 published with
the release. A mismatch means a corrupted or altered download: do not
upload it.

**2. Upload one of the two layouts.**

Configurable document root: upload the ZIP's `site/` directory above the
web root and point the domain's document root at `site/public/`.

Fixed `public_html` (the common cPanel shape): upload the ZIP's `site/`
directory as a sibling of the existing `public_html`, then upload the ZIP's
`public_html/` contents (index.php, setup.php, .htaccess, assets/) into
the existing document root. Application code, the databases, and the setup
token all stay in `site/`, which is never web-served.

While setup is pending, every routed page returns a plain 503: the archive
is closed until installation finishes. That is expected.

**3. Create the setup token on the host.** Use the host's random-password
generator (or any local generator) to make 32 random bytes, hex encoded:
exactly 64 characters. Save them as `site/shared/setup-token.txt` inside
the uploaded `site/` tree with private permissions (0600 where the file
manager allows). The token is the only key that can claim the first admin:
never reuse a token from a guide, never email it, never put it in a URL,
and delete the file when setup completes (setup deletes it itself).

**4. Run setup over HTTPS.** Open `https://your-domain/setup.php` and fill
the form: paste the token into the token field (POST form only), enter the
archive's real canonical HTTPS URL (`https://your-domain/`), choose SMTP or
PHP `mail()`, enter the from address and SMTP credentials if used, and pick
the admin email and password (8+ characters). Setup checks the host
(PHP version, pdo_sqlite, a live SQLite WAL round trip, private-file
placement), sends a test email to the admin address, writes the private
deployment record, creates exactly one administrator, and closes itself
forever: replaying the token does nothing afterward.

**5. Confirm the test email, then open registration.** A successful send
proves the transport, not the inbox. Confirm the test message actually
arrived, sign in, open `/settings`, and switch registration from `invite`
to `verify`. Until then the archive starts invite-only with no invite
codes issued, so no public registration can happen. If the test email
never arrived, keep invite mode and fix mail before issuing invitations.

**6. Cron and housekeeping.** Schedule through the stable launcher, never a
release-pinned path (it survives release switches):

    php /home/USER/site/bin/kip release:due
    php /home/USER/site/bin/kip backup

Backups land in `site/shared/backups/`; download them off the host
periodically. eFiction migration runs through the same launcher:
`php site/bin/kip import:efiction <bundle.tar.gz> --commit` takes the
exclusive run lease and puts the archive into maintenance itself; a failed
or interrupted import leaves maintenance in place (see below). The
`--dry-run` form runs without maintenance and is the safe first step.

**Recovery.** If setup was interrupted after the admin was created (the
token still exists, no completion), setup.php shows an owner-recovery form:
sign in with the admin email and password to finalize. If a committed
eFiction import failed, `site/shared/maintenance.lock` stays in place:
resolve the cause, re-run the import through the launcher, and the launcher
lifts maintenance only after a successful run. If a routine page dies, the
check names in section 8 map to the setup page's host-check rows.

**Upgrading a shared-host install.** A release can upgrade in the browser
with no shell, no Composer, and no PHP CLI. The steps below are the whole
procedure; docs/hosting-matrix.md states the measured boundary and what
happens on a host that cannot meet it.

**1. Download and verify the upgrade ZIP.** Take `kiption-upgrade.zip` from
the release page and check it before uploading, on a device you trust:

    shasum -a 256 kiption-upgrade.zip

The printed digest must equal the whole-upgrade-ZIP SHA-256 published with
the release. The release page also publishes a second digest for the same
release: the separately published `RELEASE.json` SHA-256 (it is also the
release ID, and it is what the upgrade form checks the staged tree
against). Copy that value now; the upgrade form asks for it later.

**2. Stage the release.** Extract the release-root upgrade ZIP into a new
directory under `site/releases/` on the host, named exactly for the
`RELEASE.json` digest you copied (the file manager can rename the extracted
folder afterward). This is the release-root upgrade ZIP, not the install
ZIP: the install ZIP carries a `site/` prefix and is only for first
installs. When the extraction is done, confirm `RELEASE.json` sits directly
inside the staging directory (`site/releases/<digest>/RELEASE.json`), not
inside a nested folder. Do not touch anything else: never overwrite
`site/shared`, never overwrite `site/current`, and never overwrite
`public_html/uploads`. The releases directory holds only code; your data,
your pointer, and your members' uploads live elsewhere on purpose.

**3. Upgrade in the browser.** Open `/upgrade.php` over HTTPS, sign in is
not enough: the form asks for the archive admin session, so sign in first
if you are not, then paste that digest into the form and re-enter your
admin password. The endpoint verifies the staged release against the
digest before anything else happens: every file's hash, the release
version, the PHP requirement, disk headroom for the backup and both asset
trees, and (for the browser path) a timed rehearsal of the backup it will
take. If verification refuses, read the reason; a rehearsal that cannot
fit one request on your host names the PHP CLI upgrade path instead, and
the site stays live and untouched. If it passes, the page shows the
verified target and the backup size. Activate: the endpoint takes a full
backup of all three databases, saves your current assets, swaps the new
release in, and holds the archive in maintenance until the database
migration finishes. Wait for a complete or rolled-back result; the page
says which. A complete result puts the archive back on the new release. A
rolled-back result means the migration failed and recovery restored the
previous release, its assets, and its databases exactly; the staged release
stays on the host for a retry after you understand the failure. Either
way, download the new backup off the host before you consider the upgrade
done; it is in `site/shared/backups/` and it is your off-host safety net.

**4. If the session dies mid-upgrade.** The upgrade runs in short steps
and stays recoverable at every one of them. If your login expires while
the archive is in maintenance, do not point a URL at anything: create a
file named `recovery-token` inside `site/shared/upgrade/` through the file
manager, containing 64 random hex characters (the same way the setup token
is made), open `/upgrade.php`, enter that token in the recovery form, and
continue. The token is consumed once and deleted.

**5. The PHP CLI path.** Owners with shell access can drive the same
upgrade without a browser: `php site/upgrade/cli.php
prepare|activate|continue|recover` (prepare takes `--digest=<the
RELEASE.json digest>`). The CLI uses the same verifier, lease, snapshots,
pointer, journal, and recovery as the browser, so a half-finished browser
upgrade continues on the CLI and the other way around. The CLI skips the
request-time rehearsal (a shell has no request limit) but verifies
everything else. File access is the CLI's authority; it never asks for a
password.

**6. Cron after an upgrade.** Nothing to change: scheduled commands run
through the stable launcher, and `php site/bin/kip ...` commands resolve
the active release, including after an upgrade switches it. This is why
section 6's crontab never names a release directory.

**The stable engine itself is not upgraded this way.** The engine is
`site/upgrade/`, the stable `site/bin/kip` launcher, and the web-root
wrappers (`index.php`, `setup.php`, `upgrade.php`, `.htaccess` in both
supported layouts). When a release ships a new stable engine, the release
page publishes a separate maintenance package (a ZIP with its own
whole-file SHA-256 and a `MANIFEST.json` naming every file's SHA-256 and
the upgrade protocol). Never overwrite `site/upgrade` in place. The
procedure: verify the package digest and its manifest (protocol 1, every
file matching), put the archive into maintenance from the file manager or
`php site/bin/kip`, and establish a quiet period: wait out the host's
maximum request and cron duration, or confirm with the host that nothing
is in flight. Then rename the entire `site/upgrade` directory to
`site/upgrade.previous` and `site/bin/kip` to `site/bin/kip.previous`,
copy the staged engine and launcher in, and copy the package's
`site-public/` and `public_html/` wrapper sets over the two web roots.
Smoke-test: `php site/upgrade/cli.php status` exits 0 and one page loads.
Clear maintenance. If anything fails, restore: move `site/upgrade.previous`
and `site/bin/kip.previous` back and put the previous wrapper files back;
that is the whole rollback, and it needs no shell. A package whose
manifest names an upgrade protocol this install does not run refuses
verification; do not force it.

## 1. Requirements

- PHP 8.3 or newer, with the `pdo_sqlite` extension (both are stock in
  every current distro and Homebrew package)
- Composer
- Git, and network access to fetch `kip/framework` from GitHub during
  `composer install`

Nothing else: no database server, no Node, no build step. SQLite is the
storage engine and the schema arrives by migration.

## 2. Install in four commands

    composer install
    php bin/kip migrate
    php bin/kip user:create you@example.com 'a strong password' --admin
    php bin/kip serve

What each does, and what a failure looks like:

1. `composer install` downloads the framework (`kip/framework`) and builds
   `vendor/`. Failures: a missing `git` binary or no network to GitHub
   (clone errors naming `github.com`), or an outdated Composer (complaints
   about the lock file format; run `composer self-update`).
2. `php bin/kip migrate` creates `app/data.sqlite` and applies every
   migration (app plus feature folders). Failures: `could not find driver`
   means the `pdo_sqlite` extension is not loaded (doctor check
   `pdo_sqlite`); `unable to open database file` means `app/` is not
   writable by the PHP user (doctor check `writable data dir`).
3. `php bin/kip user:create you@example.com 'a strong password' --admin`
   writes your admin account, born verified and approved so it can log in
   immediately. Failures: `Password must be at least 8 characters` (the
   reset flow's own rule), or a missing `users` table, which means step 2
   did not run (doctor check `migrations`). Run it without the password
   argument to be prompted with hidden input instead.
4. `php bin/kip serve` starts the PHP dev server on localhost:8080 for a
   first look; it also auto-migrates. It is a development server: for real
   traffic use Apache or nginx with PHP-FPM (section 4). Failure:
   `Address already in use` means port 8080 is taken; stop the other
   process or pick another port with `php -S localhost:8081 -t public`.

## 3. Permissions

The PHP user (the user your web server runs PHP as: `www-data` on
Debian/Ubuntu, `apache` or `php-fpm` on RHEL, `_www` on macOS) needs write
access to exactly these paths. Everything else can stay read-only.

| Path | Purpose | Who writes | Fix |
|---|---|---|---|
| `app/` | `data.sqlite` plus its `-wal`/`-shm` sidecars, `logs.sqlite`, `cache.sqlite`, `nav.json` | PHP | `chown -R www-data app && chmod -R u+rwX app` |
| `app/mail.log` | the `log` mail transport's output (parent dir above) | PHP | covered by the `app/` row |
| `app/backups/` | dated database snapshots from `php bin/kip backup` | PHP (cron) | `mkdir -p app/backups && chown www-data app/backups` |
| `public/cache/` | the static page cache (pre-rendered anonymous HTML) | PHP | `mkdir -p public/cache && chown www-data public/cache` |
| `public/uploads/` | story covers and avatars | PHP | `mkdir -p public/uploads && chown www-data public/uploads` |

`php bin/kip doctor` verifies every row and prints the failing path with
its fix; it also creates a missing directory when it can, so a healthy
first run may self-heal rows 2 through 5.

## 4. Web server

The supported path is plain PHP through `public/index.php`; anything that
routes requests there is correct. The webserver-level static-cache rules
below are an optional acceleration and are Apache-only.

### Apache 2.4 (known-good, shipped)

The repo ships `public/.htaccess`: real files serve directly, dotfiles are
blocked, everything else routes to `index.php`. It needs `mod_rewrite`
(effectively always on) and `AllowOverride All` for the vhost's
`public/` directory. Serving the static page cache without PHP at all is
the OPTIONAL rule from the README ("Static page cache" section): GET,
cookieless, queryless requests only, all three conditions load-bearing.

### nginx (UNVALIDATED)

The block below is the documented-correct PHP-fallback shape; it has not
been run against a production install yet, so treat it as a starting
point, not a promise. The webserver-level static-cache rules are
Apache-only: nginx users get the PHP fallback for cached pages, which is
fully correct and fast (SQLite reads from the page cache, no routing).

    server {
        listen 80;
        server_name archive.example.org;
        root /path/to/kiption/public;

        location / {
            try_files $uri $uri/ /index.php$is_args$args;
        }

        location ~ \.php$ {
            include fastcgi_params;      # or fastcgi.conf on your distro
            fastcgi_pass unix:/run/php/php8.3-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        }

        location ~ /\. { deny all; }     # the .htaccess dotfile rule, nginx spelling
    }

## 5. Email

The shipped `mail` config uses transport `log`: every message lands in
`app/mail.log` and nothing leaves the machine. Fine for trying the app;
broken for real members, because these flows send mail:

- account verification (members cannot self-register without it)
- password resets
- follower/favorite publish notifications and digests
- member-to-member contact notifications

For real use, switch the transport in `config.php` to `smtp` with your
provider's credentials (any generic SMTP provider works; substitute your
own values):

    'mail' => [
        'transport' => 'smtp',
        'host' => 'smtp.provider.example',
        'port' => 587,
        'tls' => true,
        'username' => 'postmaster@your-domain',
        'password' => 'the-smtp-password',
        'from' => 'noreply@archive.example.org',
    ],

`php bin/kip doctor` flags an `smtp` config missing its keys (check
`mail`). Test delivery without messaging members: `php bin/kip
mail:users "test" "hello" --dry-run` lists recipients, and the
`--mail-log=<path>` seam on `digest:send` and `release:due` redirects
their mail to a file.

## 6. Cron

The app ships no scheduler. Five jobs cover everything time-driven; this
crontab block is copy-pasteable after you fix the path (and it assumes the
same `php` resolution as section 1):

    */5 * * * *  cd /path/to/kiption && php bin/kip release:due
    0 * * * *    cd /path/to/kiption && php bin/kip digest:send
    30 3 * * *   cd /path/to/kiption && php bin/kip backup
    15 4 * * *   cd /path/to/kiption && php bin/kip logs:prune --days=30
    25 4 * * *   cd /path/to/kiption && php bin/kip pages:build

- `release:due` publishes chapters whose scheduled release time passed
  (every five minutes is the README's suggested cadence; it is idempotent).
- `digest:send` flushes batched follower and favorite digest emails
  (hourly is the README's suggestion).
- `backup` snapshots all three SQLite databases online (VACUUM INTO, safe
  under traffic) into `app/backups/`, pruning archives older than
  `backups.keep_days` (14 by default).
- `logs:prune --days=30` prunes the request log and the day-keyed
  `page_stats` rows older than the window; match it to your retention
  needs.
- `pages:build` pre-renders the static layer, refreshing the batch-stale
  surfaces (Trending, sitemaps). Always run it after imports and template
  changes too.

## 7. First run

After the four install commands, choose your starting content:

- Empty archive: `php bin/kip db:blank` writes the ratings ladder, starter
  tags, one category, and the five-link nav, and nothing else. This is the
  recommended start for a real archive.
- Demo archive: `php bin/kip db:seed` adds two demo stories and fixture
  accounts, and `KIP_ENV=dev php bin/kip db:demo` layers a full demo
  dataset on top (15 stories, 14 accounts). Never run demo content on a
  real site; the arm refuses databases with stories by real authors.

Then the admin account (if you did not create it during install):

    php bin/kip user:create you@example.com 'a strong password' --admin

Final gate: `php bin/kip doctor` must print `All checks passed` before you
take traffic. Fix anything it names, then run `php bin/kip pages:build`
once to pre-render the static layer.

If you change the skin before launch (SKINS.md; `/settings` or the `skin`
key in config.php), run `php bin/kip pages:build` again before taking
traffic: a config-file skin change triggers no board save, so nothing else
purges the static layer for you.

## 8. Troubleshooting

Each entry names the doctor check (section 1 command: `php bin/kip
doctor`) that catches it.

- Blank page or a fatal error on first load: run doctor. The commonest
  causes are `pdo_sqlite` (extension not loaded) and `writable data dir`
  (`app/` not writable by the PHP user). Pending schema shows as
  `migrations`: run `php bin/kip migrate`.
- Logins and signups never email anything: the mail transport is still
  `log`; every message is sitting in `app/mail.log`. Switch to `smtp`
  (section 5) before inviting members (check `mail`).
- Verification and password-reset links point at localhost: `base_url` is
  unset or wrong in `config.php`; emails and feeds link through it (check
  `base_url`).
- Search misses word forms or feels slow: your PHP lacks FTS5, so search
  runs the LIKE fallback (slower, no stemming). Doctor's `search` line
  reports the active mode; rebuild PHP with FTS5 or accept the fallback.
- Pages show stale content after edits: the static layer holds a
  pre-rendered copy. Normal edits purge it automatically; after manual
  database surgery or a template change, run `php bin/kip pages:prune`
  and `php bin/kip pages:build`.
- Locked out of the admin account: create a fresh one with
  `php bin/kip user:create newadmin@example.com 'a strong password' --admin`.

## 9. Upgrading

Back up first, then pull and re-run the install-shaped steps:

    php bin/kip backup
    git pull
    composer install
    php bin/kip migrate
    php bin/kip cache:clear
    php bin/kip pages:build

`cache:clear` matters on every deploy: a page cached while public stays
servable until its TTL even after its route gains an auth gate, because
the cache answers before routing. To see what you are running before or
after, `php bin/kip version` prints the app version, the locked framework
pin, and the PHP build; `CHANGELOG.md` (assembled from reviewed
`changelog.d/` fragments at release time) is the narrative of what
changed. Restoring a bad upgrade: unzip the backup's `data.sqlite` (and
sidecars) back in place, per the README's backups section.
