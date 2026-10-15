#!/usr/bin/env python3
"""describe.py FILE.docx [--styles]: the document's structure: sections (page size,
margins, header and footer text), each paragraph (index, style, text) and table
(index, size, first row); with --styles the paragraph and table styles it defines."""
import sys
from docx import Document
from docx.shared import Cm

doc = Document(sys.argv[1])
if "--styles" in sys.argv:
    for kind in (1, 3):  # paragraph, table
        names = sorted(s.name for s in doc.styles if s.type == kind)
        print(("paragraph" if kind == 1 else "table") + " styles:", ", ".join(names))
    sys.exit(0)
for i, s in enumerate(doc.sections):
    print(f"section {i}: {s.page_width.cm:.1f}×{s.page_height.cm:.1f} cm, margins {s.left_margin.cm:.1f}/{s.right_margin.cm:.1f} cm")
    print("  header:", " | ".join(p.text for p in s.header.paragraphs if p.text.strip()) or "-")
    print("  footer:", " | ".join(p.text for p in s.footer.paragraphs if p.text.strip()) or "-")
body = doc.element.body
p_index = t_index = 0
for child in body.iterchildren():
    tag = child.tag.split("}")[1]
    if tag == "p":
        p = doc.paragraphs[p_index]
        print(f"[p{p_index}] ({p.style.name}) {p.text[:120]}")
        p_index += 1
    elif tag == "tbl":
        t = doc.tables[t_index]
        first = " | ".join(c.text.strip()[:20] for c in t.rows[0].cells) if t.rows else ""
        print(f"[t{t_index}] table {len(t.rows)}×{len(t.columns)} ({t.style.name if t.style else '-'}): {first}")
        t_index += 1
