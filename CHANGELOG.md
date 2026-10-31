# Changelog

## Unreleased

### Changed
- **The app runs its own AI loop** (`lib/Ai/`): `Hub` and `Loop` run the tools, and the
  model is asked through an `Engine`; for now AI-Hub's engines (`Engine/AiHub.php`). Tool
  calls are no longer limited to eight per question: an answer may make 20, a document
  job its whole budget (`document_max_tools`) in one run instead of segments, and several
  calls in one reply all run. A busy provider is asked the same turn again, so no tool
  runs twice. Page images come back with the render's result, without an extra turn to
  look. Token use comes from the provider where it reports it, else estimated as before.
- The question's context goes with the question, never into the system prompt.

### Added
- **Anthropic's API as an engine** (`ai_engine` = `anthropic`): native tool calls, up to 20
  images, PDFs sent as they are, prompt caching with the cached tokens reported, web search,
  structured answers, thinking, streaming, and the model list from the API.

## 0.11.0 - 2026-10-28

### Removed
- **The bot in Matrix.** The Matrix homeserver and its adapter are gone, so the bot answers
  in Talk only. The OCS API `/ocs/v2.php/apps/talk_ai/api/v1/matrix/…` (`MatrixController`),
  the Matrix rooms in `Conversation` (`lib/Matrix/`, tokens `mx-…`), `share_file`'s posting
  into a Matrix room and the retention of Matrix messages in `CleanTraces` are removed.
- The setting `matrix_service_user`.
- The tables `talk_ai_mx_rooms`, `talk_ai_mx_members`, `talk_ai_mx_messages` and
  `talk_ai_mx_outbox`: the migration `Version001100Date20261028` drops them with their
  indexes where they exist, and deletes the setting and the Matrix rooms' own keys
  (`context_`, `memory_`, `preview_off_` and `room_` of a token `mx-…`). Job and answer
  records of Matrix rooms stay until the retention removes them.

## 0.10.2 - 2026-10-25

### Fixed
- Asked to compare two files, the bot read one and said reading the other had failed. With
  a command line tool (AI-Hub's CLI mode) the model calls a tool by a block in its reply, and
  AI-Hub runs only the first block of a reply: the second read was dropped without a word,
  so nothing failed and nothing was logged. `read_file` now reads several files in one call
  (`ids`, up to eight, sharing the length one file may have), and the prompt says that only
  the first tool block runs and that a tool without a result did not fail.

## 0.10.1 - 2026-10-25

### Fixed
- Several files sent with one caption were answered as one: Talk posts each file as a message
  of its own, the caption on the last chosen, and its mobile apps post them side by side, so
  the captioned file could come first and the answer took only that one. The person's files
  without a caption right after a captioned file (within two minutes, until a message of
  someone else or a new caption) now belong to its question, and the answer waits for them
  until none came for four seconds (thirty at most).

## 0.10.0 - 2026-10-20

### Added
- **A new chat without deleting the conversation.** "new chat", "neuer Chat", "nowy czat",
  "start over" or `/new` (`/neu`, `/nowy`), or asked in other words (the tool `new_chat`):
  from then on the bot reads only the messages after it, and the conversation's summary and
  its earlier document jobs are no longer in its context. The messages stay in the chat. In
  a group only its moderators may, as the conversation is everyone's; in Talk and in Matrix
  alike. The start is the setting `context_<token>`.

### Fixed
- `share_file` with a Matrix room as the conversation shared with a Talk room that does not
  exist, so nothing arrived in Matrix: the file is now posted in the room, read with its
  owner's rights.

## 0.9.0 - 2026-10-20

### Added
- **The bot in Matrix.** An OCS API (`/ocs/v2.php/apps/talk_ai/api/v1/matrix/…`: messages,
  observe, redactions, reactions, joined, outbox, outbox/ack, posted, info) lets a Matrix
  adapter bring the bot into Matrix rooms with all its features: every tool, documents in the
  sandbox, files both ways, polls, the status message with its steps, cancelling with ❌ and
  rating with 👍/👎, answering edited questions anew and removing answers to removed ones. A
  person's message comes with their own login, so the bot answers with their rights, as in
  Talk.
- The setting `matrix_service_user`: the adapter's account, which reads the outbox; empty (the
  default) turns the API off.
- Tables `talk_ai_mx_rooms`, `talk_ai_mx_members`, `talk_ai_mx_messages` and
  `talk_ai_mx_outbox` (the Matrix rooms' history, kept under the retention).

### Changed
- Talk's polls and moderator check moved from `Reply` into `Conversation`, which now routes
  everything Talk-specific to Talk or Matrix.

## 0.8.0 - 2026-10-18

### Added
- **Images drawn with code** in the document sandbox. `create_document` takes the formats
  png, svg, webp and jpg: diagrams and flowcharts, schematic drawings, function graphs,
  posters, invitations, icons and flat illustrations, or changes to a shared image (crop,
  label, combine). The AI draws with SVG, Graphviz, matplotlib or Pillow, renders the image,
  looks at it at full size and at chat size, corrects it and posts it in the chat; the
  preview before delivery works as for documents. It does not make photos or the likeness of
  real people, and says so.
- The skill `image` (`skills/image/SKILL.md`): which tool for which image, sizes and
  formats, text on images, colour-blind-safe colours, SVG, Graphviz, matplotlib and Pillow
  practice; `textfit.py` measures, wraps and fits text with the real font and checks
  contrast.
- `render.sh` renders images (as PNG, a checkerboard where transparent, and a small copy);
  `check.py` checks them: it opens, its size, not blank, an SVG well-formed with a viewBox,
  nothing loaded from outside and only installed fonts.
- The sandbox image has Graphviz.
- The help names images in the workshop.

## 0.7.2 - 2026-10-18

### Fixed
- A file the bot posts (a document, an .ics file, a study set) can be forwarded to another
  conversation: the conversation's share of it now lets its members share it on (read and
  share; still nobody can change it there). Forwarding shares the file again into the other
  conversation as the one who forwards it, which a read-only share refused.

## 0.7.1 - 2026-10-17

### Fixed
- A new document with a new question gets the question answered for that document. The bot
  went on with the earlier task instead: a person shared a document with "make me a study
  sheet for this" while an earlier study sheet was the newest document job, and the bot
  made the earlier one again.
  - The files a message is about are now named with it: the files shared with it, or by
    its author since the bot last answered (a file first, the question after it). A caption
    no longer loses its file.
  - The prompt says that each message is a request of its own. The summary, the recent
    messages and the document jobs are background, continued only when the message asks for
    it ("again", "continue", "the same for…", a reply to it).
  - The document jobs in the context are marked as earlier work, not a to-do. The
    conversation's summary keeps what was left open as history, not as tasks to resume.

## 0.7.0 - 2026-10-17

### Added
- **Exams by the official rules** (tool `exam`). None of a state's, school's or year's rules
  are built in. The syllabus, operators, dates, aids and grading tables come from the
  official documents the person shares or names, read like any other file or page, and the
  prompt asks for them instead of memory. The tool computes:
  - marks to points or grades by a table (the usual German 15-point table by default; the
    whole percent or the exact one);
  - an error index with its deduction steps or its own scale (as for translations);
  - grades from weighted parts (e.g. content 70 / language 30);
  - written and oral exams combined;
  - the Abitur's Block I and II and average by the KMK formulas, and what a target grade
    needs;
  - a check that a practice exam's marks add up and that its requirement levels are
    balanced;
  - a study plan up to the exam dates, as calendar tasks.

  The tests reproduce real regulations (Hessen's OAVO, Anlagen 9a to 10b) with these
  generic rules.
- The skill `exam` for the document sandbox: a practice or mock exam with cover, material
  with line numbers, tasks with operators and marks, the marking scheme by requirement level,
  and the key from marks to grades (`grading_key.py`, any table).
- **Formulas as images** (tool `formula`, with the sandbox): LaTeX the chat's Unicode cannot
  show (nested fractions, matrices, integrals with limits, aligned derivations) is typeset
  by ziamath in a short-lived sandbox container and sent as an image. The sandbox image
  has ziamath and rsvg-convert. `skills/common/scripts/formula.py` gives documents the same
  formulas (PNG, SVG or MathML), and the skills ask for formulas to be typeset.
- A preference `studies` (school or university, subjects with levels, calculator, links to
  the official documents) that every answer uses.
- **Studies** (from a sample of 19 study programmes' module handbooks):
  - `grades`: averages by the rule of the Prüfungsordnung (ECTS or own weights, one or two
    decimals cut or rounded, best-of groups, double weights), module grades from their
    parts, the average the remaining credits need, foreign grades by the bayerische Formel,
    law exam points (0–18), and the workload and phases back from a deadline.
  - `measurement`: uncertainties for lab reports (GUM, DIN 1319): repeated readings (with
    t), Gaussian and maximum propagation with partial derivatives and an uncertainty budget,
    results rounded as the norms ask, and a weighted straight-line fit with χ²/ndf.
  - `numerics`: roots (Newton, bisection, regula falsi), integrals (trapezoid, Simpson),
    initial value problems and systems (Euler, Heun, Runge-Kutta 4), eigenvalues, and
    derivatives at a point, each with its table.
  - `law`: deadlines by §§ 187–193 BGB, with weekends and each federal state's holidays;
    the holidays of a year; and the text of a federal norm from gesetze-im-internet.de.
- `statistics` also does:
  - the binomial test as at school (rejection region, errors of the first and second
    kind, decision);
  - confidence intervals of a mean;
  - one-way ANOVA and Kruskal-Wallis;
  - Mann-Whitney U, Wilcoxon signed-rank and Spearman;
  - Cohen's d and Hedges' g.

  Each test also gives a line in APA style.
- `chemistry` also does:
  - pH of strong and weak acids and bases, exact and by the school's approximation;
  - buffers;
  - titration curves from the charge balance, with their characteristic points;
  - the law of mass action: the concentrations at equilibrium from Kc, or Kc from them.
- `informatics` also runs:
  - the accumulator register machine of German school glossaries, with a trace;
  - finite automata on words (deterministic or not, ε too);
  - regular expressions on words.
- The help names exams and studies.

## 0.6.0 - 2026-10-16

### Added
- The steps of a slow answer show in its status message while the bot works: with each
  tool it uses, the model says in one sentence what it does and why ("I read the timetable
  to find the exam dates"), and the line appears at the ticker's next look (three
  seconds), above the current step; the latest six stay. The answer then takes the
  message's place, so nothing of it stays in the chat. AI-Hub hands the app only a tool
  call's arguments, so the model writes the sentence into an argument of its own, `why`,
  which every tool takes and none of them sees. On by default; Administration → General,
  "Show the steps while answering" (`show_steps`), turns it off.

## 0.5.9 - 2026-10-15

### Fixed
- Voice messages are answered for real: without a caption, Talk names the shared file's
  Activity "file_shared" (0.5.8 waited for "{file}", so it still ignored them).
- The transcript the bot shows of a voice message is just what was said: the marking that
  tells the model it is data from outside no longer shows in the chat.

## 0.5.8 - 2026-10-15

### Fixed
- Voice messages are answered: Talk tells bots of them as a shared file without a caption
  (an Activity named "{file}"), which the bot took for no question. A voice message is
  transcribed (Nextcloud's speech-to-text, e.g. the External App stt_whisper2) and
  answered: always in a conversation with the bot alone, in a group when it replies to
  the bot.

## 0.5.7 - 2026-10-15

### Fixed
- A photo or file shared with a caption ("explain this picture") is answered: Talk tells
  bots of it as a system message, which the bot took for no question, so it waited for
  the next message. The caption is the question (in a group, it names the bot), and the
  file goes with it.
- An answer comes once: a long one (over 2000 characters) came cut short in the chat and
  again whole as a Markdown file, which read as two answers. Now the whole answer is one
  message; only one longer than Talk takes (30000 characters) still comes as a file.

## 0.5.6 - 2026-10-15

### Changed
- Only the result of a document job stays: its steps are no longer saved as a protocol in
  the person's files (`Talk-AI/<date>/Traces/`), no page images are kept, and the chat no
  longer names a protocol. The previews before a delivery are the only files saved on the
  way; their messages and files are removed when the job ends (for good, not into the
  trash bin). The protocols earlier versions saved are removed by the next clean-up run,
  whatever their age; the setting `trace_days` is gone.
- "delete my data" offers everything or the delivered documents (no protocols to delete).

## 0.5.5 - 2026-10-15

### Changed
- Watched, not timed: a command in the document sandbox runs as long as it makes progress
  (output or CPU time) and is stopped only when it has had neither for two minutes, or
  after an hour in all. The runner answers every ten seconds while it runs: the job keeps
  its heartbeat, its status message shows the command's last line, and "stop" ends the
  command at once. A document job has no time limit any more by default
  (`document_max_seconds` 0); it ends when it stops making progress or has used its
  tool calls. A job's container goes when nobody asked anything of it for half an hour.
- Every allowed tool goes with every question, always in the same order, and the room,
  its messages and settings go with the question instead of the system prompt: the
  system prompt is then the same for all questions, and the provider's prompt cache (an
  hour) serves it at a tenth of the price. The tool list chosen per question (0.5.4) was
  shorter but new each time, so it was never read from the cache; `more_tools` is gone.
- An AI turn that stalled (AI-Hub's command line stopped by its watcher) is asked again,
  like one that timed out.

### Fixed
- A document job no longer stops when the model writes a tool call in its own format
  (`<invoke name="bash">…`) after many rounds: the call is run, as a call written as plain
  JSON already was.
- A document job for a person whose storage is full ends at once with that reason,
  instead of failing at the delivery after all the work (66 tool calls in a test).
- The admin guide names the settings page as Nextcloud shows it: Administration →
  Assistant → AI in Talk.

## 0.5.4 - 2026-10-15

### Added
- A status message for every slow answer and document job: from ten seconds on it shows
  the current step and the time it runs, changed every twenty seconds, also while the AI
  works on a long turn without tools; the answer takes its place.

### Changed
- Half the prompt for most questions: a core of tools goes with every question, the
  others when the question points to them, all reachable through `more_tools`.
- The system prompt and the tools' list are the same for every conversation and person,
  so the provider's prompt cache can serve them; what differs is in the context.

## 0.5.3 - 2026-10-15

### Fixed
- The release publishes the sandbox images with the repository secret `PACKAGES_TOKEN`.

## 0.5.2 - 2026-10-15

### Added
- The document sandbox as an External App (`talk_ai_sandbox`, sandbox/exapp/): for
  servers where Nextcloud cannot run a runner with Docker; AppAPI deploys it, AI in Talk
  finds it. Jobs run as an unprivileged user under a seccomp filter without network.
- The release publishes the sandbox's and the External App's images.

### Fixed
- A model that does not answer in time (AI-Hub's command line connection) is asked
  again instead of ending the document job.

## 0.5.1 - 2026-10-15

### Added
- The document sandbox without a container runtime: the runner's Bubblewrap backend
  (`RUNNER_BACKEND=bwrap`, with an unpacked image as root or the host's packages).
- An optional package mirror for the sandbox (`RUNNER_NETWORK`, `RUNNER_PIP_INDEX_URL`,
  `RUNNER_NPM_REGISTRY`).
- An edited question is answered again, a deleted one takes its answer along.

### Fixed
- Tests and CI with Nextcloud 35 and later (`IUserFolder`, PHP 8.3).

## 0.5.0 - 2026-10

### Added
- Documents built with code in a sandbox (`create_document`): the model writes code
  (python-docx, openpyxl, python-pptx; docx, exceljs, pptxgenjs), renders the pages,
  looks at them, corrects, and delivers DOCX, ODT, XLSX, ODS, PPTX, ODP or PDF. A
  container per job (no network, read-only, limited), skills per format, structure
  and spelling checks, a diff when changing a document, a preview before delivery, a
  protocol per job.
- A queue of document jobs with status, restart after a server restart, cancelling by
  "stop" or ❌, "again" with changes, quotas per person and day.
- Conversation memory: a rolling summary, the documents of the conversation, the tools
  of earlier answers; long files read by the passages that fit the question.
- Confirmation for changes outside the chat, undo within 24 hours, sharing on request,
  deleting one's own data.
- Help, shortcuts, polls, voice messages, long answers as a file, a status message for
  slow answers, room settings and personal preferences.
- Administration: settings with explanations, health check, jobs, answers with request
  ids, statistics, house styles, emergency stop, debug mode; `occ talk_ai:check`,
  `talk_ai:jobs`, `talk_ai:stop`, `talk_ai:start`, `talk_ai:golden`.
- German and Polish translations.

### Changed
- Content from files, web pages, OCR and transcripts is marked with its origin as data.
- Each message is answered once, also when Talk delivers it twice.

## 0.4.0 and earlier

See the Git history.
