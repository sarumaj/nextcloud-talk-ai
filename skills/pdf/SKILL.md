# PDF

Usually the best PDF is an exported document: build it as DOCX/ODT, XLSX or PPTX
following that skill, then export with LibreOffice, which embeds the fonts:
`python3 /skills/common/scripts/lo_update.py /work/output/Bericht.docx /work/output/Bericht.pdf`
(updates the table of contents and fields first). Render the PDF itself before delivering.

For a PDF of a fixed design (certificates, forms, posters), draw it with **reportlab**:

```python
from reportlab.lib.pagesizes import A4
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, Image
from reportlab.lib.styles import getSampleStyleSheet
from reportlab.lib import colors
from reportlab.lib.units import cm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
pdfmetrics.registerFont(TTFont("LiberationSans", "/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf"))
pdfmetrics.registerFont(TTFont("LiberationSans-Bold", "/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf"))
styles = getSampleStyleSheet()
for s in styles.byName.values():
    s.fontName = "LiberationSans-Bold" if "Heading" in s.name or s.name == "Title" else "LiberationSans"
doc = SimpleDocTemplate("/work/output/x.pdf", pagesize=A4, leftMargin=2*cm, rightMargin=2*cm, topMargin=2*cm, bottomMargin=2*cm)
story = [Paragraph("Titel", styles["Title"]), Spacer(1, 12), Paragraph("Text mit Umlauten: äöüß", styles["BodyText"])]
t = Table([["A", "B"], ["1", "2"]], colWidths=[8*cm, 8*cm])
t.setStyle(TableStyle([("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1F4E78")), ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
                       ("GRID", (0, 0), (-1, -1), 0.5, colors.grey), ("FONTNAME", (0, 0), (-1, -1), "LiberationSans")]))
story.append(t)
doc.build(story)
```

Register a TrueType font for any text beyond Latin-1 (umlauts are fine with the built-in
fonts, Polish letters and symbols are not). Merge, split, rotate or fill forms with
**pypdf** (`PdfReader`, `PdfWriter`); read text with `pdftotext -layout`.
