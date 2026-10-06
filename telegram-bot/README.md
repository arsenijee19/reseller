# Reseller Telegram admin bot

The bot is an isolated Python standard-library webhook service. It has no database credentials and talks only to `api/telegram_gateway.php` over HTTPS. The panel remains the source of truth for authorization, financial writes and audit history.

See [`../TELEGRAM_BOT_RUNBOOK.md`](../TELEGRAM_BOT_RUNBOOK.md) for migration, cPanel and VPS setup. Do not put a real Telegram token, panel API token, webhook secret or TLS private key in this directory or Git.
