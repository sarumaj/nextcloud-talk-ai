#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""The sandbox runner for talk_ai's document jobs.

Listens on a Unix socket for one JSON request per connection (a line) and answers
with one JSON line. Each job gets its own container from the sandbox image:
read-only, without network, non-root, without capabilities, limited in CPU,
memory, processes and time, writable only /work (a tmpfs) and /tmp. The skills
are mounted read-only at /skills. Containers are removed when the job ends, and
by a reaper when their time is up.

Requests carry the shared token. Operations:
  health                                   -> {ok, jobs, max_jobs, image}
  create {cpus?, memory_mb?, disk_mb?, seconds?} -> {job}
  exec {job, command, timeout?}            -> {exit_code, stdout, stderr, truncated, timed_out}
  put {job, path, data (base64)}           -> {ok}
  get {job, path}                          -> {data (base64)}
  list {job, path?}                        -> {files: [{path, size}]}
  delete {job}                             -> {ok}

Configuration by environment: RUNNER_SOCKET, RUNNER_TOKEN_FILE, RUNNER_IMAGE,
RUNNER_SKILLS, RUNNER_MAX_JOBS, RUNNER_MAX_SECONDS, RUNNER_CPUS, RUNNER_MEMORY_MB,
RUNNER_DISK_MB, DOCKER (the client binary, docker or podman).
"""

import base64
import hmac
import json
import os
import posixpath
import secrets
import socket
import socketserver
import subprocess
import sys
import threading
import time

SOCKET = os.environ.get("RUNNER_SOCKET", "/var/lib/talk-ai-sandbox/run/runner.sock")
TOKEN = open(os.environ.get("RUNNER_TOKEN_FILE", "/var/lib/talk-ai-sandbox/token")).read().strip()
IMAGE = os.environ.get("RUNNER_IMAGE", "talk-ai-sandbox:latest")
SKILLS = os.environ.get("RUNNER_SKILLS", "/var/lib/talk-ai-sandbox/skills")
MAX_JOBS = int(os.environ.get("RUNNER_MAX_JOBS", "3"))
MAX_SECONDS = int(os.environ.get("RUNNER_MAX_SECONDS", "900"))
CPUS = float(os.environ.get("RUNNER_CPUS", "2"))
MEMORY_MB = int(os.environ.get("RUNNER_MEMORY_MB", "2048"))
DISK_MB = int(os.environ.get("RUNNER_DISK_MB", "1024"))
DOCKER = os.environ.get("DOCKER", "docker")
OUTPUT_LIMIT = 30 * 1024
FILE_LIMIT = 25 * 1024 * 1024

jobs = {}  # job id -> {"container": name, "deadline": time}
lock = threading.Lock()


def docker(*args, stdin=None, timeout=60):
    return subprocess.run([DOCKER, *args], input=stdin, capture_output=True, timeout=timeout)


def work_path(path):
    """A path inside /work (relative ones are taken from /work); anything else is refused."""
    path = posixpath.normpath(posixpath.join("/work", path or "."))
    if path != "/work" and not path.startswith("/work/"):
        raise ValueError("paths must stay inside /work")
    return path


def container(job):
    with lock:
        entry = jobs.get(job)
    if entry is None:
        raise ValueError("no such job (finished or timed out)")
    return entry["container"]


def create(req):
    with lock:
        if len(jobs) >= MAX_JOBS:
            raise RuntimeError("busy: %d document jobs are running; try again in a few minutes" % MAX_JOBS)
        job = secrets.token_hex(8)
        seconds = min(int(req.get("seconds", MAX_SECONDS)), MAX_SECONDS)
        jobs[job] = {"container": "talkai-" + job, "deadline": time.time() + seconds}
    cpus = min(float(req.get("cpus", CPUS)), CPUS)
    memory = min(int(req.get("memory_mb", MEMORY_MB)), MEMORY_MB)
    disk = min(int(req.get("disk_mb", DISK_MB)), DISK_MB)
    run = docker(
        "run", "--detach", "--rm", "--name", "talkai-" + job,
        "--network", "none", "--read-only", "--user", "10001:10001",
        "--cap-drop", "ALL", "--security-opt", "no-new-privileges",
        "--cpus", str(cpus), "--memory", "%dm" % memory, "--memory-swap", "%dm" % memory,
        "--pids-limit", "256",
        "--tmpfs", "/work:rw,exec,size=%dm,uid=10001,gid=10001,mode=0700" % disk,
        "--tmpfs", "/tmp:rw,exec,size=256m,mode=1777",
        "--mount", "type=bind,source=%s,target=/skills,readonly" % SKILLS,
        "--env", "HOME=/work/.home",
        "--label", "talk-ai-sandbox=1",
        IMAGE, "sleep", str(seconds + 60),
    )
    if run.returncode != 0:
        with lock:
            jobs.pop(job, None)
        raise RuntimeError("the sandbox did not start: " + run.stderr.decode(errors="replace")[-500:])
    docker("exec", "talkai-" + job, "mkdir", "-p", "/work/.home", "/work/input", "/work/output")
    return {"job": job}


def run_exec(req):
    name = container(req["job"])
    timeout = max(1, min(int(req.get("timeout", 120)), 600))
    try:
        done = docker("exec", "--workdir", "/work", name, "timeout", "--kill-after=5", str(timeout),
                      "bash", "-lc", req["command"], timeout=timeout + 15)
        timed_out = done.returncode == 124
    except subprocess.TimeoutExpired:
        return {"exit_code": 124, "stdout": "", "stderr": "timed out", "truncated": False, "timed_out": True}
    out, err = done.stdout, done.stderr
    truncated = len(out) + len(err) > OUTPUT_LIMIT
    # Keep the beginning and the end of long output: errors are usually at the end.
    cut = lambda b: b if len(b) <= OUTPUT_LIMIT // 2 else b[: OUTPUT_LIMIT // 4] + b"\n[... cut ...]\n" + b[-OUTPUT_LIMIT // 4:]
    return {"exit_code": done.returncode, "stdout": cut(out).decode(errors="replace"), "stderr": cut(err).decode(errors="replace"),
            "truncated": truncated, "timed_out": timed_out}


def put(req):
    name = container(req["job"])
    path = work_path(req["path"])
    data = base64.b64decode(req["data"])
    if len(data) > FILE_LIMIT:
        raise ValueError("file too large")
    done = docker("exec", "-i", name, "sh", "-c", 'mkdir -p "$(dirname "$1")" && cat > "$1"', "sh", path, stdin=data)
    if done.returncode != 0:
        raise RuntimeError(done.stderr.decode(errors="replace")[-300:])
    return {"ok": True}


def get(req):
    name = container(req["job"])
    path = work_path(req["path"])
    size = docker("exec", name, "stat", "-c", "%s", path)
    if size.returncode != 0:
        raise ValueError("no such file: " + path)
    if int(size.stdout.strip() or 0) > FILE_LIMIT:
        raise ValueError("file too large")
    done = docker("exec", name, "cat", path, timeout=120)
    if done.returncode != 0:
        raise RuntimeError(done.stderr.decode(errors="replace")[-300:])
    return {"data": base64.b64encode(done.stdout).decode()}


def listing(req):
    name = container(req["job"])
    path = work_path(req.get("path", "."))
    done = docker("exec", name, "find", path, "-path", "/work/.home", "-prune", "-o", "-type", "f", "-printf", "%p\t%s\n")
    files = []
    for line in done.stdout.decode(errors="replace").splitlines()[:500]:
        if "\t" in line:
            p, s = line.rsplit("\t", 1)
            files.append({"path": p, "size": int(s)})
    return {"files": files}


def delete(req):
    with lock:
        entry = jobs.pop(req["job"], None)
    if entry is not None:
        docker("rm", "--force", entry["container"])
    return {"ok": True}


def health(_req):
    with lock:
        count = len(jobs)
    return {"ok": True, "jobs": count, "max_jobs": MAX_JOBS, "image": IMAGE, "max_seconds": MAX_SECONDS}


OPERATIONS = {"health": health, "create": create, "exec": run_exec, "put": put, "get": get, "list": listing, "delete": delete}


class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        try:
            line = self.rfile.readline(48 * 1024 * 1024)
            req = json.loads(line)
            if not hmac.compare_digest(str(req.get("token", "")), TOKEN):
                raise PermissionError("bad token")
            answer = OPERATIONS[req["op"]](req)
        except Exception as e:  # every failure goes back to the caller
            answer = {"error": "%s: %s" % (type(e).__name__, e)}
        self.wfile.write((json.dumps(answer) + "\n").encode())


class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True


def reaper():
    """Removes jobs past their time, and leftover containers of an earlier run."""
    stale = docker("ps", "--all", "--quiet", "--filter", "label=talk-ai-sandbox=1")
    for cid in stale.stdout.decode().split():
        docker("rm", "--force", cid)
    while True:
        time.sleep(10)
        now = time.time()
        with lock:
            expired = [j for j, e in jobs.items() if e["deadline"] < now]
        for job in expired:
            print("job %s timed out; removing its container" % job, flush=True)
            delete({"job": job})


def main():
    if os.path.exists(SOCKET):
        os.unlink(SOCKET)
    threading.Thread(target=reaper, daemon=True).start()
    server = Server(SOCKET, Handler)
    # Reachable by the Nextcloud container's www-data; every request needs the token.
    os.chmod(SOCKET, 0o666)
    print("listening on %s (image %s, at most %d jobs)" % (SOCKET, IMAGE, MAX_JOBS), flush=True)
    server.serve_forever()


if __name__ == "__main__":
    sys.exit(main())
