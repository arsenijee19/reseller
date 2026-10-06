import importlib.util
import json
import os
import pathlib
import unittest
from unittest import mock


BOT_PATH = pathlib.Path(__file__).resolve().parents[1] / "telegram-bot" / "bot.py"
os.environ.setdefault("BOT_TOKEN", "test-token")
os.environ.setdefault("WEBHOOK_SECRET", "test-secret")
os.environ.setdefault("PANEL_API_URL", "https://example.invalid/api/telegram_gateway.php")
os.environ.setdefault("PANEL_API_TOKEN", "test-api-token")
SPEC = importlib.util.spec_from_file_location("reseller_telegram_bot", BOT_PATH)
bot = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(bot)


class AmountParserTests(unittest.TestCase):
    def test_supported_amount_formats(self):
        self.assertEqual(bot.parse_amount("5000"), 5000)
        self.assertEqual(bot.parse_amount("5.000"), 5000)
        self.assertEqual(bot.parse_amount("5,000"), 5000)
        self.assertEqual(bot.parse_amount("5k"), 5000)
        self.assertEqual(bot.parse_amount("1,5k"), 1500)

    def test_invalid_or_negative_amounts_are_rejected(self):
        for value in ("-500", "0", "abc", "500 RSD xyz", "100000001"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                bot.parse_amount(value)


class CommandParserTests(unittest.TestCase):
    def test_command_and_arguments(self):
        self.assertEqual(bot.parse_command("/dopuna@PlayWorldBot User1 5k uplata"), ("dopuna", ["User1", "5k", "uplata"]))
        self.assertEqual(bot.parse_command("obična poruka"), ("", []))
        self.assertEqual(bot.normalize("Kreš 26"), "kres26")
        self.assertEqual(bot.format_date("2026-10-06 14:22:00"), "6. 10. 2026. 14:22")

    def test_only_configured_telegram_username_is_allowed(self):
        self.assertTrue(bot.is_allowed_username("arsoarso"))
        self.assertTrue(bot.is_allowed_username("@ArsoArso"))
        self.assertFalse(bot.is_allowed_username("someone_else"))
        self.assertFalse(bot.is_allowed_username(None))

    def test_start_is_handled_without_panel_access_only_for_allowed_username(self):
        allowed = {"text": "/start", "from": {"username": "arsoarso"}}
        blocked = {"text": "/start", "from": {"username": "someone_else"}}
        ordinary = {"text": "/help", "from": {"username": "arsoarso"}}
        self.assertTrue(bot.is_allowed_start_message(allowed))
        self.assertFalse(bot.is_allowed_start_message(blocked))
        self.assertFalse(bot.is_allowed_start_message(ordinary))


class PanelRequestTests(unittest.TestCase):
    def test_panel_request_uses_cpanel_compatible_token_header(self):
        class Response:
            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def read(self):
                return b'{"ok":true,"authorized":false}'

        with mock.patch.object(bot.urllib.request, "urlopen", return_value=Response()) as urlopen:
            bot.panel("authorized", 123)

        request = urlopen.call_args.args[0]
        self.assertEqual(request.get_header("X-panel-token"), "test-api-token")
        self.assertIsNone(request.get_header("Authorization"))


class MessageHandlingTests(unittest.TestCase):
    def test_start_sends_chat_id_without_panel_api(self):
        with mock.patch.object(bot, "send") as send, mock.patch.object(bot, "panel") as panel:
            bot.handle_message({"text": "/start", "from": {"username": "arsoarso"}, "chat": {"id": 4242}})

        send.assert_called_once()
        self.assertEqual(send.call_args.args[0], 4242)
        self.assertIn("4242", send.call_args.args[1])
        panel.assert_not_called()

    def test_help_runs_through_panel_api_and_replies(self):
        class Response:
            def __init__(self, payload):
                self.payload = payload

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def read(self):
                return self.payload

        actions = []

        def urlopen(request, timeout):
            payload = json.loads(request.data)
            actions.append(payload["action"])
            response = {"ok": True}
            if payload["action"] == "authorized":
                response["authorized"] = True
            elif payload["action"] == "get_conversation":
                response["conversation"] = None
            return Response(json.dumps(response).encode())

        with mock.patch.object(bot.urllib.request, "urlopen", side_effect=urlopen), mock.patch.object(bot, "send") as send:
            bot.handle_message({"text": "/help", "from": {"username": "arsoarso"}, "chat": {"id": 4242}})

        self.assertEqual(actions, ["authorized", "heartbeat", "get_conversation"])
        send.assert_called_once()
        self.assertIn("PlayWorld admin bot", send.call_args.args[1])


if __name__ == "__main__":
    unittest.main()
