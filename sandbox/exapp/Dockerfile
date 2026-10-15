# The document sandbox as an ExApp (AppAPI): for servers where Nextcloud cannot run
# containers itself. Built from the repository's root:
#   docker build -t talk-ai-sandbox:latest sandbox/
#   docker build -t talk-ai-sandbox-exapp:latest -f sandbox/exapp/Dockerfile .
# Each job runs as a user of its own under a seccomp filter (no network), see
# sandbox/runner.py (RUNNER_BACKEND=process) and exapp/jobexec.py.
ARG BASE=talk-ai-sandbox:latest
FROM ${BASE}
USER root
ARG FRP_VERSION=0.61.1
RUN apt-get update \
 && apt-get install -y --no-install-recommends python3-seccomp util-linux procps curl ca-certificates \
 && arch=$(dpkg --print-architecture) \
 && curl -fsSL "https://github.com/fatedier/frp/releases/download/v${FRP_VERSION}/frp_${FRP_VERSION}_linux_${arch}.tar.gz" \
    | tar -xz -C /tmp && install -m 0755 /tmp/frp_${FRP_VERSION}_linux_${arch}/frpc /usr/local/bin/frpc \
 && rm -rf /tmp/frp_* /var/lib/apt/lists/* \
 && apt-get purge -y curl && apt-get autoremove -y
COPY sandbox/runner.py sandbox/exapp/jobexec.py sandbox/exapp/start.sh sandbox/exapp/harp_connect.sh /opt/exapp/
COPY skills/ /skills/
RUN chmod 0755 /opt/exapp/*.sh /opt/exapp/*.py && chmod -R a+rX /skills && mkdir -p /work && chmod 0755 /work
WORKDIR /work
ENTRYPOINT ["/opt/exapp/start.sh"]
