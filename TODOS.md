# TODOS

- [ ] `/pentest` on the session and cookie paths (app/src/Cookie.php, App\Viewer, StaticCache) — deferred by operator decision (2026-10-04): run when authorized. The 2026-10-02 hardening wave already hunted these surfaces (commits f7ca7c3, b83596d, 4605a22) and the 2026-10-03 qa-full rounds verified and extended those fixes.
- [x] Site-wide RTA (Restricted To Adults) label: shipped 2026-10-14 as the `adult_rta` setting (config.php default or the settings board row; empty = off) applied once at boot through the `Head::siteRating` seam. A per-page `rating: adult` meta still wins and one rating meta renders per page.
