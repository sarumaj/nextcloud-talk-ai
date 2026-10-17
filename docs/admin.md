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
   location under Administration → Assistant → AI in Talk).
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
  `RUNNER_MEMORY_MB`, `RUNNER_DISK_MB`, `RUNNER_MAX_SECONDS` (a job's container at most,
  default 4 hours), `RUNNER_JOB_IDLE_SECONDS` (a job nobody asks anything of is removed,
  default 30 minutes), `RUNNER_EXEC_IDLE` (a command without output and CPU use is stopped,
  default 120 s), `RUNNER_EXEC_MAX_SECONDS` (a command at most, default 3600).
- Make the socket's folder visible to Nextcloud (a bind mount into its containers) and
  set `occ config:app:set talk_ai sandbox_socket --value /run/talk-ai-sandbox/runner.sock`
  and `occ config:app:set talk_ai sandbox_token --value … --lazy --sensitive`.

Each container: no network, read-only image, user 10001 without capabilities and
without new privileges, writable only `/work` (in memory) and `/tmp`, the CPU, memory,
process and time limits; removed when the job ends or its time is up. It sees nothing of
Nextcloud: the app copies the chosen files in and the result out.

As an External App (no runner of your own): install **talk_ai_sandbox** with AppAPI
(Administration → External Apps, or `occ app_api:app:register talk_ai_sandbox <daemon>
--info-xml https://git.ciepiela.org/dawid/nextcloud-talk-ai/raw/branch/main/sandbox/exapp/appinfo/info.xml`).
AI in Talk uses it by itself when no runner socket is set (`sandbox_exapp` names
another ExApp, empty switches this off). An ExApp's container may neither start
containers nor use namespaces, so each job runs there as an unprivileged user without
capabilities under a seccomp filter that refuses network sockets, ptrace, mount and
namespaces, with limits for memory, processes, time and space, one job at a time:
weaker isolation than a container per job, which is why the runner of your own comes
first when both exist.

Without container runtime: `RUNNER_BACKEND=bwrap` runs each job with Bubblewrap
(unprivileged user namespaces must be allowed) in a folder below `RUNNER_JOBS_DIR`,
without network, with the skills read-only. Its root is `RUNNER_ROOTFS` (the sandbox
image unpacked once, e.g. `podman export $(podman create talk-ai-sandbox) | tar -xC
/var/lib/talk-ai-sandbox/rootfs`; then everything works) or the host's `/usr` and `/etc`
(the host then needs the image's packages). Memory, processes and CPU time are limited
by `prlimit`, the working space is checked after each command; CPUs are not limited and
the isolation is weaker than a container's (no cgroups). Without either, the bot writes
documents with its built-in writers.

Package mirror (optional): give the job containers a network that reaches only your
mirror (`RUNNER_NETWORK`, made by you, e.g. an internal Docker network with a proxy) and
`RUNNER_PIP_INDEX_URL` / `RUNNER_NPM_REGISTRY`; the model may then install missing
Python and Node packages from it. By default there is no network at all.

## Settings (Administration → Assistant → AI in Talk)

Every setting has an explanation on the page; the same keys work with
`occ config:app:set talk_ai <key> --value …`.

- **General**: the bot on or off (emergency stop), guests and federated participants,
  the steps shown while answering (`show_steps`), messages per answer (history window), the model's context size, the notice when the
  bot is enabled.
- **Features per group**: writing files, the document sandbox, the web, the calendar,
  and youth mode (no web search; content for minors), each for named groups or
  everyone.
- **Quotas**: document jobs, seconds and (estimated) tokens per person and day; costs
  per million tokens for the statistics.
- **Document sandbox**: tool calls, time and correction rounds per job, the container's
  limits, the folder for documents, the wait for an answer to a preview.
- **Retention**: records and memories (90 days), delivered documents (never, by default).
  Document jobs keep nothing of their steps: no protocol, no page images; previews are
  removed (for good, not into the trash bin) when the job ends.
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
- People can delete their data themselves ("delete my data": all records, preferences
  and memories; or the delivered documents).
- API keys stay in AI-Hub; the sandbox never sees them.
- The `law` tool reads federal norms from gesetze-im-internet.de, as `read_url` reads other
  pages (only the norm's address goes there); the Abitur data, the operator lists and all
  grading tables are in the app (`data/abitur/`) and need no connection.

## Logs and troubleshooting

Log lines start with "AI bot:" and carry the request id (`request`), which the
administration page shows for each answer. `occ talk_ai:jobs` lists the document jobs
with their outcome, tool calls and rounds; the server log has a line per job. The steps
themselves are not kept.
