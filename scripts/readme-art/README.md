# CREED README art kit

Regenerates the README images under `docs/readme/` from this repo's own front
page (`site/`), brand files and policy (`policy/policy.json`). Nothing is drawn
by hand: the hero and the gallery are screenshots of the real page, the cast is
the marks resized, the badges read their facts from `composer.json` and
`policy.json`, and Form CR-1 is filled in on the page itself.

## Run

Windows PowerShell and Linux/macOS sh, side by side:

| Windows PowerShell | Linux / macOS sh |
|---|---|
| `npm --prefix scripts/readme-art ci` | `npm --prefix scripts/readme-art ci` |
| `npx --prefix scripts/readme-art playwright install chromium` | `npx --prefix scripts/readme-art playwright install chromium` |
| `node scripts/readme-art/build.mjs` | `node scripts/readme-art/build.mjs` |

The script resolves every path from its own location, so it works from any
folder on any OS. It opens `site/index.html` as a `file://` URL and blocks any
request that is not `file:` or `data:`, so it makes no network calls. It is
deterministic: the inspection day is the newest `latest_patch_checked` in
`policy.json`, not today's date.

## What it makes

- `docs/readme/hero.jpg`: the title page, with the running head and binding
  hidden.
- `docs/readme/form-cr1.svg`: Form CR-1, filled in four times on the page's
  own inspector (PHP 8.2 now and on 2027-01-01, PHP 8.1, Laravel 13), each
  certificate typed and stamped. The finding, clause and stamp words are the
  page's; the verdict is checked against `policy.json` and the build fails if
  they disagree. Under `prefers-reduced-motion` it is the first certificate,
  already stamped.
- `docs/readme/gallery.jpg`: a contact sheet of three mobile crops from
  `site/screenshots/sections/`.
- `docs/readme/board.jpg`: the brand board, re-encoded.
- `docs/readme/cast/*.png`: one 96×96 mark per stack part.
- `docs/readme/badges/*.svg`: `php`, `licence` and `policy`, generated
  locally by `badge-maker`.

Every file must stay at or under 300 KB, and no captured text may name an
internal host; the script prints each size and exits 1 on either.

If a stamp ever looks wrong, the policy is wrong, not the stamp. Creed would
have signed it anyway.
