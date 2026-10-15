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
- [ ] Awesome-selfhosted PR: say the word and it gets drafted for review;
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

- [ ] Decide the GitHub relationship (the tradeoff, from the 2026-10-15
      coordinator answer): keep the independent repo (searchable from day
      one, topics indexed, provenance documented in PROVENANCE.md + README
      + the import commit) OR convert to a true GitHub fork of
      eFiction/v3_stable (adds the "forked from" banner and upstream's full
      commit graph, but GitHub excludes forks from repository search and
      topic pages until the fork out-stars the parent, currently 13). The
      conversion path is cheap now: add upstream as a remote, rebase the 21
      maintenance commits onto the real upstream master tip, delete and
      re-fork under the same name, re-push, re-create the release.
- [ ] Either way: watch the fork's CI weekly (green on PHP 8.3/8.4 x
      MySQL/MariaDB), cut a tag when compatibility fixes accumulate.

## Engineering follow-ups (coordinator-executable, no owner input needed)

- [ ] Consolidated `/cso --code` run over the migration-fidelity, shared-host,
      and web-upgrade branches when local Docker works (the one gate all
      three lanes recorded as skipped; the threat models ran as negative
      tests meanwhile).
- [ ] Centralize the admin gate: `Adminness::requireAdmin($db, $session)`
      with the epoch check, adopted by the twelve controllers that
      copy-paste the raw `admin()` idiom (safe today only because every
      action carries #[Auth]; the wrangling miss is the proof this drifts).
- [ ] ffuf pass over the new endpoints (setup, upgrade, invites) when a
      staging target is up; the denial matrices already cover them.
- [ ] Kip-side (route through the Kip repo's own plan process):
      `Backup.php` artifacts should carry 0600 modes and a 0700 backup dir
      on shared hosting; the rate-limit prefixes collapsed to `*` (recorded
      in progress.md); the kip CI workflow decision is still parked
      (recurring-cost rule).
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

- [x] `/pentest` on the session and cookie paths (app/src/Cookie.php, App\Viewer, StaticCache) plus the launch surfaces: run 2026-10-15 as authorized task A6 (three in-harness hunters: secrets/crypto, DoS/supply-chain, auth variant loop). Findings and fixes: the wrangling gate and the Adminness moderator choke point now validate the session epoch (the one live variant of the revoked-session class); the exporter self-delete removes the out-* bundle directory; the importer bounds hostile bundles (member count, decompressed size, row length, bounded review scan, keyed uniquing); the upgrade engine sweeps orphaned work dirs, gates status on the prepare capability, caps manifests, and keeps failure detail out of responses. Verified clean: StaticCache refusal rules, Cookie name/value strictness, all token crypto (setup, capability, recovery, reset, verify, import), git history sweep. One finding routed to the Kip repo's own plan process: Backup.php artifacts carry umask modes (0600 suggested).
- [x] Site-wide RTA (Restricted To Adults) label: shipped 2026-10-14 as the `adult_rta` setting (config.php default or the settings board row; empty = off) applied once at boot through the `Head::siteRating` seam. A per-page `rating: adult` meta still wins and one rating meta renders per page.
