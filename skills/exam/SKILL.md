# Exam papers: practice exams, mock Abitur, Klausuren

For a practice task, a mock exam (e.g. Landesabitur Hessen) or a Klausur with its
Erwartungshorizont. Build the paper as DOCX (read `/skills/docx/SKILL.md` too) or as
PDF from it. The task gives you the content; this guide gives the form that German
exam papers have. Never invent official material: a mock exam is marked as one.

## Two files

1. **The paper** (for the student), `<Subject> <Level> – Übungsklausur.docx`.
2. **The Erwartungshorizont** (for checking), `… – Erwartungshorizont.docx`, unless the
   task asks for one file; then it starts on a new page after "Ende der Aufgaben".

## The paper

- **Cover (Deckblatt), its own page:** "Übungsklausur – nicht amtlich" at the top; subject
  and level (Grundkurs / Leistungskurs); for an Abitur mock the Vorschlag (A, B1, …) and
  the Auswahl rule as the task states it ("Wählen Sie einen der Vorschläge B1 oder B2");
  Bearbeitungszeit in minutes; the allowed Hilfsmittel as a list; a line for the name.
- **Material:** each text, figure or table as "Material 1", "M 2" …, with its source
  under it (author, title, year; "verändert" if shortened). Number the lines of every
  text on the left margin, every fifth line (5, 10, 15 …), so tasks can say "Z. 12–18".
  Use a table of two columns (numbers 8 pt grey | text) or line numbering of the section
  (python-docx: `w:lnNumType` with `w:countBy="5"` and `w:restart="newSection"` on the
  section's `sectPr`). Render and check that the numbers match the lines.
- **Tasks:** a table of three columns: number (1, 1.1, 1.2 …) | the task, starting with
  its operator in bold (the official operator, e.g. **Erläutern** Sie …) | BE, right
  aligned. A last row "Summe" with the total BE. Each task fits on its page; never split
  a task row across pages (`cantSplit` on the row).
- **Mathematik:** Prüfungsteil 1 (hilfsmittelfrei) on its own pages with "ohne
  Taschenrechner und Formelsammlung"; formulas as real equations (OMML via the docx skill,
  or LaTeX rendered to images only if equations fail). Space for answers only if asked.
- Header: subject, level, "Übungsklausur"; footer: page "x von y".

## The Erwartungshorizont

- A table per Vorschlag: Teilaufgabe | erwartete Leistung (bullets, the expected points,
  acceptable alternatives "oder") | BE in AFB I | AFB II | AFB III. The sums under each
  column; their total equals the paper's.
- After the table: "Bewertungshinweise" with what makes the work **ausreichend
  (5 Punkte)** and **gut (11 Punkte)**, in sentences about the whole work.
- The key from BE to points (Notenschlüssel): run
  `python3 /skills/exam/scripts/grading_key.py <total BE>` and put its table in. It is the
  OAVO Hessen's Anlage 9a (whole percent, not rounded); another scale only if the task
  names one.
- Deutsch: the share of Verstehensleistung (70 %) and Darstellungsleistung (30 %);
  languages: content and language as the task says.

## Check before delivering

- BE in the paper and in the Erwartungshorizont agree, per task and in total.
- Every task starts with an operator; line references point at the right lines.
- The cover says "nicht amtlich"; times and Hilfsmittel are those the task names.
