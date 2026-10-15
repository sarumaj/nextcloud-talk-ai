#!/bin/bash
# PID 1 of the ExApp: the HaRP tunnel and the runner; the container ends with either.
set -uo pipefail
/opt/exapp/harp_connect.sh &
tunnel=$!
RUNNER_HTTP=1 RUNNER_BACKEND=process RUNNER_SKILLS=/skills RUNNER_TOKEN_FILE=/dev/null \
	RUNNER_MAX_SECONDS="${RUNNER_MAX_SECONDS:-900}" RUNNER_MEMORY_MB="${RUNNER_MEMORY_MB:-2048}" RUNNER_DISK_MB="${RUNNER_DISK_MB:-1024}" \
	python3 -I /opt/exapp/runner.py &
runner=$!
trap 'kill -TERM $tunnel $runner 2>/dev/null' INT TERM
while :; do
	wait -n -p done
	status=$?
	[ -z "${done:-}" ] && { [ $status -eq 127 ] && break; continue; }
	if [ "$done" = "$tunnel" ] && [ $status -eq 0 ]; then continue; fi
	kill -TERM $tunnel $runner 2>/dev/null
	exit $status
done
