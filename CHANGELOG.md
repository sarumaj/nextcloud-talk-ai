# Changelog

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
