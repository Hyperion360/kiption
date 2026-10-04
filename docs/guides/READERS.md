# Reader guide

How to read comfortably, keep your place, and keep up with authors you
like. Authoring is covered in [AUTHORS.md](AUTHORS.md).

## Themes

Four themes: paper, sepia, night, and auto.

- Members pick one on the account page; it follows the account across
  devices.
- The header's quick toggle (a hand-written script, no framework) is a
  light/dark flip: on a dark page it goes to paper, on a light page to
  night, and its label always names the next state. Sepia and auto stay
  one tap away in the reader's Text sheet and Settings.
- `auto` follows the operating system's light or dark preference and is
  a per-browser choice, not a stored one.

Every reading surface works with scripting disabled; the theme toggle
is the enhancement, the account-page setting is the baseline.

## Reader typography

The chapter reader's Text sheet (the "Text" control in the reader bar,
or the `T` key) saves six reading preferences:

- text size, 16 to 24 px
- typeface, serif or sans
- line spacing
- paragraph style, indented or spaced
- column width
- reading mode, scroll or paged (paged reflows the chapter into
  snapped columns with pure CSS)

The preferences live in one device-local cookie, never in the database,
and every value is whitelisted on write: a junk cookie degrades to the
defaults rather than breaking anything. Resetting every control to its
default clears the cookie outright, which matters for one reason: any
cookie makes your requests skip the archive's static cache, so an
untouched browser loads pages fastest. One deliberate consequence:
the typography cookie outlives logout (it is a device comfort setting,
not an identity one).

## Keyboard shortcuts

In the chapter reader, with scripting enabled:

| Key | Action |
|---|---|
| `J` or right arrow | next chapter |
| `K` or left arrow | previous chapter |
| `F` | toggle focus mode (chrome-free reading) |
| `T` | open the Text sheet |
| `Esc` | close the open sheet, or leave focus mode |

Typing in a field never triggers a shortcut, and an open dialog owns
the keyboard until you close it. In paged reading mode the arrow keys
turn pages instead, and Shift plus an arrow stays the browser's
(text selection). Every destination is a URL the server rendered; the
script invents none.

## Keeping your place

- **Reading progress** records itself as you open chapters (members
  only, and it never moves backwards). The story page shows a Continue
  reading block with chapter, percent, and minutes left at 250 words
  per minute; `/browse/recent` cards carry a Continue pill; already
  read chapters are shaded in the contents.
- **Bookmarks** pin a specific chapter from the reader's control bar,
  with an optional note (up to 500 characters). They render in the
  Contents sheet beside the chapter list, and you can keep more than
  one chapter of the same story bookmarked. Deleting a chapter removes
  its bookmarks; saving a bookmark again updates it and its note.
- **Mark for later** flags a story from its page without favoriting it.

## Shelves and lists

- **Favorites** shelve stories you want on your profile's Favorites
  tab. The author is notified (per their preferences).
- **Reading lists** at `/lists`: create, edit, add any validated story
  by slug, reorder, remove. Lists are private by default; only ticking
  the public box makes the list page visible to guests. A restricted
  story on a public list renders for members but never for guests.

## Following authors

The Follow button on a profile starts a follow with three notification
modes: site inbox only, immediate email per update, or the batched
digest (one email collecting the window's updates; the operator's cron
flushes it hourly, say). Every story publish and chapter release lands
in your `/notifications` inbox regardless of mode.

## Kudos and reviews

- **Kudos** is the lightweight one: once per story per reader, guests
  included (guests are keyed by IP). It shows as a count on the story
  page and feeds the toplists.
- **Reviews** are markdown prose (the same subset as chapter text: no
  HTML ever) with an optional 0 to 10 rating, clamped server side.
  Members review under their penname; guests supply a name and are
  throttled to one review per story per day. Replies thread one level
  under the root review, with the story's author marked in thread.

## Finding things to read

- `/browse` and `/browse/recent` (with completion, length, and category
  chips), `/series`, `/browse/authors` (the member directory with letter
  filters and a beta-reader filter).
- `/search` runs full-text across titles, summaries, and chapter text,
  with category, rating, completed, and language filters.
- `/top` lists the leaders: most favorited, most kudos, most reviewed,
  top rated (three ratings minimum), and Trending over the last 7 days.
- Feeds: the site feed is Atom at `/feed` with an RSS2 alias at
  `/rss`, plus per-author and per-category feeds (autodiscovery links
  sit on the profile and category pages).
- Muting: the Mute button on profiles and directory rows hides an
  author's works from your listings and search results only. It never
  affects direct URLs, feeds, toplists, or downloads, and the muted
  author is not told.

## Downloads and printing

Every story page offers a standalone HTML export (renders offline) and
an EPUB (cover included when one exists), behind the same gates as
reading: restricted stories need a login, adult-rated stories need the
age acknowledgment. `/story/whole/{slug}` renders the complete work on
one page and doubles as the print view: the browser print command
produces a clean copy, no script involved. Printing any page drops the
site and reader chrome.
