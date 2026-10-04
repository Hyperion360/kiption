# Author guide

Everything you need to post fiction here: accounts, the submission flow,
the text format, and the structures (coauthors, series, challenges) that
hold a body of work together. Reader-facing features are covered in
[READERS.md](READERS.md); moderation in
[MODERATORS.md](MODERATORS.md).

## Account levels

Every account carries one of four roles, in rising order of trust:

| Role | What it can do |
|---|---|
| member | Read, review, kudos, bookmark, follow, and post through the validation queue |
| validated author | Everything a member can, plus direct publishing (no queue wait) |
| moderator | Everything above, plus the validation queue and reports (see MODERATORS.md) |
| admin | Everything above, plus the operator surfaces (flags, wrangling, the admin panel) |

How you get in and when you can publish depends on two archive settings,
not on you: the registration mode (`open`, `verify`, `approval`, or
`invite`) and whether the archive requires validation. When validation is
required, a member's stories and chapters wait in the validation queue
until a moderator approves them; validated authors and above publish the
moment they save. There is no per-story fast path and no way to buy past
the queue; a moderator approves or removes, nothing else.

A moderator promotes you to validated author when the archive's practice
says so. Nothing you do in the interface changes your own role.

## The submission flow

1. **Create the story.** The story form carries the title, a plain-text
   summary, story-level notes, the rating, the language, categories and
   tags (checkboxes grouped by type), a completed checkbox, a
   registered-readers-only (restricted) checkbox, the round-robin
   checkbox, the gift-to field, the syndication URL fields, and an
   optional cover upload. Your support link lives on your profile, not
   the form.
2. **Add chapters.** The chapter form carries the title, the body, and an
   optional author note. Both body and note are markdown (next section).
   Until the story itself is validated, it stays out of listings, feeds,
   search, and the sitemap; the chapters list on the edit form labels
   each chapter live or awaiting validation.
3. **Wait or publish.** With validation on, a new story (with every
   chapter already under it) lands in the moderators' queue; approving
   it publishes the story and all its chapters in one action. Each
   chapter added afterwards to a live story queues on its own. Your own
   view of pending work is the story form's chapter list, which labels
   each chapter live or awaiting validation. Approval notifies your
   followers and favoriters and refreshes every cached page that lists
   the work. Removal is a soft delete: the story leaves public surfaces
   but the row survives, so a moderator mistake is reversible by the
   admin, not by re-typing.
4. **Edit any time.** Story and chapter edits are transactional and
   purge the affected cached pages. Edits by a queue-bound member go
   back through the queue.

### Scheduled releases

The chapter form's optional Publish-at field holds the chapter invisible
until its moment. The input accepts `YYYY-MM-DDTHH:MM`, an optional
seconds part, and an optional UTC offset (`Z` or `+HH:MM`); every value
is normalized to UTC and stored in one canonical form
(`2027-03-01T09:00:00Z`, say), so release ordering compares exact
instants. A scheduled chapter stays out of the table of contents,
listings, feeds, and the sitemap until release, exactly like a queued
chapter, while you still see it on the edit form.

Scheduling overrides direct-publish rights: even a validated author's
chapter waits when a date is set. The release itself is the operator's
cron job (`php bin/kip release:due`, typically every five minutes); it
publishes the chapter and runs the same notification fan-out as a queue
approval. If the archive's `releases` flag is off, chapters still store
their dates but nothing auto-releases.

## The text format: markdown, no HTML

Chapter text and author notes are markdown at rest. The renderer
supports exactly this subset:

```markdown
*italic* and _also italic_

**bold**

---            (a scene break; *** and ___ work too)

> A quoted passage. Lines starting with > join one blockquote.

[link text](https://example.com/story)

![alt text](https://example.com/cover.png)

Plain paragraphs, separated by a blank line.
```

Raw HTML does not exist in this flavor. Every non-marker character is
escaped at render time, so pasting `<script>` or a stray `<b>` shows the
literal text; it can never execute or style anything. Two scheme rules
to know: text links must be `https://` to render as links (an `http://`
link renders literally as `[text](http://...)`, by design), while images
accept `http://` and `https://`. Summaries and story metadata are plain
text, not markdown. Word counts are computed from the markdown source
with markup syntax excluded, so formatting never inflates your stats.

## Coauthors

The story owner adds coauthors by penname from the story form. Coauthors
gain full authoring rights on that story (the story and chapter forms)
and share the byline; they may leave at any time, and the owner or an
admin can remove them. Reviews, kudos, and favorites notify the owner
and every coauthor, each gated by that recipient's own notification
preferences.

## Series

Series assemble works in order, with one of three membership modes:

- `open`: anyone's story joins at once through the series form.
- `moderated`: submissions wait as pending until the owner confirms.
- `closed`: only the owner adds stories.

Owners reorder items with up/down swaps, and any story's author can pull
their own work out of a series they did not create. Series pages show
validated-story counts and are guest-cacheable; your edits purge them.

## Challenges

Any member creates a challenge at `/challenges`: a title (1-120
characters), a plain-text summary, a membership mode (`open`,
`moderated`, or `closed`), and a prompt list you manage (add, remove,
reorder; a prompt is 1-500 characters, visible to everyone). There is no
claiming machinery: an author writes to any prompt and joins the
resulting story from the challenge page (the story's author or a
coauthor submits the slug; the owner and admins can add any story
directly). An open challenge confirms at once, a moderated one holds the
submission until you confirm it, a closed one accepts joins from you and
admins only. You are notified of every pending submission; the author is
notified on confirmation. Pending and unvalidated stories never show to
guests.

## Two more byline features

- **Round robin.** The story form's checkbox opens the story to the
  crowd: any full member gains add-chapter access (add-only: no story
  edit form, no metadata, no coauthor management), and their chapters
  land in the validation queue like any member chapter. Turning the
  archive's flag off closes the expansion; chapters already contributed
  stay.
- **Gifts.** The story form's Gift-to field (120 characters, plain text)
  renders one line under the byline: `A gift for {name}`. Display only;
  no linking, no reveal machinery.

## Export

Every story page offers two downloads behind the same gates as reading
(stories marked restricted require login; adult-rated stories require
the age acknowledgment):

- a standalone HTML document that renders offline, and
- an EPUB with the cover image (when one exists) and chapter prose
  rendered through the same markdown pipeline.

`/story/whole/{slug}` renders every validated chapter on one page and
doubles as the print view; the browser print command produces a clean
copy with no script involved. Your own stats (total reads, 30-day reads,
kudos, favorites, including restricted and pending works the public
never sees) are at `/stats`.
