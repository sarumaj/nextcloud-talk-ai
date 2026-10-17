# The sandbox in which the AI builds office documents with code: one container per
# job (see runner.py), read-only, without network, writable only /work and /tmp.
# Python and Node with the document libraries, LibreOffice headless to convert and
# render, Poppler to turn pages into images, the fonts Collabora has, and ziamath
# (LaTeX to SVG without TeX) with rsvg-convert for formulas as images.
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive LANG=C.UTF-8 LC_ALL=C.UTF-8 \
    PIP_NO_CACHE_DIR=1 PIP_DISABLE_PIP_VERSION_CHECK=1 \
    NODE_PATH=/opt/node/node_modules PATH=/opt/venv/bin:$PATH

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      python3 python3-venv nodejs npm \
      libreoffice-writer-nogui libreoffice-calc-nogui libreoffice-impress-nogui python3-uno \
      poppler-utils imagemagick librsvg2-bin ca-certificates coreutils procps \
      hunspell hunspell-de-de hunspell-en-us hunspell-pl \
      fonts-liberation2 fonts-dejavu fonts-crosextra-carlito fonts-crosextra-caladea \
      fonts-noto-core fonts-open-sans fonts-linuxlibertine \
 && rm -rf /var/lib/apt/lists/* /usr/share/doc/* /usr/share/man/*

# Versions pinned; Renovate proposes updates (pip and npm datasources).
RUN python3 -m venv --system-site-packages /opt/venv \
 && /opt/venv/bin/pip install \
      python-docx==1.1.2 openpyxl==3.1.5 python-pptx==1.0.2 reportlab==4.2.5 \
      pypdf==5.1.0 odfpy==1.4.1 pillow==11.0.0 matplotlib==3.9.2 pandas==2.2.3 \
      XlsxWriter==3.2.0 markdown==3.7 \
      ziamath==0.13 ziafont==0.11 latex2mathml==3.81.1

RUN mkdir -p /opt/node && cd /opt/node \
 && npm install --omit=dev --no-fund --no-audit docx@9.0.3 exceljs@4.4.0 pptxgenjs@3.12.0 \
 && npm cache clean --force

# The user the jobs run as; /work is a tmpfs owned by it (runner.py).
# The runner's commands run in a login shell, whose /etc/profile resets PATH.
RUN useradd --uid 10001 --home-dir /work --shell /bin/bash sandbox \
 && mkdir -p /work /skills && chown sandbox /work \
 && echo 'export PATH=/opt/venv/bin:$PATH' > /etc/profile.d/venv.sh

USER 10001
WORKDIR /work
ENV HOME=/work/.home
CMD ["sleep", "infinity"]
