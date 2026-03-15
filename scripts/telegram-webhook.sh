#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

if [[ ! -f .env ]]; then
  echo ".env file not found in ${ROOT_DIR}" >&2
  exit 1
fi

set -a
# shellcheck disable=SC1091
source .env
set +a

BOT_TOKEN="${TELEGRAM_BOT_TOKEN:-}"
WEBHOOK_SECRET="${TELEGRAM_WEBHOOK_SECRET:-}"

if [[ -z "${BOT_TOKEN}" ]]; then
  echo "TELEGRAM_BOT_TOKEN is not set in .env" >&2
  exit 1
fi

API_URL="https://api.telegram.org/bot${BOT_TOKEN}"

usage() {
  cat <<'EOF'
Usage:
  scripts/telegram-webhook.sh set <public_base_url>
  scripts/telegram-webhook.sh info
  scripts/telegram-webhook.sh delete

Examples:
  scripts/telegram-webhook.sh set https://blue-monkeys-jump.loca.lt
  scripts/telegram-webhook.sh info
  scripts/telegram-webhook.sh delete
EOF
}

command="${1:-}"

case "${command}" in
  set)
    public_base_url="${2:-}"

    if [[ -z "${public_base_url}" ]]; then
      echo "Missing public_base_url" >&2
      usage
      exit 1
    fi

    webhook_url="${public_base_url%/}/api/v1/telegram/webhook"

    curl --fail-with-body -sS -X POST "${API_URL}/setWebhook" \
      -d "url=${webhook_url}" \
      -d "secret_token=${WEBHOOK_SECRET}" \
      -d 'allowed_updates=["message","my_chat_member"]'
    echo
    ;;

  info)
    curl --fail-with-body -sS "${API_URL}/getWebhookInfo"
    echo
    ;;

  delete)
    curl --fail-with-body -sS -X POST "${API_URL}/deleteWebhook" \
      -d "drop_pending_updates=true"
    echo
    ;;

  *)
    usage
    exit 1
    ;;
esac
