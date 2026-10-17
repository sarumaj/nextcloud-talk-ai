#!/usr/bin/env python3
"""The key from marks to points or grades for an exam's total.

    python3 grading_key.py 100                          the usual German 15-point table
    python3 grading_key.py 60 --table "1,0:95;2,0:80;3,0:65;4,0:50;5,0:0"
    python3 grading_key.py 100 --csv                    as CSV

--table: result:from percent, separated by commas, or by semicolons when the results
have decimal commas (1,0:95;1,3:90). The whole percent
counts, not rounded, as most German regulations say; --exact counts the exact percent.
Half marks are allowed when the total is not a multiple of 100 / 2.
"""
import math
import re
import sys

DEFAULT = [("15", 95), ("14", 90), ("13", 85), ("12", 80), ("11", 75), ("10", 70), ("9", 65), ("8", 60),
           ("7", 55), ("6", 50), ("5", 45), ("4", 40), ("3", 33), ("2", 27), ("1", 20), ("0", 0)]


def parse(spec: str) -> list[tuple[str, float]]:
    items = [i for i in re.split(r"[;\s]+" if ";" in spec else r"[,\s]+", spec) if i.strip()]
    pairs = [i.rsplit(":", 1) for i in items if ":" in i]
    if not pairs or len(pairs) != len(items):
        sys.exit("a table like 15:95,14:90,… or, with decimal commas, 1,0:95;1,3:90;…")
    return sorted(((r.strip(), float(p.replace(",", "."))) for r, p in pairs), key=lambda x: -x[1])


def least(total: float, percent: float, whole: bool) -> float:
    """The least marks in half steps that reach the threshold."""
    marks = math.ceil(total * percent / 100 * 2) / 2
    counted = (lambda m: math.floor(m / total * 100 + 1e-9)) if whole else (lambda m: m / total * 100)
    while marks > 0 and counted(marks - 0.5) >= percent:
        marks -= 0.5
    return marks


def main() -> None:
    args = sys.argv[1:]
    if not args:
        sys.exit(__doc__)
    total = float(args[0].replace(",", "."))
    if total <= 0:
        sys.exit("the total must be positive")
    table = parse(args[args.index("--table") + 1]) if "--table" in args else DEFAULT
    whole = "--exact" not in args
    rows = [(r, p, least(total, p, whole)) for r, p in table]
    fmt = lambda v: f"{v:g}".replace(".", ",")
    if "--csv" in args:
        print("Ergebnis;ab Prozent;ab Punkten")
        for r, p, m in rows:
            print(f"{r};{fmt(p)};{fmt(m)}")
        return
    print(f"Key for {fmt(total)} marks ({'whole percent' if whole else 'exact percent'})\n")
    print("| Result | from % | from marks |\n|:---:|---:|---:|")
    for r, p, m in rows:
        print(f"| {r} | {fmt(p)} | {fmt(m)} |")


if __name__ == "__main__":
    main()
