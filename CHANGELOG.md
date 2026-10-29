# Changelog

## Unreleased

## 0.1.3 (2026-10-29)

- Changed: the proof page moves to a private site that needs a Wayne State sign-in.
- Changed: project names written as words except acronyms.
- Changed: README images show the current site and policy.
- Changed: README names which tools carry the reader copy; the auth chapter lists the `oidc` driver.
- Changed: the agent-ready kit is Squire 0.3.0's, so generated labels read "Squire's agent-ready kit".

- `bin/policy-bot.php run`: a real (non dry-run) run now commits the data
  allowlist on `auto/policy-refresh` and force-pushes it with GitLab merge
  request push options (create, target, remove source branch, label
  `auto-data`, title; `merge_request.auto_merge` on exit 3 only). The push
  URL comes from the CI_SERVER_* variables and CREED_BOT_TOKEN; missing
  variables stop the run with exit 1 naming them, and every printed line
  masks the token as `[masked]`. After an exit 0, a refresh whose
  `refreshed_on` is 7 or more days old runs the refresher again with
  `--touch` and treats that result like a normal run.

- `bin/policy-bot.php run`: after a handled real run the robot now keeps its
  GitLab alert issues in line with the report, one open issue per alert key
  (`held:<component>:<line>:<field>`, `refused:<component>:<line>:<field>`,
  `disagreement:<component>:<line>:<field>` while policy sides with neither
  source, and `upcoming-eol:<component>:<line>` within 60 days of
  `security_until`), each labelled `auto-alert` and titled `[auto-alert]
  <key>`. A key that is still present gets a note on its existing issue; a
  key that is gone is closed with a "Cleared on <date>." note. The client is
  built from CI_API_V4_URL, CI_PROJECT_ID and CREED_BOT_TOKEN; an incomplete
  environment stops the run with exit 1 naming the missing variables. A dry
  run prints the issues it would open and never calls the API.
- New `src/Bot/GitLabClient.php`: a small GitLab API client (list open
  issues, create an issue, add a note, close an issue) over an injectable
  `HttpTransport`, shipped with a dependency-free PHP streams transport
  (`GitLabClient::streamTransport`). The token rides only in the
  `PRIVATE-TOKEN` header, never in a URL, and every error it throws is
  masked, so a failed call can never print the token.
- Fixed: stale capability claims in the site and docs.

## 0.1.2 (2026-10-29)

- `bin/refresh-policy.php`: a released version that loosens a constraint now exits 3 (changes written) with an alert in the report, instead of exit 4.

- Fixed: a version of a known component that is older than the oldest listed
  line (PHP 5.x to 8.0, Laravel 5 to 8, Inertia 0.x) is now `Eol`, with the
  reason `older than the oldest listed line (8.1)` from the new
  `belowOldestLineReason($component, $version)`, instead of being refused as
  an unknown version. Unlisted components and version strings that do not
  parse are still refused, and a version above every listed line is still
  `Active`.

## 0.1.1 (2026-10-29)

- `bin/refresh-policy.php` keeps every support date, patch and new line in the
  policy tied to a canonical source (php.net, the Node.js release schedule,
  endoflife.date, Packagist, npm). Changes are classed tighten, neutral,
  loosen-warning or loosen-fail; a later security date is held until two
  sources agree or 14 days pass; nothing is ever removed. Provenance lives in
  the new `policy/sources.json` (schema and validation included), and the policy
  gains an optional `refreshed_on` date. `refresh-patches.php` keeps working.
- PHPStan now includes Pinstripe's raw preset
  (`vendor/waynestate/pinstripe/phpstan/raw.neon`) instead of carrying a hand
  copy; `phpstan.neon` keeps only this repo's own paths and phpVersion.
  `waynestate/pinstripe` joins require-dev (dev-main until 0.1.0 is tagged)
  and `phpstan/extension-installer` is allowed so the preset's deprecation
  rules load. The suit is off the rack now.
- `handbook/dependencies.md` gains a "Private packages" section: internal-only
  packages install from one GitLab registry per ecosystem, credentials stay
  out of repository URLs and lock files, builds use read-only deploy tokens,
  the private Composer entry is canonical, released versions never move, and
  public code goes to Packagist or npm. PROCTOR checks and the PREFLIGHT
  publish job enforce the rules. One standard, no cover versions.
- Policy lines may be marked `"current": true`: Active on every date, with no
  `security_until`. The schema rejects a current line that also carries a
  `security_until`, and a line that carries neither.
- `stateOf()` resolves a version to its policy line before judging it. A
  version that sorts above every listed line is `Active` (current versions
  are never refused), while a version between listed lines still throws.
- New reader method `isAheadOfPolicy($component, $version)`.
- `line()` reports a fifth field, `current` (true or null).
- The bundled policy lists Vue 3 and Inertia 2 as current lines.
- `bin/refresh-patches.php` reads the endoflife.date Vue feed and proposes new
  Vue lines (a current line, or EOL dates) alongside the patch refresh.
- The README's reader example is now a test: `tests/ReadmeTest.php` runs every
  ```php block that uses the reader against the bundled policy and asserts the
  values printed beside it. A snippet that cannot run as-is is skipped with a
  `<!-- readme-test: skip -->` comment.
- The handbook is now linted for RFC 2119 capitalization. `src/HandbookLinter.php`
  and `bin/lint-handbook.php` flag lowercase `must`, `must not`, `should`,
  `should not` and `may` in any chapter that declares RFC 2119, ignoring code
  spans and blocks; a same-line `<!-- lint: allow -->` excuses a deliberate
  plain-English use. `composer ci` runs the lint as `lint-handbook`.
- Every handbook chapter now declares RFC 2119 and uses capital keywords;
  `handbook/databases.md` gains a "Grace period" section spelling out the 90-day
  `eol_grace_days` window (`Security` on `security_until`, `Eol` for the window,
  `EolGraceExpired` after, no override).

## 0.1.0 (2026-10-27)

- First tagged release.
