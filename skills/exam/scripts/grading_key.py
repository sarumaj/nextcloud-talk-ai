#!/usr/bin/env python3
"""The key from BE (Bewertungseinheiten) to points, by OAVO Hessen Anlage 9a.

    python3 grading_key.py 100          a Markdown table: points, percent, from BE
    python3 grading_key.py 100 --csv    the same as CSV

The whole percent counts, not rounded (Anlage 9a): a point is reached with the least BE
whose percentage, cut to a whole number, is at least the threshold. Half BE are allowed
when the total is not a multiple of 100 / 2.
"""
import math
import sys

THRESHOLDS = [(15, 95), (14, 90), (13, 85), (12, 80), (11, 75), (10, 70), (9, 65), (8, 60),
              (7, 55), (6, 50), (5, 45), (4, 40), (3, 33), (2, 27), (1, 20), (0, 0)]
GRADES = {15: "1+", 14: "1", 13: "1−", 12: "2+", 11: "2", 10: "2−", 9: "3+", 8: "3", 7: "3−",
          6: "4+", 5: "4", 4: "4−", 3: "5+", 2: "5", 1: "5−", 0: "6"}


def least_be(total: float, percent: int) -> float:
    """The least BE in half steps whose whole percentage reaches the threshold."""
    be = math.ceil(total * percent / 100 * 2) / 2
    while be > 0 and math.floor((be - 0.5) / total * 100 + 1e-9) >= percent:
        be -= 0.5
    return be


def main() -> None:
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    total = float(sys.argv[1].replace(",", "."))
    if total <= 0:
        sys.exit("the total must be positive")
    rows = []
    for points, percent in THRESHOLDS:
        rows.append((points, GRADES[points], percent, least_be(total, percent)))
    fmt = lambda v: (f"{v:g}").replace(".", ",")
    if "--csv" in sys.argv:
        print("Punkte;Note;ab Prozent;ab BE")
        for p, g, pc, be in rows:
            print(f"{p};{g};{pc};{fmt(be)}")
        return
    print(f"Notenschlüssel für {fmt(total)} BE (OAVO Anlage 9a)\n")
    print("| Punkte | Note | ab % | ab BE |\n|---:|:---:|---:|---:|")
    for p, g, pc, be in rows:
        print(f"| {p} | {g} | {pc} | {fmt(be)} |")


if __name__ == "__main__":
    main()
