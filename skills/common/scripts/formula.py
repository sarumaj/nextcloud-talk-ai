#!/usr/bin/env python3
"""LaTeX maths as an image, without TeX: ziamath typesets it to SVG (its own math
font), rsvg-convert turns that into PNG.

    python3 formula.py 'x_{1,2} = \\frac{-b \\pm \\sqrt{b^2-4ac}}{2a}' out.png
    python3 formula.py --file derivation.tex out.png --size 28
    python3 formula.py --file f.tex out.svg               (SVG, e.g. for reportlab via svglib or the web)
    python3 formula.py --mathml 'e^{i\\pi}+1=0'            (MathML, e.g. for an ODT formula object)

The LaTeX is one formula; several lines go in \\begin{aligned} … \\end{aligned} (or gathered,
cases, pmatrix …). Text in formulas: \\text{…}. Options: --size in points (24), --scale
for PNG (2 = 2× for sharp output), --transparent (no white background), --color.
Prints the image's size in pixels; exit code 1 with the reason when the LaTeX fails.
"""
import argparse
import subprocess
import sys


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("latex", nargs="?", help="the formula (or --file)")
    ap.add_argument("out", nargs="?", help="output .png or .svg")
    ap.add_argument("--file", help="read the formula from this file")
    ap.add_argument("--size", type=float, default=24)
    ap.add_argument("--scale", type=float, default=2)
    ap.add_argument("--color", default="black")
    ap.add_argument("--transparent", action="store_true")
    ap.add_argument("--mathml", action="store_true", help="print MathML instead of drawing")
    a = ap.parse_args()
    if a.file:
        a.out = a.out or a.latex
        with open(a.file, encoding="utf-8") as f:
            latex = f.read().strip()
    else:
        latex = (a.latex or "").strip()
    latex = latex.strip("$").strip()
    if latex.startswith("\\[") and latex.endswith("\\]"):
        latex = latex[2:-2].strip()
    if not latex:
        print("no formula", file=sys.stderr)
        return 1
    if a.mathml:
        import latex2mathml.converter
        print(latex2mathml.converter.convert(latex, display="block"))
        return 0
    if not a.out:
        print("an output file (.png or .svg)", file=sys.stderr)
        return 1
    import ziamath
    try:
        svg = ziamath.Latex(latex, size=a.size, color=a.color).svg()
    except Exception as e:  # ziamath reports bad LaTeX as various errors
        print(f"the LaTeX could not be typeset ({type(e).__name__}: {e or 'incomplete'}); check braces and commands", file=sys.stderr)
        return 1
    if a.out.lower().endswith(".svg"):
        with open(a.out, "w", encoding="utf-8") as f:
            f.write(svg)
        print(f"{a.out}: SVG")
        return 0
    cmd = ["rsvg-convert", "-z", str(a.scale), "-o", a.out]
    if not a.transparent:
        cmd[1:1] = ["-b", "white"]
    r = subprocess.run(cmd, input=svg.encode(), capture_output=True)
    if r.returncode != 0:
        print(r.stderr.decode(errors="replace"), file=sys.stderr)
        return 1
    from PIL import Image
    with Image.open(a.out) as im:
        w, h = im.size
    # Pad a little so the formula does not touch the edge of a chat bubble or a cell.
    if not a.transparent:
        with Image.open(a.out) as im:
            pad = int(8 * a.scale)
            canvas = Image.new("RGB", (w + 2 * pad, h + 2 * pad), "white")
            canvas.paste(im.convert("RGB"), (pad, pad))
            canvas.save(a.out)
            w, h = canvas.size
    print(f"{a.out}: {w}×{h} px")
    return 0


if __name__ == "__main__":
    sys.exit(main())
