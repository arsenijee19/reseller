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

    def test_webhook_heartbeat_is_coalesced_for_thirty_seconds(self):
        previous = bot.LAST_WEBHOOK_HEARTBEAT
        bot.LAST_WEBHOOK_HEARTBEAT = 0
        try:
            with mock.patch.object(bot.time, "monotonic", side_effect=[100, 110, 131]), mock.patch.object(bot, "panel") as panel:
                bot.maybe_webhook_heartbeat()
                bot.maybe_webhook_heartbeat()
                bot.maybe_webhook_heartbeat()
            self.assertEqual(panel.call_count, 2)
        finally:
            bot.LAST_WEBHOOK_HEARTBEAT = previous


class MessageHandlingTests(unittest.TestCase):
    def test_start_sends_chat_id_without_panel_api(self):
        with mock.patch.object(bot, "send") as send, mock.patch.object(bot, "panel") as panel:
            bot.handle_message({"text": "/start", "from": {"username": "arsoarso"}, "chat": {"id": 4242}})

        send.assert_called_once()
        self.assertEqual(send.call_args.args[0], 4242)
        self.assertIn("4242", send.call_args.args[1])
        self.assertTrue(send.call_args.args[1].startswith("👋"))
        panel.assert_not_called()

    def test_help_skips_conversation_lookup_and_replies(self):
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

        with mock.patch.object(bot.urllib.request, "urlopen", side_effect=urlopen), mock.patch.object(bot, "send", return_value=None) as send:
            bot.handle_message({"text": "/help", "from": {"username": "arsoarso"}, "chat": {"id": 4242}})

        self.assertEqual(actions, ["authorized"])
        self.assertEqual(send.call_count, 2)
        self.assertTrue(send.call_args_list[0].args[1].startswith("⏳ Obrađujem komandu"))
        response_text = send.call_args_list[-1].args[1]
        self.assertIn("PlayWorld admin bot", response_text)
        self.assertTrue(response_text.startswith("🤖"))


class CommandReplyTests(unittest.TestCase):
    def test_command_result_edits_its_new_progress_message(self):
        calls = []

        def telegram(method, payload):
            calls.append((method, payload))
            if method == "sendMessage":
                return {"ok": True, "result": {"message_id": 91}}
            return {"ok": True}

        with mock.patch.object(bot, "telegram", side_effect=telegram):
            progress_id = bot.send(4242, "⏳ Obrađujem komandu…")
            bot.REPLY_CONTEXT.message_id = progress_id
            bot.send(4242, "📊 Gotovo")

        self.assertEqual([method for method, _ in calls], ["sendMessage", "editMessageText"])
        self.assertEqual(calls[1][1]["message_id"], 91)
        self.assertEqual(calls[1][1]["text"], "📊 Gotovo")
        self.assertFalse(hasattr(bot.REPLY_CONTEXT, "message_id"))

    def test_callback_is_acknowledged_before_panel_work(self):
        events = []

        def panel(action, _chat_id, **_fields):
            events.append(f"panel:{action}")
            return {"ok": True, "authorized": True, "reseller": {
                "id": 7, "email": "user@example.com", "display_name": "User", "balance_rsd": 0,
            }, "two_factor": "isključena"}

        def answer(_callback_id, _message=""):
            events.append("callback:answer")

        with mock.patch.object(bot, "panel", side_effect=panel), mock.patch.object(bot, "answer_callback", side_effect=answer), mock.patch.object(bot, "send"):
            bot.handle_callback({
                "id": "callback-1",
                "from": {"username": "arsoarso"},
                "data": "r:7",
                "message": {"message_id": 12, "chat": {"id": 4242}},
            })

        self.assertEqual(events[0], "callback:answer")
        self.assertEqual(events[1], "panel:authorized")

    def test_reseller_callback_error_replaces_the_selection_message(self):
        with mock.patch.object(bot, "panel", return_value={"ok": False, "error": "Panel API trenutno nije dostupan."}), mock.patch.object(bot, "send") as send:
            bot.show_reseller(4242, 7, edit_id=31)

        send.assert_called_once_with(4242, "Panel API trenutno nije dostupan.", edit=31)


if __name__ == "__main__":
    unittest.main()
