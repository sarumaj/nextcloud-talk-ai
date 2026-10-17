# Exam papers: practice exams, mock exams, Klausuren

For a practice task, a mock exam (school or university) or a Klausur with its marking
scheme (Erwartungshorizont). Build the paper as DOCX (read `/skills/docx/SKILL.md` too)
or as PDF from it. The task gives you the content and the rules of the real exam (time,
aids, choice of tasks, total marks, operators, grading table), taken from official
documents; this guide gives the form. Never present it as official: it is a practice paper.

## Two files

1. **The paper** (for the student), `<Subject> – Übungsklausur.docx` (or in the
   language of the task).
2. **The marking scheme** (`… – Erwartungshorizont.docx`), unless the task asks for one
   file; then it starts on a new page after the last task.

## The paper

- **Cover, its own page:** "Practice paper – not official" (in the paper's language,
  e.g. "Übungsklausur – nicht amtlich") at the top; subject, course or level; the parts
  and the choice rule as the task states it ("choose one of B1 and B2"); time in minutes;
  the allowed aids as a list; a line for the name.
- **Material:** each text, figure or table as "Material 1" ("M 1") …, with its source under
  it (author, title, year; "adapted" if shortened). Number the lines of every text every
  fifth line (5, 10, 15 …) on the left, so tasks can refer to lines: a two-column table
  (numbers 8 pt grey | text) or the section's line numbering (python-docx: `w:lnNumType`
  with `w:countBy="5"` and `w:restart="newSection"` on the section's `sectPr`). Render and
  check that the numbers match the lines.
- **Tasks:** a table of three columns: number (1, 1.1, 1.2 …) | the task, starting with
  its operator in bold (e.g. **Erläutern** Sie …, **Explain** …) | marks, right aligned. A
  last row with the total. Never split a task row across pages (`cantSplit` on the row).
- **Formulas** typeset (`/skills/common/scripts/formula.py`, see the common skill), never as
  plain text. A part without calculator gets its own pages and says so.
- Header: subject and "practice paper"; footer: page "x of y".

## The marking scheme

- A table per task set: task | expected answer (bullets, the expected points, acceptable
  alternatives) | marks per requirement level I | II | III (reproduce; apply and
  transfer; reflect and solve problems). The sums under each column; the total equals
  the paper's.
- After the table: grading notes: what makes a pass and what a good result, in sentences
  about the whole work, as official schemes do (German schools: "ausreichend (5 Punkte)"
  and "gut (11 Punkte)").
- The key from marks to grades: `python3 /skills/exam/scripts/grading_key.py <total>`
  gives the usual German 15-point table; the school's or university's own table with
  `--table "15:95,14:90,…"` (result:from percent) or `--table "1,0:95;1,3:90;…"`.

## Check before delivering

- Marks in the paper and in the scheme agree, per task and in total.
- Every task starts with an operator; line references point at the right lines.
- The cover says it is a practice paper; time and aids are those the task names.
