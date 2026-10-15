# AI in Talk: administrator's guide

## Requirements and compatibility

| Part | Supported | Without it |
|---|---|---|
| Nextcloud | 32 to 36 | – |
| Talk (spreed) | the version of each Nextcloud above (checked in CI against its API) | – |
| AI-Hub | set up for the app (Administration → AI-Hub) | the bot cannot answer |
| A model with tool calls | any AI-Hub provider whose model calls tools | chat, reading files and summaries only |
| Image input | optional | photos via OCR; document layout checked as text |
| Nextcloud Office (Collabora) | optional | files as OpenDocument only, charts as SVG |
| pdftotext, pdftoppm (Poppler) | optional | no PDFs; scanned PDFs without OCR |
| Tesseract (+ languages) | optional | no text from photos and scans |
| A speech-to-text provider | optional | voice messages are not understood |
| The document sandbox | optional | no documents built with code |
| PHP zip | required | – |

`occ talk_ai:check` (with `--deep`, also inside a sandbox container) and the
administration page show each part: present, version, and what is lost without it.

## Installing

1. Install the app (app store, or the release archive into `custom_apps/` and
   `occ app:enable talk_ai`). It installs the bot in Talk.
2. Set up AI-Hub for the app; `occ talk_ai:check` should show tool calling.
3. Moderators enable the bot per conversation (conversation settings → Bots). It then
   posts a notice of who processes the messages (set the text or the provider's
   location under Administration → Artificial intelligence → AI in Talk).
4. Background jobs should run by cron, better every minute (systemd timer) or with a
   worker (`occ background-job:worker`): queued answers and document jobs wait for
   them when the direct hand-off fails.

## The document sandbox

Documents built with code run in a container per job, started by a small runner
(`sandbox/runner.py`) on the same server:

- Build the image: `docker build -t talk-ai-sandbox:latest sandbox/` (or pull it from
  the project's registry), as the user that runs the runner, ideally with rootless
  Docker or Podman (`DOCKER=podman`).
- Copy `skills/` somewhere readable by all (it is mounted read-only into the
  containers; updating it needs no app release) and `sandbox/runner.py`.
- Write a random token to a file only the runner's user can read.
- Run the runner as a service with `RUNNER_SOCKET`, `RUNNER_TOKEN_FILE`, `RUNNER_IMAGE`,
  `RUNNER_SKILLS`, `RUNNER_MAX_JOBS` (jobs at once, default 3), `RUNNER_CPUS`,
  `RUNNER_MEMORY_MB`, `RUNNER_DISK_MB`, `RUNNER_MAX_SECONDS`.
- Make the socket's folder visible to Nextcloud (a bind mount into its containers) and
  set `occ config:app:set talk_ai sandbox_socket --value /run/talk-ai-sandbox/runner.sock`
  and `occ config:app:set talk_ai sandbox_token --value … --lazy --sensitive`.

Each container: no network, read-only image, user 10001 without capabilities and
without new privileges, writable only `/work` (in memory) and `/tmp`, the CPU, memory,
process and time limits; removed when the job ends or its time is up. It sees nothing of
Nextcloud: the app copies the chosen files in and the result out.

Without container runtime: not supported yet (the runner needs Docker or Podman); the
bot then writes documents with its built-in writers.

## Settings (Administration → Artificial intelligence → AI in Talk)

Every setting has an explanation on the page; the same keys work with
`occ config:app:set talk_ai <key> --value …`.

- **General**: the bot on or off (emergency stop), guests and federated participants,
  messages per answer (history window), the model's context size, the notice when the
  bot is enabled.
- **Features per group**: writing files, the document sandbox, the web, the calendar,
  and youth mode (no web search; content for minors), each for named groups or
  everyone.
- **Quotas**: document jobs, seconds and (estimated) tokens per person and day; costs
  per million tokens for the statistics.
- **Document sandbox**: tool calls, time and correction rounds per job, the container's
  limits, the folder for documents, the wait for an answer to a preview.
- **Retention**: protocols (default 14 days, at least 7), records and memories (90 days),
  delivered documents (never, by default).
- **Emergency stop**: conversations and people switched off; also
  `occ talk_ai:stop all|room <token>|user <id>` and `occ talk_ai:start`.
- **House styles**: templates, logo, colours and fonts per group, uploaded on the page
  (kept in the app's data, not in anyone's files).
- **Debugging**: questions and answers in the log for some hours, masked; off by
  default.

The page also shows the health check, the document jobs (cancel a running one), the
answers with their request id (in the log too) and the tools they ran, statistics
(jobs, success, run time, tokens and cost per person, conversation and day), and the
golden set per AI connection.

## Evaluating a model

`occ talk_ai:golden <test account> [T-01 …]` runs the acceptance cases as real document
jobs with the AI connection AI-Hub uses now, judges the files with the sandbox's checks,
and keeps the result per provider and model (shown on the page). Run it after changing
the model and before letting everyone use it.

## Privacy

- Messages that address the bot, the files it reads for an answer and the documents
  it builds go to the AI provider set up in AI-Hub, nowhere else. With a local provider
  (Ollama, LocalAI) nothing leaves the server.
- All tools act with the rights of the person asking. Private things (their calendar,
  their own files) are shown in a group conversation only after their yes.
- Changes outside the chat (calendar entries, shares, deleting) need the person's yes,
  for exactly one action, and can be undone for 24 hours.
- Nothing of questions or answers is logged unless debugging is switched on.
- People can delete their data themselves ("delete my data": protocols; all records,
  preferences and memories; or the delivered documents).
- API keys stay in AI-Hub; neither the sandbox nor the protocols see them.

## Logs and troubleshooting

Log lines start with "AI bot:" and carry the request id (`request`), which the
administration page shows for each answer. `occ talk_ai:jobs` lists the document jobs;
a job's protocol (each tool call with input and output, the last render's pages) is in
the person's files under `Talk-AI/<date>/Traces/`.
