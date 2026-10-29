# Changelog

## Unreleased

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
