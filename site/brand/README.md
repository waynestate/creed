# CREED brand files

**The mark is a stone arch whose gold keystone holds the other stones in place**, because CREED is the source the
rest of the stack rests on. The mark itself is serious. The jokes are in the docs.

## Colours
| Role | Colour |
|---|---|
| Stones, tile | Wayne State green `#0C5449` |
| Keystone (the only gold) | Wayne State gold `#FFCC33` |
| Stones on the tile or on dark backgrounds | White `#FFFFFF` |
| Site accent only, never in the mark | Ochre `#634D00` |

Gold never sits behind white text.

## Files
| File | Use |
|---|---|
| `creed-mark.svg` | The mark on light backgrounds: green stones, gold keystone |
| `creed-mark-white.svg` | The mark on dark or green backgrounds: white stones, gold keystone |
| `creed-mark-mono.svg` | One colour (`currentColor`), for print and single-ink use |
| `creed-tile.svg` | The app icon: a green rounded tile with the white arch |
| `creed-favicon.svg` | The tile simplified for 32 and 64 px: three stones and wider joints |
| `creed-favicon-16.svg` | Hand-hinted on the 16 px grid. One unit is one pixel |
| `creed-avatar-512.png` | The GitLab project avatar and the Open Graph image base |
| `creed-mark-1024.png`, `creed-mark-white-1024.png` | Raster marks for slides and documents |
| `favicon-16.png`, `favicon-32.png`, `favicon-64.png`, `favicon.ico` | Browser favicons; the `.ico` holds all three |

## Geometry
The SVGs are drawn from exact geometry, not traced, so the shapes are symmetric to the hundredth of a unit.
- **The ring:** an outer semicircle of radius 168 and an opening of radius 82, centred at (256, 262) on a 512 grid.
  It stands on short piers, 84 units tall.
- **The stones:** five, with radial joints 12 units wide at 90° ± 12° and at 138° and 42°.
- **The keystone:** it stands 18 units above the ring and drops 18 below it.

## Rendering
The PNGs were rendered with Inkscape 1.4, for example
`inkscape creed-tile.svg --export-type=png --export-filename=creed-avatar-512.png -w 512 -h 512`. The `.ico` was
built with ImageMagick 7: `magick favicon-16.png favicon-32.png favicon-64.png favicon.ico`.
