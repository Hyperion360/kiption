# TODOS

Everything left between here and, and after, launch, in one list. Decisions
that cost money stay with the owner (the standing no-spend rule); everything
else is coordinator-executable and says so.

## Owner actions (30 seconds to 30 minutes each; nothing else blocks on these except where noted)

- [ ] Upload the social preview: repo Settings > Social preview > drag
      `docs/social-preview.png` (GitHub has no API for this; the image is
      rendered and committed).
- [ ] Submit both packages on packagist.org under the Hyperion360 account:
      `https://github.com/Hyperion360/kip` as `hyperion360/kip` and
      `https://github.com/Hyperion360/kipsaas` as `hyperion360/kipsaas`.
      Free; enables `composer require` installs and a second search surface.
- [ ] Pick the outbound email provider for the hosted flagship: Brevo free
      tier (recommended: $0, 300 emails/day, SPF/DKIM at the registrar) or
      Amazon SES (~$0.10 per 1,000, needs an AWS account). This gates
      `registration_mode` leaving `invite` on kiption.com.
- [ ] Post the fan-wiki blurbs (draft exists in the 2026-10-15 coordinator
      summary) to the eFiction community wikis' download/version pages.
- [ ] Awesome-selfhosted PR: DRAFTED 2026-10-15 (entry text, section fit,
      and the pre-submission checklist, including waiting for the
      kiption.com demo link, live in
      MVC-Lite docs/superpowers/research/2026-10-15-awesome-selfhosted-draft.md);
      submitting runs under the owner's account so it stays owner-gated.
- [ ] Launch posts (Hacker News, Reddit r/fanfiction / r/selfhosted, Product
      Hunt): owner fires, coordinator drafts on request.
- [ ] Stripe go-live decision (after the erotica policy, preconditions are
      met): live keys, webhook endpoint, kipsaas billing loop. 2.9% + 30c
      per charge, no monthly fee.
- [ ] Reserved-instance decision after one month of stable VM load (~30%
      off the B2als_v3).

## Hosted go-live: kiption.com on Azure (launch plan Track C; scripted in
docs/superpowers/plans/2026-10-14-kiption-launch.md)

- [ ] C1: confirm B2als_v3 + disk + static IP pricing in the Azure
      calculator (stop above $30/mo total and fall back to B2ats_v3 + swap),
      then provision (az commands are in the plan), lock SSH to the
      operator IP, record the run-command recovery path.
- [ ] C2: nginx + PHP 8.3-FPM on Ubuntu 24.04 (exact package list in the
      plan; no nginx cache fast path by design).
- [ ] C4: DNS A `@` and CNAME `www` at the registrar, certbot TLS, forced
      HTTPS.
- [ ] C6: email provider setup BEFORE the setup wizard (the wizard refuses
      to complete without a proven test send); Brevo/SES DNS records.
- [ ] C3: install from the B2 release ZIP at `/var/www/site` (site/public
      docroot, site/bin/kip CLI), setup wizard over an SSH tunnel,
      `doctor --json` green, `db:blank`, admin account. This is the real
      off-host validation of the ZIP.
- [ ] C5: cron (release:due every 5 min, digest daily, backup nightly,
      logs:prune weekly) + the logrotate stanza.
- [ ] C7: flagship content: policy page live, validation queue ON (the
      erotica-line enforcement gate), moderator account ready, starter
      shelf seeded, landing page published.
- [ ] C8: UptimeRobot monitors, off-VM backup copies (Azure Blob, lifecycle
      30 days), restore drill covering DB + uploads + config.
- [ ] C9: the go-live checklist (doctor over HTTPS, anonymous curl has no
      Set-Cookie, cron log clean, test email received, pentest closed).
      Registration leaves `invite` only after C6's test email lands.

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
      Checked 2026-10-15: latest runs green on main (compatibility branch
      merged; three provisioning-failure runs earlier the same day were
      fixed forward). The weekly cadence is manual: no recurring automation
      was created (recurring-cost rule; ask if wanted).

## Engineering follow-ups (coordinator-executable, no owner input needed)

- [ ] Kip-side (route through the Kip repo's own plan process): Backup.php
      artifact modes are DONE on kip main (commit cd929db, 2026-10-15:
      0700 dir re-hardened every run, 0600 artifacts new and stale, checked
      chmod failing loud; write-plan + codex adversarial review with six
      findings folded; composer check green) but UNRELEASED: the framework's
      only tag is v0.5.0, so kiption cannot consume it and cannot collapse
      its 26 rate_limit prefixes to '*' (the catch-all is on kip main since
      the 2026-10-07 branch, also unreleased) until the owner tags and
      pushes a post-0.5.0 kip release, then runs composer update here.
      The kip CI workflow decision stays parked (recurring-cost rule).
- [ ] README screenshot pass once kiption.com is live: a hero shot of the
      reader is the one discovery gap the star-research flagged.

## Ongoing discovery (marketing and SEO cadence)

- [ ] Cut a release every time user-facing work lands (releases are the
      strongest sustained search signal; 0.1.1 shipped 2026-10-15).
- [ ] Answer every Discussion and issue within a day (community-engagement
      signals feed both GitHub ranking and the eFiction community's trust).
- [ ] Re-check repo topics quarterly against what people actually search
      (both efiction spellings are in place; the 20-topic cap is full, so
      swaps only for proven better terms).

## Backlog (recorded non-goals, revisit only on demand)

- [ ] Member data-export bundles (per-member archive download).
- [ ] Per-user skins (operator-level ships today).
- [ ] OPDS catalog feed.
- [ ] Adult landing-page gate (view() renders metadata ungated for guests
      while read/whole gate): a product decision, recorded 2026-10-15.

## Closed (kept for the record)

- [x] Admin gate centralized 2026-10-15 (commit 3914387): the new
      `Adminness::requireAdmin($db, $session)` mirrors requireModerator
      (sessionValid epoch check first, then the role row, fail-closed 403 on
      every arm) and replaced the copy-pasted private `admin()` helper in
      all eleven controllers, 34 call sites; the nine helpers without the
      epoch check are safe by construction now instead of by #[Auth]
      discipline. HomeController keeps its render-time isAdmin flag: it is
      not a POST gate, Viewer::id has already re-validated the epoch there,
      and the home page's query budget pins its one users read. 1076 tests
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
