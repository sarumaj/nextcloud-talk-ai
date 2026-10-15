#!/usr/bin/env python3
"""The golden cases: each builds a file as a model should (or as it should not) and
states what the checks must say. T-01 ODT protocol, T-02 template, T-03 CSV to XLSX
with formulas and chart, T-04 PPTX deck with notes, T-05 the same as ODP, T-06 a
change that keeps the rest, T-07 PDF export with embedded fonts; and broken files the
checks must catch (heading jump, empty paragraphs, overflowing table, empty slide,
typed results instead of formulas)."""
import csv, os, subprocess, sys

os.makedirs("/work/out", exist_ok=True)
os.environ["HOME"] = "/tmp"
results = []


def check(path, *flags):
    run = subprocess.run(["python3", "/skills/common/scripts/check.py", path, *flags], capture_output=True, text=True, timeout=300)
    return run.returncode == 0, run.stdout


def soffice(target, path):
    subprocess.run(["soffice", "--headless", "--norestore", "--convert-to", target, "--outdir", "/work/out", path], capture_output=True, timeout=300)
    return os.path.splitext(path)[0] + "." + target.split(":")[0]


def expect(name, ok, report, want=True, must=()):
    good = ok == want and all(m in report for m in must)
    results.append(good)
    print(("PASS " if good else "FAIL ") + name)
    if not good:
        print(report)


from docx import Document
from docx.shared import Cm


def protocol(path, base=None):
    d = Document(base) if base else Document()
    d.add_heading("Protokoll der Vorstandssitzung", 0)
    d.add_paragraph("14.10.2026, 19:00–20:30, Vereinsheim")
    d.add_heading("Tagesordnung", 1)
    for item in ["Kassenbericht", "Sommerfest 2027", "Gartengeräte", "Verschiedenes"]:
        d.add_paragraph(item, style="List Number")
    d.add_heading("Beschlüsse", 1)
    d.add_paragraph("Budget für das Sommerfest: 1.500 EUR (4 dafür, 0 dagegen).")
    d.save(path)


# T-01: a protocol as ODT
protocol("/work/out/t01.docx")
odt = soffice("odt", "/work/out/t01.docx")
ok, report = check(odt, "--lang", "de")
expect("T-01 protocol as ODT opens, has headings, no empty page", ok, report)

# T-02: on a template: header and footer survive
base = Document()
base.sections[0].header.paragraphs[0].text = "Verein Sonnenhof e.V."
base.sections[0].footer.paragraphs[0].text = "Vertraulich"
base.save("/work/out/template.docx")
protocol("/work/out/t02.docx", "/work/out/template.docx")
ok, report = check("/work/out/t02.docx")
diff = subprocess.run(["python3", "/skills/common/scripts/diff.py", "/work/out/template.docx", "/work/out/t02.docx"], capture_output=True, text=True).stdout
expect("T-02 template keeps header and footer", ok, report + diff, must=())
expect("T-02 diff sees header and footer kept", "headers and footers: kept" in diff, diff)

# T-03: CSV to XLSX with formulas and chart
from openpyxl import Workbook
from openpyxl.chart import BarChart, Reference
rows = list(csv.reader(open("/work/umsatz.csv", encoding="utf-8"), delimiter=";"))
wb = Workbook(); ws = wb.active; ws.append(rows[0])
for r in rows[1:]:
    ws.append([r[0], float(r[1].replace(".", "").replace(",", ".")), float(r[2].replace(".", "").replace(",", "."))])
ws.append(["Summe", "=SUM(B2:B4)", "=SUM(C2:C4)"])
chart = BarChart(); chart.add_data(Reference(ws, min_col=2, min_row=1, max_row=4, max_col=3), titles_from_data=True)
chart.set_categories(Reference(ws, min_col=1, min_row=2, max_row=4)); ws.add_chart(chart, "E2")
wb.save("/work/out/t03.xlsx")
ok, report = check("/work/out/t03.xlsx", "--formulas")
expect("T-03 XLSX with formulas", ok, report, must=("formulas: 2",))
wb2 = Workbook(); ws2 = wb2.active; ws2.append(["Summe", 39800.5]); wb2.save("/work/out/typed.xlsx")
ok, report = check("/work/out/typed.xlsx", "--formulas")
expect("caught: typed results instead of formulas", ok, report, want=False, must=("no formulas",))

# T-04 and T-05: a deck with notes, and as ODP
from pptx import Presentation
p = Presentation()
for i in range(10):
    s = p.slides.add_slide(p.slide_layouts[1])
    s.shapes.title.text = "Wasserkreislauf %d" % (i + 1)
    s.placeholders[1].text = "Verdunstung\nKondensation\nNiederschlag"
    s.notes_slide.notes_text_frame.text = "Hinweise für Folie %d" % (i + 1)
p.save("/work/out/t04.pptx")
ok, report = check("/work/out/t04.pptx")
expect("T-04 ten slides, notes, no overflow", ok and "without speaker notes" not in report, report, must=("slides: 10",))
odp = soffice("odp", "/work/out/t04.pptx")
ok, report = check(odp)
expect("T-05 the deck as ODP opens with ten slides", ok, report, must=("pages: 10",))

# T-06: a chapter added, the rest kept
d = Document("/work/out/t02.docx"); d.add_heading("Nächste Schritte", 1); d.add_paragraph("Angebot einholen bis 31.10."); d.save("/work/out/t06.docx")
diff = subprocess.run(["python3", "/skills/common/scripts/diff.py", "/work/out/t02.docx", "/work/out/t06.docx"], capture_output=True, text=True).stdout
expect("T-06 only the new chapter changed", "2 line(s) added, 0 removed" in diff and "styles: kept" in diff, diff)

# T-07: PDF export with embedded fonts and the same pages
pdf = soffice("pdf", "/work/out/t02.docx")
ok, report = check(pdf)
expect("T-07 PDF with embedded fonts", ok and "not embedded" not in report, report)

# Broken files the checks must catch
d = Document(); d.add_heading("A", 1); d.add_heading("C", 3); d.save("/work/out/jump.docx")
ok, report = check("/work/out/jump.docx")
expect("caught: heading jump", ok, report, want=False, must=("jumps from 1 to 3",))
d = Document(); d.add_paragraph("x"); [d.add_paragraph("") for _ in range(3)]; d.add_paragraph("y"); d.save("/work/out/empty.docx")
ok, report = check("/work/out/empty.docx")
expect("caught: empty paragraphs", ok, report, want=False, must=("empty paragraphs",))
d = Document(); t = d.add_table(rows=1, cols=8)
for c in t.rows[0].cells:
    c.width = Cm(4)
d.save("/work/out/wide.docx")
ok, report = check("/work/out/wide.docx")
expect("caught: table wider than the page", ok, report, want=False, must=("wider than the text area",))
p = Presentation(); p.slides.add_slide(p.slide_layouts[6]); p.save("/work/out/empty.pptx")
ok, report = check("/work/out/empty.pptx")
expect("caught: empty slide", ok, report, want=False, must=("slide 1 is empty",))

print("%d of %d golden cases pass" % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)
