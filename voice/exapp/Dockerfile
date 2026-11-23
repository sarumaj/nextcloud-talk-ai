# SPDX-License-Identifier: AGPL-3.0-or-later
#
# AI in Talk: voice, the External App (AppAPI) that gives Nextcloud a text-to-speech
# provider with Piper (voice.py). Built from the repository's root:
#   docker build -t talk-ai-voice-exapp:latest -f voice/exapp/Dockerfile .
# The voices are baked in, pinned to a commit of rhasspy/piper-voices and checked by
# their sha256: the container downloads nothing.
FROM python:3.12-slim-bookworm
ARG FRP_VERSION=0.61.1
# renovate: datasource=pypi depName=piper-tts
ARG PIPER_VERSION=1.8.0
# renovate: datasource=pypi depName=lameenc
ARG LAMEENC_VERSION=1.9.0
ARG VOICES_URL=https://huggingface.co/rhasspy/piper-voices/resolve/375a0fe641dea077c2a47b4e9a056d6da521eed3
RUN apt-get update \
 && apt-get install -y --no-install-recommends curl ca-certificates \
 && arch=$(dpkg --print-architecture) \
 && curl -fsSL "https://github.com/fatedier/frp/releases/download/v${FRP_VERSION}/frp_${FRP_VERSION}_linux_${arch}.tar.gz" \
    | tar -xz -C /tmp && install -m 0755 /tmp/frp_${FRP_VERSION}_linux_${arch}/frpc /usr/local/bin/frpc \
 && pip install --no-cache-dir "piper-tts==${PIPER_VERSION}" "lameenc==${LAMEENC_VERSION}" \
 && rm -rf /tmp/frp_* /var/lib/apt/lists/*
# The voices (voices.txt: sha256 and path below VOICES_URL), each checked.
COPY voice/exapp/voices.txt /tmp/voices.txt
RUN mkdir -p /voices && cd /voices \
 && while read -r sum path; do \
      curl -fsSL -o "$(basename "$path")" "$VOICES_URL/$path" \
      && echo "$sum  $(basename "$path")" | sha256sum -c - || exit 1; \
    done < /tmp/voices.txt \
 && rm /tmp/voices.txt && chmod -R a+rX /voices \
 && apt-get purge -y curl && apt-get autoremove -y
COPY sandbox/exapp/harp_connect.sh voice/exapp/start.sh voice/exapp/voice.py /opt/exapp/
RUN chmod 0755 /opt/exapp/*.sh /opt/exapp/*.py
ENTRYPOINT ["/opt/exapp/start.sh"]
