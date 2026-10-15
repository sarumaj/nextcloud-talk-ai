#!/bin/bash
# The document golden set without a model (F-47, Q-02): reference builds of the
# acceptance cases with the sandbox image and the skills, judged by the same checks
# the jobs use. Good files must pass, broken ones must be caught. Run in CI after
# any change to sandbox/ or skills/; a failure blocks the merge (Q-03).
#   tests/golden/run.sh IMAGE
set -euo pipefail
image="${1:-talk-ai-sandbox:ci}"
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
work="$(mktemp -d)"
chmod 777 "$work"
cp "$here"/*.py "$here"/*.csv "$work"/
docker run --rm --network none --read-only --tmpfs /tmp:exec --user 10001:10001 \
  -v "$work:/work" -v "$repo/skills:/skills:ro" -w /work --entrypoint bash "$image" -lc 'python3 cases.py'
