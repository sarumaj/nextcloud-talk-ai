# AI in Talk: developer's guide

## Layout

- `app/talk_ai/`: the Nextcloud app (PHP, no build step).
  - `lib/Ai/`: the AI layer, see below. Nothing in it uses Talk or the rest of `lib/`.
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
- `tests/`: PHPUnit (no Nextcloud, no network: `FakeEngine` plays a scripted model behind
  the real `Hub` and `Loop`, a fake sandbox answers like the real one, Talk's events come
  as fixtures),
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

Images are document jobs too: `create_document` takes png, svg, webp and jpg
(`DocumentJob::IMAGES`), and the job reads `skills/image/SKILL.md` (Graphviz, SVG,
matplotlib, Pillow; `textfit.py` for text that fits).

## The document job's tools

All paths stay inside `/work` (the runner refuses others). The job is one run of `Loop`
with its whole budget; a model that stops without `finish` is reminded, three times at most.
Page images come back with the result of `render_document` or `read_file`.

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

## The AI layer

`lib/Ai/` talks to the providers; the app asks it through `Hub`. It depends on Nextcloud's
public API only, so it can become an app of its own; `Engines::APP` names the app whose
settings it reads.

- `Engine` (interface): one model call, `turn(Request): Turn`; it never runs tools.
  `status(): Status` says what the engine can do now (images and how many, PDFs, search,
  structured answers, streaming, caching) and why not when it is not ready.
  `models()` lists the provider's models.
- `Request`: the system prompt, the tools (name → description and JSON schema), the
  messages, and the switches (search, answer schema, thinking, streaming callback, cache).
  A message is `{role: user|assistant|tool, parts}`; a part is text, image, document,
  tool call or tool result (see the class's comment).
- `Turn`: the words, the tool calls, the structured answer, the usage (tokens in, out,
  from the cache, cost), and an error word from `Errors`.
- `Loop`: runs a question: a turn, the tools (with the asking person's rights), their
  results back, until no tool is called, the caller's budget is used (then one more turn
  to answer), or one call (tool and arguments alike) runs a fourth time. It runs tool
  calls written as text, reports a failing tool to the model, cuts a result at 100 000
  characters, keeps only the latest images (the engine's limit), asks a busy provider the
  same turn again, and calls a heartbeat before each turn that may cancel.
- `Hub`: scenarios (system prompt, tools, search, answer schema, who may ask, budget),
  `ask()` with the result in the app's shape (`state`, `text`, `answer`, `tools`,
  `usage`, `error`), the context with the question (not in the system prompt, for the
  prompt cache), and the checks of images.
- `TextProtocol`: tools and answers in words, for engines without them
  (`{"tool_calls": […]}` and `{"tool_results": […]}`).
- `Engines`: the engine the administrator chose (app setting `ai_engine`).
- `Engine/AiHub.php`: for now, AI-Hub's engines behind `Engine`, with `TextProtocol`.

## Skills

`skills/<format>/SKILL.md` with `scripts/`. The model gets the list (from
`skills/README.md`) and reads a skill before building. `common/scripts/`:
`render.sh` (PDF, page images, contact sheet; an image as one page and a small copy),
`check.py` (package, opening, pages, fonts, structure, formulas, spelling; for images size,
blankness, and an SVG's outside references and fonts), `diff.py` (what a change changed and kept),
`lo_update.py` (fields, contents, recalculation, conversion with LibreOffice's UNO).

When changing a skill, the prompt or a tool's description: raise the version, run
`tests/golden/run.sh` (CI does) and `occ talk_ai:golden` with the model in use.

## Tests

```bash
composer install && vendor/bin/phpunit
docker build -t talk-ai-sandbox:test sandbox/ && tests/golden/run.sh talk-ai-sandbox:test
python3 tests/compat/talk_api.py
```
