# Reseller Telegram admin bot

The bot is an isolated Python standard-library webhook service. It has no database credentials and talks only to `api/telegram_gateway.php` over HTTPS. The panel remains the source of truth for authorization, financial writes and audit history.

Typed slash commands immediately post a temporary reply at the bottom of the chat, then replace that same new reply with the result. This keeps command results easy to find without rewriting older chat messages. Inline-button actions continue updating their own cards so completed confirmations do not leave active buttons behind. Panel requests run concurrently without an artificial inter-request delay, and command handling skips the conversation lookup it does not need.

Reseller selection buttons first replace the selection list with a visible loading state, then replace it with the reseller profile or an inline error. Callback queries are acknowledged before panel lookups, so Telegram clears the button spinner promptly.

See [`../TELEGRAM_BOT_RUNBOOK.md`](../TELEGRAM_BOT_RUNBOOK.md) for migration, cPanel and VPS setup. Do not put a real Telegram token, panel API token, webhook secret or TLS private key in this directory or Git.
