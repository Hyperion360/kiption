### Added
- Members can sign in from a one-time emailed link ("Email me a sign-in link" on the login page). The link works once, for 30 minutes, and opens a page with a Sign in button, so mail scanners that open links can't use it up. An expired or used link offers a new one.
- `bin/kip auth:link <email> [--hours=1..168]` mints a sign-in link for a member, to paste into a support reply.

### Fixed
- Using a sign-in link, or finishing a password reset, now verifies the member's email. Someone who never got the verification mail is no longer stuck at "Please verify your email first."
