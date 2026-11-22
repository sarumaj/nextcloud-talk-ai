# SPDX-License-Identifier: AGPL-3.0-or-later
"""Tests of voice/exapp/voice.py, run inside its image (Piper and the voices there):
    docker run --rm -v "$PWD/tests/voice:/tests:ro" --entrypoint python3 talk-ai-voice-exapp:ci \
        -m unittest discover -s /tests -v
A fake Nextcloud answers the provider's OCS calls."""

import base64
import http.server
import json
import os
import sys
import threading
import unittest

sys.path.insert(0, os.environ.get("VOICE_APP", "/opt/exapp"))
import voice  # noqa: E402  pylint: disable=wrong-import-position


class FakeNextcloud(http.server.BaseHTTPRequestHandler):
    """Records the requests; answers the task file upload with an id."""
    calls = []

    def log_message(self, fmt, *args):
        pass

    def _answer(self, data, code=200):
        body = json.dumps({"ocs": {"meta": {"status": "ok"}, "data": data}}).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):  # pylint: disable=invalid-name
        body = self.rfile.read(int(self.headers.get("Content-Length", 0)))
        FakeNextcloud.calls.append((self.path, dict(self.headers), body))
        self._answer({"fileId": 42} if "/file" in self.path else {})


class VoiceTest(unittest.TestCase):
    def test_the_language_is_read_from_the_text(self):
        self.assertEqual(voice.language_of("Ich weiß nicht, ob das für dich und deine Schule passt, aber es ist so."), "de")
        self.assertEqual(voice.language_of("Nie wiem, czy to jest dobre dla ciebie, ale możesz spróbować."), "pl")
        self.assertEqual(voice.language_of("I do not know if this is right for you, but it is what she said."), "en")
        self.assertEqual(voice.language_of("OK 42.", "pl"), "pl")

    def test_each_language_is_spoken_as_mp3(self):
        for language, text in (("de", "Guten Morgen."), ("pl", "Dzień dobry."), ("en", "Good morning."), ("auto", "Das ist ein ganz kurzer Test für die Sprache.")):
            audio = voice.synthesize(text, language)
            self.assertTrue(audio[:3] == b"ID3" or (audio[0] == 0xFF and audio[1] & 0xE0 == 0xE0), language)
            self.assertGreater(len(audio), 4000, language)

    def test_a_task_is_spoken_uploaded_and_reported(self):
        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), FakeNextcloud)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        os.environ.update(NEXTCLOUD_URL="http://127.0.0.1:%d" % server.server_port, APP_ID="talk_ai_voice", APP_SECRET="s3cret", APP_VERSION="0.1.0")
        FakeNextcloud.calls = []
        try:
            voice.handle({"id": 7, "input": {"input": "Hallo, das ist eine Antwort.", "language": "de"}})
        finally:
            server.shutdown()
            server.server_close()
        paths = [c[0] for c in FakeNextcloud.calls]
        self.assertEqual(paths, ["/ocs/v2.php/taskprocessing/tasks_provider/7/file?format=json",
                                 "/ocs/v2.php/taskprocessing/tasks_provider/7/result?format=json"])
        upload, result = FakeNextcloud.calls
        self.assertIn("multipart/form-data", upload[1]["Content-Type"])
        self.assertEqual(upload[1]["Ex-App-Id"], "talk_ai_voice")
        self.assertEqual(base64.b64decode(upload[1]["Authorization-App-Api"]).decode(), ":s3cret")
        self.assertEqual(json.loads(result[2]), {"taskId": 7, "output": {"speech": 42}, "errorMessage": None})

    def test_an_empty_task_is_reported_as_failed(self):
        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), FakeNextcloud)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        os.environ["NEXTCLOUD_URL"] = "http://127.0.0.1:%d" % server.server_port
        FakeNextcloud.calls = []
        try:
            voice.handle({"id": 8, "input": {"input": "  "}})
        finally:
            server.shutdown()
            server.server_close()
        self.assertEqual(len(FakeNextcloud.calls), 1)
        reported = json.loads(FakeNextcloud.calls[0][2])
        self.assertIsNone(reported["output"])
        self.assertIn("no text", reported["errorMessage"])

    def test_the_provider_takes_a_language(self):
        self.assertEqual(voice.PROVIDER["task_type"], "core:text2speech")
        values = [v["value"] for v in voice.PROVIDER["optional_input_shape_enum_values"]["language"]]
        self.assertEqual(values, ["auto", "de", "pl", "en"])


if __name__ == "__main__":
    unittest.main()
