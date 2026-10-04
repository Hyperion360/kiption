# Installing Kiption

A step-by-step self-host guide: requirements, install, permissions, web
server, email, cron, first run, troubleshooting, and upgrades. Every claim
here matches what the shipped code does; where something is unvalidated, it
says so. `php bin/kip doctor` checks most of this automatically, and its
check names are the troubleshooting keys in section 8.

Commands assume the repo lives at `/path/to/kiption` and that `php` is
PHP 8.3 or newer. On macOS with Homebrew PHP, use
`PATH="/opt/homebrew/bin:$PATH" php ...` if the default `php` is older.

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
