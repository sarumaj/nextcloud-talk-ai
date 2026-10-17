#!/bin/bash
# render.sh FILE [FIRST-LAST]: the file as PDF (LibreOffice) and its pages as PNG at
# 150 dpi, plus a contact sheet of up to 12 pages. Prints the paths, one per line.
# An image (png jpg webp gif svg) is one page: as PNG, at most 2000 px on its longer
# side, on a checkerboard where it is transparent, plus a small copy (sheet) to judge
# it at the size a chat shows it.
set -euo pipefail
file="$1"; pages="${2:-}"
[[ -f "$file" ]] || { echo "no such file: $file"; exit 1; }
name="$(basename "${file%.*}")"
# A folder name without spaces or other specials: the paths are printed one per line.
out="/work/.render/${name//[^A-Za-z0-9._-]/_}"
rm -rf "$out"; mkdir -p "$out"
case "${file,,}" in
  *.png|*.jpg|*.jpeg|*.webp|*.gif|*.svg)
    if [[ "${file,,}" == *.svg ]]; then
      rsvg-convert --keep-aspect-ratio -w 2000 "$file" -o "$out/raw.png" 2>"$out/rsvg.log" \
        || { echo "the SVG does not render:"; cat "$out/rsvg.log"; exit 1; }
    else
      convert "$file[0]" -resize '2000x2000>' "$out/raw.png" 2>"$out/convert.log" \
        || { echo "the image does not open:"; cat "$out/convert.log"; exit 1; }
    fi
    size=$(identify -format '%wx%h' "$out/raw.png")
    convert -size "$size" tile:pattern:checkerboard "$out/raw.png" -composite "$out/page-1.png" 2>/dev/null \
      || cp "$out/raw.png" "$out/page-1.png"
    convert "$out/page-1.png" -resize 480x480 "$out/sheet.png"
    echo "pages: 1"
    echo "$out/page-1.png"
    echo "sheet: $out/sheet.png"
    exit 0;;
esac
if [[ "${file,,}" == *.pdf ]]; then
  cp "$file" "$out/$name.pdf"
else
  soffice --headless --norestore --convert-to pdf --outdir "$out" "$file" >/dev/null 2>"$out/soffice.log" \
    || { echo "LibreOffice could not open or convert the file:"; cat "$out/soffice.log"; exit 1; }
fi
pdf="$out/$(basename "${file%.*}").pdf"
[[ -s "$pdf" ]] || { echo "no PDF came out; the file may be damaged"; exit 1; }
args=(-r 150 -png)
if [[ -n "$pages" ]]; then args+=(-f "${pages%-*}" -l "${pages#*-}"); fi
pdftoppm "${args[@]}" "$pdf" "$out/page"
count=$(pdfinfo "$pdf" | awk '/^Pages:/ {print $2}')
echo "pdf: $pdf"
echo "pages: $count"
ls "$out"/page-*.png
# A contact sheet: all pages small, to see the whole document at once.
mapfile -t thumbs < <(ls "$out"/page-*.png | head -12)
if (( ${#thumbs[@]} > 1 )); then
  cols=$(( ${#thumbs[@]} < 4 ? ${#thumbs[@]} : 4 ))
  montage "${thumbs[@]}" -tile "${cols}x" -geometry 420x420+10+10 -background '#d0d0d0' "$out/sheet.png" 2>/dev/null && echo "sheet: $out/sheet.png"
fi
