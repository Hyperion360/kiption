# TODOS

Everything left on the public app's side of launch, in one list. The hosted
runway (server, DNS, mail, the go-live gates) is operational work and lives in
the private operations repo's TODOS.md; nothing on the public side blocks on
it except the items noted. Decisions that cost money stay with the owner.

## Owner actions (30 seconds to 30 minutes each; nothing else blocks on these except where noted)

- [ ] Upload the social preview: repo Settings > Social preview > drag
      `docs/social-preview.png` (GitHub has no API for this; the image is
      rendered and committed). Image verified 2026-10-25: it is the product
      composite (the search-first screen beside the value matrix), which
      sells better than a logo card would, so regeneration is not wanted;
      the web upload is the only remaining step.
- [x] Submit both packages on packagist.org: RESOLVED 2026-10-25, both are
      already live and auto-updating (the entry predated the submissions).
      `kip/framework` carries v0.5.0 plus dev-main and syncs within hours
      of each push (last update 2026-10-24 20:11 UTC);
      `hyperion360/kipsaas` is at v0.1.4 with dev-main (last update
      2026-10-24 21:20 UTC). Nothing remains unless the owner wants the
      Packagist profile page filled in.
- [ ] Post the fan-wiki blurbs (draft exists in the 2026-10-15 coordinator
      summary) to the eFiction community wikis' download/version pages.
- [ ] Awesome-selfhosted PR: DRAFTED 2026-10-15 (entry text, section fit,
      and the pre-submission checklist, including waiting for the
      demo link, live in
      MVC-Lite docs/superpowers/research/2026-10-15-awesome-selfhosted-draft.md);
      submitting runs under the owner's account so it stays owner-gated.
- [ ] Launch posts (Hacker News, Reddit r/fanfiction / r/selfhosted,
      Product Hunt): owner fires once the hosted flagship is live (a demo
      link beats a bare repo in every one of those communities);
      coordinator drafts on request.

## eFiction fork: the remaining decision and steps

- [x] GitHub relationship DECIDED 2026-10-15: keep the independent repo.
      Rationale: the fork exists so eFiction users can find it, and GitHub
      excludes true forks from repository search and topic pages until it
      out-stars the parent (13), which kills the one thing the fork is for;
      the "forked from" banner and upstream commit graph are cosmetic next
      to that, and provenance is already documented (PROVENANCE.md, README,
      the import commit, GPL license text). Conversion stays possible later
      if search stops mattering, but it is destructive (delete, re-fork,
      re-push) and needs the owner's account, so it would be an explicit
      owner project, not a default.
- [ ] Either way: watch the fork's CI weekly (green on PHP 8.3/8.4 x
      MySQL/MariaDB), cut a tag when compatibility fixes accumulate.
      Checked 2026-10-25: latest runs green on main (compatibility
      workflow, 1m9s). Next check by 2026-11-01. The weekly cadence
      is manual: no recurring automation was created (recurring-cost rule;
      ask if wanted).

## Engineering follow-ups (coordinator-executable, no owner input needed)

- [x] `import:epub`: SHIPPED in v0.2.0 (2026-10-21) as the release headline
      (bulk EPUB importer: AO3 downloads, FanFicFare output, any EPUB
      library; dry-run, author resolution, covers, per-file idempotency;
      app/src/Import/Epub.php + tests/EpubImportTest.php on main, the
      feature/import-epub branch is merged). This entry predates the ship
      and was left open by mistake.
- [ ] Kip-side (route through the Kip repo's own plan process): Backup.php
      artifact modes are DONE on kip main (commit cd929db, 2026-10-15:
      0700 dir re-hardened every run, 0600 artifacts new and stale, checked
      chmod failing loud; write-plan + codex adversarial review with six
      findings folded; composer check green) but UNRELEASED, FULLY STAGED
      as of 2026-10-25: 25 commits on kip main since v0.5.0 (cd929db's
      backup modes plus the complete rate_limit '*' fallback series, its
      own review rounds folded), CI green, three changelog fragments
      pending (the backup fragment added by c89eb30 after this audit found
      the fix had none), and the Packagist hook verified live so the tag
      publishes itself. The owner's whole step, in a kip checkout:
      `php bin/release 0.5.1 && git push origin main --tags`. Then here:
      `composer update kip/framework` and collapse the 26 rate_limit
      prefixes to '*' (coordinator-executable once the tag exists).
      The kip CI workflow decision stays parked (recurring-cost rule).
- [ ] README screenshot pass once the hosted flagship is live: a hero shot
      of the reader is the one discovery gap the star-research flagged.

## Ongoing discovery (marketing and SEO cadence)

- [ ] Cut a release every time user-facing work lands (releases are the
      strongest sustained search signal; 0.1.1 shipped 2026-10-15, 0.2.0
      shipped 2026-10-21 with import:epub as the headline, 0.3.0 cut
      2026-10-25 with import:fictioneer and the post-password gate as the
      headlines, 15 fragments, tag local: the push and GitHub release stay
      owner-gated).
- [ ] Answer every Discussion and issue within a day (community-engagement
      signals feed both GitHub ranking and the eFiction community's trust).
      Checked 2026-10-25: zero Discussions, only the pinned issue open.
- [ ] Re-check repo topics quarterly against what people actually search
      (last optimized 2026-10-15: the competitor platforms went in as bare
      names plus substack, fanfiction-net, and self-hosting; next review
      January 2027).

## Backlog (recorded non-goals, revisit only on demand)

- [ ] Member data-export bundles (per-member archive download).
- [ ] Per-user skins (operator-level ships today).
- [ ] OPDS catalog feed.
- [ ] Adult landing-page gate (view() renders metadata ungated for guests
      while read/whole gate): a product decision, recorded 2026-10-15.

## Closed (kept for the record)

- [x] The qa-full follow-up batch shipped 2026-10-21 (commit on top of
      v0.2.0): cap messages interpolate the constants so words and
      enforcement cannot drift; four enforced-but-untested refusal paths
      gained coverage pins (container DOCTYPE, missing rootfile,
      absolute-href escape, cover-absent clean import via a toggled
      fixture builder); an empty dc:title refuses with a clear message
      instead of importing a story titled nothing; fixture hygiene
      (override-winning union, checked addFile/addFromString, tempnam
      guard). Full suite 1144 tests / 9366 assertions.
- [x] v0.2.0 shipped 2026-10-21: tag pushed, GitHub release live with the
      folded changelog (import:epub as the headline Added); the cadence
      item above executed the same day the work landed.
- [x] The install/upgrade guide benchmarked against efiction.org/download
      (2026-10-21): theirs offers one download, CHMOD 777/666, upload-over
      upgrades, and no requirements, troubleshooting, rollback, or
      checksums; INSTALL.md carries digest-pinned install and upgrade
      ZIPs, a permissions table with no world-writable step (and now says
      so, naming the habit it replaces), troubleshooting, and a shell-free
      rollback. Superior on every axis their page lacks.
- [x] The README first screen names the EPUB import path next to the
      eFiction import (2026-10-21): AO3 downloads, FanFicFare output, or
      any platform with an EPUB export.

- [x] The hosted-go-live checklist (the C1-C9 track, the email provider
      pick, the Stripe go-live gate, and the reserved-instance decision)
      moved 2026-10-15 to the private operations repo's TODOS.md, where the
      runbook it references lives; this public file keeps the app and the
      marketing surface only.
- [x] Admin gate centralized 2026-10-15 (commit 3914387): the new
      `Adminness::requireAdmin($db, $session)` mirrors requireModerator
      (sessionValid epoch check first, then the role row, fail-closed 403 on
      every arm) and replaced the copy-pasted private `admin()` helper in
      all eleven controllers, 34 call sites; the nine helpers without the
      epoch check are safe by construction now instead of by #[Auth]
      discipline. HomeController keeps its render-time isAdmin flag: it is
      not a POST gate, Viewer::id has already re-validated the epoch there,
      and the home page's budget pins its one users read. 1076 tests
      / 8975 assertions green; two new Adminness tests pin the gate (wrong
      role, stale epoch, broken users table).
- [x] Consolidated `/cso --code` run finished 2026-10-15 over the three
      merged lanes on main (run 1792078457439-9c523c5f003f1ee1, 10-minute
      daily budget, static only, scanner profiles absent as before):
      no supported findings. Hunted seams: setup.php (token-gated, lock-
      serialized, generic errors), upgrade.php + UpgradeController
      (hash_equals capability, CSRF, same-origin, 5-per-10-minute failure
      cap verified live), ReleaseVerifier (read-only contract, digest
      pinned to stage name and manifest), LegacyRedirects and the 035
      migration (parameterized throughout, query values never become
      redirect destinations), EfictionMapper (no direct query building;
      writes live in the pentest-bounded Importer). Completeness partial
      by design: CLI internals and build scripts were covered by the A6
      pentest and the per-lane negative tests, not re-read here.
- [x] ffuf pass over setup, upgrade, and invites 2026-10-15 on a local
      staging server (8095): clean. Method fuzz: only routed verbs exist
      (405 otherwise); POST denials 403 with generic bodies. Parameter
      fuzz (60 names on setup and upgrade): byte-identical responses,
      parameter-blind. Source-disclosure extensions (.bak, ~, .save, ...):
      calibrated out. Invites subpaths: 403/405 only, no 500s anywhere.
      Positive controls fired: upgrade's failure cap engaged at the fifth
      refused POST (429), setup/upgrade serve private no-store with
      Secure/HttpOnly/SameSite cookies, the anonymous home page stays
      public-cached with no Set-Cookie.
- [x] `/pentest` on the session and cookie paths (app/src/Cookie.php, App\Viewer, StaticCache) plus the launch surfaces: run 2026-10-15 as authorized task A6 (three in-harness hunters: secrets/crypto, DoS/supply-chain, auth variant loop). Findings and fixes: the wrangling gate and the Adminness moderator choke point now validate the session epoch (the one live variant of the revoked-session class); the exporter self-delete removes the out-* bundle directory; the importer bounds hostile bundles (member count, decompressed size, row length, bounded review scan, keyed uniquing); the upgrade engine sweeps orphaned work dirs, gates status on the prepare capability, caps manifests, and keeps failure detail out of responses. Verified clean: StaticCache refusal rules, Cookie name/value strictness, all token crypto (setup, capability, recovery, reset, verify, import), git history sweep. One finding routed to the Kip repo's own plan process: Backup.php artifacts carry umask modes (0600 suggested). [RESOLVED UPSTREAM 2026-10-15: kip main commit cd929db, unreleased until the owner tags post-0.5.0.]
- [x] Site-wide RTA (Restricted To Adults) label: shipped 2026-10-14 as the `adult_rta` setting (config.php default or the settings board row; empty = off) applied once at boot through the `Head::siteRating` seam. A per-page `rating: adult` meta still wins and one rating meta renders per page.
- [x] Ship a favicon: resolved 2026-10-24. The owner chose the mark (the K in the brand serif). public/favicon.svg carries the site's exact --serif stack so supporting browsers render the header's own face; public/favicon.ico is a 16/32/48 raster of Iowan Old Style (the stack's first named font) rendered locally. Both linked from every layout; svg joins the 86400 image lifetime in .htaccess. Console sweep's last error class is gone.
