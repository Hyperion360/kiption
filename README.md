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

Demo content for trying every feature by hand:

    php bin/kip db:seed      # the small fixture the tests use (skip if already seeded)
    KIP_ENV=dev php bin/kip db:demo   # 15 stories, 14 accounts, reviews, lists, messages, a full queue

Every demo account's password is `password123`. Sign in as
`reader@demo.kiption.test` for a reader with a full library (progress,
bookmarks with notes, favorites, follows, messages, notifications),
`wrenfield@demo.kiption.test` for an author with reviews and stats,
`moderator@demo.kiption.test` or `admin@demo.kiption.test` for the
validation queue, reports, wrangling and analytics. `db:demo --force`
rebuilds only the demo rows; the test fixture is never touched. The
command runs only with `KIP_ENV=dev`, and even then refuses a database
with stories by real authors, so its known-password admin never reaches
a live site.

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
writers the only write path, and after template-only deploys). `php bin/kip
cache:clear` deletes the framework page cache alone (`app/cache.sqlite` and
its WAL sidecars): a page cached while public stays servable until its TTL
even after its route gains an auth gate, because the cache answers before
routing, so clear it on every deploy. `pages:prune` and `db:seed` already
wipe it; `cache:clear` is the scoped, deploy-time command.

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
English with the failure recorded in the error log. Members may also pick
their own language and theme on the account page; see the next section.

## Per-member language, theme, and reader preferences

Members choose their own interface language and theme on the account page.
Both are stored in `user_prefs` (`lang`, empty string = follow the archive
default; `theme`, one of `paper`, `sepia`, or `night`) and both reach the
render through cookies, so no page pays an extra query for them. The fourth
theme choice, `auto`, is cookie-level only and never a stored value: saving
it clears the theme cookie and the row keeps `paper`, so the OS
`prefers-color-scheme` decides. Archives older than the reader redesign
stored `light`/`dark`; the cookie read maps them to `paper`/`night` once,
and migration 026 rebuilt the column with that mapping (SQLite cannot
alter a CHECK constraint in place).

The cookie-sync architecture. The database row is the source of truth and
the cross-device record; the `lang` and `theme` cookies are the runtime
cache. Three write points sync both sides at once: the login (one prefs
lookup on the auth write path sets the cookies from the stored row; write
paths sit outside the page query budget), the account preferences save,
and the reader's settings POST at `/reader/settings` (the Text sheet),
which also writes the row when the poster is logged in. Logout clears
both cookies along with the session.
The render path never queries prefs: `public/index.php` applies the `lang`
cookie before routing (validated as a two-letter code; a tampered or junk
cookie degrades to the archive default, never an error page), and the
layout resolves the theme as cookie, then OS preference. Pref and cookie
cannot drift, because every write point writes both, and a login on a new
device re-syncs from the row. Switching the language back to the empty
value clears the cookie on the save response, so the very next render is
back in the archive language, not stuck in the old pack until logout.

Anonymous renders stay byte-identical by construction: a cookieless
request reads neither cookie and never queries prefs, and the static
cache's cookieless rule already keeps cookied requests off cached pages.

Right-to-left packs. A pack declares itself right-to-left with the
reserved array key `'_dir' => 'rtl'`; the layout then emits `dir="rtl"`
beside `lang="{code}"`, and screen readers pick up both attributes. No
RTL pack ships with Kiption (no translation exists to ship; installing one
is an operator content decision), but the mechanism is complete: the
account page's language select lists `App\Lang::installed()` (the
`app/lang/{code}.php` files), and the save validates the posted code
against that list or the empty follow-the-default value, answering 422
for anything else.

The stylesheet guarantee. Every shipped stylesheet is direction-agnostic:
no `margin-left`/`margin-right`, `padding-left`/`padding-right`,
`border-left`/`border-right`, or `text-align: left`/`right` anywhere,
pinned by a test in `tests/PerUserTest.php`. The layout flips natively
under `dir="rtl"`: flexbox order reverses, auto margins mirror through
their logical spellings (`margin-inline-start` and friends), and
`text-align: center` is direction-neutral. One exception, also pinned:
the reader's progress bar fill anchors its sized background physically
(CSS has no logical `background-position`), so a `dir="rtl"` rule
re-anchors it and the fill grows from the reading edge either way. A
future stylesheet change that reintroduces a physical directional
property breaks the pin before it ships a half-mirrored page.

No Accept-Language sniffing, on purpose. The archive language is a config
decision and the member language is an explicit account choice; nothing
is auto-detected from the browser (a privacy and surprise stance).

Flags. `peruserlang` gates the language select, the login's lang-cookie
sync, and the save's lang column; `perusertheme` gates the theme radios,
the login's theme sync, the save's theme column, and the settings POST's
row write-through. Both store, never delete: with a flag off the stored
preference goes inert and returns on re-enable. One documented window:
the render-time cookie seam sits above the flags database (moving it
below would put the flags DB in front of the maintenance 503, a
framework-level page that must never open it), so it consults only the
config-shipped default. A runtime flag-off therefore stops new cookie
syncs immediately (logins and saves skip them) but a browser already
carrying the cookie keeps rendering that language or theme until its
next logout or login clears or re-syncs it. `/reader/settings` keeps
writing its cookies with `perusertheme` off: the cookie path is guest
core (a cookie-set, never stored state).

Responsive reader. The reading surfaces (story page, chapter reader,
whole work, recently updated) and the site shell are redesigned around a
390/834/1440px breakpoint set: a 56px header with a bottom-sheet menu on
phones, a floating control pill on tablets, and a 1200px shell on
desktop. Every control is a link, a form, or native CSS (`:target`
sheets, `:checked` tabs, a scroll-driven progress bar where the browser
supports it); `prefers-reduced-motion` disables the one animation.
Scripting is progressive enhancement, never a requirement: pages are
fully usable without it, and one hand-written vanilla layer
(`public/assets/app.js`, loaded with `defer`, no build step or
dependency) adds keyboard shortcuts (J/K chapters, F focus, T text,
Esc), live reading position in the reader header, infinite scroll for
chapters and listings, instant preference application, and the header
theme quick toggle. Every feature module loads only when its markup
marker is present, and every behavior has a no-script path (forms,
`:target` sheets, the pager, the Text sheet's Save). Fragment renders
of chapters always answer with `X-Robots-Tag: noindex`, apply the same
gates as the chapter page (age, restricted, validation), and never
write reading progress - progress records when the chapter page itself
is read, exactly as before.

Discovery surfaces. `/browse/recent` carries the comp's chip rows:
the built-in completion/length filters plus category chips folded from
the listing's own query (one query total; a category chip narrows by a
bound EXISTS probe, and junk values render the empty state, never an
error). `/series` indexes every series with validated-story counts,
and `/browse/authors` remains the member directory; both are paged,
guest-cacheable, and purged with the content they count. The seeded
starter nav links Browse, Recent, Authors, Series, Library (operator-
managed afterwards via the nav admin). Printing any page drops the site and reader
chrome, including a sheet left open at print time.

Reader typography. The chapter reader's Text sheet saves six reading
preferences into one compact `reader` cookie: text size (16 to 24 px),
typeface (serif or sans), line spacing, paragraph style (indented or
spaced), column width, and reading mode (scroll or pages; pages mode
reflows the chapter into snapped columns with pure CSS). The cookie is
device-local by design: the per-user theme row covers theme, text
preferences never touch the database, and every value is whitelisted at
write time, so a junk cookie degrades to the defaults rather than
rewriting anything. Saving the all-default combination clears the cookie
instead of setting it: a visitor who never customizes keeps full
static-cache hits, because any cookie makes a request live. Cookieless
cached pages stay byte-stable for the same reason: the layout emits the
preferences as `data-*` attributes on `<html>` only when a non-default
cookie is present, and a cookieless render emits none. One deliberate
consequence: the `reader` cookie outlives logout (typography is a
device comfort setting, not an identity one), so a browser that ever
customized it keeps bypassing the static cache until the cookie is
cleared or reset to defaults. Every preference cookie (theme, reader,
lang, toc, age_ok) is minted through one builder that appends `Secure`
whenever the request arrived over HTTPS, mirroring the session cookie.

Bookmarks. Members bookmark the chapter they are reading from the
reader's control bar; `bookmarks` keys on (user, story, chapter), so
re-bookmark is an upsert, and each bookmark carries an optional note
(trimmed to 500 characters on the way in, escaped on the way out; the
note is scrubbed to valid UTF-8 so one garbage byte cannot null the
member's whole bookmarks blob). Deleting a chapter removes its
bookmarks in the same transaction, so rows never outlive their
chapter. Bookmarks
render in the reader's Contents sheet beside the chapter list, and both
the add and remove POSTs are token-checked for members.

Reading progress. Every member chapter read upserts
`reading_history.last_position`, the furthest-read chapter (the marker
never moves backwards; re-reading chapter 1 does not reset it). The
redesign surfaces that existing data instead of adding a table: the
story page shows a Continue reading block (chapter, percent, and minutes
left at 250 words per minute), marks the current row "You're here",
shades chapters already read, and `/browse/recent` cards carry a
Continue pill. The numbers join the page's single query as a LEFT JOIN
against the existing table; guests see none of this markup, and the
read beacon stays anonymous.

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
bio and beta-reader flag, a default listing sort, the member's own
interface language and theme (see Per-member language, theme, and reader
preferences), a
table-of-contents first reading mode (a cookie, so the bare
`/story/read/{slug}` redirect costs zero queries), and notification
toggles for reviews, replies, and favorites. Members contact each other
through an
auth-gated form (CSRF, three messages per sender per hour); the
target's email address is never rendered, only mailed to.

## Challenges, scheduled releases, round robin, and gifts

### Challenges

Any member creates challenges at `/challenges`: a title (1-120
characters), a plain-text summary, and one of three membership modes,
`open`, `moderated`, or `closed`. The creator owns the challenge, and
the edit form manages the metadata plus a prompt list (add, remove,
reorder; a prompt is 1-500 characters of plain text, visible to
everyone). There is no claiming machinery: an author writes to any
prompt and joins the resulting story, the eFiction model.

Joining is story-side. The challenge page carries a join form for
members (open and moderated challenges) that takes a story slug; the
story's author or a coauthor submits it, and the challenge owner and
admins can add any story directly. Membership decides the outcome: an
open challenge confirms the story at once, a moderated challenge holds
it as pending until the owner confirms it on the same page, and a
closed challenge accepts joins from the owner and admins only. The
owner is notified of every pending submission, and the author is
notified when their story is confirmed. Pending and unvalidated
stories never show to guests; the owner and admins see pending items
flagged for confirmation. The story's author, the challenge owner, or
an admin can pull a story back out.

Challenge pages fill the anonymous static cache, and every challenge,
prompt, or item write purges both cached surfaces (the index and the
challenge's page). An itemless challenge renders `noindex` and never
caches. The `challenges` flag gates the whole module; see the flag
table.

### Scheduled releases

The chapter form carries an optional Publish-at field (a
`datetime-local` input). Leave it empty and nothing changes. Set it
and the chapter stores invisible: it stays out of the table of
contents, listings, feeds, and the sitemap until its moment, exactly
like a queued chapter, while the author still sees it on the edit
form. The input accepts `YYYY-MM-DDTHH:MM`, an optional seconds part,
and an optional UTC offset (`Z` or `+HH:MM`); every value is
normalized to UTC and stored in one canonical form, for example
`2027-03-01T09:00:00Z`, so release ordering compares exact instants.
Scheduling overrides direct-publish rights: even a validated author's
chapter waits when a date is set.

`php bin/kip release:due` releases every due chapter: it flips the
chapter live, clears the stored date, runs the same notification
fan-out as a queue approve (follower and favoriter inboxes plus the
immediate email), and purges the story's cached pages. It is
idempotent: a second run releases nothing. The app ships no scheduler
of its own, so run the arm from cron, every five minutes say:

    */5 * * * * cd /path/to/kiption && php bin/kip release:due

The `releases` flag gates the arm, never the input: with the flag
off, authors keep scheduling chapters, the arm prints `releases
feature is disabled` and exits 0, and nothing auto-releases.

### Round robin

The story form's Round-robin checkbox opens a story to the crowd:
while the `roundrobin` flag is on, any full member (approved,
verified, not locked) gains add-chapter access to that story, not just
the author, coauthors, and admins. The scope is add-only: contributors
write chapters, they do not gain the story edit form, metadata
changes, or coauthor management, and their chapters land in the
validation queue like any member chapter. Contributors reach the form
directly at `/chapter/new/{slug}`; the Add-chapter link on the story
form belongs to the owner surface. Turning the flag off closes the
expansion on the next request; chapters already contributed stay.

### Gifts

The story form's Gift-to field (120 characters, plain text) renders
one line under the byline: `A gift for {name}`. It is display
metadata only: no linking, no exchange machinery, no anonymous or
reveal states. Leave it empty and the line does not render.

## Private messages, author mute, and tag wrangling

### Private messages

Members write each other at `/messages` (the layout carries the link
beside Notifications). A conversation is a two-member thread: compose
at `/messages/new/{slug}`, read it at `/messages/view/{slug}`, and the
inbox folds each conversation into one row with the partner link, the
latest message as a preview, and an unread badge. Opening the thread
marks it read and clears the badge; the newest 200 messages render,
oldest first. Bodies are markdown at rest exactly like reviews, 1 to
5000 characters, and raw HTML cannot be stored. Every send drops one
`pm` notification in the recipient's inbox, `{actor} sent you a
message`, linking straight to the thread.

PMs are unthrottled on purpose: a rate limit taxes normal
back-and-forth conversation, and occasional first contact already has
the contact form (three per hour, mailed, never stored). Abuse rides
the existing moderation paths: the report queue covers stories and
reviews, the sender's account is lockable through the admin panel,
and the body pipeline blocks raw HTML by construction. Targets
resolve by profile slug; a self-send or an unknown slug 404s. The
`pms` flag gates the whole surface, inbox, threads, compose form, and
send POST alike: off answers 404 and the layout link hides.

### Author mute

The Mute button on profiles and directory rows adds an author to a
per-member mute list, managed under "Authors you mute" on the account
page. Muting is silent: the muted author receives no notification and
no indicator, ever. The list is private to the muter and unlimited,
and the toggle is idempotent.

Mute is a curation filter on listings, nothing more. It affects:

- `/browse` listings (recent, category, language) for the muter only
- `/search` results for the muter only
- the story lists inside challenge and series pages, same rule

It never affects:

- direct URLs: a story or author profile the muter visits explicitly
  always renders, and profile tabs stay intact
- the Atom and RSS feeds, the toplists hub, and the home featured
  list, which are anonymous surfaces
- exports and downloads: a downloaded work is a direct access
- the member directory: it lists authors, not stories, and it is
  where the mute button rides

Anonymous renders are byte-identical whether or not mute rows exist:
the filter clause is viewer-conditional by construction, so the
static cache and every cookieless path never filter and never
personalize. The `mute` flag gates the buttons and the filtering
together: with the flag off the toggle POSTs 404, the profile and
directory buttons and the account block hide, and listings stop
filtering for everyone, planted mute rows included.

### Tag wrangling

Tags are taxonomy. The story form carries tag checkboxes grouped by
type, and the story page renders the selected tags as inert badges
(`genre: Fantasy`), each name resolved through its canonical tag.
Authors pick from existing tags; creating and reshaping tags is the
wrangler's job, not the author's.

`/wrangling` (admins only, moderators get 403) lists every tag with
its type and live story count and groups synonyms under their
canonicals. Merging is a two-step form: pick the synonym, then the
canonical (the select offers same-type, unretired tags only). One
transaction runs the quadruple:

1. Every `story_tags` row carrying the synonym is re-pointed to the
   canonical with `INSERT OR IGNORE`, so a story already carrying the
   canonical loses nothing.
2. The synonym's own rows are deleted.
3. The synonym is retired: `canonical_id` is set, the tag itself is
   never deleted, so imports and stored references still resolve.
4. Any tag whose canonical was the synonym follows it to the new
   canonical (the chain move).

Merges are idempotent: running one again lands clean. Three guards
reject a merge before the transaction opens: a self-merge, a
cross-type merge, and a merge into an already-retired canonical each
answer 422. Story-form writes normalize synonym ids to their
canonicals, and retired tags drop out of the story-form checkboxes
and the canonical selects.

Unmerge clears the retirement only. The tag becomes a selectable
canonical again, but `story_tags` rows already moved to the canonical
stay moved: a story tagged only with the synonym ended up tagged with
the canonical, which is what a merge is for. Unmerge is for mistakes
caught early, not a time machine.

One operator note: a merge rewrites `story_tags` but does not purge
cached story pages, so a guest can keep seeing the synonym label until
the cache layer refreshes. Run `php bin/kip pages:build` after a
wrangling session to rebuild the static layer on the spot. The
`wrangling` flag gates the surface, index, merge form, and both
POSTs: off answers 404.

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

Rate limiting. Every non-GET request passes a fixed-window limiter keyed
on the first URL segment and the caller IP, counted in `rate_limits`
(migration 025; expired windows prune on the same index they seek). The
shipped `config.php` values: `auth` and `report` at 10 per minute,
`messages`, `account`, `user` contact and `challenges` at 20, `images`
at 10 (per-request upload cost), the moderation surfaces (`wrangling`,
`adminstories`, `notifications`) at 60 so bulk work never trips, and
every other writable segment (news comments included; the news surface
POSTs to `/news/comment/{id}`, so its bucket key is `news`) at 30. The
limit's 429 response carries `Retry-After` with the seconds left in the
window. GET renders are never counted, so the static cache and the
one-query page budget are untouched. Every POST-bearing first segment
has a bucket; a test drives `/auth/attempt` past the shipped map, so
deleting or misspelling an entry fails the suite rather than silently
disabling its limit.

## Feature flags

Every discretionary surface is admin-togglable at `/features` (admins only;
moderators get 403). Defaults ship in `config.php` under `features` (all
on); the `feature_flags` table is the runtime surface, and the board writes
one row per flip, effective on the next request with no restart. Database
rows override the config defaults.

A disabled surface answers with the same 404 the router would return, at
the route and before any data work, and its cross-page links stop
rendering, so nothing dangles. Flags gate surfaces only, never security:
validated, deleted, restricted, and age gates run regardless of flag
state. Every toggle purges the whole static layer plus the framework page
cache and refreshes `sitemap.xml` on the spot, so no cached page outlives a
link it embeds, and the news sitemap segment drops out of the index the
moment news goes off.

| Flag | Gates | Off behavior |
|---|---|---|
| news | `/news`, item views, the post and edit forms, commenting | 404; the operator Post-news link hides |
| comments | comments on news items only | comment POST 404; the member form hides; existing comments and counts stay |
| contact | `/user/contact/{slug}` (GET and POST) | 404; the profile Contact link hides |
| stats | `/stats` | 404; the account Stats link hides |
| lists | `/lists`, public list views, all list write operations | 404; the story page Reading-lists link hides |
| search | `/search` | 404; the home page SearchAction JSON-LD suppresses |
| toplists | `/top` | 404 |
| exports | `/story/download/{slug}/{fmt}` and `/story/whole/{slug}` | 404; the story page Download and Whole-work links hide |
| feeds | `/feed`, `/rss`, `/feed/author/{slug}`, `/feed/category/{slug}` | 404; the layout autodiscovery link and the profile and category feed links hide |
| directory | `/browse/authors` and its letter pages | 404; profiles stay reachable (they are core) |
| digest | the `php bin/kip digest:send` job | prints `digest feature is disabled` and exits 0; immediate story-update emails are unaffected |
| analytics | `/analytics` | 404 |
| challenges | `/challenges`, challenge views, and all challenge write operations | 404 |
| releases | the `php bin/kip release:due` job | prints `releases feature is disabled` and exits 0; scheduling a chapter still stores, it just never auto-releases |
| roundrobin | the any-member chapter gate on round-robin stories | chapters on round-robin stories stay limited to the author, coauthors, and admins |
| pms | `/messages`, thread views, the compose form, and the send POST | 404 |
| mute | `/mute/add/{slug}` and `/mute/remove/{slug}` | 404; the profile and directory mute buttons and the account block hide, and listings stop filtering for everyone |
| wrangling | `/wrangling`, the merge form, and the merge and unmerge POSTs | 404 |
| peruserlang | the language select on the account page, the login's lang-cookie sync, and the save's lang column | the field hides and the stored preference goes inert; new logins stop syncing the lang cookie, and a browser already carrying it keeps it until the next logout or login (the documented window in Per-member language, theme, and reader preferences) |
| perusertheme | the theme radios on the account page, the login's theme sync, the save's theme column, and the settings POST's row write-through | the fields hide and the stored preference goes inert; `/reader/settings` keeps writing its cookies (guest core) and the cookie, then the OS, governs as before |

The two flags compose: `comments` is a sub-flag of `news`. News on with
comments off renders items with their existing comments and counts but no
member form, and the comment POST 404s; news off takes the whole surface,
commenting included, with it.

Never flaggable, by design. A flag is for discretionary surfaces, and
these are not discretionary:

- Reading (story and chapter pages), auth, account, profiles, series, and
  engagement (kudos, favorites, follows, reviews): core archive function.
  An archive that could turn reading off would just be in maintenance
  mode, which already exists.
- The validation queue, reports, and moderation: turning oversight off is
  not a feature.
- The read beacon: data collection, not a surface. The `stats` and
  `analytics` flags gate the views; the beacon keeps counting either way.
- Custom pages, images, and member notifications: content and core
  plumbing that other surfaces embed.
- Maintenance mode, `/features` itself, and the admin panel: the operator
  levers. Flagging them off would lock the operator out mid-operation.
- SEO furniture (sitemaps, robots.txt, canonicals) stays always-on.

Data collection stance: flags gate surfaces, never collection. The beacon
counts reads into `page_stats` regardless of any flag state, the table
stays aggregate-only (day-keyed counts, no identities), and it is pruned
with `php bin/kip logs:prune`. Turning `stats` or `analytics` off hides
the dashboards, not the counting.

Operator notes:

- To toggle flags while maintenance mode is on, put `'/features/'` in
  `maintenance_allow` in `config.php`; otherwise the maintenance 503
  intercepts the board itself.
- `php bin/kip mail:users` is flag-agnostic by design: batch announcements
  are an operator channel, and recipient eligibility (approved, verified,
  unlocked, carrying a penname) never depended on a feature flag.
- Nav menu links (`/nav`) are flag-agnostic too: the menu is curated by
  hand, so after disabling a surface, remove or repoint its menu entry
  yourself. A stale entry leads to the honest 404, nothing worse.

## Site analytics

`/analytics` (admins only) is the whole-archive companion to the author's
own `/stats` page. It reads only already-collected aggregates: reads,
kudos, favorites, and new members by day over the last 30 days, the top
ten stories by 30-day reads plus kudos (behind the same gates as public
listings), and totals: stories, validated chapters, members, reviews,
kudos, favorites, and all-time reads. Nothing new is collected, nothing
member-identifying is shown, and the page is noindex and never cached.

Reads carry the same approximate stance everywhere (see Reading
retention): no bot filtering, aggregate day-keyed counts only, labeled as
approximate on the dashboard. Kudos, favorites, and membership counts are
exact.

The author companion `/stats` (members only, covered under Reading
retention) shows each member their own works, including restricted and
pending ones the public surfaces hide. Both pages read the same rollup
rows; there is no per-reader data to show because none is collected.

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
