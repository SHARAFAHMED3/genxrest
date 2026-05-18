#!/usr/bin/env bash
# DB pre-check on the VPS. Env: GENX_PHP_BIN. Arg: app root (directory containing .env).
set -euo pipefail

APP_ROOT="${1:-}"
if [ -z "$APP_ROOT" ] || [ ! -f "$APP_ROOT/.env" ]; then
  echo "ERROR: .env not found under: ${APP_ROOT:-<missing app root>}"
  exit 1
fi

PHP_BIN="${GENX_PHP_BIN:-/usr/local/lsws/lsphp83/bin/php}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=== DB pre-check ==="
exec "$PHP_BIN" "$SCRIPT_DIR/db-precheck.php" "$APP_ROOT/.env"
