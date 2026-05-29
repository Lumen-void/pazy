#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

php "$ROOT_DIR/bin/console.php" qa:smoke --company="${1:-1}"
