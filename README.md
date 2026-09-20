# Kiption

Self-hosted fiction archive built on [Kip](https://github.com/Hyperion360/kip),
the batteries-included, zero-dependency PHP framework. Working title; the
product name is not final.

Status: bootstrap milestone. The schema is complete; reading, authoring,
validation queues, and the static page cache arrive in the next milestones.

## Requirements

- PHP 8.3+ with the pdo_sqlite extension
- Composer
- Network access to fetch `kip/framework` from its GitHub repository
  (during dual-repo framework development, a temporary local path
  repository pointing at `../MVC-Lite` may be substituted; do not commit it)

## Run it

    composer install
    php bin/kip migrate
    php bin/kip serve

Tests:

    vendor/bin/phpunit

Maintenance mode: `touch app/maintenance.lock` enables it (effective on
the next request, no restart), `rm app/maintenance.lock` disables it. The
`KIP_MAINTENANCE=1` environment variable also enables it for deploy-time
use. `KIP_ENV=dev` bypasses the guard for the developer; note `php bin/kip
serve` runs in dev mode, so maintenance is observed via a plain
`php -S` server as shown in the plan's smoke test.

## Static page cache

Anonymous guests are served pre-rendered HTML from `public/cache/` before PHP
boots. The layer fills itself on first visit; `php bin/kip pages:build`
pre-renders everything (run it after deploys and imports); `php bin/kip
pages:prune` wipes it plus the framework page cache (run it after editing
stories through the built-in admin panel until Plan 5 makes the app's own
writers the only write path, and after template-only deploys).

Serving the layer without PHP at the webserver level is OPTIONAL and
subtle. The PHP fallback in `public/index.php` is the supported path and is
always correct on its own.

    # Apache 2.4, in .htaccess: GET, cookieless, queryless requests only.
    # All three conditions are load-bearing: dropping the cookie condition
    # traps consented readers on the cached age-gate page forever; dropping
    # the query condition breaks pagination; and during maintenance the
    # webserver serves cached pages without running PHP, so an outage
    # requires removing the cache directory (or disabling this rule).
    RewriteCond %{REQUEST_METHOD} ^GET$ [NC]
    RewriteCond %{QUERY_STRING} ^$
    RewriteCond %{HTTP_COOKIE} ^$
    RewriteCond %{DOCUMENT_ROOT}/cache%{REQUEST_URI}/index.html -f
    RewriteRule ^ cache%{REQUEST_URI}/index.html [L]

nginx deliberately has NO snippet here: `try_files` cannot express the
cookie and query conditions, and the map- or internal-location workarounds
have not been validated on a real server. A tested webserver cookbook
(nginx included) is a tracked TODO; until then, nginx users get the PHP
fallback, which is fully correct.

## SEO

Every page ships a unique title with the site name, a meta description
(stories use the summary unless `meta_description` is set), Open Graph and
Twitter tags, a self-canonical URL, and JSON-LD (`WebSite` on home, `Book`
on stories, `BreadcrumbList` on categories, `ItemList` on listings). The
Atom feed is at `/feed` with an RSS2 alias at `/rss` (autodiscovery is
built in). Empty category listings are `noindex` and never cached. Crawlers
are kept off faceted query-string permutations via `robots.txt`.

## Search and toplists

`/search` runs full-text search across story titles, summaries, and chapter
text, powered by SQLite FTS5 (porter stemming) whose sync triggers keep the
index current on every story and chapter write; the migration backfill indexes
existing content once. On builds whose SQLite lacks FTS5 (common on shared
hosting) the migration completes as a no-op and search automatically falls
back to an escaped LIKE query with the same gates and filters: category,
rating, completed only, and language, ranked by relevance (recency in the
fallback), paginated. Restricted works appear for members only; unvalidated
and deleted works never appear. The query is sanitized (tokens quoted, capped
at eight; `%` and `_` escaped in the fallback), and the surface renders
`noindex` plus an `X-Robots-Tag` header since query permutations are not
canonical content. The home page's `SearchAction` JSON-LD now has a resolving
target at `/search?q=`.

`/top` lists the archive leaders on one page: most favorited, most kudos,
most reviewed (root reviews only), and top rated (three ratings minimum,
showing the average and count). It renders from a single query, fills the
anonymous static cache like the other whitelisted pages, and every
engagement write (kudos, favorites, reviews) purges it automatically.

## Accounts and writing

Registration supports four modes (`registration_mode` in `config.php`):
`open` (immediate), `verify` (email link, 24h; an expired link clears the
never-activated account), `approval` (an admin approves in the validation
queue), and `invite` (codes created in the admin panel, consumed once).
Pennames are 3-30 characters, lowercase. Chapter text and author notes are
markdown: *italic*, **bold**, `---` scene breaks, quotes, and https links;
raw HTML cannot be stored. Members post through the validation queue
(`validation_required`); `validated_author` and above publish directly.
Story and chapter edits are transactional and purge the affected static
pages. The queue at `/queue` (moderator and admin roles) approves or
removes pending stories, chapters, and member approvals.

## Engagement

Readers can leave kudos (once per story; guests keyed by IP), favorite
stories to a shelf, follow authors with per-follow notification modes
(site inbox, immediate email, digest), track reading progress with a
continue-reading list, and mark stories for later. Every engagement
event lands in the notification inbox at `/notifications`. Counts on
cached pages snapshot at fill time; per-reader state renders only on
the dynamic (cookie-carrying) path, so the static layer never serves
personal variants.

## Reviews and moderation

Members and guests review stories: markdown bodies with an optional
0-10 rating (clamped server side), guests supply a name and are
throttled to one review per story per day. Members can reply, and
replies thread one level under the root review with the story's author
marked. Members report stories and reviews; open reports land in the
moderation queue at `/queue` (moderator and admin roles) alongside the
validation work, where a moderator resolves or dismisses them. Authors
can mark a work restricted to registered readers: guests get a 404
before any content renders, so restricted pages never enter the static
cache and are skipped by `pages:build`. Stories carry a language tag
(filterable on `/browse`), an optional cover upload, and authors can
set a support link shown on their story pages. Members whose follows
are in digest mode (or who opted into favorite digests) receive one
batched email; run `php bin/kip digest:send` from cron (say, hourly)
to flush them. The app ships no scheduler of its own.

## Series, coauthors, and members

Authors assemble their works into series (`open`, `moderated`, or
`closed` membership): anyone's story joins an open series at once,
moderated series hold submissions as pending until the owner confirms,
and closed series accept additions from the owner only. Owners reorder
items with up/down swaps, and a story's author may pull their own work
out of any series. Story owners add coauthors by penname; coauthors
gain full authoring rights (story and chapter forms) and share the
byline, may leave at any time, and can be removed by the owner or an
admin. Reviews, kudos, and favorites notify the author and every
coauthor, each gated by that recipient's own notify preference.

Every member gets a public profile at `/user/view/{slug}` (bio in
markdown, avatar, support link, story and series counts) with stories
and favorites tabs, and a member directory at `/browse/authors` with
letter filters and a beta-reader filter. Account preferences cover the
bio and beta-reader flag, a default listing sort, a table-of-contents
first reading mode (a cookie, so the bare `/story/read/{slug}`
redirect costs zero queries), and notification toggles for reviews,
replies, and favorites. Members contact each other through an
auth-gated form (CSRF, three messages per sender per hour); the
target's email address is never rendered, only mailed to.

## Migrating from eFiction

Moving an archive off a live eFiction 3.5.5 install is a two-stage
runbook: export a bundle from the old install, then import it here.
Steps 1-3 run on the old install, steps 4-7 on the new one.

1. On the new archive, run `php bin/kip import:token`. It prints a
   one-time token plus the exact one-line contents for a file named
   `export-token.php`.
2. Upload `resources/efiction-export.php` from this repository to the
   old install's webroot (it needs PHP 8.0+ with the zlib and phar
   extensions), and create `export-token.php` beside it containing the
   printed line.
3. Open `efiction-export.php` in the browser and paste the token. Put
   the old archive in maintenance mode (Admin > Settings) first so the
   snapshot is consistent, or tick the force box to accept the risk,
   then run the export and download `kiption-export.tar.gz`.

Treat the downloaded bundle as a password file: it contains member
email addresses and legacy password hashes. When you are done, use the
exporter page's self-delete button (or delete by hand) to remove both
`efiction-export.php` and `export-token.php` from the old webroot.

4. On the new archive (after `php bin/kip migrate`), dry-run the
   import and read the report it prints:
   `php bin/kip import:efiction kiption-export.tar.gz --dry-run`.
   Nothing is written; the counts are real. Read it end to end:
   per-table counts, rejects with reasons, dropped features, missing
   chapter files, and extracted author responses. Chapters
   whose files are missing from the bundle reject by default; pass
   `--allow-missing-text` to import visible placeholder chapters
   instead.
5. If the samples show substituted characters or garbled text, the
   charset heuristic guessed wrong: pass `--encoding=latin1` (or
   `--encoding=utf8`) and repeat the dry run until the samples read
   clean. Both dry-runs and commits under the same options are one
   family; the importer refuses to mix families.
6. Commit: `php bin/kip import:efiction kiption-export.tar.gz
   --commit`. A `pre-import-*.sqlite` snapshot of the database is
   written next to the bundle before anything else happens, then the
   whole import lands in one transaction. The command prints the
   report again plus a verification section (per-table manifest
   versus imported counts, and wordcount drift), writes the legacy
   301 map, and runs pages:build so the static cache comes up warm.
   An interrupted or partially failed commit can simply be re-run:
   already-imported rows are recognized and skipped.
7. Cutover checklist: spot-check story, profile, and series pages
   against the old archive (titles, chapter text, author notes,
   reviews); log in once with an old eFiction password (it works, is
   immediately rehashed the modern way, clears the legacy hash, and
   the member gets a short heads-up email); test one legacy link such
   as `viewstory.php?sid=<a real id>` and confirm the 301; swap DNS
   or the docroot to the new install; decommission the old install
   once you are confident, and delete the bundle (it is a password
   file for as long as it exists).

Rollback: put the site in maintenance mode, copy the
`pre-import-*.sqlite` snapshot over the database file, run
`php bin/kip pages:prune`, and lift maintenance.

What lands where: authors become members with pennames, bios, and
notification preferences (validated authors keep direct publishing;
legacy read counts import as page-stats baselines); categories,
classes (as tags), characters, and ratings import as taxonomy;
stories, chapters, series and their items, coauthors, reviews (with
author response blocks split out), favorites, news, and the old
moderation log all import. Chapter and note HTML is converted to the
markdown subset and word counts are recomputed. Dropped features are
counted in the report rather than silently lost: custom profile
fields beyond bio, news author name strings, series challenges, and
pagelinks and mail templates (both stay in the bundle for a later
phase). Legacy URL redirects (301, GET requests only) cover
`viewstory.php?sid=`, `viewuser.php?uid=`, `viewseries.php?seriesid=`,
`browse.php?catid=`, and `reviews.php?type=ST&item=` (also `type=SE`
for series); `browse.php` links that use `id=` and `type=categories`
instead of `catid=` do not redirect.

Admin roles: the importer upgrades members listed in the old
install's admins setting, which the exporter only began including
when the `admins` manifest key was added. Bundles exported by older
copies of `efiction-export.php` import zero admins (the report line
`admins upgraded from the manifest CSV: 0` says so); promote a member
through the built-in admin panel afterwards, or create a fresh
administrator with `php bin/kip user:create <email> [password]
--admin`.
