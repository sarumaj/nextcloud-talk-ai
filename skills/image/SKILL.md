# Images: diagrams, drawings, illustrations, posters

You draw the image with code, look at it, correct it, and deliver it. Read
`/skills/common/SKILL.md` first: the workflow, the fonts and the formulas are the same.

## What you can and cannot make

- **Can:** diagrams and flowcharts, mind maps, timelines, family trees, organisation
  charts, schematic drawings (a cell, a circuit, the water cycle, a machine part),
  geometric constructions and function graphs, charts, maps drawn from shapes, icons and
  logos of simple shapes, posters, invitations, cards, worksheets' pictures, flat
  illustrations and pixel art; changes to an input image: crop, resize, rotate, mark,
  label, blur a part, put several into a collage, a frame or a text on it.
- **Cannot:** photos or photo-realistic pictures, the likeness of a real person, a copy
  of someone else's logo, character or artwork. When a task asks for these, draw what
  comes closest (a schematic, a flat illustration, the person's own logo from the house
  style) and say what is missing in `finish` under open issues.

## Choose the tool

| Kind of image | Tool |
|---|---|
| Graph of nodes and arrows: flowchart, tree, process, states, dependencies, mind map | Graphviz `dot` (layout done for you) |
| Diagram, poster, invitation, icon, illustration, infographic, labelled drawing | SVG written by a Python script |
| Chart, function graph, geometry with exact coordinates, statistics | matplotlib |
| Change to an input photo or image, collage, pixel art, noise and textures | Pillow (or ImageMagick 6 `convert`) |

Mixing is fine: a Graphviz or matplotlib SVG, a formula from `formula.py` or a photo
(as a `data:` URI) can be placed inside your own SVG.

## Format and size

- **PNG** by default: sharp lines and text, transparency. **SVG** when the person wants
  to scale or edit it, or for print and the web; **WebP** when it must be small; **JPEG**
  only for photos.
- Build in SVG and convert: `rsvg-convert -w 1600 --keep-aspect-ratio in.svg -o out.png`
  (`-b white` for a background; WebP or JPEG then with `convert out.png -quality 90 out.webp`).
- Sizes: a size asked for comes first; otherwise 1600 px wide for the chat and screens,
  square 1080 × 1080 for social media, 1920 × 1080 for a slide or screen (16:9), A4 at
  300 dpi 2480 × 3508 (A5 1748 × 2480) for print, 512 × 512 for an icon. An SVG gets
  `width`, `height` and a `viewBox` of the same proportions.
- Transparent background only for icons and logos (or when asked); everything else gets
  a filled background.

## Steps

1. **Plan** before drawing: what must be in the image (every item the task names), the
   text on it word for word, the size, a grid (margins about 5 % of the width, columns,
   the reading order: top left to bottom right, or the centre outwards).
2. **Compute, do not guess**: write `/work/build.py` that sets the size and the grid as
   variables and computes every position from them. Measure text with the real font
   (`textfit.py`, below) so it fits its box; never place text by eye.
3. **Build** the file in `/work/output/`, **render_document** it and look: the image at
   full size, then a small copy as the chat shows it (a checkerboard marks transparency).
4. **Check** what the task asks for is there and right (labels at the right parts, arrows
   in the right direction, the right count of steps), text is legible at the small size and
   spelt right, nothing is cut off at the edges or overlaps, proportions are right (a
   circle is round), colours have contrast. Correct the script and render again.
5. **Deliver** the file, then `finish` with one sentence that describes the image (it
   serves as its alt text).

## Text on images

- Fonts: the installed ones from `/skills/common/SKILL.md` (Open Sans, Liberation Sans,
  DejaVu Sans, Noto Sans for clean text; Liberation Serif, Linux Libertine for classic;
  DejaVu Sans Mono for code). At most two families in one image.
- Sizes at 1600 px width: title 64–96 px, headings 40–56 px, text and labels at least
  28 px (smaller is unreadable in the chat). Scale with the width for other sizes.
- `textfit.py` measures with the real font file:

  ~~~python
  import sys; sys.path.insert(0, "/skills/image/scripts")
  from textfit import fit, svg_text, measure, contrast, font_file
  size, lines = fit("Sommerfest 2027", "Open Sans", 1200, 200, largest=120, bold=True)
  block, height = svg_text("Samstag, 12. Juni, ab 15 Uhr", 800, 420, "Open Sans", 44, 1200, anchor="middle")
  ~~~

- Text in the language of the task, with its quotation marks, dates and decimal
  separators. Check the spelling of every word on the image yourself.
- Text needs a contrast of at least 4.5:1 with what is behind it (`contrast()`), 3:1 for
  large titles. On a photo, put text on a band or a shadow, not straight on the busy part.

## Colours

- Use the house style's colours when one is given (the first is the main colour).
- Otherwise one main colour, one accent, and greys. For categories that must be told
  apart (charts, maps, lines), the colour-blind-safe Okabe–Ito set: `#0072B2` blue,
  `#E69F00` orange, `#009E73` green, `#D55E00` vermilion, `#CC79A7` purple, `#56B4E9`
  sky blue, `#F0E442` yellow, `#000000`. Never rely on red against green alone: add
  labels, shapes or patterns.
- Text dark on light (`#1a1a1a` on white) or white on dark; not grey on colour.

## SVG

- Root: `<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="900" viewBox="0 0
  1600 900">`, then `<title>` (the alt text) and a background `<rect width="100%"
  height="100%" fill="…"/>`.
- Stand alone: no `href` to files or the web; embed images as
  `data:image/png;base64,…` and draw icons yourself. `check.py` reports anything loaded
  from outside and fonts that are not installed.
- Use `font-family="Open Sans"` (an installed family), `text-anchor` (start, middle,
  end) and the baseline `y` (text sits on it); `dominant-baseline` is not reliable in
  rsvg.
- Groups with `transform="translate(x,y)"` keep coordinates simple; `<defs>` with a
  `<marker>` for arrowheads; `stroke-linecap="round"`, `stroke-linejoin="round"` for clean
  lines; gradients and `opacity` render, heavy filters (blur) are slow.
- Arrows from box to box: compute the start and end on the boxes' edges, not the
  centres, so the arrowheads stay visible.

## Graphviz

~~~bash
dot -Tsvg /work/flow.dot -o /work/flow.svg && rsvg-convert -w 1600 --keep-aspect-ratio -b white /work/flow.svg -o /work/output/flow.png
~~~

- In the graph: `graph [rankdir=TB, fontname="Open Sans", pad=0.4, nodesep=0.5,
  ranksep=0.6]; node [shape=box, style="rounded,filled", fillcolor="#e8f1fa",
  color="#0072B2", fontname="Open Sans", fontsize=16, margin="0.2,0.1"]; edge
  [color="#555555", fontname="Open Sans", fontsize=13]`.
- Decisions as `shape=diamond`, start and end as `shape=oval`; `rankdir=LR` for wide
  processes; `{rank=same; a; b}` keeps nodes on one level. Labels with line breaks: `\n`.
- `neato` or `fdp` instead of `dot` for networks without a direction; `circo` for cycles.

## matplotlib

- `import matplotlib; matplotlib.use("Agg")`, `plt.rcParams["font.family"] = "Open Sans"`
  (or "DejaVu Sans"), figure size in inches × dpi = pixels:
  `plt.figure(figsize=(16, 9), dpi=100)` gives 1600 × 900.
- Geometry: `ax.set_aspect("equal")` so circles stay round; `ax.axis("off")` for drawings
  without axes. `savefig(path, dpi=100, bbox_inches="tight", pad_inches=0.3,
  facecolor="white")`; `.svg` for vector output.
- Function graphs: the axes through the origin, labelled with units, a grid, the
  function's formula in the legend (`$f(x)=x^2$`), key points marked and labelled.

## Pillow and input images

- Open with `ImageOps.exif_transpose(Image.open(path))` so photos stand upright; convert
  to `RGB` (or `RGBA` for transparency) before drawing.
- Smooth shapes: draw at twice the size and shrink with `Image.LANCZOS`.
- Text: `ImageFont.truetype(font_file("Open Sans", bold=True), size)`; `draw.text((x, y),
  text, font=font, fill=…, anchor="mm")` centres on (x, y).
- Remove location and camera data from photos before delivery (save without `exif`):
  people do not expect it to travel with the picture.
- Keep the original's aspect ratio unless asked to crop; crop to the subject, not the
  middle.
- ImageMagick 6 is `convert`, `montage`, `identify` (no `magick` command).

## Formulas and chemistry in images

Formulas as SVG from `python3 /skills/common/scripts/formula.py 'LaTeX' f.svg`, then
placed in your SVG inside a `<g transform="translate(x,y) scale(s)">` (or as a PNG
`data:` URI). Never write `x^2` as plain text in an image.
