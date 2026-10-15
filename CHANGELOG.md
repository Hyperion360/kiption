# Changelog

All notable changes to Kiption are documented here. Formats follow Keep a Changelog; versions are semver. Entries arrive as `changelog.d/<slug>.md` fragments and are combined into dated sections at release time by `bin/release`; this initial Unreleased section is the one hand-written exception (it predates fragment adoption).

## [Unreleased]

## [0.1.1] - 2026-10-15

### Added
- Launch: shared-host deployment, browser setup, web upgrades, and the eFiction import fidelity fixes. The release builder (scripts/build-release.php) produces a verified off-host install ZIP (site/releases/<digest>/ with a stable public wrapper, plain-text current pointer, and a site/bin/kip launcher; the checker pins the release id to the RELEASE.json hash); one install service backs both the CLI and the locked one-use browser setup (owner secret, CSRF, live WAL preflight). The web upgrade coordinator stages a digest-verified release, snapshots databases and assets under an exclusive lease, swaps assets in two journaled steps, migrates on a fresh request, and restores from snapshots without booting either release; the CLI runs the same lifecycle. The eFiction importer now maps rating-warning bits to member/age gates (imported adult stories arrive restricted), hides restricted navigation links, redirects legacy custom-page and category URLs (migration 035), and names every account a bundle's manifest promoted to admin. New: an invite-code admin surface, a settings-gated site-wide RTA label, a seeded content-policy page (migration 036 backfills existing installs), tag merges purge the static cache, and backup filenames no longer collide within a second. Security review fixes: the setup session cookie carries real Secure/HttpOnly/SameSite flags, the invites gate validates the session epoch, anonymous upgrade visits learn nothing, wrapper sessions run strict mode, the CLI launcher rechecks maintenance under the lease, upgrade failure detail goes to the log instead of the response, and the dev-server passthrough never serves PHP source. The authorized pentest pass hardened the import path against hostile bundles (member-count, decompressed-size, and row-length bounds; a bounded review scan; constant-time-uniquing loops), made the eFiction exporter's self-delete remove the exported bundle directory, sweeps orphaned upgrade work directories, and gates the wrangling and moderator surfaces on the session epoch. 1074 tests.

### Fixed
- Search: the FTS ranking statement no longer re-runs the match set once per story. The old shape (join stories, group by the ten story columns, order by rank, limit) let SQLite flip stories to the outer loop, so a query whose token matches many chapters re-executed the FTS co-routine once per grouped row: 10.0-13.1s median for a common token and 0.78s for a rare one on a 1,500-story / 6,000-chapter archive. The aggregate now folds per story in a fts_matches CTE pinned as the outer loop with CROSS JOIN; the same archive answers in 1.4-15.0ms across all search variants. Bind order, results, and the one-query budget are unchanged; a regression test pins the loop order.

## [0.1.0] - 2026-10-06

### Added
- Cache-Control decided at the static-cache seam; the one response shape the layer stores answers public with s-maxage=14400, everything else private no-store, and webserver-served cache files get the same header from an env-gated Header set in public/.htaccess
- optional CDN purge hook behind cdn.* config keys (default-off); the flag-board purge set fires it deferred, so a slow or failed purge never fails the write
- audience guides for authors, moderators, and readers under docs/guides, linked from the README
- bin/kip doctor validates the install environment (PHP, pdo_sqlite, FTS5, config sanity, writables, migrations) with a fix per failure and --json output
- bin/kip version reports the app version, the locked framework pin (git/path/tag renderings from composer.lock), and PHP; INSTALL.md documents install, permissions, web servers, SMTP, cron, first run, troubleshooting, and upgrades
- bin/publish-audit publish gate (protected paths in tree and history, secret shapes, private-cloud boundary strings incl. the hosted pipe prose stems, required docs; --json mode; exit 0 only when publishable)
- operator skins (classic, manuscript) as view-override directories under app/skins with the views_override boot seam in both entrypoints, a skin select on the /settings board that purges the static layer on save, and SKINS.md as the authoring guide
- changelog.d fragment workflow and bin/release; releases combine reviewed fragments into a dated CHANGELOG section and an annotated tag
- `bin/kip db:blank`: fresh-install bootstrap data (ratings ladder, starter tags, one category, five-link nav) without demo content.
- Powered-by footer behind the `powered_by` config switch.
- Full archive feature set through milestone 14 (see README for the tour); eFiction 3.5.5 migration path (exporter, importer, legacy redirects).
