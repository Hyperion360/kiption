# QA.md: the test surface handoff

Written 2026-10-25 for the QA agent running the full pipeline (`/qa-full`)
against this checkout. The last full qa-full verdict covered
`d406251..ec930bd` (feeds, caching, Apache rules, passthrough; report in
`qa-full-reports/main-2026-10-24.md`). Everything below is either newer
than that verdict or was never through this harness. Verify state before
trusting any of it: the tree moves.

## How to run

- Tests: `PATH="/opt/homebrew/bin:$PATH" vendor/bin/phpunit` (the default
  `php` is 5.6; the Homebrew one is required). Full suite baseline at
  2026-10-25: 1263 tests, 10133 assertions, green.
- Dev servers: never port 8090. Port 8093 runs the long-lived preview.
  Ad-hoc servers use 8095+. Router-mode (exercises the passthrough):
  `KIP_BASE_URL=http://127.0.0.1:<port> php -S 127.0.0.1:<port> -t public
  public/index.php` with the Homebrew php.
- The demo database in `app/data.sqlite` carries the seeded authors and
  stories (wrenfield, harrowgate, the-rabbit-hole); it is the visual-fixture
  source the README hero shot came from.

## Environment traps (read before blaming a failure)

- **A second agent session shares this working tree.** It commits to
  whatever branch HEAD names and leaves in-flight edits behind. Before
  diagnosing any suite failure: `git status --porcelain`, classify each
  modified file as committed-or-theirs, and rerun the failing test in
  isolation on a clean seed. Never `git add -A`; stage explicit paths.
  Its known-red in-flight files may need `--filter` exclusion, documented
  in the run.
- The chrome-devtools MCP browser sometimes wedges on a stale profile
  lock (`SingletonLock` in `~/.cache/chrome-devtools-mcp/chrome-profile`):
  pkill the Chrome carrying that profile, remove the lock, retry. If it
  keeps flapping, finish with curl-level checks and mark the console
  category provisional, as the 2026-10-24 run did.
- `public/robots.txt` is a tracked seed. Local `pages:build` no longer
  rewrites it (cc1521a guards the unconfigured-base case), but a build
  run with `KIP_BASE_URL` set to localhost will still rewrite it; restore
  with `git checkout -- public/robots.txt` and rerun the RobotsTest rows.

## Test surface: newer than the last qa-full verdict

Each block: what shipped, where its coverage sits, what the pipeline
should still do with it.

1. **Email quota guard** (`b0de780`, app/src/MailGuard.php,
   migration 044, config `mail_daily_cap`): pauses non-critical mail near
   a daily cap; verification and password resets always send. Covered by
   tests/MailGuardTest.php and DigestTest. Never through a QA pipeline:
   give it the security and adversarial lanes (cap bypass via clock,
   timezone, counter reset races), and a fuzz pass on the mail-sending
   endpoints under a tiny cap.
2. **Signup terms link** (`edc8605`, config `signup_terms_url`): https and
   site-relative link, other schemes render plain text. Covered by
   RegisterTermsTest. Cheap: include the register form in the browser QA
   pass with the config set and unset.
3. **Per-page noindex flag** (`f480da6`, migration 045): admin checkbox,
   meta + X-Robots-Tag on both spellings, sitemap and build exclusion.
   Covered by PageNoindexTest. Verify the static-cache interaction for
   real: `pages:build` with a flagged page must leave no cache file and no
   sitemap entry (the unit rows assert the SQL; the build path only
   through Builder's own tests).
4. **The logomark** (`7cd7da6`, `92000d0`): favicon.svg (site font stack),
   favicon.ico (Iowan Old Style raster), apple-touch-icon.png (180x180
   full-bleed). Covered by LayoutShellTest (links + containers). Not
   covered anywhere: rendering on non-Apple platforms (the SVG falls back
   through Charter to Georgia; check a Linux/Windows browser if available),
   the ICO in a tab at 16px, the touch icon on a real iOS device. Manual,
   visual, low severity.
5. **Kip v0.5.1 consumed + rate-limit collapse** (`bd19900`, `cee128c`):
   the pin moved and `config.php` rate_limit now carries 11 explicit
   budgets plus `'*'` at 30/min. RateLimitTest pins framework semantics
   with its own config; SettingsTest pins the shipped-config contract.
   Gap worth one live probe: confirm a real non-explicit surface (e.g.
   POST /kudos) actually 429s after 30 hits in a minute against the
   shipped config, and that an explicit surface (POST /auth/login) trips
   at 10, not 30.
6. **README hero image** (`6ec9b5f`, docs/reader-hero.png): pushed; verify
   it renders on the GitHub README (camo sometimes mangles large PNGs) and
   that the alt text reads sensibly.
7. **e107-eFiction 3.5.9 importer line** (`a3e5519`, the other session):
   covered by its own round-trip test. If the pipeline's diff window
   reaches it, it belongs to the importer lane (ImporterTest,
   EfictionMapperTest); hostile-bundle behavior is fuzzed there already.
8. **v0.3.0 release itself** (`4863532`, `0b615e7`): tag pushed; the
   GitHub Release object and the install/upgrade ZIPs were never built.
   Not a QA step, but `scripts/build-release.php` has not run since the
   tag: building the ZIPs once and running SharedHostReleaseTest is the
   verification that the released tree installs.

## Coverage map (where the existing pins live)

- Passthrough, file types, private names, traversal, nosniff:
  tests/FrontControllerPassthroughTest.php
- Cache policy (feed/page 304s, HEAD polls, write-path 200-only, noindex
  directive stances): tests/StaticCacheTest.php
- Apache rules as source (types, nosniff, lifetimes bound to FilesMatch
  spans, encoded /cache/ refusal, private-name cross-check):
  tests/HtaccessContractTest.php
- Feeds (identity, empty-feed stability, autodiscovery titles):
  app/Features/Feed/Tests/, tests/FeedsExpansionTest.php
- robots.txt (absolute Sitemap, seed byte identity, CLI arm wiring):
  tests/RobotsTest.php, tests/SitemapTest.php
- Router-mode integration (wire-level 304s, cache stamps through the
  stable wrapper): tests/SharedHostReleaseTest.php
- Query budget (one query per page): tests/QueryBudgetTest.php, which must stay
  green over any new page surface.
- Dev-server scaffolding shared by the suites: tests/Support/DevServerHttp.php

## Gaps only live or manual verification can close

- **The .htaccess rules have never executed.** Every Apache assertion is a
  source pin (no Apache runs in this harness). One smoke on a real Apache
  2.4 host: AddType applies (check `/uploads/x.webp` content type), the
  per-type Cache-Control lifetimes fire, nosniff is present, and direct
  `/cache/`, `/%63ache/`, and `/CACHE/` requests all 403. Until then the
  rules are verified by reflection only.
- **The rate-limit fallback against the shipped config** (live probe
  described above).
- **pages:build with a noindex-flagged page** (integration, described
  above).
- **Cross-platform favicon rendering and the iOS touch icon** (visual,
  manual).
- **The mail cap on a real SMTP transport** (the log transport is the test
  seam; a capped run against real mail pauses non-critical sends by
  design; verify the log line and that a reset mail still goes).

## Pipeline scoping notes

- Diff triggers that will fire for the v0.2.0..HEAD window: migrations
  (044, 045) route to `/db-optimize`; new endpoints and input parsing
  (terms config, noindex form field, importers) route to `/fuzz`;
  UI-bearing changes (register form, page form, icons) route to `/qa` and
  `/design-review`; prose changes route to `/humanize` (README, docs).
- `/pentest` trigger: the mail guard and the rate-limit collapse are
  auth-adjacent metering surfaces; if `/defense` rates them CRITICAL/HIGH
  the pentest lane is authorized by that finding.
- The four backlog features (data-export, per-user skins, OPDS, adult
  gate) are NOT built. Any test writing against them is testing nothing;
  they need the plan-first process, not QA.
- Owner actions deliberately outside QA: the social-preview upload, the
  v0.3.0 GitHub Release object, launch posts.
