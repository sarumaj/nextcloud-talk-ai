"""Fields for python-docx documents: page numbers and a table of contents.

    import sys; sys.path.insert(0, "/skills/docx/scripts")
    from fields import add_page_number, add_toc
    add_page_number(doc.sections[0].footer.paragraphs[0])   # "3"
    add_toc(doc.add_paragraph())                             # filled by lo_update.py
"""
from docx.oxml import OxmlElement
from docx.oxml.ns import qn


def _field(paragraph, instruction, placeholder=""):
    run = paragraph.add_run()
    for kind, text in (("begin", None), (None, instruction), ("separate", None), ("text", placeholder), ("end", None)):
        if kind == "text":
            t = OxmlElement("w:t"); t.text = placeholder; run._r.append(t)
        elif kind:
            el = OxmlElement("w:fldChar"); el.set(qn("w:fldCharType"), kind); run._r.append(el)
        else:
            el = OxmlElement("w:instrText"); el.set(qn("xml:space"), "preserve"); el.text = text; run._r.append(el)
    return run


def add_page_number(paragraph, total=False):
    _field(paragraph, "PAGE", "1")
    if total:
        paragraph.add_run(" / ")
        _field(paragraph, "NUMPAGES", "1")


def add_toc(paragraph, levels="1-3"):
    """A table of contents of the headings; run /skills/common/scripts/lo_update.py to fill it."""
    _field(paragraph, f'TOC \\o "{levels}" \\h \\z \\u', "Table of contents: run lo_update.py")
