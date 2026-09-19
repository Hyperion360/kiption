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
