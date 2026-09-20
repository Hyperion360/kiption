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
