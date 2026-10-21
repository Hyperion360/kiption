# Upgrade guide

How to move a running Kiption archive to a newer release. All three
upgrade routes drive the same engine: verify the release, snapshot the
databases and assets, swap under a lease, migrate, and restore the
snapshots if the migration fails. Fresh installs: [INSTALL.md](INSTALL.md).
Coming from eFiction: upgrade that archive first (see the maintenance
fork's UPGRADE.md), then follow [docs/guides/SWITCHING.md](docs/guides/SWITCHING.md).

## Which release, and how far can you jump

Releases are listed on the [releases page](https://github.com/Hyperion360/kiption/releases)
with their ZIP digests, and `CHANGELOG.md` narrates what each one changed.
You can jump any number of releases in one upgrade: database migrations
run sequentially, so an archive several releases behind receives every
pending migration in order during the single upgrade. Read the changelog
sections between your version and the target before you start; entries
name behavior changes an operator would want to know (new config keys,
new cron jobs, backfilled pages).

## Route 1: the browser (shared hosting, no shell)

1. Download the release's **upgrade ZIP** — the `<upgrade.zip>` artifact
   the release builder produces alongside the install ZIP
   (`scripts/build-release.php --output=<install.zip>
   --upgrade-output=<upgrade.zip>`). If a release tag ships without
   attached artifacts, build both from the tagged commit and verify them
   with `scripts/check-release.php`.
2. Upload the upgrade ZIP to the release root with your file manager (the
   same place `site/` lives; INSTALL.md's "Shared hosting" section shows
   both layouts).
3. Open `/upgrade.php` on your site and follow it. The endpoint verifies
   the release, snapshots databases and assets under an exclusive lease,
   swaps the assets in two journaled steps, runs the migrations on a fresh
   request, and restores the snapshots by itself if the migration fails —
   neither release boots in a half-swapped state.

## Route 2: the CLI (shell access)

    php site/upgrade/cli.php

Same lifecycle as the browser route, driven from the terminal. `site/`
is the release layout — in a git checkout the launcher is
`upgrade/cli.php`. Use it when you have SSH or prefer not to drive a long
operation through a browser.

## Route 3: a git checkout (development installs)

    php bin/kip backup
    git pull
    composer install
    php bin/kip migrate
    php bin/kip cache:clear
    php bin/kip pages:build

`cache:clear` matters on every deploy: a page cached while public stays
servable until its TTL even after its route gains an auth gate, because
the cache answers before routing. `pages:build` re-renders the static
layer against the new code.

## What an upgrade does to your data

- Databases and assets are snapshotted before anything changes; snapshots
  live in the backups directory and are what a failed upgrade restores
  from (`bin/kip backup` writes the same format on a schedule).
- Migrations are forward-only schema and backfill steps, applied in
  order. Recent examples: legacy eFiction URL redirects, the seeded
  content-policy page, and the `legacy_bcrypt` column that carries 3.5.7
  password hashes across an import.
- Member accounts, passwords, and sessions survive an upgrade; nobody
  needs to reset anything or log in twice.
- After the swap, run `php bin/kip pages:prune` and
  `php bin/kip pages:build` if pages look stale (the browser and CLI
  routes handle their own swap, but a manual rebuild is always safe).

## If an upgrade fails

The coordinator restores the pre-upgrade snapshots without booting either
release, so a failed upgrade leaves the archive running the old version
with its data untouched; the failure detail lands in the log. Verify the
result with `php bin/kip doctor`, fix the cause it names, and run the
upgrade again.

For deeper recovery: restore `data.sqlite` and its sidecars from the
backups directory (README's backups section has the layout), and
`php bin/kip rollback` reverts the most recent migration step when a
migration specifically is the problem. There is no published
cross-release downgrade: pick the restore-from-snapshot path instead and
report the failure so the next release fixes it forward.

## After every upgrade

    php bin/kip doctor
    php bin/kip version

Doctor validates the environment (PHP, `pdo_sqlite`, FTS5, writables,
migrations) with a fix per failure; `version` prints what you are now
running. CHANGELOG.md is the narrative of what changed between what you
had and what you have.
