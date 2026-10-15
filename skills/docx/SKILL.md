# Text documents: DOCX and ODT

Build with **python-docx**; for ODT build the DOCX and convert it with LibreOffice
(`soffice --headless --convert-to odt --outdir /work/output file.docx`). Render the
final file, not only the intermediate one.

## A new document

```python
from docx import Document
from docx.shared import Pt, Cm, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT

doc = Document()                       # or Document("/work/template.docx"), see below
sec = doc.sections[0]
sec.page_width, sec.page_height = Cm(21), Cm(29.7)       # A4 (python-docx defaults to Letter!)
sec.left_margin = sec.right_margin = Cm(2.5)
styles = doc.styles
styles["Normal"].font.name = "Liberation Sans"; styles["Normal"].font.size = Pt(11)
# The document's language (spelling, hyphenation): de-DE, pl-PL, en-GB …
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
rpr = styles["Normal"].element.get_or_add_rPr()
lang = OxmlElement("w:lang"); lang.set(qn("w:val"), "de-DE"); rpr.append(lang)

doc.add_heading("Protokoll der Besprechung", level=0)   # level 0 = Title style
p = doc.add_paragraph(); p.add_run("Datum: ").bold = True; p.add_run("15.10.2026")
doc.add_heading("Teilnehmende", level=1)
for name in ["Ewa", "Maciej"]:
    doc.add_paragraph(name, style="List Bullet")
t = doc.add_table(rows=1, cols=3); t.style = "Light Grid Accent 1"; t.alignment = WD_TABLE_ALIGNMENT.CENTER
for cell, text in zip(t.rows[0].cells, ["Aufgabe", "Wer", "Bis"]):
    cell.text = text
row = t.add_row().cells; row[0].text = "Angebot einholen"; row[1].text = "Dawid"; row[2].text = "22.10."
# Footer with page numbers (field PAGE):
import sys; sys.path.insert(0, "/skills/docx/scripts")
from fields import add_page_number
fp = sec.footer.paragraphs[0]; fp.alignment = WD_ALIGN_PARAGRAPH.CENTER; add_page_number(fp)
doc.save("/work/output/Protokoll_2026-10-15.docx")
```

- Headings: `add_heading(level=1..3)` so they are real heading styles (the table of
  contents and navigation depend on it). Lists: styles "List Bullet", "List Number".
- Built-in table styles: "Table Grid", "Light Grid Accent 1", "Light List Accent 1",
  "Medium Shading 1 Accent 1". Set column widths on every cell of a column
  (`cell.width = Cm(4)`), python-docx ignores column widths alone.
- Page breaks: `doc.add_page_break()`. Never end with an empty page (no page break at the
  end, no trailing empty paragraphs after a table on a full page).
- Images: `doc.add_picture("/work/chart.png", width=Cm(15))`; charts: draw them with
  matplotlib (`plt.savefig(..., dpi=200, bbox_inches="tight")`).
- Page numbers and a table of contents are fields: use the helpers
  ```python
  import sys; sys.path.insert(0, "/skills/docx/scripts")
  from fields import add_page_number, add_toc
  add_page_number(doc.sections[0].footer.paragraphs[0], total=True)   # "3 / 7"
  add_toc(doc.add_paragraph())                                         # where the contents go
  ```
  python-docx cannot compute the contents; after saving, let LibreOffice fill them in:
  `python3 /skills/common/scripts/lo_update.py /work/output/Doc.docx` (updates the table of
  contents and fields in place; with a second path it also converts, e.g. to `.odt` or `.pdf`).

## From a template (.dotx, .ott)

`python3 /skills/docx/scripts/from_template.py /work/input/Vorlage.dotx /work/base.docx --clear-body`
makes a normal document from the template, keeping styles, page setup, headers,
footers and logos, with an empty body. Then `Document("/work/base.docx")` and add
content **only with the template's styles**: list them with
`python3 /skills/docx/scripts/describe.py /work/base.docx --styles` and use those names
(e.g. its "Überschrift 1" or "Heading 1"). Do not set fonts or colours by hand then.

## Changing an existing document (keep its formatting)

1. `python3 /skills/docx/scripts/describe.py /work/input/Doc.docx` lists every paragraph
   with its index, style and text, tables and sections. (ODT: convert to DOCX first,
   change, convert back to ODT; check the render against the original's.)
2. Change in place: insert new paragraphs with the document's own styles next to
   existing ones, e.g. before paragraph `i`:
   `doc.paragraphs[i].insert_paragraph_before("Nächste Schritte", style=doc.paragraphs[j].style)`.
   To append after the last body paragraph, add at the end. Keep all other paragraphs,
   runs and tables untouched.
3. Save under `/work/output/` with the original name (unless told otherwise), render and
   compare with a render of the original (`render_document /work/input/Doc.docx`).

## Pitfalls

- Letter size by default: always set A4 (unless asked for Letter).
- `cell.text = ...` replaces the cell's paragraph formatting; for bold header cells use
  `cell.paragraphs[0].runs[0].bold = True` after setting the text.
- Very long tables: set `repeat` header rows (`tblHeader`) so headers repeat on each page.
- Line breaks inside a paragraph: `run.add_break()`, not "\n".
