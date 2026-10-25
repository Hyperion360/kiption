# Switching from another platform

How to bring an existing body of work to a Kiption archive, and an honest
account of what transfers and what does not. The submission flow itself
(roles, the markdown subset, covers, coauthors, series, scheduling) is in
[AUTHORS.md](AUTHORS.md); the why is in
[COMPARE.md](COMPARE.md).

## Start at zero, on purpose

Unless you come from eFiction, reader counts, comments, reviews, and kudos
do not transfer. A fresh archive starts every counter at zero and earns
them back from its own readers. What does transfer, on every path below,
is the text itself, your covers, and your ownership of it: the archive
exports any story as EPUB or HTML whenever you want it, which is the thing
most of these platforms never offered.

## From eFiction (automated)

`bin/kip import:efiction` takes an eFiction export bundle and brings over
stories, chapters, members, categories, tags, series, and challenges.
HTML becomes markdown on the way in, legacy passwords keep working, and
old URLs 301 to their new addresses. This is the one automated path, and
it is the reference implementation for migration dignity here.

## From Archive of Our Own

AO3 downloads your own works: open the work, use its download button
(EPUB, MOBI, PDF, or HTML). That button is AO3's own feature, so nothing
on this path touches its terms. Re-upload through the author submission
flow: create the story, paste or attach the chapters (markdown, cleaned
from the download), re-enter the rating, warnings, categories, and
fandom/tags through the archive's taxonomy, and upload the cover if you
had art, or hand the downloads to the operator: `bin/kip import:epub
<dir> --commit --author=<you>` performs the re-upload in one command.
Chapter comments, kudos, and bookmark histories do not transfer.

## From Wattpad or FanFiction.net

Neither platform offers an official export of your own catalog, so the
text comes over by copy and paste, story by story, into the author
submission flow. The markdown subset is small on purpose; the paste from
an HTML-based editor typically needs its styling stripped, which the
author guide documents. Set the rating and warning gates to match what
the old platform's content rating claimed. If the old account stays up,
add a final chapter or profile note pointing readers at the new address;
Kiption's own old-URL 301s cover eFiction imports and Fictioneer
imports (the WordPress export carries the old /story/ and /chapter/
slugs across as redirect rows).

You own your text on both platforms; what their terms restrict is
automated access to their sites, which is why this archive ships no
downloader for them. Established open-source export tools exist instead:
[FanFicFare](https://github.com/jimmxinu/fanficfare) (a Calibre plugin
and CLI whose site adapters include both catalogs) fetches stories under
your own login and produces EPUB copies, which are worth having as the
durable backup the platform never gave you, and the EPUBs it produces
import directly with the same command. A tool like that runs under
your account and its use is between you and the platform's terms.

## From Substack

Export your posts from the Substack dashboard (settings has a full
content export), convert the posts you want to keep to the markdown
subset, and re-upload as stories or news posts. The subscriber list
exports as CSV; the archive does not ingest email lists, so readers come
by pointing at your profile's follow button and the RSS and author feeds,
then choose inbox, immediate email, or digest themselves.

## How Kiption compares to eFiction

eFiction is the grandparent of self-hosted fiction archives; it has run
fandom sites since 2003, and Kiption's author was on its original 2005
development team. The comparison is not a rivalry. If your eFiction
archive is healthy and you enjoy running PHP software, the maintained
3.5.5 repair fork (github.com/Hyperion360/efiction) keeps exactly that
experience alive on modern PHP. This section is for the operator who is
tired of running PHP software.

Upkeep. Stock eFiction 3.5.5 stops on PHP 8 outright; the official
line's updates have been sporadic (2018, 2021, then the 2026 revival),
and its bundled editor dates from 2008. Every archive is its own PHP
install: files to patch, permissions to check, a database server to
keep alive. Kiption is one directory and one SQLite file on PHP 8.3 or
newer, with versioned upgrades that snapshot before they switch and
restore themselves if a migration fails.

What moves with you. The import is one command and it is the most
traveled path in this guide: every maintained eFiction line imports
(3.5.0 through 3.5.9, official, e107-eFiction, and the mod lines, each
pinned by an automated round-trip test). Stories, chapters, members,
categories, series, and reviews come across; members keep their
passwords, logging in the first time re-keys them to modern hashing;
and old story, chapter, and custom-page URLs 301 to their new homes so
search rankings follow.

What readers get. eFiction's feature set froze years ago. Kiption
ships zero-to-ten reviews with author responses, kudos, follows with
email digests, reading lists, scheduled chapters, and per-story feeds,
with exact counts and pages that render in one database query.

Security posture. The repair fork's changelog records 61 defects it
fixed in 3.5.5 with reproductions, from missing write tokens to
upload validation; Kiption started with that list closed and adds
argon-grade password hashing, CSRF protection, and a locked browser
installer by design.

Minimums, not adjectives: eFiction needs a PHP host with a MySQL or
MariaDB database and hands willing to patch it. Kiption needs PHP 8.3
and a writable directory. Both are open source; eFiction is GPL,
Kiption is MIT, so extending Kiption commercially carries no copyleft
obligations.

## How Kiption compares to Fictioneer

Fictioneer is a WordPress theme for publishing fiction. Kiption is a
standalone fiction archive: one SQLite file, no plugins, no build step.
Both are open source and free, so the choice comes down to upkeep, what
moves with you, and what readers can do once they arrive.

Setup and upkeep. Fictioneer needs WordPress, MySQL, and a careful
plugin stack; its own docs call it hard for newcomers and warn that
popular caching plugins break it. Because it ships outside the official
directory, every update is a manual theme upload. Kiption installs as
one directory, and updates are versioned releases you can stage and
roll back.

Getting your archive in. Fictioneer has no export tool. Its migration
guide asks you to paste PHP into functions.php, warns you to delete the
script afterward because leaving it deployed is a security hole, and
gives no path in from other software at all. Kiption imports the
standard WordPress export file with one command: stories, chapters,
author notes, genres, fandoms, characters, comments, collections, and
recommendations come across. A dry-run report counts everything first,
and a database snapshot is taken before any of it commits. Old /story/
and /chapter/ links keep working through the 301 map the import writes,
so your search rankings move with you.

Reader engagement. Fictioneer refuses ratings and review scores on
purpose; three separate request threads were closed wontfix. Kiption
ships zero-to-ten reviews with author responses, kudos, favorites,
follows with email digests, and reading lists. Reader email
notifications, which Fictioneer declines to build, are built in.

Counts you can trust. Fictioneer caches hard: its author index can run
24 hours stale, and its follow counters drift, a bug its author tracks
but does not plan to fix. Kiption renders every page with at most one
database query and keeps the numbers exact.

Your data stays yours. Chapters are markdown at rest, not WordPress
HTML. Any work downloads as EPUB or HTML whenever a reader or the
author wants it, so nothing about leaving Kiption needs a plugin.

Minimums, not adjectives: Kiption needs PHP 8.3 and a SQLite file.
Fictioneer needs at least WordPress 6.5, PHP 7.4, and MySQL, plus the
hosting headroom its own FAQ says large archives require.

## Moving gradually

Scheduled chapter releases and drafts behind the validation queue exist
exactly for a staged move: upload the back catalog as drafts, set a
release cadence, and let the follower fan-out announce each chapter as it
lands. Readers arriving mid-move hit working links, because every
chapter's address is stable once published.
