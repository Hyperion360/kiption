# Why Kiption

Every claim below maps to a shipped, tested feature. Nothing here is a
roadmap item. Where a complaint on another platform has no Kiption answer
yet, it says so.

## The value proposition

Your archive is one SQLite file on a server you control. There are no ads,
no paid unlocks, and no landlord. You can export any story as EPUB or HTML,
import an entire eFiction archive in one command, and back the whole site
up with `bin/kip backup`.

Every platform section below is the same five promises in that platform's
dialect:

1. **You own the whole thing.** The archive is a file you can copy. Any
   story exports as EPUB or HTML. Text is markdown at rest, so no format
   owns you. MIT licensed, so no license owns you either.
2. **It is fast on purpose.** Every page renders in one database query,
   enforced by an automated test. Anonymous pages come from a static cache.
   Search runs on SQLite FTS5 in single-digit milliseconds.
3. **No landlord.** No ads, no coins, no platform fees, no algorithm
   rationing reach. The only money path is the author's own support link.
4. **Moderation is local.** The validation queue, the restricted-works
   gate, and the content-policy page belong to the operator, not to a
   distant trust-and-safety queue.
5. **Migration has dignity.** One command imports an eFiction archive;
   legacy passwords keep working; old URLs 301 to their new addresses.

## Coming from eFiction

eFiction 3.5 has been unmaintained since 2018 and throws fatal errors on
current PHP. Kiption covers its feature set and fixes what drove people
away from it:

- **One-command migration.** `bin/kip import:efiction` takes an eFiction
  export bundle and brings over stories, chapters, members, categories,
  tags, series, and challenges. HTML becomes markdown on the way in.
- **Old links keep working.** Legacy eFiction URLs 301 to their Kiption
  addresses, so search engine rankings and bookmarks survive the move.
- **Members keep their passwords.** Legacy password hashes rehash on the
  first login after import; nobody resets anything.
- **Adult content arrives correctly gated.** eFiction's member-only and
  age-gated ratings map to Kiption's restriction flags on import.
- **PHP 8.3 native.** No warnings, no fatals, no compatibility shims.
- **MIT, not GPL.** eFiction is GPL; Kiption is MIT. Extend it, fork it, or
  build a service on it and no copyleft obligations follow you.

Still running eFiction and not ready to move?
[Hyperion360/efiction](https://github.com/Hyperion360/efiction) is a
maintained PHP 8.3 compatibility fork (GPL, provenance verified against
upstream) so existing archives keep running while you decide. Migrate to
Kiption whenever you are ready; the importer is the same team.

## Coming from FanFiction.net

FanFiction.net's interface has been effectively frozen for a decade, and
its moderators have purged entire content categories with no appeal. The
gripes every FFN author knows:

- **Your stories can leave.** FFN offers no export at all; writers resort
  to third-party scrapers to get their own text back. Kiption exports any
  story as EPUB or HTML from its own page.
- **Covers exist.** FFN has never allowed cover art. Kiption stories carry
  a cover image on the story page and inside the EPUB export.
- **Authors see their numbers.** FFN shows raw hit counters from another
  era. Kiption's analytics dashboard covers reads, kudos, favorites,
  follows, and reviews per story.
- **A purge cannot take the archive.** On a self-hosted Kiption the
  moderation policy is the operator's, the queue is local, and the whole
  archive is one backed-up file. A content dispute on someone else's
  platform cannot delete what you wrote.
- **Follows actually notify.** Follow an author and choose inbox,
  immediate email, or a daily digest; FFN's subscription emails are one
  setting from 2011.

## Coming from Wattpad

- **No ads mid-story.** There is no ad system in the codebase at all.
- **No coin paywalls.** Monetization on Kiption is the author's own support
  link (Patreon, Ko-fi) on their profile and stories. The platform never
  sits between an author and their reader's money.
- **No algorithm rations your reach.** Wattpad's home feed decides who
  sees a story. On Kiption a new chapter fans out to every follower's
  chosen channel (inbox, email, digest), and listings are chronological
  and documented, not engagement-tuned.
- **Your works are never the platform's to lose.** Authors export any story
  as EPUB or HTML at any time. The operator backs up the whole archive as
  one SQLite file. A takedown dispute cannot delete your archive.
- **Reading that respects the text.** Reader typography controls (size,
  typeface, spacing, width, light and dark), infinite scroll or chapter
  units, keyboard navigation, and a whole-work view for printing.

## Coming from Royal Road

- **Rankings are not a black box.** Trending is a documented seven-day
  window over kudos, favorites, and reviews, and the top-rated list
  requires at least three reviews before a story can rank on it, which
  keeps a single drive-by from ranking anything.
- **Guest reviews carry accountability.** A guest can review with a name,
  one review per IP per story per day, enforced atomically in the
  database rather than by a form the impatient can double-submit.
- **Curate your own feed.** Mute any author and their stories stop
  appearing in your listings, silently and reversibly.
- **Authors see their numbers.** The analytics dashboard covers reads,
  kudos, favorites, follows, and review counts per story.
- **Serial publishing tools.** Scheduled chapter releases with follower
  notification fan-out, drafts behind a validation queue, coauthors, and
  series with confirmed membership.
- **Readers can subscribe their way.** Per-author RSS and Atom feeds, plus
  per-chapter email or daily digest for followers.

## Coming from Archive of Our Own

- **You are not in a registration queue.** The operator picks the
  registration mode: open, email-verified, approval, or invite. One
  archive's growth is never throttled by another site's donation drives.
- **The site is fast.** Anonymous pages are served from a static cache.
  Search runs on SQLite FTS5 with median response in single-digit
  milliseconds. Every page renders in one database query, enforced by an
  automated test.
- **Covers.** AO3 famously has no cover images. Kiption stories do, on
  the story page and in the EPUB export.
- **Posting can be scheduled.** AO3 has no scheduled publishing; chapter
  releases on Kiption can be timed with follower fan-out.
- **Reading progress is remembered.** Kiption tracks your position across
  chapters and shows story-span progress; AO3 has no equivalent.
- **Reading lists keep their order.** Your lists, your sequence.
- **Notifications are granular.** Follow an author and choose inbox,
  email, or digest per subscription.
- **Kudos, bookmarks with private notes, tag wrangling with synonym
  merges, restricted works, challenges with prompts, gift metadata, and
  threaded reviews are all here.**

## Coming from WebNovel

- **No coins, no locked chapters.** Everything an author publishes is
  readable by everyone the author allows. There is no fast-pass, no
  waiting-for-unlocks, no per-chapter toll.
- **There is no contract to sign.** WebNovel's original-content terms are
  the platform authors' number-one complaint: rights assigned to the
  platform as a condition of publishing. Kiption is MIT and the text is
  markdown in your own database; there is nothing to assign because you
  own the server, and exporting is a button, not a negotiation.
- **Stolen-content handling starts with control.** On a self-hosted
  archive the operator knows their community, the validation queue screens
  new work, and reports go to a human who answers for the decision.

## Coming from Substack-style serials

- **No platform fee.** Substack takes ten percent of paid subscriptions.
  Kiption takes zero of anything: the software has no payment plumbing,
  and the author's support link is the only money path.
- **The list is yours.** The subscriber relationship lives in your
  database, not a platform's; followers pick inbox, immediate email, or
  digest per author, and story, author, and category feeds ship RSS and
  Atom.
- **No recommendation network to feed.** Substack's app now decides who
  discovers you. Kiption has no algorithm to appease; reach is followers,
  feeds, and search engines.
- **Serial-shaped, not essay-shaped.** Substack posts are flat emails.
  Kiption gives a story chapters, a cover, ratings, warning gates,
  restricted works, tag wrangling, chapter-level comment threads, and
  reading progress, none of which an email list can express.

## What Kiption deliberately does not build

- In-app payments, coins, or paid chapter unlocks. Monetization is a
  support link, never a toll booth.
- Per-paragraph inline comments. Chapter-level threads and reviews only.
- Image and video hosting beyond covers and avatars.
- Social feeds, clubs, and activity streams.

## The fine print

Self-hosting is free forever under the MIT license; this repository is
the whole product. The hosted flagship at kiption.com runs the same code
and bans works whose primary purpose is sexual gratification; romance,
including mature romance behind the content gate, is welcome. What an
archive accepts is always the operator's call, and the seeded
content-policy page is theirs to edit.
