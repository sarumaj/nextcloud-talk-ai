#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# talk_ai on a real Nextcloud, after the shared action nextcloud-app-server of
# dawid/ci-actions installed or upgraded it there (CI's job server): what the
# administrator and the other apps get without an AI connection. The bot is
# registered with Talk once, the administration page and the OCS status answer, the
# metrics need their token (404 without one, 401 for a wrong one) and the occ
# commands run. The action passes OCC, NEXTCLOUD_URL, NEXTCLOUD_USER and
# NEXTCLOUD_PASSWORD.
#   tests/server/check.sh
set -euo pipefail
: "${OCC:?}" "${NEXTCLOUD_URL:?}" "${NEXTCLOUD_USER:?}" "${NEXTCLOUD_PASSWORD:?}"
admin=(-u "$NEXTCLOUD_USER:$NEXTCLOUD_PASSWORD")
body="$(mktemp)"
trap 'rm -f "$body"' EXIT

# Expects the status $1 for curl's other arguments; the answer is left in $body. With
# SETTLE=1 it asks again for up to 30 seconds: the web server keeps an app setting that
# occ just changed in its cache (APCu) for a few seconds.
expect() {
  local want="$1" got tries=1
  shift
  [[ ${SETTLE:-0} == 1 ]] && tries=15
  for ((try = 1; try <= tries; try++)); do
    got="$(curl -s -o "$body" -w '%{http_code}' "$@")"
    [[ $got == "$want" ]] && break
    ((try < tries)) && sleep 2
  done
  if [[ $got != "$want" ]]; then
    echo "::error::${*: -1} answered $got, not $want: $(head -c 500 "$body")"
    exit 1
  fi
  echo "OK: ${*: -1} answers $want"
}

# Fails unless $body contains $1.
contains() {
  grep -qF -- "$1" "$body" || { echo "::error::The answer lacks $1: $(head -c 500 "$body")"; exit 1; }
}

# Talk knows the bot once, also after the action disabled and enabled the app again.
bots="$("$OCC" talk:bot:list --output=json)"
count="$(jq '[.[] | select(.name == "AI")] | length' <<<"$bots")"
[[ $count == 1 ]] || { echo "::error::Talk has $count bots named AI: $bots"; exit 1; }
echo "OK: Talk has the bot AI once"

expect 200 "${admin[@]}" "$NEXTCLOUD_URL/index.php/settings/admin/ai"
contains 'id="talk-ai-admin"'
contains 'apps/talk_ai/js/admin.js'

expect 200 "${admin[@]}" -H 'OCS-APIRequest: true' "$NEXTCLOUD_URL/ocs/v2.php/apps/talk_ai/api/v1/status?format=json"
[[ $(jq -r '.ocs.data.ready' "$body") == false ]] || { echo "::error::The status is ready without an AI connection"; exit 1; }
echo "OK: the OCS status says not ready without an AI connection"

metrics="$NEXTCLOUD_URL/index.php/apps/talk_ai/metrics"
expect 404 "$metrics"
"$OCC" config:app:set talk_ai metrics_token --value ci-metrics-token --lazy >/dev/null
SETTLE=1 expect 401 -H 'Authorization: Bearer wrong' "$metrics"
expect 200 -H 'Authorization: Bearer ci-metrics-token' "$metrics"
contains 'talk_ai_metrics_timestamp_seconds '

"$OCC" talk_ai:metrics | grep -q '^talk_ai_metrics_timestamp_seconds ' || { echo "::error::occ talk_ai:metrics printed no metrics"; exit 1; }
echo "OK: occ talk_ai:metrics prints the metrics"
# Without an AI connection the check fails (1), and says so; 2 or more is a crash.
code=0
"$OCC" talk_ai:check --output=json >"$body" || code=$?
((code <= 1)) || { echo "::error::occ talk_ai:check failed ($code): $(head -c 500 "$body")"; exit 1; }
jq -e 'any(.[]; .name == "AI connection" and .ok == false)' "$body" >/dev/null \
  || { echo "::error::occ talk_ai:check does not report the missing AI connection: $(head -c 500 "$body")"; exit 1; }
echo "OK: occ talk_ai:check reports the missing AI connection"
