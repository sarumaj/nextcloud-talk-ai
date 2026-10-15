#!/usr/bin/env python3
"""from_template.py TEMPLATE OUT.pptx [--clear]: a presentation from a PowerPoint (.potx)
or Impress (.otp, .odp) template, keeping masters, layouts and theme; --clear removes
the template's slides."""
import os, shutil, subprocess, sys, tempfile, zipfile
src, out = sys.argv[1], sys.argv[2]
if src.lower().endswith((".otp", ".odp", ".ppt", ".pot")):
    work = tempfile.mkdtemp(dir="/tmp")
    subprocess.run(["soffice", "--headless", "--norestore", "--convert-to", "pptx", "--outdir", work, src], check=True, capture_output=True)
    shutil.copy(os.path.join(work, os.path.splitext(os.path.basename(src))[0] + ".pptx"), out)
else:
    with zipfile.ZipFile(src) as zin, zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zout:
        for item in zin.infolist():
            data = zin.read(item.filename)
            if item.filename == "[Content_Types].xml":
                data = data.replace(b"presentationml.template.main+xml", b"presentationml.presentation.main+xml")
            zout.writestr(item, data)
if "--clear" in sys.argv:
    from pptx import Presentation
    prs = Presentation(out)
    ids = prs.slides._sldIdLst
    for sld in list(ids):
        prs.part.drop_rel(sld.rId); ids.remove(sld)
    prs.save(out)
print("ready:", out)
