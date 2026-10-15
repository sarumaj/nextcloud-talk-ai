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

Inside an ExApp (AppAPI, sandbox/exapp/): RUNNER_BACKEND=process. A container
there may not use Docker or namespaces, so each job runs as a user ID of its own
(setpriv, no capabilities, no new privileges) under a seccomp filter that refuses
network sockets, ptrace, mount and namespaces (exapp/jobexec.py), with the limits
above, in /work; one job at a time. The runner then speaks HTTP for AppAPI instead
of the socket protocol (RUNNER_HTTP=1, set by the ExApp's start script).

Package mirror (optional): RUNNER_NETWORK, a network for the job containers that
reaches only the mirror (made by the administrator; default "none": no network), and
RUNNER_PIP_INDEX_URL / RUNNER_NPM_REGISTRY, given to pip and npm in the container.

Without a container runtime: RUNNER_BACKEND=bwrap runs each job with Bubblewrap
(unprivileged user namespaces) in a directory below RUNNER_JOBS_DIR, without network,
with the skills read-only at /skills. Its root is RUNNER_ROOTFS (the sandbox image
unpacked, e.g. podman export | tar -x; then everything works) or the host's own
/usr and /etc (the host then needs the image's packages). Limits: memory, processes
and CPU time by prlimit, the size of /work checked after each command; CPUs are not
limited there (less isolation than a container: no cgroups, and the host's kernel).
"""

import base64
import hmac
import http.server
import urllib.request
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
TOKEN_FILE = os.environ.get("RUNNER_TOKEN_FILE", "/var/lib/talk-ai-sandbox/token")
TOKEN = open(TOKEN_FILE).read().strip() if os.path.exists(TOKEN_FILE) else ""
IMAGE = os.environ.get("RUNNER_IMAGE", "talk-ai-sandbox:latest")
SKILLS = os.environ.get("RUNNER_SKILLS", "/var/lib/talk-ai-sandbox/skills")
MAX_JOBS = int(os.environ.get("RUNNER_MAX_JOBS", "3"))
MAX_SECONDS = int(os.environ.get("RUNNER_MAX_SECONDS", "900"))
CPUS = float(os.environ.get("RUNNER_CPUS", "2"))
MEMORY_MB = int(os.environ.get("RUNNER_MEMORY_MB", "2048"))
DISK_MB = int(os.environ.get("RUNNER_DISK_MB", "1024"))
DOCKER = os.environ.get("DOCKER", "docker")
NETWORK = os.environ.get("RUNNER_NETWORK", "none")
PIP_INDEX = os.environ.get("RUNNER_PIP_INDEX_URL", "")
NPM_REGISTRY = os.environ.get("RUNNER_NPM_REGISTRY", "")
BACKEND = os.environ.get("RUNNER_BACKEND", "docker")
JOBS_DIR = os.environ.get("RUNNER_JOBS_DIR", "/var/lib/talk-ai-sandbox/jobs")
ROOTFS = os.environ.get("RUNNER_ROOTFS", "")
JOBEXEC = os.environ.get("RUNNER_JOBEXEC", "/opt/exapp/jobexec.py")
if BACKEND == "process":
    MAX_JOBS = 1  # one /work, one job
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
    with lock:
        jobs[job].update({"memory": memory, "disk": disk})
    if BACKEND in ("bwrap", "process"):
        return bw_create(job)
    env = []
    if PIP_INDEX:
        env += ["--env", "PIP_INDEX_URL=" + PIP_INDEX, "--env", "PIP_USER=1"]
    if NPM_REGISTRY:
        env += ["--env", "npm_config_registry=" + NPM_REGISTRY]
    run = docker(
        "run", "--detach", "--rm", "--name", "talkai-" + job,
        "--network", NETWORK, "--read-only", "--user", "10001:10001", *env,
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
    if BACKEND in ("bwrap", "process"):
        return bw_exec(req)
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
    if BACKEND in ("bwrap", "process"):
        return bw_put(req)
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
    if BACKEND in ("bwrap", "process"):
        return bw_get(req)
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
    if BACKEND in ("bwrap", "process"):
        return bw_list(req)
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
        if BACKEND == "process":
            uid = str(entry["uid"])
            subprocess.run(["pkill", "-KILL", "-U", uid])
            subprocess.run(["sh", "-c", 'rm -rf /work/* /work/.[!.]* 2>/dev/null; find /tmp /var/tmp /dev/shm -xdev -user "$1" -delete 2>/dev/null', "sh", uid])
        elif BACKEND == "bwrap":
            subprocess.run(["rm", "-rf", "--one-file-system", os.path.join(JOBS_DIR, req["job"])])
        else:
            docker("rm", "--force", entry["container"])
    return {"ok": True}


def health(_req):
    with lock:
        count = len(jobs)
    return {"ok": True, "jobs": count, "max_jobs": MAX_JOBS, "image": IMAGE if BACKEND == "docker" else BACKEND + ":" + (ROOTFS or "host"),
            "max_seconds": MAX_SECONDS, "backend": BACKEND, "network": NETWORK if BACKEND == "docker" else "none"}


# --- Bubblewrap: a job is a folder, each command a short-lived sandbox over it ---

def bw_dir(job):
    container(job)  # the job must exist
    return "/work" if BACKEND == "process" else os.path.join(JOBS_DIR, job)


def bw_host(path):
    """The host path of a /work path of the job."""
    return lambda job: os.path.join(bw_dir(job), posixpath.relpath(work_path(path), "/work"))


def bw_create(job):
    root = bw_dir(job)
    uid = None
    if BACKEND == "process":
        # The image's unprivileged user (LibreOffice needs one with a home); one job at a time, and what a
        # job leaves (processes, files in /tmp) is removed before the next one starts.
        uid = 10001
        with lock:
            jobs[job]["uid"] = uid
        subprocess.run(["sh", "-c", "rm -rf /work/* /work/.[!.]* 2>/dev/null"])
    for sub in ("", ".home", ".tmp", "input", "output"):
        os.makedirs(os.path.join(root, sub), mode=0o700, exist_ok=True)
        if uid is not None:
            os.chown(os.path.join(root, sub), uid, uid)
    return {"job": job}


def bw_command(job, command, timeout):
    with lock:
        entry = jobs[job]
    root = ["--ro-bind", ROOTFS, "/"] if ROOTFS else [
        "--ro-bind", "/usr", "/usr", "--ro-bind", "/etc", "/etc", "--ro-bind-try", "/opt", "/opt",
        "--symlink", "usr/bin", "/bin", "--symlink", "usr/sbin", "/sbin", "--symlink", "usr/lib", "/lib",
        "--symlink", "usr/lib64", "/lib64"]
    return ["prlimit", "--as=%d" % (entry["memory"] * 1024 * 1024 * 4), "--nproc=256", "--cpu=%d" % (timeout * 2), "--",
            "timeout", "--kill-after=5", str(timeout),
            "bwrap", "--die-with-parent", "--new-session", "--unshare-all", "--cap-drop", "ALL", *root,
            "--dev", "/dev", "--proc", "/proc", "--tmpfs", "/tmp", "--tmpfs", "/run",
            "--bind", os.path.join(JOBS_DIR, job), "/work", "--ro-bind", SKILLS, "/skills",
            "--chdir", "/work", "--setenv", "HOME", "/work/.home", "--setenv", "PATH", "/opt/venv/bin:/usr/local/bin:/usr/bin:/bin",
            "--setenv", "NODE_PATH", "/opt/node/node_modules", "--setenv", "LANG", "C.UTF-8",
            "bash", "-lc", command]


def process_command(job, command, timeout):
    """A job's command as its own user, without capabilities, under the seccomp filter (no network)."""
    with lock:
        entry = jobs[job]
    uid = str(entry["uid"])
    return ["prlimit", "--as=%d" % (entry["memory"] * 1024 * 1024 * 4), "--nproc=256", "--cpu=%d" % (timeout * 2), "--",
            "timeout", "--kill-after=5", str(timeout),
            "setpriv", "--reuid", uid, "--regid", uid, "--clear-groups", "--inh-caps=-all", "--bounding-set=-all", "--no-new-privs", "--",
            "env", "-i", "HOME=/work/.home", "TMPDIR=/work/.tmp", "PATH=/opt/venv/bin:/usr/local/bin:/usr/bin:/bin",
            "NODE_PATH=/opt/node/node_modules", "LANG=C.UTF-8", "LC_ALL=C.UTF-8",
            "/usr/bin/python3", "-I", JOBEXEC, "bash", "-lc", command]


def bw_exec(req):
    job = req["job"]
    bw_dir(job)
    timeout = max(1, min(int(req.get("timeout", 120)), 600))
    argv = process_command(job, req["command"], timeout) if BACKEND == "process" else bw_command(job, req["command"], timeout)
    try:
        done = subprocess.run(argv, capture_output=True, timeout=timeout + 15, cwd="/work" if BACKEND == "process" else None)
    except subprocess.TimeoutExpired:
        return {"exit_code": 124, "stdout": "", "stderr": "timed out", "truncated": False, "timed_out": True}
    out, err = done.stdout, done.stderr
    # The working space: over its size, the job's newest files are what broke it; it ends.
    du = subprocess.run(["du", "-sm", bw_dir(job)], capture_output=True, text=True).stdout.split()
    used = int(du[0]) if du and du[0].isdigit() else 0
    with lock:
        limit = jobs.get(job, {}).get("disk", DISK_MB)
    if used > limit:
        delete({"job": job})
        err += ("\nthe working space is over %d MB: the job was ended" % limit).encode()
    truncated = len(out) + len(err) > OUTPUT_LIMIT
    cut = lambda b: b if len(b) <= OUTPUT_LIMIT // 2 else b[: OUTPUT_LIMIT // 4] + b"\n[... cut ...]\n" + b[-OUTPUT_LIMIT // 4:]
    return {"exit_code": done.returncode, "stdout": cut(out).decode(errors="replace"), "stderr": cut(err).decode(errors="replace"),
            "truncated": truncated, "timed_out": done.returncode == 124}


def bw_put(req):
    target = bw_host(req["path"])(req["job"])
    data = base64.b64decode(req["data"])
    if len(data) > FILE_LIMIT:
        raise ValueError("file too large")
    os.makedirs(os.path.dirname(target), mode=0o700, exist_ok=True)
    # A link the job made must not lead out of its folder.
    if not os.path.realpath(target).startswith(os.path.realpath(bw_dir(req["job"])) + os.sep):
        raise ValueError("paths must stay inside /work")
    with open(target, "wb") as f:
        f.write(data)
    if BACKEND == "process":
        with lock:
            uid = jobs[req["job"]]["uid"]
        base = os.path.realpath(bw_dir(req["job"]))
        folder = os.path.dirname(os.path.realpath(target))
        while folder.startswith(base):
            os.chown(folder, uid, uid)
            folder = os.path.dirname(folder)
        os.chown(target, uid, uid)
    return {"ok": True}


def bw_get(req):
    target = bw_host(req["path"])(req["job"])
    # A link the job made must not lead out of its folder.
    if not os.path.realpath(target).startswith(os.path.realpath(bw_dir(req["job"])) + os.sep):
        raise ValueError("paths must stay inside /work")
    if not os.path.isfile(target):
        raise ValueError("no such file: " + work_path(req["path"]))
    if os.path.getsize(target) > FILE_LIMIT:
        raise ValueError("file too large")
    with open(target, "rb") as f:
        return {"data": base64.b64encode(f.read()).decode()}


def bw_list(req):
    base = bw_dir(req["job"])
    start = bw_host(req.get("path", "."))(req["job"])
    files = []
    for folder, dirs, names in os.walk(start):
        dirs[:] = [d for d in dirs if not (folder == base and d == ".home")]
        for name in names:
            full = os.path.join(folder, name)
            if not os.path.islink(full):
                files.append({"path": "/work/" + os.path.relpath(full, base), "size": os.path.getsize(full)})
            if len(files) >= 500:
                return {"files": files}
    return {"files": files}


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
    """Removes jobs past their time, and leftover containers or job folders of an earlier run."""
    if BACKEND == "process":
        subprocess.run(["sh", "-c", "rm -rf /work/* /work/.[!.]* 2>/dev/null"])
    elif BACKEND == "bwrap":
        os.makedirs(JOBS_DIR, mode=0o700, exist_ok=True)
        for leftover in os.listdir(JOBS_DIR):
            subprocess.run(["rm", "-rf", "--one-file-system", os.path.join(JOBS_DIR, leftover)])
    else:
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


class ExAppHandler(http.server.BaseHTTPRequestHandler):
    """The runner for AppAPI: heartbeat, init, enabled, and the operations at /op (JSON), each
    request signed by AppAPI with the ExApp's secret."""
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):
        pass

    def address_string(self):
        return "harp"

    def reply(self, code, body):
        data = json.dumps(body).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def authorized(self):
        try:
            secret = base64.b64decode(self.headers.get("AUTHORIZATION-APP-API", "")).decode().split(":", 1)[1]
        except Exception:
            return False
        return hmac.compare_digest(secret, os.environ.get("APP_SECRET", "")) and self.headers.get("EX-APP-ID") == os.environ.get("APP_ID")

    def do_GET(self):
        if self.path.startswith("/heartbeat"):
            return self.reply(200, {"status": "ok"})
        self.reply(404, {"error": "not found"})

    def do_PUT(self):
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        if self.path.startswith("/enabled"):
            return self.reply(200, {"error": ""})
        self.reply(404, {"error": "not found"})

    def do_POST(self):
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(length) if length else b"{}"
        if self.path.startswith("/init"):
            threading.Thread(target=report_init, daemon=True).start()
            return self.reply(200, {})
        if self.path.startswith("/op"):
            try:
                req = json.loads(body)
                answer = OPERATIONS[req["op"]](req)
            except Exception as e:
                answer = {"error": "%s: %s" % (type(e).__name__, e)}
            return self.reply(200, answer)
        self.reply(404, {"error": "not found"})


def report_init():
    """Tells AppAPI the ExApp is ready (it has nothing to download)."""
    url = os.environ.get("NEXTCLOUD_URL", "").rstrip("/") + "/ocs/v1.php/apps/app_api/ex-app/status"
    request = urllib.request.Request(url, data=json.dumps({"progress": 100}).encode(), method="PUT", headers={
        "Content-Type": "application/json", "OCS-APIRequest": "true", "AA-VERSION": os.environ.get("AA_VERSION", "2.0.0"),
        "EX-APP-ID": os.environ.get("APP_ID", ""), "EX-APP-VERSION": os.environ.get("APP_VERSION", ""),
        "AUTHORIZATION-APP-API": base64.b64encode((":" + os.environ.get("APP_SECRET", "")).encode()).decode()})
    try:
        urllib.request.urlopen(request, timeout=30)
    except Exception as e:
        print("init report failed: %s" % e, flush=True)


class UnixHTTPServer(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True

    def get_request(self):
        request, _ = super().get_request()
        return request, ("harp", 0)


def serve_http():
    """Under HaRP on a unix socket (frpc tunnels it), else on APP_HOST:APP_PORT."""
    threading.Thread(target=reaper, daemon=True).start()
    if os.environ.get("HP_SHARED_KEY"):
        path = os.environ.get("HP_EXAPP_SOCK", "/tmp/exapp.sock")
        if os.path.exists(path):
            os.unlink(path)
        server = UnixHTTPServer(path, ExAppHandler)
        print("listening on %s for AppAPI (HaRP), backend %s" % (path, BACKEND), flush=True)
    else:
        server = http.server.ThreadingHTTPServer((os.environ.get("APP_HOST", "127.0.0.1"), int(os.environ.get("APP_PORT", "23000"))), ExAppHandler)
        print("listening on %s:%s for AppAPI, backend %s" % (os.environ.get("APP_HOST"), os.environ.get("APP_PORT"), BACKEND), flush=True)
    server.serve_forever()


def main():
    if os.environ.get("RUNNER_HTTP") == "1":
        return serve_http()
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
