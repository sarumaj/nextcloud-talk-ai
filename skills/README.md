# Skills for document jobs

Each folder holds a `SKILL.md` that the model reads before building a file of that
kind, and scripts it may run. They are mounted read-only at `/skills` in the
sandbox (see `sandbox/`), so they can be updated without a release of the app.

| Skill | For |
|---|---|
| `common` | The workflow, rendering (`render.sh`), the automatic checks (`check.py`) and formulas as images (`formula.py`, ziamath). Read first. |
| `docx` | Text documents: DOCX (python-docx) and ODT; templates (.dotx/.ott); changing existing documents. |
| `xlsx` | Spreadsheets: XLSX (openpyxl) and ODS; formulas, charts, formatting. |
| `pptx` | Presentations: PPTX (python-pptx) and ODP; templates (.potx/.otp), layouts, notes. |
| `pdf` | PDF: from a document by LibreOffice, or directly with reportlab; reading and merging with pypdf. |
| `exam` | Exam papers: practice and mock exams with cover, numbered material, tasks with operators and marks, the marking scheme by requirement level, and the key to grades (`grading_key.py`). |
| `image` | Images drawn with code: diagrams and flowcharts (Graphviz), posters, illustrations and icons (SVG), charts and geometry (matplotlib), changes to photos (Pillow); text that fits (`textfit.py`), colours, sizes. |

Written for this project (AGPL-3.0-or-later); not taken from other skill collections.
