#!/bin/bash
# Быстрая установка одной командой (для публичного репозитория):
#   curl -fsSL https://raw.githubusercontent.com/pptkaa-del/relay-server/main/bootstrap.sh | sudo bash
#   curl -fsSL .../bootstrap.sh | sudo bash -s -- --admin-pass 'Пароль' --devices "1 2"
# Для приватного репозитория используйте git clone (см. README.md).
set -euo pipefail
REPO_URL=${REPO_URL:-https://github.com/pptkaa-del/relay-server.git}
BRANCH=${BRANCH:-main}
DIR=${DIR:-/opt/relay-server}

[ "$(id -u)" -eq 0 ] || { echo "Запускайте через sudo" >&2; exit 1; }
if ! command -v git >/dev/null; then
    apt-get update -q && DEBIAN_FRONTEND=noninteractive apt-get install -y -q git ca-certificates
fi
if [ -d "$DIR/.git" ]; then
    echo "→ Обновляю $DIR"; git -C "$DIR" pull --ff-only
else
    echo "→ Клонирую $REPO_URL в $DIR"; git clone --depth 1 -b "$BRANCH" "$REPO_URL" "$DIR"
fi
exec bash "$DIR/install.sh" "$@"
