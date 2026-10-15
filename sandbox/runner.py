#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""The sandbox runner for talk_ai's document jobs.

Listens on a Unix socket for one JSON request per connection (a line) and answers
with one JSON line. Each job gets its own container from the sandbox image:
read-only, without network, non-root, without capabilities, limited in CPU,
memory, processes and time, writable only /work (a tmpfs) and /tmp. The skills
are mounted read-only at /skills. Containers are removed when the job ends, and
by a reaper when the job is abandoned or its time is up.

Requests carry the shared token. Operations:
  health                                   -> {ok, jobs, max_jobs, image}
  create {cpus?, memory_mb?, disk_mb?, seconds?} -> {job}
  exec {job, command, timeout?, idle?, wait?} -> {exit_code, stdout, stderr, truncated, timed_out, stalled, seconds}
                                              or, with wait and still running: {running, run, seconds, last}
  exec_wait {job, run, wait?}              -> either answer of exec
  exec_stop {job, run}                     -> the result of the stopped command
  put {job, path, data (base64)}           -> {ok}
  get {job, path}                          -> {data (base64)}
  list {job, path?}                        -> {files: [{path, size}]}
  delete {job}                             -> {ok}

A command runs as long as it makes progress: output, or CPU time of its processes. It
is stopped after `idle` seconds without either (default RUNNER_EXEC_IDLE, 120: hung,
or waiting on something that never comes), or after `timeout` seconds in all (at
most RUNNER_EXEC_MAX_SECONDS, 3600). With `wait`, exec answers after that many
seconds at the latest, while the command goes on; exec_wait asks again, so the
caller can show progress and stop it (exec_stop). Without, exec waits for the end.

A job ends with delete, after RUNNER_MAX_SECONDS in all (14400), or when nothing
was asked of it for RUNNER_JOB_IDLE_SECONDS (1800) and no command is running.

Configuration by environment: RUNNER_SOCKET, RUNNER_TOKEN_FILE, RUNNER_IMAGE,
RUNNER_SKILLS, RUNNER_MAX_JOBS, RUNNER_MAX_SECONDS, RUNNER_JOB_IDLE_SECONDS,
RUNNER_EXEC_IDLE, RUNNER_EXEC_MAX_SECONDS, RUNNER_CPUS, RUNNER_MEMORY_MB,
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
import collections
import hmac
import http.server
import urllib.request
import json
import os
import posixpath
import secrets
import signal
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
MAX_SECONDS = int(os.environ.get("RUNNER_MAX_SECONDS", "14400"))
JOB_IDLE = int(os.environ.get("RUNNER_JOB_IDLE_SECONDS", "1800"))
EXEC_IDLE = int(os.environ.get("RUNNER_EXEC_IDLE", "120"))
EXEC_MAX = int(os.environ.get("RUNNER_EXEC_MAX_SECONDS", "3600"))
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

jobs = {}  # job id -> {"container": name, "deadline": time, "seen": time, "run": Run or None}
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
        if entry is not None:
            entry["seen"] = time.time()
    if entry is None:
        raise ValueError("no such job (finished or timed out)")
    return entry["container"]


def create(req):
    with lock:
        if len(jobs) >= MAX_JOBS:
            raise RuntimeError("busy: %d document jobs are running; try again in a few minutes" % MAX_JOBS)
        job = secrets.token_hex(8)
        seconds = min(int(req.get("seconds") or MAX_SECONDS), MAX_SECONDS)
        jobs[job] = {"container": "talkai-" + job, "deadline": time.time() + seconds, "seen": time.time(), "run": None}
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


class Capture:
    """The output of a stream: the beginning and the end (errors are usually at the end), and its size."""

    def __init__(self):
        self.head = bytearray()
        self.tail = collections.deque()
        self.tail_size = 0
        self.size = 0

    def add(self, data):
        self.size += len(data)
        room = OUTPUT_LIMIT // 4 - len(self.head)
        if room > 0:
            self.head += data[:room]
            data = data[room:]
        if data:
            self.tail.append(bytes(data))
            self.tail_size += len(data)
            while self.tail_size - len(self.tail[0]) >= OUTPUT_LIMIT // 4:
                self.tail_size -= len(self.tail.popleft())

    def text(self):
        tail = b"".join(self.tail)
        if self.cut():
            return (bytes(self.head) + b"\n[... cut ...]\n" + tail[-OUTPUT_LIMIT // 4:]).decode(errors="replace")
        return (bytes(self.head) + tail).decode(errors="replace")

    def cut(self):
        return self.size > len(self.head) + self.tail_size

    def last(self):
        tail = b"".join(self.tail) or bytes(self.head)
        lines = [l for l in tail[-2000:].decode(errors="replace").splitlines() if l.strip()]
        return lines[-1][-200:] if lines else ""


def tree_cpu(pid):
    """CPU seconds of a process and all below it (those that ended count with their parent)."""
    parents, cpu = {}, {}
    for entry in os.listdir("/proc"):
        if not entry.isdigit():
            continue
        try:
            with open("/proc/%s/stat" % entry) as f:
                fields = f.read().rsplit(")", 1)[1].split()
        except OSError:
            continue
        parents[int(entry)] = int(fields[1])
        cpu[int(entry)] = sum(int(x) for x in fields[11:15])
    total, todo = 0, [pid]
    while todo:
        p = todo.pop()
        total += cpu.get(p, 0)
        todo += [c for c, parent in parents.items() if parent == p]
    return total / os.sysconf("SC_CLK_TCK")


class Run:
    """One command of a job, watched: it goes on while it makes progress (output, CPU time)."""

    def __init__(self, job, argv, cwd, limit, idle):
        self.job, self.limit, self.idle = job, limit, idle
        self.id = secrets.token_hex(6)
        self.out, self.err = Capture(), Capture()
        self.started = time.time()
        self.stalled = self.over = self.stopped = False
        self.done = threading.Event()
        self.proc = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, stdin=subprocess.DEVNULL,
                                     cwd=cwd, start_new_session=True)
        self.readers = [threading.Thread(target=self.read, args=(self.proc.stdout, self.out), daemon=True),
                        threading.Thread(target=self.read, args=(self.proc.stderr, self.err), daemon=True)]
        for reader in self.readers:
            reader.start()
        threading.Thread(target=self.watch, daemon=True).start()

    @staticmethod
    def read(stream, capture):
        for chunk in iter(lambda: stream.read1(65536), b""):
            capture.add(chunk)

    def cpu(self):
        if BACKEND != "docker":
            return tree_cpu(self.proc.pid)
        # The job's container as a whole (its processes are not ours to see).
        done = docker("exec", container(self.job), "cat", "/sys/fs/cgroup/cpu.stat", timeout=20)
        for line in done.stdout.decode(errors="replace").splitlines():
            if line.startswith("usage_usec "):
                return int(line.split()[1]) / 1e6
        return 0.0

    def watch(self):
        last_progress, seen = time.time(), (0, 0.0)
        while self.proc.poll() is None:
            time.sleep(1 if time.time() - self.started < 10 else 5)
            now = time.time()
            try:
                current = (self.out.size + self.err.size, self.cpu())
            except Exception:
                current = (self.out.size + self.err.size, seen[1])
            # Progress: new output, or a tenth of a CPU second more (a sleeping process uses none).
            if current[0] != seen[0] or current[1] - seen[1] >= 0.1:
                last_progress, seen = now, current
            if now - last_progress > self.idle:
                self.stalled = True
                self.kill()
            elif now - self.started > self.limit:
                self.over = True
                self.kill()
        # What the command left running in the background ends with it (it would hold the output open).
        try:
            os.killpg(self.proc.pid, signal.SIGKILL)
        except OSError:
            pass
        for reader in self.readers:
            reader.join(10)
        self.done.set()

    def kill(self):
        try:
            os.killpg(self.proc.pid, signal.SIGKILL)
        except OSError:
            pass
        with lock:
            entry = jobs.get(self.job) or {}
        # What escaped the process group: the job's processes (its own user; in a container, all but its init).
        if BACKEND == "process" and "uid" in entry:
            subprocess.run(["pkill", "-KILL", "-U", str(entry["uid"])])
        elif BACKEND == "docker" and "container" in entry:
            docker("exec", entry["container"], "sh", "-c", "kill -9 -1", timeout=20)

    def status(self):
        return {"running": True, "run": self.id, "seconds": int(time.time() - self.started), "last": self.err.last() or self.out.last()}

    def result(self):
        out, err = self.out.text(), self.err.text()
        if self.stalled:
            err += "\n[stopped: no output and no CPU use for %d s; the command hung or waited for input]" % self.idle
        elif self.over:
            err += "\n[stopped: over the time limit of %d s]" % self.limit
        elif self.stopped:
            err += "\n[stopped on request]"
        code = self.proc.returncode
        return {"exit_code": 124 if self.stalled or self.over else code, "stdout": out, "stderr": err,
                "truncated": self.out.cut() or self.err.cut(), "timed_out": self.over or code == 124,
                "stalled": self.stalled, "seconds": int(time.time() - self.started)}


def exec_argv(job, command, limit):
    if BACKEND == "process":
        return process_command(job, command, limit), "/work"
    if BACKEND == "bwrap":
        return bw_command(job, command, limit), None
    return [DOCKER, "exec", "--workdir", "/work", container(job), "timeout", "--kill-after=5", str(limit),
            "bash", "-lc", command], None


def run_exec(req):
    job = req["job"]
    container(job)
    limit = max(1, min(int(req.get("timeout") or 120), EXEC_MAX))
    idle = max(10, min(int(req.get("idle") or EXEC_IDLE), EXEC_MAX))
    with lock:
        if jobs[job].get("run") is not None:
            raise RuntimeError("a command of this job is still running")
    if BACKEND in ("bwrap", "process"):
        bw_dir(job)
    argv, cwd = exec_argv(job, req["command"], limit)
    run = Run(job, argv, cwd, limit, idle)
    with lock:
        jobs[job]["run"] = run
    return answer(job, run, req.get("wait"))


def current_run(req):
    container(req["job"])
    with lock:
        run = jobs[req["job"]].get("run")
    if run is None or run.id != req.get("run"):
        raise ValueError("no such command (finished, or the job ended)")
    return run


def exec_wait(req):
    return answer(req["job"], current_run(req), req.get("wait", 30))


def exec_stop(req):
    run = current_run(req)
    run.stopped = True
    run.kill()
    return answer(req["job"], run, None)


def answer(job, run, wait):
    """The result once the command ended; with wait, its status after at most that many seconds."""
    if not run.done.wait(None if wait is None else max(0, min(float(wait), 60))):
        return run.status()
    with lock:
        if jobs.get(job, {}).get("run") is run:
            jobs[job]["run"] = None
    result = run.result()
    if BACKEND in ("bwrap", "process") and job in jobs:
        # The working space: over its size, the job's newest files are what broke it; it ends.
        du = subprocess.run(["du", "-sm", bw_dir(job)], capture_output=True, text=True).stdout.split()
        used = int(du[0]) if du and du[0].isdigit() else 0
        with lock:
            limit = jobs.get(job, {}).get("disk", DISK_MB)
        if used > limit:
            delete({"job": job})
            result["stderr"] += "\nthe working space is over %d MB: the job was ended" % limit
    return result


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
        entry = jobs.get(req["job"])
        run = entry.get("run") if entry is not None else None
    if run is not None:
        run.stopped = True
        run.kill()
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
            "max_seconds": MAX_SECONDS, "backend": BACKEND, "network": NETWORK if BACKEND == "docker" else "none",
            "exec_idle": EXEC_IDLE, "exec_max_seconds": EXEC_MAX, "features": ["exec_wait"]}


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


OPERATIONS = {"health": health, "create": create, "exec": run_exec, "exec_wait": exec_wait, "exec_stop": exec_stop,
              "put": put, "get": get, "list": listing, "delete": delete}


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
    """Removes jobs past their time or abandoned, and leftover containers or job folders of an earlier run."""
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
            expired = [j for j, e in jobs.items() if e["deadline"] < now or (e["run"] is None and now - e["seen"] > JOB_IDLE)]
        for job in expired:
            print("job %s is over its time or abandoned; removing it" % job, flush=True)
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
