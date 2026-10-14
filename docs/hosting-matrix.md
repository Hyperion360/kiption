# Kiption hosting matrix

What this release needs from a host, what has actually been validated, and
what is not supported. Kiption makes no universal-host claim: the
requirements below are the support boundary, and a host that fails any
required row is unsupported for this release.

## Required

| Requirement | Why | How to check |
|---|---|---|
| PHP 8.3 or newer | language features the app is written against | your host's PHP selector; `php -v` over SSH/CLI, or the setup page's `php` row |
| `pdo_sqlite` extension | every database in the app is SQLite through PDO | the setup page's `pdo_sqlite` row; `php -m` lists `pdo_sqlite` |
| Writable local persistent storage where SQLite WAL works | SQLite write-ahead logging needs a real local filesystem; WAL is probed live by the setup page's `wal` row (two connections, one write, one read) | the setup page's `wal` row |
| Private files outside the web root | databases, the deployment record, and the setup token must not be web-fetchable | either a configurable document root pointed at `site/public/`, or a fixed `public_html/` with `site/` uploaded beside it |
| HTTPS | setup records a canonical HTTPS URL; cookies are marked Secure; registration links use it | your host's TLS/certificate panel |
| Request routing (Apache `mod_rewrite` or an equivalent) | every app path is routed through the front controller; the shipped `public/.htaccess` targets Apache 2.4 | the setup page marks rewrite `unverified` until `/__kiption-route-probe` serves through the real host; load the home page after setup and confirm routing works |
| Cron (for timed publication, digests, automated backups) | `release:due`, `digest:send`, and `backup` are cron-driven through the stable launcher | your host's cron panel |
| Working outbound mail (SMTP or PHP `mail()`) | verification, password reset, digests, and notifications | the setup flow sends a test message; confirm it arrived before opening registration |

## Unsupported

A host that fails any required row above is not supported for this
release. These are outside the support boundary outright:

- A host without `pdo_sqlite` (the setup page fails before install).
- Storage where the WAL probe fails: network filesystems (NFS, SMB) and
  oversold shared storage commonly fail it. The probe refuses to continue
  rather than install onto storage that can corrupt a database.
- A layout with no private placement: if everything the operator uploads
  lands inside the document root, databases and setup state would be
  web-fetchable. Kiption refuses that shape.
- PHP older than 8.3, or a PHP build without `pdo_sqlite`.
- MySQL or any other database server: SQLite is the storage engine; there
  is no DSN switch to another engine.

## What has been validated

This release is local-fixture-validated only. Both supported layouts (configurable document root
at `site/public/`, and fixed `public_html` with `site/` as a sibling) were
exercised end to end on local Apache-compatible fixtures with PHP 8.3+ and
local writable storage: install through the browser setup, the anonymous
home and assets serving, CLI commands through the stable `site/bin/kip`
launcher, static-cache and sitemap writes landing in the web root while
SQLite stays private, the committed eFiction import's maintenance window,
and guest denial after a restricted import. Release artifacts pass the
production checker (`scripts/check-release.php`) before publication.

No provider account (cPanel, Plesk, or any named shared host) has been
validated as of this release. Validate a specific host against the required
rows above before relying on it, and record the provider, PHP version, and
any limitation here.

## Cron entry shape

Schedule through the stable launcher, never a release-pinned path, so the
command survives every future release switch:

    php /home/USER/site/bin/kip release:due
    php /home/USER/site/bin/kip digest:send
    php /home/USER/site/bin/kip backup

Backups land in `site/shared/backups/` (private); download them off the
host on a schedule you own.
