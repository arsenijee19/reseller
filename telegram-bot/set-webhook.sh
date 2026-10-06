#!/bin/sh
set -eu

: "${BOT_TOKEN:?Set BOT_TOKEN from BotFather}"
: "${WEBHOOK_SECRET:?Set WEBHOOK_SECRET}"
: "${PUBLIC_WEBHOOK_URL:?Set PUBLIC_WEBHOOK_URL}"
: "${TLS_CERT:?Set TLS_CERT to the public certificate file}"

curl --fail --silent --show-error \
  "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
  -F "url=${PUBLIC_WEBHOOK_URL}" \
  -F "secret_token=${WEBHOOK_SECRET}" \
  -F "certificate=@${TLS_CERT}" \
  -F "allowed_updates=[\"message\",\"callback_query\"]" \
  -F "drop_pending_updates=false"
printf '\n'
