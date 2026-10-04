# TODOS

- [ ] `/pentest` on the session and cookie paths (app/src/Cookie.php, App\Viewer, StaticCache) — deferred by operator decision (2026-10-04): run when authorized. The 2026-10-02 hardening wave already hunted these surfaces (commits f7ca7c3, b83596d, 4605a22) and the 2026-10-03 qa-full rounds verified and extended those fixes.
- [ ] Site-wide RTA (Restricted To Adults) label — the per-page `rating: adult` meta ships on the age gate (Google's adult-content guidance); RTA is the site-level complement worth adding if the archive's adult share grows.
