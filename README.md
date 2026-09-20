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
    php bin/kip user:create you@example.com 'a strong password' --admin
    php bin/kip serve

The `user:create` line creates your admin account; run it without a password
argument to be prompted with hidden input instead.

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

## Languages (interface packs)

Every interface string renders through a zero-dependency language-pack
layer: plain PHP arrays, no gettext and no intl extension. `app/lang/en.php`
is the base inventory, a flat `'dotted.key' => 'Text'` map with keys
namespaced per surface (`nav.*`, `auth.*`, `story.*`, `account.*`, and so
on). To translate the interface, author a pack such as `app/lang/es.php`
returning the same flat shape:

    <?php // app/lang/es.php
    return [
        'nav.login' => 'Iniciar sesion',
        'nav.browse' => 'Explorar',
    ];

A pack may override any subset of keys: it merges over English, and keys
the pack leaves out fall back to the English text. A key missing from both
renders the key itself, so a bad key is visible on the page, never fatal.
Placeholders interpolate `{param}` tokens, for example
`'story.chapter_of' => 'Chapter {n} of {m}'`.

Enable a language site-wide with `ui_lang` in `config.php` (a two-letter
code) or the `KIP_UI_LANG` environment variable. Packs under `app/lang/`
are discovered by filename (`{code}.php`); a third-party pack living
elsewhere registers itself with `App\Lang::addPackPath('xx',
'/path/to/xx.php')` from its own bootstrap, ahead of autodiscovery. Pack
files are code and run at load, so deploy only packs you trust (the same
trust level as `config.php`); a pack that fails to load degrades to
English with the failure recorded in the error log. Per-member language
choice and right-to-left layouts are future work; today the language is
site-wide.

## SEO

Every page ships a unique title with the site name, a meta description
(stories use the summary unless `meta_description` is set), Open Graph and
Twitter tags, a self-canonical URL, and JSON-LD (`WebSite` on home, `Book`
on stories, `BreadcrumbList` on categories, `ItemList` on listings).
Profiles carry h-card and chapter reads carry h-entry microformats. Empty
category listings are `noindex` and never cached. Crawlers are kept off
faceted query-string permutations via `robots.txt`.

### Feeds

The site feed is Atom at `/feed` with an RSS2 alias at `/rss`, plus
per-author feeds at `/feed/author/{slug}` and per-category feeds at
`/feed/category/{slug}`; the profile and category pages carry their own
autodiscovery links alongside the layout-wide one. Each feed lists the
twenty most recently updated eligible stories in a single query. An
unknown, locked, or penname-less author 404s exactly like the profile
page would; a known author with no eligible stories still gets a valid
empty feed titled with the penname, and an empty category likewise
renders an empty feed. Restricted, unvalidated, and deleted stories
never appear. Full-text mode (`feeds_full_text => true` in `config.php`,
or `KIP_FEEDS_FULL_TEXT=1`) makes every entry carry its first validated
chapter, rendered from markdown into `<content type="html">`, instead of
the summary-only default. External-canonical stories (below) never
appear in any feed.

### Story syndication

The story form exposes two optional URL fields (Canonical URL and
Cross-posted from; both must start with `http://` or `https://`, are
capped at 200 characters, and only one may be set at a time), giving
each story one of three syndication states:

- Self (both empty, the default): the canonical URL points at this
  archive's own page.
- External original (`canonical_url` set): the story is a mirror whose
  original lives elsewhere. The local page points its canonical at the
  author's URL and deindexes itself (a `noindex` meta plus an
  `X-Robots-Tag` header, on the story page and every chapter read), and
  the story leaves the feeds and the sitemap, so the original can outrank
  the mirror.
- Cross-post (`crosspost_url` set): first published elsewhere, hosted
  here by arrangement. The canonical link is suppressed (Open Graph's
  `og:url` keeps the local URL) and readers see a "Cross-posted from the
  original" note whose outbound link carries `rel="nofollow"`.

### Sitemaps and robots.txt

`/sitemap.xml` cannot route through the convention router (dot-paths are
files, not routes), so the sitemap and `robots.txt` are generated real
files in `public/`, rebuilt at the end of every `php bin/kip pages:build`
(the eFiction import runs it too) and refreshed on demand with
`php bin/kip robots`. The index `sitemap.xml` lists one segment file per
non-empty group: `sitemap-stories-{n}.xml` (each story's view URL plus
every validated chapter read URL, split at 45,000 URLs per segment,
lastmod from the story's `updated_at`), and the authors, categories,
series, pages, and news segments. Inclusion gates mirror the static
cache builder: restricted, unvalidated, and deleted stories stay out,
pages with empty bodies stay out, and external-canonical stories stay
out. Sitemaps are batch artifacts and do not refresh on every write, so
run `pages:build` after bulk imports; search engines tolerate lastmod
staleness. The generated `robots.txt` keeps the hand-written file's
root-relative `Sitemap: /sitemap.xml` line for byte-compatibility; major
crawlers resolve it fine against the host root.

### AI crawler toggle

`ai_crawlers => false` in `config.php` (or `KIP_AI_CRAWLERS=0`) prepends
an RFC 9309 group to `robots.txt` that disallows the named AI crawlers:
GPTBot, CCBot, ClaudeBot, anthropic-ai, and Google-Extended. Apply it
with `php bin/kip robots` (which regenerates `robots.txt` alone and
prints the path it wrote) or let the next `pages:build` carry it. The
permissive output stays byte-identical to the classic file. Note that
robots.txt is a request, not a technical block: a crawler that ignores
it will still fetch.

### Content policy template

The AI toggle decides what your server allows. A public policy page
decides what your community expects. Create a custom page at `/page` and
paste this markdown as a starting point; the bracketed options are yours
to edit or delete.

```markdown
# [Archive name] content policy

## Stance on AI-generated content

[Choose and edit one:]

- **Prohibited.** Works generated in whole or in part by large-language
  models or other generative tools may not be posted. Authors are
  responsible for what they submit; a first violation removes the work,
  a second removes posting rights.
- **Permitted with labeling.** AI-assisted works are welcome but must be
  marked as such in the summary so readers can filter them.
- **Permitted.** We do not screen for generative tooling; the usual
  content rules still apply.

## Stance on AI crawlers

[Edit to match your `ai_crawlers` setting:]

- We [allow / disallow] AI training crawlers (GPTBot, CCBot, ClaudeBot,
  anthropic-ai, Google-Extended) via robots.txt. Using works hosted here
  to train generative models [is / is not] consented to by this archive.
  Authors who want a specific work excluded from any dataset should
  [contact the admins]; we honor takedown requests at [contact address].

## Stance on fundraiser and support links

Authors may set one support link on their profile and story pages (tip
platforms, project pages). [Choose and edit:]

- Any personal fundraising platform is fine.
- Only links funding the author's own creative work; charity drives and
  third-party fundraisers are not.
- Links to the author's own work only; no commercial advertising.

The archive hosts these links as a courtesy and does not endorse,
process, or guarantee any fundraiser. Report abuse to [contact address].
```

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
most reviewed (root reviews only), top rated (three ratings minimum,
showing the average and count), and Trending over the last 7 days (see
Reading retention for its approximate-reads and staleness semantics). It
renders from a single query, fills the anonymous static cache like the
other whitelisted pages, and every engagement write (kudos, favorites,
reviews) purges it automatically.

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

## Reading retention

The read beacon. Every chapter page, including statically cached copies,
embeds a 1x1 image at `/beacon/read/{story}/{chapter}`. Loading the page
counts one read into `page_stats`, a day-keyed aggregate table (a row per
chapter plus a story-level rollup). Reads are approximate and labeled as
such: no bot filtering, and nothing about the reader is ever recorded,
no account, no IP, no referrer. Restricted stories count too; the counts
surface only on the author's own dashboard, which the author already
gates. `php bin/kip logs:prune --days=N` prunes old `page_stats` days
alongside the request log.

Trending. `/top` carries a fifth section, Trending (last 7 days): reads
plus kudos inside the window, top ten. It is batch-stale like the
sitemaps: beacon hits never refresh it, while kudos, favorite, and
review writes do (they already purge the hub), and so does
`php bin/kip pages:build`. The section renders its own staleness note.

Author stats. `/stats` (members only) lists your own stories with total
reads, 30-day reads, kudos, and favorites. The scope is everything you
author or coauthor that is not deleted, so your restricted and pending
works appear here even though public surfaces hide them. All counts come
from the rollup rows, aggregate only; there is no per-reader data to
show because none is collected.

Whole-work reading and printing. `/story/whole/{slug}` renders every
validated chapter on one page behind the same gates as a chapter read
(the age cookie for adult works, a login for restricted ones). It is
noindex (chapters are the canonical units), never enters the static
cache (whole works are large), and records no reading progress. The page
doubles as the print view: a print-only stylesheet hides the site chrome
and breaks the page after the table of contents, so printing is simply
the browser print command, no JavaScript anywhere.

Downloads. Every story page offers two exports behind the identical
gates: a standalone HTML document that renders offline (its single
outbound link is the "exported from" attribution), and an EPUB assembled
by a pure-PHP zip writer, no zip extension or third-party tool needed,
with `mimetype` stored first per the EPUB rule, the cover image when one
exists, and chapter prose rendered through the same markdown pipeline.
Exports are rate-unlimited; a download is a read with the same gates.

Reading lists. Members curate lists at `/lists`: create, edit, add any
validated story by slug, reorder, remove. Lists are private by default;
only ticking the public box makes the list page (`/lists/view/{slug}`)
visible to guests and anonymously cacheable. A listed restricted story
renders for the owner and members but never for guests on a public
list, and flipping a listed story restricted or deleting it purges every
public list containing it, so no stale guest copy survives.

## Operating the archive

The built-in panel at `/admin` edits database tables generically. It is a
power tool: it knows nothing about the app's invariants, so some rows are
dangerous to touch raw. Kiption ships targeted surfaces for exactly those
rows; use them first and the panel for everything else.

Member roles are the clearest case. The `users` table carries a `role`
string and an `is_admin` flag that must always agree (`role = 'admin'`
exactly when the flag is 1); every permission check leans on that
agreement, and editing either column raw in the panel can desync them.
`/adminmembers` (admin only) lists members in a searchable, paged table
with a role selector, writes through the one code path that keeps the
pair consistent, and rejects unknown roles with a 422 instead of a
crash. Moderators get no access: only admins change roles, so a
moderator can never mint an admin.

Story tools live at `/adminstories` (admin only): reassigning a story to
another author by penname (the move also cleans up both authors'
coauthor rows and purges every cached page it touches) and toggling the
featured flag that puts the story in the home page's Featured list.

News and pages: admins post and edit news at `/news` (markdown bodies;
members comment, throttled to one comment per member per item per hour)
and custom pages at `/page`, served at `/page/view/{slug}` with markdown
bodies. Nav links (`/nav`, admin only) are the menu entries that point
at pages or any other internal path; every write rebuilds a small JSON
artifact the layout reads, so no page pays a database query to render
the menu.

Mail: the transactional mails (verification, password reset, coauthor
invites, member contact, story updates, digests) render from editable
templates, and names you have not edited fall back to the built-in
literals, so mail works before you touch anything. Batch announcements
go through the CLI: `php bin/kip mail:users "Subject" "Body" --dry-run`
prints the eligible member count and the first five addresses (eligible
means approved, verified, unlocked, and carrying a penname); `--commit`
sends, and `--template=<name>` uses a saved template instead of the
literal pair.

Images (`/images`, admin only): upload PNG, JPG, WEBP, or GIF up to
2 MiB into the shared uploads directory, browse the library with sizes
and totals, and delete what nothing references; a file still used as an
avatar or cover refuses deletion with a 409.

Backups: `php bin/kip backup` snapshots every SQLite database online
(VACUUM INTO, safe while the archive serves traffic) into a dated zip
in `app/backups/` holding `data.sqlite`, `logs.sqlite`, and
`cache.sqlite`; restoring is unzipping the files back into place. Each
run prunes archives older than `backups.keep_days` (14 by default), so
it is safe in cron, and the `KIP_BACKUP_DIR` environment variable
redirects the archive directory. The archive name has one-second
resolution: two runs in the same second overwrite the same zip.

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
5. If the report counts substituted characters (the samples section
   lists the affected text), the charset heuristic guessed wrong: pass
   `--encoding=latin1` (or `--encoding=utf8`) and repeat the dry run
   until the substituted-chars count reads zero and the samples read
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
author response blocks split out), favorites, news, the old
moderation log, and custom pages all import. Chapter and note HTML is
converted to the markdown subset and word counts are recomputed.
Custom pages (eFiction custpages) land as markdown pages and their
menu links as nav links, with the nav rebuilt on commit; re-running
the import never duplicates either. Note that eFiction menu links
could be marked members-only (their `link_access` flag), and Kiption
pages are public, so every custpage link imports visible: after the
import, review the nav at `/nav` and hide anything that was meant to
stay off the public menu. Dropped features are
counted in the report rather than silently lost: custom profile
fields beyond bio, news author name strings, series challenges, and
the old install's mail texts (Kiption ships its own editable template
set instead). Legacy URL redirects (301, GET requests only) cover
`viewstory.php?sid=`, `viewuser.php?uid=`, `viewseries.php?seriesid=`,
`browse.php?catid=`, and `reviews.php?type=ST&item=` (also `type=SE`
for series); `browse.php` links that use `id=` and `type=categories`
instead of `catid=` do not redirect, and old `viewpage.php?page=`
custpage URLs 404 rather than redirect (the pages live at
`/page/view/{slug}` now; a redirect rider is a recorded future
option).

Admin roles: the importer upgrades members listed in the old
install's admins setting, which the exporter only began including
when the `admins` manifest key was added. Bundles exported by older
copies of `efiction-export.php` import zero admins (the report line
`admins upgraded from the manifest CSV: 0` says so); promote a member
through the built-in admin panel afterwards, or create a fresh
administrator with `php bin/kip user:create <email> [password]
--admin`.
