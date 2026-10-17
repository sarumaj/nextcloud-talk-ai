# AI in Talk: developer's guide

## Layout

- `app/talk_ai/`: the Nextcloud app (PHP, no build step).
  - `lib/Listener/BotInvokeListener.php`: Talk's in-process bot events (messages and
    reactions); decides whether to answer, takes "stop", preview answers, confirmations.
  - `lib/Service/Dispatcher.php`: hands work off the request (signed loopback to
    `/process` and `/document`; background jobs as fallback).
  - `lib/Service/Reply.php`: an answer: context (history window, summary, documents of
    the conversation, tool results), capabilities, tools, the status message.
  - `lib/Service/DocumentJob.php`: a document job in the sandbox: the agent loop.
  - `lib/Service/Jobs.php`, `Requests.php`, `Actions.php`: the job queue, the answer
    records (idempotency, tokens, tools), the undo records.
  - `lib/Service/Capabilities.php`, `Policy.php`, `Switches.php`, `Confirmations.php`,
    `Texts.php`, `Health.php`, `Settings.php`, `Golden.php`.
  - `lib/Document/`: the built-in writers and readers, maths and subject tools.
- `sandbox/`: the image (`Dockerfile`) and the runner (`runner.py`: backends docker,
  bwrap and process; the socket protocol, or HTTP for AppAPI); `sandbox/exapp/` the
  External App (Dockerfile, start, HaRP tunnel, the seccomp wrapper `jobexec.py`,
  `appinfo/info.xml`).
- `skills/`: the guides and scripts for the model, mounted read-only at `/skills`.
- `tests/`: PHPUnit (no Nextcloud, no network: a fake AI-Hub replays tool calls, a fake
  sandbox answers like the real one, Talk's events come as fixtures),
  `tests/golden/` (the document cases with the image), `tests/compat/` (Talk's API).

## Exams and studies without built-in rules

The exam and study tools (`lib/Document/Exams.php`, `Study.php`, `Measurement.php`,
`Numerics.php`, `Law.php`, `Equilibria.php`, `Machines.php`) compute; the rules of a state,
school, university or year (grading tables, steps of an error index, weights, syllabus,
operators, dates) are their arguments, which the model takes from the official documents the
person shares or names. Only rules valid everywhere are defaults: the usual German 15-point
table, the KMK formulas of the Abitur, §§ 187–193 BGB. `tests/ExamsTest.php` checks that
the generic rules reproduce real regulations (Hessen's OAVO, Anlagen 9a–10b), so the app
needs no update when an Erlass changes.

`formula` (in `Reply.php`) typesets LaTeX in a sandbox container with
`skills/common/scripts/formula.py` (ziamath to SVG, rsvg-convert to PNG); documents use the
same script.

## The document job's tools

All paths stay inside `/work` (the runner refuses others). AI-Hub runs at most eight
tool calls per question, so the job asks in rounds with its own history and a summary
of the steps; page images go with the next round.

| Tool | Arguments | Result |
|---|---|---|
| `bash` | `command`, `timeout_s` (in all; 1800, at most 3600) | exit code, stdout, stderr (30 kB); stopped as stalled after 120 s without output and CPU use |
| `read_file` | `path`, `offset`, `limit` | text with line numbers; images as image input; PDF text |
| `write_file` | `path`, `content` | written |
| `edit_file` | `path`, `old_string`, `new_string`, `replace_all` | changed / not unique |
| `list_files` | `path`, `glob` | paths and sizes |
| `render_document` | `path`, `pages` | pages as PNG (150 dpi) and the checks |
| `deliver_file` | `path`, `target_folder`, `display_name` | the file in Files and the chat (after a preview) |
| `finish` | `summary`, `open_issues` | ends the job (only after a delivery) |

Limits: tool calls (60), correction rounds (3), the same call at most three times; no
time limit by default, each step is watched for progress. The steps are not saved: the
server log's line per job names the versions of the prompt and tools
(`DocumentJob::VERSION`), and previews are removed when the job ends.

## Skills

`skills/<format>/SKILL.md` with `scripts/`. The model gets the list (from
`skills/README.md`) and reads a skill before building. `common/scripts/`:
`render.sh` (PDF, page images, contact sheet), `check.py` (package, opening, pages,
fonts, structure, formulas, spelling), `diff.py` (what a change changed and kept),
`lo_update.py` (fields, contents, recalculation, conversion with LibreOffice's UNO).

When changing a skill, the prompt or a tool's description: raise the version, run
`tests/golden/run.sh` (CI does) and `occ talk_ai:golden` with the model in use.

## Tests

```bash
composer install && vendor/bin/phpunit
docker build -t talk-ai-sandbox:test sandbox/ && tests/golden/run.sh talk-ai-sandbox:test
python3 tests/compat/talk_api.py
```
