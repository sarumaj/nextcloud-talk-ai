# SPDX-License-Identifier: AGPL-3.0-or-later
"""The bot in a call, end to end, against Talk's high-performance backend (the signaling
server and Janus, Nextcloud's aio-talk image) without a Nextcloud: a caller (an internal
client, as the bot) joins a room and asks a question aloud, spoken by Piper; the bot
(call.Call with Whisper and Piper, and an ask() that answers a fixed sentence) joins,
greets, hears the question, answers; the caller records what it hears, and Whisper writes
it down. It passes when the bot heard the question and the caller heard the greeting and
the answer. How to run it: docs/developer.md, "Calls".

Settings from the environment: HPB (ws://talk:8081), INTERNAL_SECRET, TURN_SECRET,
TURN (talk:3478), BACKEND (https://nc.test), WHISPER_DIR, VOICES_DIR."""

import asyncio
import base64
import hashlib
import hmac
import json
import os
import sys
import time

sys.path.insert(0, "/opt/exapp")

import call  # noqa: E402  pylint: disable=wrong-import-position
import voice  # noqa: E402  pylint: disable=wrong-import-position

HPB = os.environ.get("HPB", "ws://talk:8081")
SECRET = os.environ.get("INTERNAL_SECRET", "internalsecret")
BACKEND = os.environ.get("BACKEND", "https://nc.test")
ROOM = "e2etest1"
QUESTION = "Was ist die Hauptstadt von Deutschland?"
ANSWER = "Die Hauptstadt von Deutschland ist Berlin."


def settings():
    """Talk's signaling settings as Talk would give them: the HPB and fresh TURN credentials."""
    user = "%d:e2e" % (int(time.time()) + 3600)
    credential = base64.b64encode(hmac.new(os.environ.get("TURN_SECRET", "turnsecret").encode(), user.encode(), hashlib.sha1).digest()).decode()
    turn = os.environ.get("TURN", "talk:3478")
    return {"server": HPB, "stunservers": [], "turnservers": [
        {"urls": ["turn:%s?transport=udp" % turn, "turn:%s?transport=tcp" % turn], "username": user, "credential": credential}]}


class Caller:
    """A person in the call: joins first, records the bot, asks the question when greeted."""

    def __init__(self, whisper):
        self.whisper = whisper
        self.speech = call.Speech()
        self.heard = bytearray()
        self.ws = None
        self.session = None
        self.pcs = []

    async def send(self, message):
        await self.ws.send(json.dumps(message))

    async def join(self):
        import websockets  # pylint: disable=import-outside-toplevel
        from aiortc import RTCConfiguration, RTCPeerConnection  # pylint: disable=import-outside-toplevel

        self.config = RTCConfiguration(iceServers=call.ice_servers(settings()))
        self.ws = await websockets.connect(call.websocket_url(HPB))
        await self.send(call.hello(SECRET, BACKEND))
        while self.session is None:
            message = json.loads(await self.ws.recv())
            if message.get("type") == "hello":
                self.session = message["hello"]["sessionid"]
        await self.send(call.in_call(3))
        await self.send(call.join(ROOM, self.session))
        pc = RTCPeerConnection(self.config)
        pc.addTransceiver(call.speech_track(self.speech), direction="sendonly")
        await pc.setLocalDescription(await pc.createOffer())
        self.pcs.append(pc)
        self.publisher = pc
        await self.send(call.to_session(self.session, {"to": self.session, "type": "offer", "sid": "c1", "roomType": "video",
                                                       "payload": {"type": "offer", "sdp": pc.localDescription.sdp, "nick": "Anna"}}))

    async def run(self, until):
        from aiortc import RTCPeerConnection, RTCSessionDescription  # pylint: disable=import-outside-toplevel

        bot, offered, requested = None, False, 0.0
        while time.monotonic() < until:
            # As a browser does: again until Janus offers the bot's stream (it publishes after joining).
            if bot is not None and not offered and time.monotonic() - requested > 4:
                requested = time.monotonic()
                await self.send(call.to_session(bot, {"type": "requestoffer", "roomType": "video"}))
            try:
                message = json.loads(await asyncio.wait_for(self.ws.recv(), 1))
            except asyncio.TimeoutError:
                continue
            if message.get("type") == "event" and message["event"].get("target") == "participants":
                for user in message["event"].get("update", {}).get("users", []):
                    if user.get("sessionId") != self.session and bot is None and int(user.get("inCall") or 0) & 2:
                        bot = user["sessionId"]
            elif message.get("type") == "message":
                data = message["message"]["data"]
                sender = message["message"]["sender"]["sessionid"]
                if data.get("type") == "answer" and sender == self.session:
                    await self.publisher.setRemoteDescription(RTCSessionDescription(data["payload"]["sdp"], "answer"))
                elif data.get("type") == "offer" and sender == bot:
                    offered = True
                    pc = RTCPeerConnection(self.config)
                    self.pcs.append(pc)

                    @pc.on("track")
                    def on_track(track):
                        asyncio.ensure_future(self.record(track))

                    await pc.setRemoteDescription(RTCSessionDescription(data["payload"]["sdp"], "offer"))
                    for transceiver in pc.getTransceivers():
                        transceiver.direction = "recvonly" if transceiver.kind == "audio" else "inactive"
                    await pc.setLocalDescription(await pc.createAnswer())
                    await self.send(call.to_session(bot, {"to": bot, "type": "answer", "sid": data.get("sid"), "roomType": "video",
                                                          "payload": {"type": "answer", "sdp": pc.localDescription.sdp}}))

    async def record(self, track):
        """What the bot says, at 16 kHz; the question asked once the greeting is over."""
        convert = call.resampler(call.VAD_RATE)
        asked = False
        quiet = 0
        while True:
            try:
                frame = await track.recv()
            except Exception:  # pylint: disable=broad-except
                return
            pcm = convert(frame)
            self.heard += pcm
            loud = any(abs(int.from_bytes(pcm[i:i + 2], "little", signed=True)) > 500 for i in range(0, len(pcm), 64))
            quiet = 0 if loud else quiet + 1
            if not asked and len(self.heard) > call.VAD_RATE * 2 * 3 and quiet > 50:
                asked = True
                print("caller: asking", flush=True)
                self.speech.say(voice.pcm(QUESTION, "de"))

    async def close(self):
        for pc in self.pcs:
            await pc.close()
        await self.send({"type": "bye", "bye": {}})
        await self.ws.close()


async def main():
    whisper = call.Whisper(threads=4)
    whisper.load()
    asked = []

    def ask(text):
        asked.append(text)
        print("bot asked: %r" % text, flush=True)
        time.sleep(1)
        return ANSWER

    caller = Caller(whisper)
    await caller.join()
    bot = call.Call(ROOM, {"secret": SECRET, "backend": BACKEND, "language": "de", "name": "Anna Example", "bot": "Claude"},
                    ask=ask, transcribe=whisper, speak=voice.pcm, settings=settings)
    bot.hear_internal = True
    until = time.monotonic() + 75
    listening = asyncio.ensure_future(caller.run(until))
    await asyncio.sleep(2)
    botting = asyncio.ensure_future(bot.run())
    await listening
    bot.stop("the test is over")
    await botting
    await caller.close()
    heard, _ = whisper(bytes(caller.heard), "de")
    print("caller heard: %r" % heard, flush=True)
    ok = bool(asked) and "hauptstadt" in asked[0].lower() and "hallo anna" in heard.lower() and "berlin" in heard.lower()
    print("PASSED" if ok else "FAILED", flush=True)
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
