import importlib.util
import os
import pathlib
import unittest


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


if __name__ == "__main__":
    unittest.main()
