#!/usr/bin/env python3
"""from_template.py TEMPLATE OUT.docx [--clear-body]: a document from a Word (.dotx)
or LibreOffice (.ott) template, keeping styles, page setup, headers and footers;
with --clear-body the template's sample text is removed (section settings kept)."""
import os, shutil, subprocess, sys, tempfile, zipfile

src, out = sys.argv[1], sys.argv[2]
work = tempfile.mkdtemp(dir="/tmp")
if src.lower().endswith((".ott", ".odt", ".doc", ".dot")):
    subprocess.run(["soffice", "--headless", "--norestore", "--convert-to", "docx", "--outdir", work, src], check=True, capture_output=True)
    shutil.copy(os.path.join(work, os.path.splitext(os.path.basename(src))[0] + ".docx"), out)
else:
    # A .dotx is a .docx whose main part has the template content type.
    with zipfile.ZipFile(src) as zin, zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zout:
        for item in zin.infolist():
            data = zin.read(item.filename)
            if item.filename == "[Content_Types].xml":
                data = data.replace(b"wordprocessingml.template.main+xml", b"wordprocessingml.document.main+xml")
            zout.writestr(item, data)
if "--clear-body" in sys.argv:
    from docx import Document
    doc = Document(out)
    body = doc.element.body
    for child in list(body.iterchildren()):
        if not child.tag.endswith("}sectPr"):
            body.remove(child)
    doc.save(out)
print("ready:", out)
