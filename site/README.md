# CREED front page

A static page: `index.html`, `assets/creed.css`, `assets/creed.js`. No build step. Serve the `site/` folder from
anywhere (Citadel, any web server, or `python -m http.server` inside `site/` for a look).

- type: **Archivo**, wide (font-stretch 112–125%, SIL OFL 1.1, self-hosted in `assets/fonts/`, licence in
  `OFL-archivo.txt`) for display; Lato from WayneUI for body text; the system monospace for commands and running times.
- base: WayneUI 2.0.8, copied from its static path into `assets/wayne-ui/`, then re-pressed on paper and vinyl.
- world: an LP package. The front cover is the hero, each standard is a track with a running time and a tag that says
  how the stack holds it, the inner sleeve carries the liner notes, the white-label test pressings are the open
  questions, the gatefold has the credits and the label's catalogue (the WELD parts), and the back cover is the quick
  start.
- magic: drop the needle. The tonearm swings onto the record, the platter runs at 78, and the tracklist plays: each
  standard lights up in turn with its reason, the arm walks inward groove by groove, and between sides the record
  flips over and the label changes to side B. Pick any track and the arm finds it. A hidden track plays after a
  short silence. The liner notes' bonus track is a working copy of `Policy::stateOf` that shows the inclusive
  boundary and the 90-day grace on real dates.
- data: the bonus track carries a copy of the dates in `policy/policy.json` (2026-10-28) in `assets/creed.js`. The
  policy file stays the source; refresh the copy when the dates change.
- marks: `brand/` is CREED's (generated with ChatGPT, never redrawn). `assets/marks/` holds the other stack parts'
  marks, copied from WELD's and BOOSTER's sites; WayneUI uses its own reversed mark on green.
- reduced motion: the record is already out of its sleeve and not turning, every word present at first paint; the
  deck and the reader still work, without movement.
- jokes: Creed song titles as headings and Creed Bratton's record-keeping. GIFs are linked from the credits, never
  committed. No lyrics, no quotes.

Screenshots are in `screenshots/` (desktop, mobile, reduced motion, a motion preview, and mobile crops per section in
`screenshots/sections/`).

The needle never guesses either.
