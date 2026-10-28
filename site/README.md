# CREED front page

A static page: `index.html`, `assets/creed.css`, `assets/creed.js`. No build step. Serve the `site/` folder from
anywhere (Citadel, any web server, or `python -m http.server` inside `site/` for a look).

- type: **Source Serif 4** (400, 400 italic, 600, 700) for display and the book, **IBM Plex Mono** (400, 600) for
  clause numbers, stamps and forms (both SIL OFL 1.1, self-hosted in `assets/fonts/`, licences in `OFL-*.txt`); Lato
  from WayneUI for running text.
- base: WayneUI 2.0.8, copied from its static path into `assets/wayne-ui/` (tokens, container, skip link, Lato), then
  printed on cream stock and bound in green cloth.
- world: a building code book, the kind an inspector carries. The hero is the title page beside the bound book (mark
  set into the cover as a plate, foil title, printed chapter tabs on the fore-edge). §0.1 spells out the acronym.
  Then the contents with a stamp tally per chapter, the key to the stamps (Enforced, Checked, Documented, Not yet,
  the real tags from `BEST_PRACTICES.md`), Form CR-1, chapters 1 to 7 as numbered clauses with margin notes for the
  rationale and cross-references to the tool that holds each one, Table 2-1 (the adopted editions from
  `policy.json`), an errata slip for the open questions, the authorities having jurisdiction (the WELD parts), and
  Appendix A, the quick start per OS. Clause §c.n maps to practice numbers: §2.1 is practice 11.
- magic: the inspection stamp. Pick a runtime, a version and a day on Form CR-1, and the inspector's rubber stamp
  thunks onto the certificate with the state `Policy::stateOf` would return (SUPPORTED, SECURITY ONLY, EOL in grace,
  EOL grace expired, or REFUSED for an unlisted version that is not newer than every line, because the reader does not guess; current versions are never refused). The
  certificate shows the real reader call and its return value, the clause that applies is highlighted in the book,
  the row lights up in Table 2-1, and earlier stamps stay behind as faint impressions. The result is live-region
  text. The first time the form comes into view, the stamp comes down on the filled-in certificate.
- data: the form and Table 2-1 carry a copy of the dates in `policy/policy.json` (2026-10-28) in `assets/creed.js`.
  The policy file stays the source; refresh the copy when the dates change.
- marks: `brand/` is CREED's (generated with ChatGPT, never redrawn). `assets/marks/` holds the other stack parts'
  marks, copied from WELD's and BOOSTER's sites; WayneUI uses its own reversed mark on green.
- reduced motion: the book is already printed, every stamp already inked, every word present at first paint; the form
  still stamps, without the thunk.
- jokes: Creed Bratton's unauditable records, "with arms wide open" for the inclusive boundary, Creed being one version
  ahead. GIFs are linked from the colophon, never committed. No lyrics, no quotes.

Screenshots are in `screenshots/` (desktop, mobile, reduced motion, a motion preview, and mobile crops per section in
`screenshots/sections/`).

The inspector never guesses either.

## Links between the stack sites

Links to the other parts are committed as production URLs (`https://<project>.apps.wayne.edu/`), so crawlers and
visitors without JavaScript always get production. `assets/stack-env.js` (the same file on every stack site) rewrites
them to `http://127.0.0.1:18432/<project>/site/` in development, for a static server whose root is the
`the stack` folder. Dev is picked automatically on `localhost` or `127.0.0.1`; `?env=dev` or `?env=production` forces
one (remembered until `?env=auto`), `<meta name="stack-env">` sets the page default, and `?devBase=http://127.0.0.1:<port>`
points at a server on another port. WayneUI links stay on production.
