# Changelog

All notable changes to Kiption are documented here. Formats follow Keep a Changelog; versions are semver. Entries arrive as `changelog.d/<slug>.md` fragments and are combined into dated sections at release time by `bin/release`; this initial Unreleased section is the one hand-written exception (it predates fragment adoption).

## [Unreleased]

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
