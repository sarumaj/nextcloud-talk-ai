#!/usr/bin/env python3
"""Text that fits, for images drawn with code: measure, wrap and place text with the
real font, and check colour contrast. Import it, or run it to try a size.

    import sys; sys.path.insert(0, "/skills/image/scripts")
    from textfit import font_file, measure, wrap, fit, svg_text, contrast

    font_file("Open Sans", bold=True)           -> path of the TTF (for Pillow)
    measure("Hello", "Open Sans", 40)           -> (width, height) in px
    wrap(text, "Open Sans", 40, 900)            -> lines no wider than 900 px
    fit(text, "Open Sans", 900, 300, 72)        -> (size, lines): the largest size up to
                                                   72 at which the text fits 900 × 300 px
    svg_text(text, x, y, "Open Sans", 40, 900, anchor="middle", fill="#222", bold=True)
                                                -> (SVG <text> with one <tspan> per line,
                                                   the block's height in px)
    contrast("#ffffff", "#0072B2")              -> 5.0 (WCAG ratio; text needs 4.5, large 3)

    python3 textfit.py "Sommerfest 2027" "Open Sans" 900 300 --bold   (prints size and lines)

Sizes are in pixels at the image's own scale (an SVG's viewBox units). Lines break at
spaces; a word wider than the width stays on its own line (fit then takes a smaller size).
"""
import argparse
import functools
import subprocess
import sys
from xml.sax.saxutils import escape

from PIL import ImageFont


@functools.lru_cache(maxsize=64)
def font_file(family: str, bold: bool = False, italic: bool = False) -> str:
    """The installed font file for a family (fontconfig picks the nearest if it is missing)."""
    style = ":bold" if bold else ""
    style += ":italic" if italic else ""
    run = subprocess.run(["fc-match", "-f", "%{file}", family + style], capture_output=True, text=True)
    return run.stdout.strip()


@functools.lru_cache(maxsize=256)
def _font(family: str, size: float, bold: bool, italic: bool):
    return ImageFont.truetype(font_file(family, bold, italic), max(1, round(size)))


def measure(text: str, family: str, size: float, bold: bool = False, italic: bool = False) -> tuple:
    """Width and height of one line of text in px."""
    font = _font(family, size, bold, italic)
    ascent, descent = font.getmetrics()
    return font.getlength(text), ascent + descent


def wrap(text: str, family: str, size: float, width: float, bold: bool = False, italic: bool = False) -> list:
    """The text in lines no wider than width (explicit line breaks are kept)."""
    lines = []
    for paragraph in text.split("\n"):
        line = ""
        for word in paragraph.split():
            candidate = (line + " " + word).strip()
            if line and measure(candidate, family, size, bold, italic)[0] > width:
                lines.append(line)
                line = word
            else:
                line = candidate
        lines.append(line)
    return lines


def fit(text: str, family: str, width: float, height: float, largest: float = 96, smallest: float = 8,
        line_height: float = 1.25, bold: bool = False, italic: bool = False) -> tuple:
    """The largest size (and its lines) at which the text fits into width × height."""
    size = largest
    while size > smallest:
        lines = wrap(text, family, size, width, bold, italic)
        widest = max(measure(line, family, size, bold, italic)[0] for line in lines)
        if widest <= width and len(lines) * size * line_height <= height:
            return size, lines
        size -= 1
    return smallest, wrap(text, family, smallest, width, bold, italic)


def svg_text(text: str, x: float, y: float, family: str, size: float, width: float, anchor: str = "start",
             fill: str = "#000", bold: bool = False, italic: bool = False, line_height: float = 1.25) -> tuple:
    """An SVG <text> block whose first baseline is at y, wrapped to width; and its height."""
    lines = wrap(text, family, size, width, bold, italic)
    attrs = 'x="%g" y="%g" font-family="%s" font-size="%g" text-anchor="%s" fill="%s"' % (x, y, escape(family), size, anchor, fill)
    attrs += ' font-weight="bold"' if bold else ""
    attrs += ' font-style="italic"' if italic else ""
    spans = "".join('<tspan x="%g" dy="%g">%s</tspan>' % (x, 0 if i == 0 else size * line_height, escape(line))
                    for i, line in enumerate(lines))
    return "<text %s>%s</text>" % (attrs, spans), size * line_height * (len(lines) - 1) + size


def _luminance(colour: str) -> float:
    c = colour.lstrip("#")
    c = "".join(ch * 2 for ch in c) if len(c) == 3 else c
    channels = [int(c[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    linear = [v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4 for v in channels]
    return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2]


def contrast(a: str, b: str) -> float:
    """The WCAG contrast ratio of two #rrggbb colours (1 to 21)."""
    hi, lo = sorted((_luminance(a), _luminance(b)), reverse=True)
    return round((hi + 0.05) / (lo + 0.05), 2)


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("text")
    ap.add_argument("family")
    ap.add_argument("width", type=float)
    ap.add_argument("height", type=float)
    ap.add_argument("--largest", type=float, default=96)
    ap.add_argument("--bold", action="store_true")
    a = ap.parse_args()
    size, lines = fit(a.text, a.family, a.width, a.height, a.largest, bold=a.bold)
    print("font: %s\nsize: %g px\nlines:\n  %s" % (font_file(a.family, a.bold), size, "\n  ".join(lines)))
    sys.exit(0)
