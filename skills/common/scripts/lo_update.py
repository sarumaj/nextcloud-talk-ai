#!/usr/bin/python3
"""lo_update.py IN [OUT]: opens a document in LibreOffice (UNO), updates its tables of
contents, indexes and fields (and recalculates a spreadsheet), and saves it in place
or as OUT, whose extension sets the format (.docx .odt .xlsx .ods .pptx .odp .pdf).
Runs with the system Python (it has LibreOffice's uno module)."""
import os, subprocess, sys, time, uuid

import uno
from com.sun.star.beans import PropertyValue

FILTERS = {".docx": "MS Word 2007 XML", ".odt": "writer8", ".pdf": None, ".xlsx": "Calc MS Excel 2007 XML", ".ods": "calc8",
           ".pptx": "Impress MS PowerPoint 2007 XML", ".odp": "impress8"}
PDF = {"text": "writer_pdf_Export", "spreadsheet": "calc_pdf_Export", "presentation": "impress_pdf_Export"}


def prop(name, value):
    p = PropertyValue(); p.Name = name; p.Value = value
    return p


src = os.path.abspath(sys.argv[1])
dst = os.path.abspath(sys.argv[2]) if len(sys.argv) > 2 else src
pipe = "lo" + uuid.uuid4().hex[:8]
office = subprocess.Popen(["soffice", "--headless", "--norestore", "--nologo", f"--accept=pipe,name={pipe};urp;"],
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
try:
    resolver = uno.getComponentContext().ServiceManager.createInstanceWithContext("com.sun.star.bridge.UnoUrlResolver", uno.getComponentContext())
    for _ in range(60):
        try:
            ctx = resolver.resolve(f"uno:pipe,name={pipe};urp;StarOffice.ComponentContext")
            break
        except Exception:
            time.sleep(0.5)
    else:
        sys.exit("LibreOffice did not start")
    desktop = ctx.ServiceManager.createInstanceWithContext("com.sun.star.frame.Desktop", ctx)
    doc = desktop.loadComponentFromURL(uno.systemPathToFileUrl(src), "_blank", 0, (prop("Hidden", True),))
    if doc is None:
        sys.exit("LibreOffice could not open " + src)
    kind = "text"
    if doc.supportsService("com.sun.star.sheet.SpreadsheetDocument"):
        kind = "spreadsheet"; doc.calculateAll()
    elif doc.supportsService("com.sun.star.presentation.PresentationDocument"):
        kind = "presentation"
    else:
        indexes = doc.getDocumentIndexes()
        for i in range(indexes.getCount()):
            indexes.getByIndex(i).update()
        doc.getTextFields().refresh()
        doc.refresh()
    ext = os.path.splitext(dst)[1].lower()
    flt = PDF[kind] if ext == ".pdf" else FILTERS.get(ext)
    if ext not in FILTERS:
        sys.exit("unknown target format " + ext)
    doc.storeToURL(uno.systemPathToFileUrl(dst), (prop("FilterName", flt), prop("Overwrite", True)))
    doc.close(True)
    print("saved:", dst)
finally:
    try:
        desktop.terminate()
    except Exception:
        pass
    office.wait(timeout=20) if office.poll() is None else None
