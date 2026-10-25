# Changelog

All notable changes to Kiption are documented here. Formats follow Keep a Changelog; versions are semver. Entries arrive as `changelog.d/<slug>.md` fragments and are combined into dated sections at release time by `bin/release`; this initial Unreleased section is the one hand-written exception (it predates fragment adoption).

## [Unreleased]

## [0.3.0] - 2026-10-25

### Added
- eFiction 3.5.7 imports: bcrypt password hashes are carried in `users.legacy_bcrypt`, verified at first login (rehashed, both legacy columns cleared), alongside the existing MD5 path. Version coverage widens to every importable release: 3.0-3.4.x schemas export with their gaps recorded in the manifest's `schema_gaps` and normalized, pre-3.0 archives are refused with the upgrade-chain runbook (the maintained fork's install/upgrade11.php / upgrade20.php) instead of a broken bundle, and any future official 3.5.x release imports with no upper gate (unknown extra columns ride through; hash detection covers the whole `password_hash()` family). The manifest records the source version and a password-format census; unknown formats are counted and imported as reset-required.
- import:fictioneer, a WordPress/Fictioneer WXR importer (streaming reader with hostile-file bounds, era-tolerant row mapper covering every 5.x schema, channel-author and declared-extension handling, WordPress-era HTML and shortcode conversion on the way in, categories resuming by slug, a legacy-URL 301 map consulted after 404s, and a verify block reporting adoption and gaps; dry-run by default, --commit writes).
- a config-driven footer legal line (`footer_copyright`, `footer_copyright_url`, `footer_links` in config.php; `KIP_FOOTER_COPYRIGHT`, `KIP_FOOTER_COPYRIGHT_URL`, `KIP_FOOTER_LINKS` as JSON overrides): a copyright holder and legal links render as one `foot-legal` paragraph after the foot-nav, absent when unconfigured, escaped, identical bytes for every visitor. Only https and site-relative URLs link (the nav stance); `javascript:`, `http:`, and `//host` values render their labels as plain text. The year is the render date, so a cached page carries its build year until the next build or purge. README's Operating the archive section documents the keys with the flagship example values.
- the logomark: the K in the brand serif as favicon.svg (carrying the site's own font stack), a 16/32/48 favicon.ico raster of Iowan Old Style, and a full-bleed 180x180 apple-touch-icon; every layout links all three.
- an email daily-send cap (mail_daily_cap / KIP_MAIL_DAILY_CAP, 0 = off): non-critical mail pauses as the cap approaches with a log line, verification and password-reset mail always send (App\MailGuard).
- a per-page noindex flag (admin checkbox on the page form, pages.noindex via migration 045): a flagged page carries the meta tag and X-Robots-Tag on both its /page/view and top-level marketing spelling and leaves the sitemap and the static-cache build.
- a post-password gate on stories and chapters (per-work access password; a locked work stays listed with title and summary while the prose is withheld, unlock markers are bound to the hash, and locked works leave the sitemap).
- a config-gated signup terms line on the register form (signup_terms_url / KIP_SIGNUP_TERMS_URL): https and site-relative URLs link, any other scheme renders the label as plain text, unconfigured renders nothing.

### Changed
- every syndication feed identifies itself: each Atom feed's id and rel=self are its own address and its alternate link points at its page (profile or category) instead of the site root, RSS carries the advisory's atom:link rel=self, and the layout's autodiscovery link keeps the feed's own title instead of borrowing the page title.
- the caching contract: the syndication XML answers public max-age=300/s-maxage=3600 with 304 revalidations repeating the stored policy, page-shape 304s repeat the page bound, HEAD polls revalidate like GETs, and under Apache the docroot's real files get pinned types (image/webp, text/javascript), nosniff, per-type lifetimes (images and svg one day, unversioned assets four hours, robots and sitemaps hourly), and a refusal of direct /cache/ URLs in every spelling.

### Removed
- the /compare/* marketing pages; the platform comparison lives in docs/COMPARE.md (the Fictioneer section included) as documentation instead of routed site surface.

### Fixed
- cache-policy edges: a 304 no longer flips a stored CDN entry to no-store on first revalidation (feed or page), empty feeds pin a stable updated instant so their ETag stops churning every second, and images lost their year-long immutable lifetime in favor of one day (a moderated or deleted upload stops serving from caches within a day).
- Fictioneer import fidelity: split-file imports seed chapter positions from the database rather than the file alone, the summary fallback caps at the form's 1000 characters, adoption only adopts the same run's prior accounts, and legacy slug rows and shortlinks redirect correctly.
- the dev-server passthrough serves real files under a symlinked docroot (both sides resolved through realpath, so a /var-style symlink no longer routes assets into the application) and covers every extension the uploads allowlist accepts plus nosniff parity with the Apache path.
- robots.txt regeneration writes an absolute Sitemap URL (RFC 9309; crawlers ignore a relative one), and a build whose base_url is the unconfigured default skips sitemap and robots regeneration entirely so local runs never rewrite the committed seed.

## [0.2.0] - 2026-10-21

### Security
- admin surfaces route through one epoch-checked gate, Adminness::requireAdmin (valid session, then the role row, fail-closed 403 on any arm), replacing the twelve copies of the raw COUNT idiom; an authorized pentest pass over the result returned no exploitable findings.

### Added
- a seeded copyright and DMCA page at /dmca on every install (migration 037 backfills existing archives, INSERT OR IGNORE preserves an operator's own page): what a takedown notice must contain, counter-notice handling per 17 U.S.C. 512(g), account termination after three sustained notices, and a designated-agent contact block the operator fills in through the admin page forms.
- import:epub, a bulk EPUB importer (AO3 downloads, FanFicFare output, any EPUB library) with dry-run, author resolution, covers, and per-file idempotency.
- top-level marketing paths served from page rows (/cloud, /dmca, and /compare/ for eFiction, Wattpad, Webnovel, Royal Road, FanFiction.net, AO3, and Substack); each mapped slug carries one canonical URL, /page/view/{slug} 301-redirects to it, the sitemap advertises only the canonical spellings, and the static-cache build warms the compare paths.
- an original-work attestation on the story form (migration 039 adds the flag to existing stories and imports as unchecked): checking it states the work is entirely the author's own, and it gates the monetization surfaces.

### Changed
- the story-page support link renders only on attested original works, never on fan-work story pages; author profiles keep it for every author.

### Fixed
- the powered-by footer's default product URL is cloud.kiption.com; the kiption.cloud spelling it replaced never existed as a domain (one hosted domain, owner-confirmed).
- the release builder's staging copy carries its directory prefix, so root-relative exclusion paths (app/backups/, app/nav.json) match again; the install tree, the manifest, and the upgrade ZIP agree on one payload.

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
