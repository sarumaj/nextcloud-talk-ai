# Changelog

## 0.5.1 – 2026-10-15

### Added
- The document sandbox without a container runtime: the runner's Bubblewrap backend
  (`RUNNER_BACKEND=bwrap`, with an unpacked image as root or the host's packages).
- An optional package mirror for the sandbox (`RUNNER_NETWORK`, `RUNNER_PIP_INDEX_URL`,
  `RUNNER_NPM_REGISTRY`).
- An edited question is answered again, a deleted one takes its answer along.

### Fixed
- Tests and CI with Nextcloud 35 and later (`IUserFolder`, PHP 8.3).

## 0.5.0 – 2026-10

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
