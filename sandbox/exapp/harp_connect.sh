#!/bin/sh
# The HaRP tunnel (frpc) to the runner's unix socket; without HaRP (manual install)
# it steps aside and the runner listens on APP_HOST:APP_PORT.
set -eu
[ -n "${HP_SHARED_KEY:-}" ] || { echo "harp_connect: no HaRP, the runner listens on APP_PORT"; exit 0; }
SOCK="${HP_EXAPP_SOCK:-/tmp/exapp.sock}"
CERTS="${HP_FRP_CERT_DIR:-/certs/frp}"
if [ -r "$CERTS/client.crt" ] && [ -r "$CERTS/client.key" ] && [ -r "$CERTS/ca.crt" ]; then
	TLS="transport.tls.enable = true
transport.tls.certFile = \"$CERTS/client.crt\"
transport.tls.keyFile = \"$CERTS/client.key\"
transport.tls.trustedCaFile = \"$CERTS/ca.crt\"
transport.tls.serverName = \"harp.nc\""
else
	TLS="transport.tls.enable = true"
fi
umask 077
mkdir -p /run/exapp
cat > /run/exapp/frpc.toml <<CONF
serverAddr = "${HP_FRP_ADDRESS}"
serverPort = ${HP_FRP_PORT}
loginFailExit = false
$TLS
metadatas.token = "${HP_SHARED_KEY}"

[[proxies]]
remotePort = ${APP_PORT}
type = "tcp"
name = "${APP_ID}"
[proxies.plugin]
type = "unix_domain_socket"
unixPath = "$SOCK"
CONF
exec /usr/local/bin/frpc -c /run/exapp/frpc.toml
