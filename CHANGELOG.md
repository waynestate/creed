# Changelog

## Unreleased

- Policy lines may be marked `"current": true`: Active on every date, with no
  `security_until`. The schema rejects a current line that also carries a
  `security_until`, and a line that carries neither.
- `stateOf()` resolves a version to its policy line before judging it. A
  version that sorts above every listed line is `Active` — current versions
  are never refused — while a version between listed lines still throws.
- New reader method `isAheadOfPolicy($component, $version)`.
- `line()` reports a fifth field, `current` (true or null).
- The bundled policy lists Vue 3 and Inertia 2 as current lines.
- `bin/refresh-patches.php` reads the endoflife.date Vue feed and proposes new
  Vue lines (a current line, or EOL dates) alongside the patch refresh.
