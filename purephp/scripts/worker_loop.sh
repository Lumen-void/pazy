#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

INTERVAL="${1:-15}"
LIMIT="${2:-50}"
COMPANY="${3:-all}"
ACTOR="${4:-1}"

exec php "$ROOT_DIR/bin/console.php" worker:loop \
  --interval="$INTERVAL" \
  --limit="$LIMIT" \
  --company="$COMPANY" \
  --actor="$ACTOR"
