# SPDX-License-Identifier: AGPL-3.0-or-later
"""Tests of voice/exapp/call.py (the bot in a call), run inside its image as test_voice.py.
Without the signaling server: the messages, the cutting of speech into utterances, what is
said and how a question is asked. The call itself, end to end against Talk's
high-performance backend: call_e2e.py (docs/developer.md, "Calls")."""

import asyncio
import hashlib
import hmac
import os
import sys
import unittest
import urllib.error

sys.path.insert(0, os.environ.get("VOICE_APP", "/opt/exapp"))
import call  # noqa: E402  pylint: disable=wrong-import-position


class MessagesTest(unittest.TestCase):
    def test_hello_is_an_internal_client_with_the_hmac_of_the_secret(self):
        message = call.hello("s3cret", "https://cloud.example")
        auth = message["hello"]["auth"]
        self.assertEqual(auth["type"], "internal")
        self.assertEqual(auth["params"]["backend"], "https://cloud.example")
        random = auth["params"]["random"]
        self.assertGreaterEqual(len(random), 32)
        self.assertEqual(auth["params"]["token"], hmac.new(b"s3cret", random.encode(), hashlib.sha256).hexdigest())
        self.assertIn("internal-incall", message["hello"]["features"])

    def test_websocket_address_from_talks_setting(self):
        for server, url in [("https://signal.example/", "wss://signal.example/spreed"),
                            ("https://signal.example/standalone-signaling", "wss://signal.example/standalone-signaling/spreed"),
                            ("wss://signal.example/spreed", "wss://signal.example/spreed"),
                            ("http://talk:8081", "ws://talk:8081/spreed")]:
            self.assertEqual(call.websocket_url(server), url)

    def test_people_are_not_internal_nor_virtual_sessions(self):
        users = [{"sessionId": "me", "inCall": 3, "internal": True},
                 {"sessionId": "anna", "inCall": 7},
                 {"sessionId": "recorder", "inCall": 1, "internal": True},
                 {"sessionId": "phone", "inCall": 3, "virtual": True},
                 {"sessionId": "ben", "inCall": 0}]
        self.assertEqual(call.people(users, "me"), {"anna": 7, "ben": 0})
        self.assertEqual(call.people(users, "me", internal=True), {"anna": 7, "recorder": 1, "ben": 0})

    def test_ice_servers_from_talks_settings(self):
        servers = call.ice_servers({"stunservers": [{"urls": ["signal.example:3478"]}],
                                    "turnservers": [{"urls": ["turn:signal.example:3478?transport=udp", "other:x"],
                                                     "username": "u", "credential": "c"}]})
        self.assertEqual(servers[0].urls, ["stun:signal.example:3478"])
        self.assertEqual((servers[1].urls, servers[1].username, servers[1].credential),
                         (["turn:signal.example:3478?transport=udp"], "u", "c"))


class SegmenterTest(unittest.TestCase):
    FRAME = b"\x00" * call.VAD_FRAME_BYTES

    def feed(self, pattern):
        """Frames by a pattern of 1 (speech) and 0 (silence); the utterances' lengths in frames."""
        voiced = iter(pattern)
        segmenter = call.Segmenter(lambda frame: next(voiced) == "1")
        ended, began = segmenter.feed(self.FRAME * len(pattern))
        return [len(u) // call.VAD_FRAME_BYTES for u in ended], began

    def test_speech_then_silence_is_one_utterance_with_what_came_before(self):
        silence_to_end = -(-call.Segmenter.END_MS // call.VAD_FRAME_MS)
        lengths, began = self.feed("0" * 20 + "1" * 30 + "0" * silence_to_end)
        self.assertTrue(began)
        self.assertEqual(lengths, [call.Segmenter.PREROLL + 30 - call.Segmenter.START + silence_to_end])

    def test_a_click_is_no_utterance(self):
        self.assertEqual(self.feed("0" * 10 + "1" * 3 + "0" * 60), ([], False))

    def test_a_short_sound_is_noise(self):
        lengths, began = self.feed("1" * 4 + "0" * 40)
        self.assertTrue(began)
        self.assertEqual(lengths, [])

    def test_a_long_speech_is_cut(self):
        frames = call.Segmenter.MAX_SECONDS * 1000 // call.VAD_FRAME_MS + 50
        lengths, _ = self.feed("1" * frames)
        self.assertEqual(len(lengths), 1)


class SpeechTest(unittest.TestCase):
    def test_frames_are_speech_then_silence(self):
        speech = call.Speech()
        speech.say(b"\x01\x00" * 1000)
        self.assertTrue(speech.speaking())
        self.assertEqual(speech.frame(), b"\x01\x00" * 960)
        self.assertEqual(speech.frame(), b"\x01\x00" * 40 + b"\x00\x00" * 920)
        self.assertFalse(speech.speaking())
        speech.say(b"\x01\x00" * 10)
        speech.clear()
        self.assertEqual(speech.frame(), b"\x00\x00" * 960)

    def test_resampled_to_48k(self):
        self.assertEqual(len(call.to_48k(b"\x00\x00" * 22050, 22050)), 48000 * 2)
        self.assertEqual(call.to_48k(b"ab", 48000), b"ab")

    def test_spoken_without_markdown_links_and_citations(self):
        self.assertEqual(call.spoken("## Berlin\n\n**Berlin** is the capital [1]. See [the page](https://x.example) or https://y.example.\n"
                                     "- one\n- two\n```\ncode\n```"),
                         "Berlin Berlin is the capital. See the page or one two")

    def test_whispers_inventions_are_no_question(self):
        for text in ["", " . ", "Untertitel im Auftrag des ZDF, 2020", "Thank you for watching!", "Napisy stworzone przez społeczność"]:
            self.assertTrue(call.invented(text), text)
        for text in ["Wie spät ist es?", "Subtitles are on in this film, can you turn them off and tell me how? " * 2]:
            self.assertFalse(call.invented(text), text)

    def test_sayings_in_the_language_or_english(self):
        self.assertEqual(call.saying("hello", "de", name=" Anna", bot="Claude"), "Hallo Anna, hier ist Claude. Was kann ich für dich tun?")
        self.assertEqual(call.saying("thinking", "fr"), "One moment.")


class AskTest(unittest.TestCase):
    def test_the_question_goes_as_the_person_and_the_answer_comes_back(self):
        requests = []
        states = iter([{"state": "running"}, {"state": "done", "text": "Berlin."}])

        def ocs(method, path, body=None, user=""):
            requests.append((method, path, body, user))
            return {"id": "t1"} if method == "POST" else next(states)

        ask = call.asker(ocs, "anna", "call-abc-1", poll=0)
        self.assertEqual(ask("Was ist die Hauptstadt?"), "Berlin.")
        self.assertEqual(requests[0], ("POST", "/ocs/v2.php/apps/talk_ai/api/v1/ask",
                                       {"scenario": "call", "conversation": "call-abc-1",
                                        "messages": [{"role": "user", "text": "Was ist die Hauptstadt?"}]}, "anna"))
        self.assertEqual(requests[1][1], "/ocs/v2.php/apps/talk_ai/api/v1/result/t1")

    def test_busy_and_failures_are_said(self):
        def busy(method, path, body=None, user=""):
            raise urllib.error.HTTPError(path, 429, "Too Many Requests", {}, None)

        with self.assertRaises(call.AskError) as caught:
            call.asker(busy, "anna", "c", poll=0)("x")
        self.assertEqual(caught.exception.key, "busy")

        def failing(method, path, body=None, user=""):
            return {"id": "t"} if method == "POST" else {"state": "error", "error": "no-key"}

        with self.assertRaises(call.AskError) as caught:
            call.asker(failing, "anna", "c", poll=0)("x")
        self.assertEqual(caught.exception.key, "failed")


class CallsTest(unittest.TestCase):
    def test_join_checks_and_one_call_per_conversation(self):
        made = []

        class Idle:
            def __init__(self, token):
                self.token = token
                self.stopped = asyncio.Event()

            async def run(self):
                await asyncio.sleep(3600)

            def stop(self, why):
                pass

        def factory(token, params):
            made.append(token)
            return Idle(token)

        calls = call.Calls(factory)
        params = {"secret": "s", "backend": "https://cloud.example"}
        self.assertEqual(calls.join({"token": "../x"} | params), "bad-request")
        self.assertEqual(calls.join({"token": "abcd1234"}), "bad-request")
        self.assertEqual(calls.join({"token": "abcd1234"} | params), "")
        self.assertEqual(calls.join({"token": "abcd1234"} | params), "")
        self.assertEqual(made, ["abcd1234"])
        for token in ["efgh5678", "ijkl9012"][: call.MAX_ACTIVE - 1]:
            self.assertEqual(calls.join({"token": token} | params), "")
        self.assertEqual(calls.join({"token": "mnop3456"} | params), "busy")


if __name__ == "__main__":
    unittest.main()
