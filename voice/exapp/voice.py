# SPDX-License-Identifier: AGPL-3.0-or-later
"""AI in Talk: voice, an External App (AppAPI) that gives Nextcloud a text-to-speech
provider: TaskProcessing's core:text2speech, spoken by Piper on the CPU in German,
Polish or English, as MP3 (which every Talk client plays as a voice message, the iOS
app too, which has no Ogg/Opus).

AI in Talk uses it for its spoken replies to voice messages (lib/Service/Speech.php);
any other app may too (the Assistant's "Generate speech"). The optional input
"language" (auto, de, pl, en) chooses the voice; auto reads it from the text.

Endpoints for AppAPI (each request signed with the ExApp's secret): GET /heartbeat,
POST /init (ready at once: the voices are in the image), PUT /enabled (registers or
removes the provider). A thread asks Nextcloud for the next task every POLL seconds
while the app is enabled.

Calls (call.py): AI in Talk asks it to join a call (POST /call/join) or leave one (POST
/call/leave), through AppAPI; it takes part as a voice that hears, asks the bot and speaks.

    python3 voice.py --say de "Hallo"   # writes speech to stdout (MP3), for the image's smoke test
"""

import base64
import hmac
import http.server
import io
import json
import os
import re
import socketserver
import sys
import threading
import time
import urllib.error
import urllib.request
import uuid
import wave

# call.py beside this file: python3 -I leaves the script's directory off the path.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

VOICES_DIR = os.environ.get("VOICES_DIR", "/voices")
# The voice per language: <dir>/<name>.onnx with its .onnx.json (Dockerfile).
VOICES = {"de": "de_DE-thorsten-medium", "pl": "pl_PL-gosia-medium", "en": "en_US-lessac-medium"}
PROVIDER_ID = "talk_ai_voice:piper"
TASK_TYPE = "core:text2speech"
POLL = float(os.environ.get("VOICE_POLL_SECONDS", "2"))
MP3_KBPS = int(os.environ.get("VOICE_MP3_KBPS", "64"))
# Longer texts are cut: a task is one voice message, not an audiobook.
MAX_CHARS = int(os.environ.get("VOICE_MAX_CHARS", "6000"))

WORDS = {
    "de": {"und", "der", "die", "das", "ist", "nicht", "ich", "du", "mit", "auf", "für", "ein", "eine", "auch", "zu", "sie", "es", "wie", "aber", "oder", "wenn", "dass"},
    "pl": {"i", "nie", "się", "to", "jest", "na", "że", "w", "z", "do", "jak", "ale", "czy", "co", "tak", "dla", "jestem", "są", "też", "albo", "jeśli", "mi"},
    "en": {"the", "and", "is", "not", "you", "with", "for", "a", "to", "of", "it", "that", "this", "are", "or", "if", "but", "what", "how", "can", "your", "be"},
}

_voices = {}
_voice_lock = threading.Lock()
enabled = threading.Event()


def language_of(text, fallback="en"):
    """de, pl or en by the text's letters and common words (as Speech::language in PHP)."""
    lower = text.lower()
    score = {"de": 0, "pl": 0, "en": 0}
    score["pl"] += 3 * len(re.findall("[ąćęłńśźż]", lower))
    score["de"] += 3 * len(re.findall("[äöüß]", lower))
    for word in re.findall(r"[^\W\d_]+", lower):
        for language, words in WORDS.items():
            if word in words:
                score[language] += 1
    ranked = sorted(score.items(), key=lambda kv: -kv[1])
    (best, first), (_, second) = ranked[0], ranked[1]
    return best if first >= 3 and first >= 1.5 * second else fallback


def voice(language):
    """The Piper voice of the language, loaded once (about 100 MB of memory each)."""
    from piper import PiperVoice  # pylint: disable=import-outside-toplevel

    with _voice_lock:
        if language not in _voices:
            _voices[language] = PiperVoice.load(os.path.join(VOICES_DIR, VOICES[language] + ".onnx"))
        return _voices[language]


def synthesize(text, language):
    """The text spoken in the language's voice, as MP3 (mono, MP3_KBPS)."""
    import lameenc  # pylint: disable=import-outside-toplevel

    if language not in VOICES:
        language = language_of(text)
    buffer = io.BytesIO()
    with wave.open(buffer, "wb") as wav:
        voice(language).synthesize_wav(text[:MAX_CHARS], wav)
    buffer.seek(0)
    with wave.open(buffer, "rb") as wav:
        rate, channels, frames = wav.getframerate(), wav.getnchannels(), wav.readframes(wav.getnframes())
    encoder = lameenc.Encoder()
    encoder.set_bit_rate(MP3_KBPS)
    encoder.set_in_sample_rate(rate)
    encoder.set_channels(channels)
    encoder.set_quality(2)
    return bytes(encoder.encode(frames) + encoder.flush())


def pcm(text, language):
    """The text spoken in the language's voice, as 48 kHz mono 16-bit PCM (for a call)."""
    from call import to_48k  # pylint: disable=import-outside-toplevel

    if language not in VOICES:
        language = language_of(text)
    buffer = io.BytesIO()
    with wave.open(buffer, "wb") as wav:
        voice(language).synthesize_wav(text[:MAX_CHARS], wav)
    buffer.seek(0)
    with wave.open(buffer, "rb") as wav:
        return to_48k(wav.readframes(wav.getnframes()), wav.getframerate())


# Nextcloud's OCS API, as the ExApp (AppAPI's headers), or as a person through it (user).
def _headers(extra=None, user=""):
    headers = {
        "OCS-APIRequest": "true",
        "AA-VERSION": os.environ.get("AA_VERSION", "2.0.0"),
        "EX-APP-ID": os.environ.get("APP_ID", ""),
        "EX-APP-VERSION": os.environ.get("APP_VERSION", ""),
        "AUTHORIZATION-APP-API": base64.b64encode((user + ":" + os.environ.get("APP_SECRET", "")).encode()).decode(),
    }
    headers.update(extra or {})
    return headers


def ocs(method, path, body=None, data=None, content_type="application/json", timeout=60, user=""):
    """An OCS request; its data, or None for 204 No Content. Raises urllib.error.HTTPError."""
    url = os.environ.get("NEXTCLOUD_URL", "").rstrip("/") + path + ("&" if "?" in path else "?") + "format=json"
    payload = data if data is not None else (json.dumps(body).encode() if body is not None else None)
    request = urllib.request.Request(url, data=payload, method=method, headers=_headers({"Content-Type": content_type} if payload is not None else {}, user))
    with urllib.request.urlopen(request, timeout=timeout) as response:
        if response.status == 204:
            return None
        raw = response.read()
    return json.loads(raw)["ocs"]["data"] if raw else None


PROVIDER = {
    "id": PROVIDER_ID,
    "name": "Piper (AI in Talk: voice)",
    "task_type": TASK_TYPE,
    "expected_runtime": 20,
    "optional_input_shape": [{"name": "language", "description": "The language spoken (auto: read from the text)", "shape_type": 6}],
    "optional_output_shape": [],
    "input_shape_enum_values": {},
    "input_shape_defaults": {},
    "optional_input_shape_enum_values": {"language": [
        {"name": "Automatic", "value": "auto"}, {"name": "Deutsch", "value": "de"},
        {"name": "Polski", "value": "pl"}, {"name": "English", "value": "en"}]},
    "optional_input_shape_defaults": {"language": "auto"},
    "output_shape_enum_values": {},
    "optional_output_shape_enum_values": {},
}


def register(on):
    """Registers the provider (on) or removes it."""
    if on:
        ocs("POST", "/ocs/v1.php/apps/app_api/api/v1/ai_provider/task_processing", {"provider": PROVIDER})
    else:
        try:
            ocs("DELETE", "/ocs/v1.php/apps/app_api/api/v1/ai_provider/task_processing?name=" + urllib.request.quote(PROVIDER_ID))
        except urllib.error.HTTPError as e:
            if e.code != 404:
                raise


def upload(task_id, audio):
    """The audio as the task's file; its id."""
    boundary = uuid.uuid4().hex
    body = (f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"speech.mp3\"\r\n"
            f"Content-Type: audio/mpeg\r\n\r\n").encode() + audio + f"\r\n--{boundary}--\r\n".encode()
    data = ocs("POST", f"/ocs/v2.php/taskprocessing/tasks_provider/{task_id}/file", data=body,
               content_type="multipart/form-data; boundary=" + boundary)
    return int(data["fileId"])


def report(task_id, output=None, error=None):
    ocs("POST", f"/ocs/v2.php/taskprocessing/tasks_provider/{task_id}/result",
        {"taskId": task_id, "output": output, "errorMessage": error})


def handle(task):
    """One task: spoken, uploaded, reported; a failure is reported as the task's error."""
    task_id = int(task["id"])
    started = time.time()
    try:
        text = str(task.get("input", {}).get("input", "")).strip()
        if not text:
            raise ValueError("no text to speak")
        language = str(task.get("input", {}).get("language") or "auto")
        audio = synthesize(text, language)
        report(task_id, {"speech": upload(task_id, audio)})
        print("task %d: %d characters spoken in %.1f s" % (task_id, len(text), time.time() - started), flush=True)
    except Exception as e:  # pylint: disable=broad-except
        print("task %d failed: %s: %s" % (task_id, type(e).__name__, e), flush=True)
        try:
            report(task_id, error="%s: %s" % (type(e).__name__, e))
        except Exception as again:  # pylint: disable=broad-except
            print("task %d: the failure was not reported: %s" % (task_id, again), flush=True)


def worker():
    """Asks for the next task while the app is enabled; waits longer after an error."""
    wait = POLL
    while True:
        enabled.wait()
        try:
            data = ocs("GET", "/ocs/v2.php/taskprocessing/tasks_provider/next", {"providerIds": [PROVIDER_ID], "taskTypeIds": [TASK_TYPE]})
            wait = POLL
            if data and data.get("task"):
                handle(data["task"])
                continue
        except Exception as e:  # pylint: disable=broad-except
            print("next task: %s" % e, flush=True)
            wait = min(60.0, wait * 2)
        time.sleep(wait)


def set_enabled(on):
    try:
        register(on)
    except Exception as e:  # pylint: disable=broad-except
        print("provider %s: %s" % ("registration" if on else "removal", e), flush=True)
        return str(e)
    (enabled.set if on else enabled.clear)()
    return ""


def report_init():
    """Tells AppAPI the ExApp is ready (the voices are in the image), and starts serving."""
    try:
        ocs("PUT", "/ocs/v1.php/apps/app_api/ex-app/status", {"progress": 100}, timeout=30)
    except Exception as e:  # pylint: disable=broad-except
        print("init report failed: %s" % e, flush=True)


class Handler(http.server.BaseHTTPRequestHandler):
    """AppAPI's calls: heartbeat, init, enabled."""
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
        except Exception:  # pylint: disable=broad-except
            return False
        return hmac.compare_digest(secret, os.environ.get("APP_SECRET", "")) and self.headers.get("EX-APP-ID") == os.environ.get("APP_ID")

    def do_GET(self):  # pylint: disable=invalid-name
        if self.path.startswith("/heartbeat"):
            return self.reply(200, {"status": "ok"})
        return self.reply(404, {"error": "not found"})

    def do_PUT(self):  # pylint: disable=invalid-name
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        if self.path.startswith("/enabled"):
            on = re.search(r"[?&]enabled=(1|true)\b", self.path) is not None
            return self.reply(200, {"error": set_enabled(on)})
        return self.reply(404, {"error": "not found"})

    def do_POST(self):  # pylint: disable=invalid-name
        if not self.authorized():
            return self.reply(401, {"error": "unauthorized"})
        length = int(self.headers.get("Content-Length", 0))
        raw = self.rfile.read(min(length, 65536)) if length else b""
        if self.path.startswith("/init"):
            threading.Thread(target=report_init, daemon=True).start()
            return self.reply(200, {})
        if self.path.startswith("/call/"):
            try:
                params = json.loads(raw or b"{}")
            except ValueError:
                params = None
            if not isinstance(params, dict):
                return self.reply(400, {"error": "bad-request"})
            if self.path.startswith("/call/join"):
                return self.reply(200, {"error": calls().join(params) if enabled.is_set() else "disabled"})
            if self.path.startswith("/call/leave"):
                calls().leave(str(params.get("token") or ""))
                return self.reply(200, {"error": ""})
        return self.reply(404, {"error": "not found"})


_calls = None
_calls_lock = threading.Lock()


def calls():
    """The calls the bot is in (call.Calls), made at the first."""
    global _calls  # pylint: disable=global-statement
    import call  # pylint: disable=import-outside-toplevel

    with _calls_lock:
        if _calls is None:
            whisper = call.Whisper()

            def factory(token, params):
                user = str(params.get("user") or "")
                return call.Call(
                    token, params,
                    ask=call.asker(ocs, user, "call-%s-%d" % (token, int(time.time()))),
                    transcribe=whisper,
                    speak=pcm,
                    settings=lambda: call.talk_settings(ocs, user, token),
                )

            _calls = call.Calls(factory)
        return _calls


class UnixHTTPServer(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True

    def get_request(self):
        request, _ = super().get_request()
        return request, ("harp", 0)


def serve():
    """Under HaRP on a unix socket (frpc tunnels it), else on APP_HOST:APP_PORT."""
    threading.Thread(target=worker, daemon=True).start()
    # A restarted container of an enabled app serves again without waiting for AppAPI's call.
    if os.environ.get("APP_SECRET"):
        threading.Thread(target=lambda: set_enabled(True), daemon=True).start()
    if os.environ.get("HP_SHARED_KEY"):
        path = os.environ.get("HP_EXAPP_SOCK", "/tmp/exapp.sock")
        if os.path.exists(path):
            os.unlink(path)
        server = UnixHTTPServer(path, Handler)
        print("listening on %s for AppAPI (HaRP)" % path, flush=True)
    else:
        server = http.server.ThreadingHTTPServer((os.environ.get("APP_HOST", "127.0.0.1"), int(os.environ.get("APP_PORT", "23000"))), Handler)
        print("listening on %s:%s for AppAPI" % (os.environ.get("APP_HOST"), os.environ.get("APP_PORT")), flush=True)
    server.serve_forever()


def main(argv):
    if len(argv) == 3 and argv[0] == "--say":
        sys.stdout.buffer.write(synthesize(argv[2], argv[1]))
        return 0
    serve()
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
