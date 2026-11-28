# SPDX-License-Identifier: AGPL-3.0-or-later
"""AI in Talk: calls. The bot answers a call in a conversation where it answers every
message (a person alone with it): AI in Talk (lib/Listener/CallListener.php) asks this
External App to join, and it takes part as a voice.

How it is in the call: through Talk's high-performance backend (the signaling server and
Janus) as an internal client (its internal secret, like Talk's recording server and SIP
bridge): no participant of the conversation, so Talk shows no tile for it, but every
client plays its audio. It publishes one audio stream and subscribes to the audio of the
people in the call.

What it does with their audio: the speech is cut into utterances (webrtcvad: voice
activity), each written down by Whisper on the CPU (faster-whisper, the model in the
image), asked as the person through AI in Talk's OCS API (the scenario "call": short
spoken answers; the call is one remembered conversation), and the answer spoken by Piper
(voice.py) into the call. Speaking while it talks stops it (barge-in). Nothing of the call
is kept here; the question and answers go where every question to the bot goes.

It leaves when nobody is in the call any more, when the call ends, when AI in Talk says
so (POST /call/leave) or after CALL_MAX_MINUTES.

The protocol: nextcloud-spreed-signaling's docs/standalone-signaling-api-v1.md; the
message handling follows nextcloud/live_transcription (AGPL-3.0-or-later), which only
listens.
"""

import asyncio
import base64
import collections
import fractions
import hashlib
import hmac
import json
import os
import re
import secrets
import threading
import time
import urllib.parse

# Talk's call flags.
IN_CALL = 1
WITH_AUDIO = 2

SAMPLE_RATE = 48000
FRAME_SAMPLES = 960  # 20 ms at 48 kHz: what WebRTC's Opus sends
VAD_RATE = 16000
VAD_FRAME_MS = 30
VAD_FRAME_BYTES = VAD_RATE * VAD_FRAME_MS // 1000 * 2

MAX_MINUTES = float(os.environ.get("CALL_MAX_MINUTES", "30"))
MAX_ACTIVE = int(os.environ.get("CALL_MAX_ACTIVE", "2"))
# Seconds the bot waits in a call nobody (else) is in, at the start and after the last left.
ALONE_SECONDS = float(os.environ.get("CALL_ALONE_SECONDS", "20"))
# Seconds of an answer the bot waits for before it says it is thinking.
THINKING_SECONDS = float(os.environ.get("CALL_THINKING_SECONDS", "3"))
ANSWER_TIMEOUT = float(os.environ.get("CALL_ANSWER_TIMEOUT", "180"))

LANGUAGES = ("de", "pl", "en")
SAYINGS = {
    "hello": {"de": "Hallo{name}, hier ist {bot}. Was kann ich für dich tun?",
              "pl": "Cześć{name}, tu {bot}. W czym mogę pomóc?",
              "en": "Hello{name}, this is {bot}. What can I do for you?"},
    "thinking": {"de": "Moment.", "pl": "Chwileczkę.", "en": "One moment."},
    "failed": {"de": "Das hat leider nicht geklappt. Versuch es bitte noch einmal.",
               "pl": "Niestety się nie udało. Spróbuj jeszcze raz.",
               "en": "Sorry, that did not work. Please try again."},
    "busy": {"de": "Ich bin gerade zu beschäftigt. Versuch es bitte gleich noch einmal.",
             "pl": "Jestem teraz zajęty. Spróbuj za chwilę.",
             "en": "I am too busy right now. Please try again in a moment."},
    "bye": {"de": "Die Zeit für diesen Anruf ist um. Tschüss!",
            "pl": "Czas na tę rozmowę minął. Do usłyszenia!",
            "en": "The time for this call is up. Goodbye!"},
}
# Whisper's well-known inventions on noise and silence (subtitle credits, a farewell to the
# viewers): never a question.
INVENTIONS = ("untertitel", "subtitles", "napisy", "thank you for watching", "thanks for watching",
              "vielen dank fürs zuschauen", "dziękuję za uwagę", "amara.org", "copyright")


def invented(text):
    """Whether a transcript is nothing, or one of Whisper's inventions."""
    plain = re.sub(r"[^\w\s.]", "", text.lower()).strip()
    return not re.search(r"\w", plain) or (len(plain) < 60 and plain.startswith(INVENTIONS))


def saying(key, language, **values):
    """A fixed sentence in the language (English when it has none)."""
    texts = SAYINGS[key]
    return texts.get(language, texts["en"]).format(**values)


# --- the signaling server's messages ---

def hello(secret, backend):
    """The hello of an internal client: a random and its HMAC with the internal secret."""
    random = secrets.token_hex(32)
    token = hmac.new(secret.encode(), random.encode(), hashlib.sha256).hexdigest()
    return {"type": "hello", "hello": {"version": "2.0", "features": ["internal-incall"],
                                       "auth": {"type": "internal", "params": {"random": random, "token": token, "backend": backend}}}}


def in_call(flags):
    """The internal client's own call flags (before joining: the browsers subscribe to a
    session that is new to them and has audio at that moment)."""
    return {"type": "internal", "internal": {"type": "incall", "incall": {"incall": flags}}}


def join(token, session):
    return {"type": "room", "room": {"roomid": token, "sessionid": session}}


def to_session(session, data):
    return {"type": "message", "message": {"recipient": {"type": "session", "sessionid": session}, "data": data}}


def websocket_url(server):
    """The signaling server's WebSocket address from Talk's setting (https://host/ or wss://host/spreed)."""
    url = server.strip()
    if url.startswith("https://"):
        url = "wss://" + url[8:]
    elif url.startswith("http://"):
        url = "ws://" + url[7:]
    url = url.rstrip("/")
    return url if url.endswith("/spreed") else url + "/spreed"


def ice_servers(settings):
    """STUN and TURN of Talk's signaling settings (the person's, with fresh TURN credentials)."""
    from aiortc import RTCIceServer  # pylint: disable=import-outside-toplevel

    servers = []
    for stun in settings.get("stunservers") or []:
        urls = [u if u.startswith("stun") else "stun:" + u for u in stun.get("urls") or []]
        if urls:
            servers.append(RTCIceServer(urls=urls))
    for turn in settings.get("turnservers") or []:
        urls = [u for u in turn.get("urls") or [] if u.startswith(("turn:", "turns:"))]
        if urls:
            servers.append(RTCIceServer(urls=urls, username=turn.get("username"), credential=turn.get("credential")))
    return servers


def people(users, own, internal=False):
    """The sessions of people in the call, with audio, from a participants update: not the
    internal clients (the bot itself, a recording server; internal: True for a test, whose
    caller is one) nor virtual ones (a phone)."""
    found = {}
    for user in users:
        session = user.get("sessionId") or user.get("sessionid")
        if not session or session == own or (user.get("internal") and not internal) or user.get("virtual"):
            continue
        found[session] = int(user.get("inCall") or 0)
    return found


# --- audio ---

class Segmenter:
    """Cuts 16 kHz mono PCM into utterances by voice activity (is_speech of 30 ms frames):
    one starts after START voiced frames of the last WINDOW, with PREROLL frames before it,
    and ends after END_MS of silence or at MAX_SECONDS; with less than MIN_SECONDS of voice
    it was noise (a cough, a door)."""

    START, WINDOW, PREROLL = 4, 8, 10
    END_MS = 800
    MIN_SECONDS = 0.5
    MAX_SECONDS = 30

    def __init__(self, is_speech):
        self.is_speech = is_speech
        self.buffer = b""
        self.recent = collections.deque(maxlen=self.WINDOW)
        self.before = collections.deque(maxlen=self.PREROLL)
        self.speech = None
        self.voiced = 0
        self.silent = 0

    def feed(self, pcm):
        """Utterances that ended within this audio, and whether speech began (barge-in)."""
        self.buffer += pcm
        ended, began = [], False
        while len(self.buffer) >= VAD_FRAME_BYTES:
            frame, self.buffer = self.buffer[:VAD_FRAME_BYTES], self.buffer[VAD_FRAME_BYTES:]
            voiced = self.is_speech(frame)
            if self.speech is None:
                self.before.append(frame)
                self.recent.append(voiced)
                if sum(self.recent) >= self.START:
                    self.speech = bytearray(b"".join(self.before))
                    self.voiced = sum(self.recent)
                    self.silent = 0
                    began = True
                continue
            self.speech += frame
            self.voiced += voiced
            self.silent = 0 if voiced else self.silent + VAD_FRAME_MS
            if self.silent >= self.END_MS or len(self.speech) >= self.MAX_SECONDS * VAD_RATE * 2:
                if self.voiced * VAD_FRAME_MS >= self.MIN_SECONDS * 1000:
                    ended.append(bytes(self.speech))
                self.speech = None
                self.recent.clear()
                self.before.clear()
        return ended, began


class Speech:
    """What the bot says, as 48 kHz mono 16-bit PCM, handed out in 20 ms frames."""

    def __init__(self):
        self.lock = threading.Lock()
        self.pcm = bytearray()

    def say(self, pcm):
        with self.lock:
            self.pcm += pcm

    def clear(self):
        with self.lock:
            self.pcm.clear()

    def speaking(self):
        with self.lock:
            return len(self.pcm) > 0

    def frame(self):
        """The next 20 ms: speech, else silence."""
        size = FRAME_SAMPLES * 2
        with self.lock:
            chunk = bytes(self.pcm[:size])
            del self.pcm[:size]
        return chunk + b"\x00" * (size - len(chunk))


def speech_track(speech):
    """An aiortc audio track that plays the Speech, in real time."""
    from aiortc import MediaStreamTrack  # pylint: disable=import-outside-toplevel
    from av import AudioFrame  # pylint: disable=import-outside-toplevel

    class Track(MediaStreamTrack):
        kind = "audio"

        def __init__(self):
            super().__init__()
            self.start = None
            self.pts = 0

        async def recv(self):
            if self.start is None:
                self.start = time.monotonic()
            wait = self.start + self.pts / SAMPLE_RATE - time.monotonic()
            if wait > 0:
                await asyncio.sleep(wait)
            frame = AudioFrame(format="s16", layout="mono", samples=FRAME_SAMPLES)
            frame.planes[0].update(speech.frame())
            frame.sample_rate = SAMPLE_RATE
            frame.pts = self.pts
            frame.time_base = fractions.Fraction(1, SAMPLE_RATE)
            self.pts += FRAME_SAMPLES
            return frame

    return Track()


def resampler(rate, layout="mono"):
    """A function from av.AudioFrames to 16-bit PCM bytes at the rate."""
    import av  # pylint: disable=import-outside-toplevel

    convert = av.AudioResampler(format="s16", layout=layout, rate=rate)

    def run(frame):
        return b"".join(bytes(out.planes[0])[: out.samples * 2] for out in convert.resample(frame))

    return run


def to_48k(pcm, rate):
    """Mono 16-bit PCM at a rate (Piper's 22 050 Hz) as 48 kHz."""
    import numpy as np  # pylint: disable=import-outside-toplevel

    if rate == SAMPLE_RATE or not pcm:
        return pcm
    samples = np.frombuffer(pcm, dtype=np.int16).astype(np.float32)
    count = int(len(samples) * SAMPLE_RATE / rate)
    resampled = np.interp(np.linspace(0, len(samples) - 1, count), np.arange(len(samples)), samples)
    return np.clip(resampled, -32768, 32767).astype(np.int16).tobytes()


def spoken(answer):
    """An answer as it is said: no Markdown, code, links or citation marks (the scenario asks
    for none; models write some anyway)."""
    text = re.sub(r"```.*?```", " ", answer, flags=re.S)
    text = re.sub(r"!\[[^\]]*\]\([^)]*\)", " ", text)
    text = re.sub(r"\[([^\]]+)\]\([^)]*\)", r"\1", text)
    text = re.sub(r"(?:https?://|www\.)\S+", " ", text)
    text = re.sub(r"\[\^?\d+(?:\s*[,–-]\s*\d+)*\]", "", text)
    text = re.sub(r"`([^`]*)`", r"\1", text)
    text = re.sub(r"^\s{0,3}(#{1,6}|>+)\s*", "", text, flags=re.M)
    text = re.sub(r"^\s*(?:[-*+•]|\d+[.)])\s+", "", text, flags=re.M)
    text = re.sub(r"(\*\*|__|\*|_|~~)(\S(?:.*?\S)?)\1", r"\2", text)
    text = re.sub(r"\s+", " ", text)
    # What was taken out leaves no space before a mark.
    return re.sub(r"\s+([.,!?;:…])", r"\1", text).strip()


# --- a call ---

class Call:  # pylint: disable=too-many-instance-attributes
    """The bot in one call. ask, transcribe and speak are the work outside the call:

        ask(text) -> answer text (raises AskError with a SAYINGS key)
        transcribe(pcm16k, fallback language) -> (text, language)
        speak(text, language) -> 48 kHz mono PCM
        settings() -> Talk's signaling settings for the person (server, STUN, TURN)
    """

    # A test's caller is an internal client too (tests/voice/call_e2e.py).
    hear_internal = False

    def __init__(self, token, params, ask, transcribe, speak, settings, connect=None):
        self.token = token
        self.secret = params["secret"]
        self.backend = params["backend"]
        self.language = params.get("language") if params.get("language") in LANGUAGES else "en"
        self.caller = params.get("name", "")
        self.bot = params.get("bot") or "AI"
        self.ask, self.transcribe, self.speak, self.settings = ask, transcribe, speak, settings
        self.connect = connect
        self.ws = None
        self.session = None
        self.speech = Speech()
        self.config = None
        self.publisher = None
        self.subscribers = {}
        self.present = {}
        self.utterances = asyncio.Queue()
        self.stopped = asyncio.Event()
        self.seen_anyone = False
        self.alone_since = time.monotonic()
        self.log = lambda message: print("call %s: %s" % (token[:6], message), flush=True)

    # The connection.

    async def send(self, message):
        await self.ws.send(json.dumps(message))

    async def run(self):
        """Joins, takes part until the call is over for it, leaves; never raises."""
        try:
            await self._run()
        except Exception as e:  # pylint: disable=broad-except
            self.log("ended by %s: %s" % (type(e).__name__, e))
        finally:
            await self._close()

    async def _run(self):
        from aiortc import RTCConfiguration  # pylint: disable=import-outside-toplevel

        settings = await asyncio.get_running_loop().run_in_executor(None, self.settings)
        self.config = RTCConfiguration(iceServers=ice_servers(settings))
        connect = self.connect
        if connect is None:
            import websockets  # pylint: disable=import-outside-toplevel

            connect = websockets.connect
        self.ws = await connect(websocket_url(str(settings.get("server") or "")), max_size=2**22)
        await self.send(hello(self.secret, self.backend))
        while self.session is None:
            message = json.loads(await asyncio.wait_for(self.ws.recv(), 15))
            if message.get("type") == "error":
                raise RuntimeError("signaling: %s" % message.get("error"))
            if message.get("type") == "hello":
                self.session = message["hello"]["sessionid"]
        await self.send(in_call(IN_CALL | WITH_AUDIO))
        await self.send(join(self.token, self.session))
        await self.publish()
        self.log("joined")
        tasks = [asyncio.ensure_future(self.receive()), asyncio.ensure_future(self.think()), asyncio.ensure_future(self.watch())]
        name = (" " + self.caller.split()[0]) if self.caller.strip() else ""
        await self.say(saying("hello", self.language, name=name, bot=self.bot), self.language)
        await self.stopped.wait()
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)

    async def _close(self):
        for pc in list(self.subscribers.values()) + ([self.publisher] if self.publisher else []):
            try:
                await pc.close()
            except Exception:  # pylint: disable=broad-except
                pass
        if self.ws is not None:
            try:
                await self.send({"type": "bye", "bye": {}})
                await self.ws.close()
            except Exception:  # pylint: disable=broad-except
                pass
        self.log("left")

    def stop(self, why):
        if not self.stopped.is_set():
            self.log("leaving: " + why)
            self.stopped.set()

    async def watch(self):
        """Leaves after MAX_MINUTES (saying so), or when nobody has been in the call for ALONE_SECONDS."""
        started = time.monotonic()
        while not self.stopped.is_set():
            await asyncio.sleep(1)
            if time.monotonic() - started > MAX_MINUTES * 60:
                self.speech.clear()
                await self.say(saying("bye", self.language), self.language)
                while self.speech.speaking():
                    await asyncio.sleep(0.2)
                self.stop("time is up")
            elif not self.present and time.monotonic() - self.alone_since > ALONE_SECONDS:
                self.stop("nobody in the call")

    # Media.

    async def publish(self):
        """The bot's audio stream: an offer to its own session, which Janus answers."""
        from aiortc import RTCPeerConnection  # pylint: disable=import-outside-toplevel

        self.publisher = RTCPeerConnection(self.config)
        self.publisher.addTransceiver(speech_track(self.speech), direction="sendonly")
        await self.publisher.setLocalDescription(await self.publisher.createOffer())
        await self.send(to_session(self.session, {"to": self.session, "type": "offer", "sid": secrets.token_hex(8), "roomType": "video",
                                                  "payload": {"type": "offer", "sdp": self.publisher.localDescription.sdp, "nick": self.bot}}))

    async def subscribe(self, session, sdp, sid):
        """A person's stream offered by Janus: answered for audio alone; its audio heard."""
        from aiortc import RTCPeerConnection, RTCSessionDescription  # pylint: disable=import-outside-toplevel

        old = self.subscribers.pop(session, None)
        if old is not None:
            await old.close()
        pc = RTCPeerConnection(self.config)
        self.subscribers[session] = pc

        @pc.on("track")
        def on_track(track):
            if track.kind == "audio":
                asyncio.ensure_future(self.listen(session, track))

        await pc.setRemoteDescription(RTCSessionDescription(sdp, "offer"))
        for transceiver in pc.getTransceivers():
            transceiver.direction = "recvonly" if transceiver.kind == "audio" else "inactive"
        await pc.setLocalDescription(await pc.createAnswer())
        await self.send(to_session(session, {"to": session, "type": "answer", "sid": sid, "roomType": "video",
                                             "payload": {"type": "answer", "sdp": pc.localDescription.sdp}}))

    async def candidate(self, session, payload):
        from aiortc.sdp import candidate_from_sdp  # pylint: disable=import-outside-toplevel

        pc = self.publisher if session == self.session else self.subscribers.get(session)
        line = (payload.get("candidate") or {}).get("candidate") or ""
        if pc is None or not line:
            return
        candidate = candidate_from_sdp(line.split(":", 1)[1] if line.startswith("candidate:") else line)
        candidate.sdpMid = payload["candidate"].get("sdpMid")
        candidate.sdpMLineIndex = payload["candidate"].get("sdpMLineIndex")
        await pc.addIceCandidate(candidate)

    async def listen(self, session, track):
        """A person's audio, cut into utterances; speaking stops the bot's speech."""
        import webrtcvad  # pylint: disable=import-outside-toplevel

        vad = webrtcvad.Vad(2)
        segmenter = Segmenter(lambda frame: vad.is_speech(frame, VAD_RATE))
        convert = resampler(VAD_RATE)
        while not self.stopped.is_set():
            try:
                frame = await track.recv()
            except Exception:  # pylint: disable=broad-except  # the track ended
                return
            ended, began = segmenter.feed(convert(frame))
            if began and self.speech.speaking():
                self.speech.clear()
            for utterance in ended:
                await self.utterances.put(utterance)

    # Talking.

    async def say(self, text, language):
        loop = asyncio.get_running_loop()
        pcm = await loop.run_in_executor(None, self.speak, text, language)
        self.speech.say(pcm)

    async def think(self):
        """Each utterance written down, asked and answered, one after the other; what was said
        meanwhile goes with the next question."""
        loop = asyncio.get_running_loop()
        while True:
            utterance = await self.utterances.get()
            text, language = await loop.run_in_executor(None, self.transcribe, utterance, self.language)
            while not self.utterances.empty():
                more, _ = await loop.run_in_executor(None, self.transcribe, self.utterances.get_nowait(), self.language)
                text = (text + " " + more).strip()
            if invented(text):
                continue
            language = language if language in LANGUAGES else self.language
            self.language = language
            self.log("heard %d characters (%s)" % (len(text), language))
            asked = asyncio.ensure_future(loop.run_in_executor(None, self.ask, text))
            done, _ = await asyncio.wait({asked}, timeout=THINKING_SECONDS)
            if not done:
                await self.say(saying("thinking", language), language)
            try:
                answer = spoken(await asked)
            except AskError as e:
                answer = saying(e.key, language)
            except Exception as e:  # pylint: disable=broad-except
                self.log("no answer: %s: %s" % (type(e).__name__, e))
                answer = saying("failed", language)
            if answer:
                await self.say(answer, language)

    # The signaling server's messages.

    async def receive(self):
        while True:
            message = json.loads(await self.ws.recv())
            kind = message.get("type")
            if kind == "message":
                await self.message(message["message"])
            elif kind == "event":
                await self.event(message["event"])
            elif kind == "bye":
                self.stop("the signaling server said bye")
            elif kind == "error":
                self.log("signaling error: %s" % message.get("error"))

    async def message(self, message):
        data = message.get("data") or {}
        sender = (message.get("sender") or {}).get("sessionid") or data.get("from")
        kind = data.get("type")
        if kind == "answer" and sender == self.session and self.publisher is not None:
            from aiortc import RTCSessionDescription  # pylint: disable=import-outside-toplevel

            await self.publisher.setRemoteDescription(RTCSessionDescription(data["payload"]["sdp"], "answer"))
        elif kind == "offer" and sender in self.present:
            await self.subscribe(sender, data["payload"]["sdp"], data.get("sid"))
        elif kind == "candidate":
            await self.candidate(sender, data.get("payload") or {})

    async def event(self, event):
        if event.get("target") == "participants" and event.get("type") == "update":
            update = event.get("update") or {}
            if update.get("all") and int(update.get("incall") or 0) == 0:
                self.stop("the call ended")
                return
            await self.participants(people(update.get("users") or [], self.session, self.hear_internal))
        elif event.get("target") == "room" and event.get("type") == "leave":
            for session in event.get("leave") or []:
                await self.gone(session)

    async def participants(self, changed):
        for session, flags in changed.items():
            if flags & IN_CALL and flags & WITH_AUDIO:
                if session not in self.present:
                    self.present[session] = flags
                    self.seen_anyone = True
                    await self.send(to_session(session, {"type": "requestoffer", "roomType": "video"}))
            else:
                await self.gone(session)

    async def gone(self, session):
        self.present.pop(session, None)
        pc = self.subscribers.pop(session, None)
        if pc is not None:
            await pc.close()
        if not self.present:
            self.alone_since = time.monotonic()


class AskError(Exception):
    """An answer that did not come, with the SAYINGS key the bot says instead."""

    def __init__(self, key, detail=""):
        super().__init__(detail or key)
        self.key = key


class Calls:
    """The calls the bot is in: an asyncio loop in a thread of its own; join and leave come
    from the HTTP server's threads."""

    def __init__(self, factory):
        self.factory = factory
        self.loop = asyncio.new_event_loop()
        self.calls = {}
        threading.Thread(target=self.loop.run_forever, daemon=True, name="calls").start()

    def join(self, params):
        """'' when the bot joins (or is there already); else why not."""
        token = str(params.get("token") or "")
        if not re.fullmatch(r"[a-z0-9]{4,32}", token) or not params.get("secret") or not params.get("backend"):
            return "bad-request"
        if token in self.calls:
            return ""
        if len(self.calls) >= MAX_ACTIVE:
            return "busy"
        call = self.factory(token, params)
        self.calls[token] = call

        async def run():
            try:
                await call.run()
            finally:
                self.calls.pop(token, None)

        asyncio.run_coroutine_threadsafe(run(), self.loop)
        return ""

    def leave(self, token):
        call = self.calls.get(token)
        if call is not None:
            self.loop.call_soon_threadsafe(call.stop, "asked to leave")


class Whisper:
    """Speech to text on the CPU: faster-whisper with the model in the image (WHISPER_DIR),
    loaded at the first call and kept. The language is Whisper's guess when it is one of
    LANGUAGES, else the call's."""

    def __init__(self, directory=None, threads=None):
        self.directory = directory or os.environ.get("WHISPER_DIR", "/models/whisper-small")
        self.threads = threads or int(os.environ.get("WHISPER_THREADS", "2"))
        self.model = None
        self.lock = threading.Lock()

    def load(self):
        from faster_whisper import WhisperModel  # pylint: disable=import-outside-toplevel

        with self.lock:
            if self.model is None:
                self.model = WhisperModel(self.directory, device="cpu", compute_type="int8", cpu_threads=self.threads)
            return self.model

    def __call__(self, pcm, fallback="en"):
        """(text, language) of 16 kHz mono 16-bit PCM."""
        import numpy as np  # pylint: disable=import-outside-toplevel

        audio = np.frombuffer(pcm, dtype=np.int16).astype(np.float32) / 32768
        model = self.load()
        with self.lock:  # one at a time: the CPU is the limit anyway
            segments, info = model.transcribe(audio, beam_size=1, condition_on_previous_text=False)
            language = info.language if info.language in LANGUAGES and info.language_probability >= 0.5 else fallback
            if language != info.language:
                segments, info = model.transcribe(audio, beam_size=1, language=language, condition_on_previous_text=False)
            text = " ".join(s.text.strip() for s in segments if s.no_speech_prob < 0.6 or s.avg_logprob > -1.0)
        return text.strip(), language


def talk_settings(ocs, user, token):
    """Talk's signaling settings for the person in the conversation (HPB address, STUN, TURN)."""
    return ocs("GET", "/ocs/v2.php/apps/spreed/api/v3/signaling/settings?token=" + urllib.parse.quote(token), user=user)


def asker(ocs, user, conversation, poll=0.5, timeout=ANSWER_TIMEOUT):
    """ask(text): the question through AI in Talk's OCS API as the person (scenario "call"),
    the answer when its ticket is done."""
    import urllib.error  # pylint: disable=import-outside-toplevel

    def ask(text):
        try:
            ticket = ocs("POST", "/ocs/v2.php/apps/talk_ai/api/v1/ask", {"scenario": "call", "conversation": conversation,
                                                                         "messages": [{"role": "user", "text": text}]}, user=user)
        except urllib.error.HTTPError as e:
            raise AskError("busy" if e.code == 429 else "failed", "ask: HTTP %d" % e.code) from e
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            time.sleep(poll)
            result = ocs("GET", "/ocs/v2.php/apps/talk_ai/api/v1/result/" + urllib.parse.quote(ticket["id"]), user=user)
            if result.get("state") == "done":
                return str(result.get("text") or "")
            if result.get("state") == "error":
                raise AskError("busy" if result.get("error") in ("busy", "rate-limited") else "failed", str(result.get("error")))
        raise AskError("failed", "no answer in time")

    return ask


def basic_auth(user, secret):
    """AppAPI's AUTHORIZATION-APP-API value: the person it acts as, and the ExApp's secret."""
    return base64.b64encode(("%s:%s" % (user, secret)).encode()).decode()
