#!/usr/bin/env python3
"""describe.py FILE.pptx [--layouts] [--overflow]: slides with their shapes and text;
--layouts lists the masters' layouts and their placeholders; --overflow estimates
text frames whose text needs more room than they have."""
import sys
from pptx import Presentation
from pptx.util import Emu
prs = Presentation(sys.argv[1])
print(f"size {Emu(prs.slide_width).inches:.2f}×{Emu(prs.slide_height).inches:.2f} in, {len(prs.slides)} slides")
if "--layouts" in sys.argv:
    for i, layout in enumerate(prs.slide_layouts):
        holders = ", ".join(f"{p.placeholder_format.idx}:{p.placeholder_format.type}".replace("PP_PLACEHOLDER.", "") for p in layout.placeholders)
        print(f"layout {i} {layout.name!r}: {holders}")
    sys.exit(0)
for n, slide in enumerate(prs.slides, 1):
    print(f"slide {n} (layout {slide.slide_layout.name!r})")
    for shape in slide.shapes:
        text = shape.text_frame.text.replace("\n", " / ")[:90] if shape.has_text_frame else ""
        print(f"   {shape.shape_type} {shape.name!r} at {Emu(shape.left or 0).inches:.1f},{Emu(shape.top or 0).inches:.1f} {Emu(shape.width or 0).inches:.1f}×{Emu(shape.height or 0).inches:.1f} in {text}")
        if "--overflow" in sys.argv and shape.has_text_frame and shape.width and shape.height:
            lines = 0.0
            for p in shape.text_frame.paragraphs:
                size = max([r.font.size.pt for r in p.runs if r.font.size] or [18])
                chars_per_line = max(1, Emu(shape.width).inches * 72 / (size * 0.5))
                lines += max(1, len(p.text) / chars_per_line) * size * 1.25 / 72
            if lines > Emu(shape.height).inches * 1.05:
                print(f"      ⚠ text needs about {lines:.1f} in, the box has {Emu(shape.height).inches:.1f} in")
    if slide.has_notes_slide and slide.notes_slide.notes_text_frame.text.strip():
        print("   notes:", slide.notes_slide.notes_text_frame.text[:80].replace("\n", " "))
