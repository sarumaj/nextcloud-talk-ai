# Spreadsheets: XLSX and ODS

Build with **openpyxl**. For ODS build the XLSX and convert it with LibreOffice
(`python3 /skills/common/scripts/lo_update.py /work/output/x.xlsx /work/output/x.ods`,
which also recalculates). Render the final file.

## Rules

- **Formulas, not results.** Every sum, average, share or difference is a formula
  (`=SUM(B2:B13)`, `=B2/B$14`), so the sheet stays right when numbers change. Write the
  English function names in openpyxl; Excel and LibreOffice show them in the user's
  language (SUMME, MITTELWERT).
- **Real numbers and dates** in cells (`12.5`, `datetime.date(2026, 10, 15)`), never
  text like "12,50 €"; the display comes from `number_format` ('#,##0.00 €',
  '0.0%', 'DD.MM.YYYY').
- A header row (bold, filled, frozen: `ws.freeze_panes = "A2"`), an autofilter for lists
  (`ws.auto_filter.ref = ws.dimensions`), readable column widths.
- Only data the person gave you or that the inputs hold; never invented figures.

## Pattern

```python
import csv
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.chart import BarChart, LineChart, PieChart, Reference
from openpyxl.utils import get_column_letter

wb = Workbook(); ws = wb.active; ws.title = "Daten"
rows = list(csv.reader(open("/work/input/data.csv", newline="", encoding="utf-8-sig"), delimiter=";"))
ws.append(rows[0])
for r in rows[1:]:
    ws.append([float(v.replace(",", ".")) if v.replace(",", "").replace(".", "").lstrip("-").isdigit() else v for v in r])
last = ws.max_row
ws.append(["Summe"] + [f"=SUM({get_column_letter(c)}2:{get_column_letter(c)}{last})" for c in range(2, ws.max_column + 1)])
head = PatternFill("solid", fgColor="1F4E78")
for cell in ws[1]:
    cell.font = Font(bold=True, color="FFFFFF"); cell.fill = head; cell.alignment = Alignment(horizontal="center")
for cell in ws[ws.max_row]:
    cell.font = Font(bold=True); cell.border = Border(top=Side(style="thin"))
for c in range(1, ws.max_column + 1):
    width = max(len(str(ws.cell(r, c).value or "")) for r in range(1, ws.max_row + 1))
    ws.column_dimensions[get_column_letter(c)].width = min(max(10, width + 2), 50)
for row in ws.iter_rows(min_row=2, min_col=2):
    for cell in row:
        cell.number_format = "#,##0.00"
ws.freeze_panes = "A2"

chart = BarChart(); chart.title = "Umsatz je Monat"; chart.y_axis.title = "€"
data = Reference(ws, min_col=2, min_row=1, max_row=last, max_col=ws.max_column)   # with the header row
cats = Reference(ws, min_col=1, min_row=2, max_row=last)
chart.add_data(data, titles_from_data=True); chart.set_categories(cats)
chart.width, chart.height = 18, 9
ws.add_chart(chart, f"{get_column_letter(ws.max_column + 2)}2")
# Printing (and the render): landscape, all columns on one page width.
ws.page_setup.orientation = "landscape"; ws.page_setup.fitToWidth = 1; ws.page_setup.fitToHeight = 0
ws.sheet_properties.pageSetUpPr.fitToPage = True
wb.save("/work/output/Auswertung.xlsx")
```

## Checks

- `python3 /skills/xlsx/scripts/describe.py FILE` shows sheets, sizes, header rows,
  formulas and sample values (also for CSV, ODS and existing workbooks).
- `python3 /skills/common/scripts/check.py FILE --formulas` after
  `python3 /skills/common/scripts/lo_update.py FILE` (which stores calculated values) finds
  error values (#REF!, #DIV/0!) and missing formulas.
- Render: a chart must show data and stand whole on one page (not cut at a page edge: fit to
  page width, or put it below the table); columns must not show "###".

## Changing an existing workbook

`openpyxl.load_workbook(path)` keeps values, formulas and most formatting (not charts
from other programs, which are lost: then work on a copy, add a new sheet, and say so).
Never use `data_only=True` when you save afterwards: it would replace formulas by values.
