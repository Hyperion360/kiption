# Moderator guide

The moderation surfaces, what each decision does, and where the boundary
sits between moderator and admin work. Author-facing material is in
[AUTHORS.md](AUTHORS.md); the operator (admin) levers live in the
README's "Operating the archive" and "Feature flags" sections.

## Who moderates

The `moderator` and `admin` roles. Everything below answers 403 for
anyone else. Moderators hold the queues and the reports; admins
additionally hold the flag board, tag wrangling, member role changes,
and the generic admin panel.

## The validation queue at `/queue`

One page, four sections. It is the only approval surface; there is no
email approval and no bulk action.

### Stories

New stories from members (when the archive requires validation) wait
here with their author and title. Two actions:

- **Approve** validates the story and every chapter already under it in
  one action, notifies its followers and favoriters (inbox, immediate
  email, or digest, per each recipient's own preference), and purges
  every cached page that lists the work.
- **Remove** soft-deletes it: the story leaves all public surfaces
  (listings, feeds, search, the sitemap, its own page) but the row
  survives, so an admin can undo a mistake instead of re-typing the
  work.

### Chapters

Chapters added to already-live stories queue here individually. The
same two actions, per chapter. Approve runs the same notification
fan-out as a story approval; remove hides the chapter (a story whose
last validated chapter disappears leaves the listings with it, which is
the intended effect).

### Members awaiting approval

Only when the archive runs `approval`-mode registration. Two actions:

- **Approve** completes the signup (sets the approval timestamp); the
  member appears in the directory.
- **Reject** locks the account (`is_locked = 1`). The member cannot sign
  in until an admin unlocks it.

Changing a member's role is not a moderator action: only admins do
that, through `/adminmembers`, so a moderator can never mint an admin.

### Reports

Members report stories and reviews with a required reason (at most 500
characters); duplicates while a report is open answer 409 to the
reporter, not a new queue row. Each open report shows the target (a
review's text, or the story link) and carries one action:

- **Dismiss** closes the report.

Dismiss is the queue's only report action, deliberately. To act on the
content itself, use the queue's story or chapter Remove (which
soft-deletes), or the admin surfaces for anything heavier. A report you
dismiss is gone from the queue; it does not archive anywhere member-
visible.

## What you are moderating against

The archive's content policy is an operator decision, not code. The
README ships a starting template (its "Content policy template"
section) that the operator posts as a custom page at `/page`; read your
archive's posted policy first and moderate to it. The two stances that
most often need a call, both in the template: AI-generated content
(prohibited, labeled, or permitted) and fundraiser or support links.
Rate the work, do not rewrite it: the rating system, the age gate, and
the restricted flag below are the mechanical guardrails.

- **Adult-rated stories** stay behind a content warning until the reader
  acknowledges it (the `age_ok` cookie). The gate runs on the story
  page, the reader fragment, and the bookmark and progress writes; it
  is not optional for the reader and not bypassable by link crafting.
- **Restricted stories** are marked by their own author: guests get a
  404 before any content renders, members read normally. If a work
  should not be guest-visible at all, the author's restricted flag is
  the correct tool; ask them to set it, or remove the work if the
  policy is breached.
- Deleted and unvalidated works never appear on public surfaces at all,
  so there is nothing to chase there.

## Private messages and conduct

Members contact each other at `/messages` (unthrottled conversation by
design) and through the profile contact form (three messages per sender
per hour, mailed, never stored). There is no moderator read access to
either. Conduct enforcement rides the tools you already have: the
report queue for stories and reviews, and account locking (the member
Reject action locks; an admin unlocks or changes roles through
`/adminmembers`). Abusive message content is handled by the sender's
account being locked, not by inspecting threads.

## Tag wrangling: the boundary

Tag taxonomy (merging synonyms into canonicals, unmerging) lives at
`/wrangling` and is admin-only; moderators get 403. The mechanics, for
when you need to route a request: a merge re-points every story tag to
the canonical, retires the synonym (it is never deleted, so imports and
stored references still resolve), and moves chained synonyms along;
self-merges, cross-type merges, and merges into a retired canonical are
rejected with 422. Unmerge clears the retirement only; already-moved
story tags stay moved. Merges do not purge cached story pages, so the
admin runs `php bin/kip pages:build` after a session. If your archive
is small, the practical answer is: collect the synonym requests, hand
them to an admin.

## Practical notes

- The queue purges the static cache as part of every approve and remove,
  so a decision is live on the next request; you never need to clear
  anything by hand.
- Reads, kudos, favorites, and review counts on cached pages snapshot
  at fill time; a stale count on a listing heals on the next write to
  that page's purge set. This is eventual consistency, not a bug.
- Moderation actions are not flaggable. The validation queue, reports,
  and the moderation tools are on the never-flaggable list: turning
  oversight off is not a feature.
- When unsure, prefer dismissal of the report over removal of the work:
  removal is visible and disruptive, dismiss is quiet, and a pattern of
  repeated reports on the same target is the signal to act.
