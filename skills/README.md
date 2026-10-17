# Skills for document jobs

Each folder holds a `SKILL.md` that the model reads before building a file of that
kind, and scripts it may run. They are mounted read-only at `/skills` in the
sandbox (see `sandbox/`), so they can be updated without a release of the app.

| Skill | For |
|---|---|
| `common` | The workflow, rendering (`render.sh`) and the automatic checks (`check.py`). Read first. |
| `docx` | Text documents: DOCX (python-docx) and ODT; templates (.dotx/.ott); changing existing documents. |
| `xlsx` | Spreadsheets: XLSX (openpyxl) and ODS; formulas, charts, formatting. |
| `pptx` | Presentations: PPTX (python-pptx) and ODP; templates (.potx/.otp), layouts, notes. |
| `pdf` | PDF: from a document by LibreOffice, or directly with reportlab; reading and merging with pypdf. |
| `exam` | Exam papers: practice exams and mock Abitur with cover, numbered material, tasks with operators and BE, the Erwartungshorizont and the key to points (`grading_key.py`). |

Written for this project (AGPL-3.0-or-later); not taken from other skill collections.
