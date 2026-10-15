# Presentations: PPTX and ODP

Build with **python-pptx** (16:9); for ODP build the PPTX and convert it
(`python3 /skills/common/scripts/lo_update.py /work/output/x.pptx /work/output/x.odp`).
Render the final file and look at **every** slide.

## Design rules

- 16:9: `prs.slide_width, prs.slide_height = Inches(13.333), Inches(7.5)`.
- One message per slide, a title that says it; at most 5 bullets of at most ~10 words, or
  a visual (chart, numbers, diagram, table, picture) instead of text.
- A consistent design: one colour palette (a dark and an accent colour), one font pair
  (e.g. "Open Sans" body, "Carlito" or "Liberation Sans" titles), the same margins (0.6 in)
  and title position on every slide. Vary the layouts: title slide, agenda, section
  slides, two columns, big number, comparison, picture with text, closing slide.
- Text sizes: title 32–40 pt, body 18–24 pt, never below 14 pt. Nothing may overflow its
  box or the slide: shorten the text rather than shrink it.
- Speaker notes on every content slide: `slide.notes_slide.notes_text_frame.text = "…"`.
- Charts as native charts (`slide.shapes.add_chart`) so they stay editable; tables with
  `add_table`.

## Pattern

```python
from pptx import Presentation
from pptx.util import Inches, Pt, Emu
from pptx.dml.color import RGBColor
from pptx.enum.shapes import MSO_SHAPE
from pptx.enum.text import PP_ALIGN, MSO_ANCHOR
from pptx.chart.data import CategoryChartData
from pptx.enum.chart import XL_CHART_TYPE, XL_LEGEND_POSITION

DARK, ACCENT, LIGHT = RGBColor(0x1F, 0x38, 0x64), RGBColor(0x2E, 0x75, 0xB6), RGBColor(0xF2, 0xF5, 0xFA)
prs = Presentation(); prs.slide_width, prs.slide_height = Inches(13.333), Inches(7.5)
blank = prs.slide_layouts[6]

def text(slide, x, y, w, h, value, size=20, bold=False, color=DARK, align=PP_ALIGN.LEFT, font="Open Sans"):
    box = slide.shapes.add_textbox(Inches(x), Inches(y), Inches(w), Inches(h)); tf = box.text_frame
    tf.word_wrap = True
    for i, line in enumerate(value if isinstance(value, list) else [value]):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.text = line; p.alignment = align; p.space_after = Pt(8)
        p.font.size, p.font.bold, p.font.color.rgb, p.font.name = Pt(size), bold, color, font
    return box

def content_slide(title, notes=""):
    s = prs.slides.add_slide(blank)
    bar = s.shapes.add_shape(MSO_SHAPE.RECTANGLE, 0, 0, Inches(0.25), prs.slide_height)
    bar.fill.solid(); bar.fill.fore_color.rgb = ACCENT; bar.line.fill.background()
    text(s, 0.6, 0.4, 12, 1, title, 34, True, font="Carlito")
    s.notes_slide.notes_text_frame.text = notes
    return s

s = prs.slides.add_slide(blank)                               # title slide
bg = s.background.fill; bg.solid(); bg.fore_color.rgb = DARK
text(s, 0.8, 2.6, 11.5, 1.4, "Thema X", 48, True, RGBColor(255, 255, 255), font="Carlito")
text(s, 0.8, 4.0, 11.5, 0.8, "Untertitel · Datum", 22, color=RGBColor(0xDD, 0xE6, 0xF3))

s = content_slide("Drei Zahlen, die zählen", "Erklärt die Zahlen …")    # big numbers
for i, (num, label) in enumerate([("97 %", "Salzwasser"), ("2 %", "Eis"), ("1 %", "nutzbar")]):
    card = s.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(0.6 + i * 4.1), Inches(2), Inches(3.8), Inches(3.6))
    card.fill.solid(); card.fill.fore_color.rgb = LIGHT; card.line.fill.background()
    text(s, 0.6 + i * 4.1, 2.5, 3.8, 1.4, num, 54, True, ACCENT, PP_ALIGN.CENTER, "Carlito")
    text(s, 0.6 + i * 4.1, 4.0, 3.8, 1.0, label, 20, align=PP_ALIGN.CENTER)

s = content_slide("Entwicklung", "…")                                   # native chart
data = CategoryChartData(); data.categories = ["2022", "2023", "2024"]; data.add_series("Umsatz", (12, 15, 19))
chart = s.shapes.add_chart(XL_CHART_TYPE.COLUMN_CLUSTERED, Inches(0.6), Inches(1.6), Inches(12), Inches(5.4), data).chart
chart.has_legend = False; chart.plots[0].series[0].format.fill.solid(); chart.plots[0].series[0].format.fill.fore_color.rgb = ACCENT
prs.save("/work/output/Thema_X.pptx")
```

## From a template (.potx, .otp) or changing a presentation

- `python3 /skills/pptx/scripts/from_template.py /work/input/Vorlage.potx /work/base.pptx --clear`
  makes a presentation from the template (its masters, layouts, theme, logos) without
  its sample slides. Then use **its layouts**: list them with
  `python3 /skills/pptx/scripts/describe.py /work/base.pptx --layouts` and fill their
  placeholders (`slide.placeholders[idx].text = …`) instead of drawing own boxes.
- To change a presentation, inspect it, edit the slides you must, keep the others, and
  keep its design; new slides use its layouts.

## Checks

`python3 /skills/pptx/scripts/describe.py FILE --overflow` estimates text that does not fit
its box; the render shows the truth. Fix overflow by shortening or splitting the slide.
