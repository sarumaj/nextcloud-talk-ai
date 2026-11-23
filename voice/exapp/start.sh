#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# PID 1 of the ExApp: the HaRP tunnel and the provider; the container ends with either.
set -uo pipefail
/opt/exapp/harp_connect.sh &
tunnel=$!
python3 -I /opt/exapp/voice.py &
voice=$!
trap 'kill -TERM $tunnel $voice 2>/dev/null' INT TERM
while :; do
	wait -n -p finished
	status=$?
	[ -z "${finished:-}" ] && { [ $status -eq 127 ] && break; continue; }
	if [ "$finished" = "$tunnel" ] && [ $status -eq 0 ]; then continue; fi
	kill -TERM $tunnel $voice 2>/dev/null
	exit $status
done
