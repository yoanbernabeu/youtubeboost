# Security policy

## Where this project stands

YoutubeBoost is at version 0.1.0 and is **meant to run on `localhost`**, on a
machine or a private network you control. Authentication is one shared password
from an environment variable: no accounts, no second factor, no audit trail.
That is enough for one person on their own machine and not enough for a public
domain, which is why the README says so at the top.

Reports are welcome all the same — knowing where the edges are is how that
changes.

## Reporting a vulnerability

Please report security issues **privately**, not in a public issue.

Use GitHub's private vulnerability reporting: go to the
[Security tab](https://github.com/yoanbernabeu/youtubeboost/security/advisories/new)
of this repository and open a draft advisory. It is visible only to you and the
maintainers.

You can expect a first answer within a week. If the report is valid, you will be
credited in the advisory unless you would rather not be.

## What is in scope

YoutubeBoost is self-hosted and single-user. It holds three things worth
protecting, and reports about them are always in scope:

- **The Google refresh token.** It is encrypted before it reaches the database.
  Anything that leaks it, decrypts it without `APP_SECRET`, or lets a request
  read it back is a vulnerability.
- **The session behind `APP_PASSWORD`.** Every route except the login page sits
  behind the firewall. Anything reachable without a session is a vulnerability.
- **The stored images.** Reference photos, generated thumbnails and archived
  thumbnails are served through a controller, never from `public/`. A path that
  escapes `var/storage`, or reaches an image without a session, is a
  vulnerability.

Injection, CSRF on a state-changing route, and anything that turns a request into
a call on someone else's YouTube channel are in scope too.

## What is not in scope

- **Running the application over plain HTTP.** FrankenPHP serves HTTPS directly;
  a deployment that disables it is a configuration choice, not a flaw.
- **Weaknesses that only matter once the instance is on a public domain.** The
  single shared password, the absence of accounts and the absence of an audit
  trail are known, documented, and the reason the README tells you to stay on
  `localhost`. A report that the password could be brute-forced given unlimited
  attempts is already answered by the rate limiter; a report that the limiter can
  be bypassed is a real finding.
- **The "unverified application" warning from Google.** It is expected for a
  personal OAuth client and is documented in the README.
- **Quota exhaustion caused by your own clicks.** Every costly operation states
  its price before you trigger it.
- **Anything that needs the `APP_PASSWORD`, shell access to the host, or access
  to the database to begin with.** The threat model has one legitimate user, and
  that user is trusted.

## Supported versions

The latest tagged release is supported. There is no long-term support branch:
this is a tool one person runs for one channel, and the fix for a security issue
is to update.
