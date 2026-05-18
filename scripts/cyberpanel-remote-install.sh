#!/usr/bin/env bash
# Run on the VPS after rsync (GitHub Actions appleboy/ssh-action).
# Env: REMOTE_DOMAIN, REMOTE_APP_DIR, RUN_MIGRATIONS, RUN_SEEDERS, GENX_DOTENV_B64, GENX_PHP_BIN
set -euo pipefail

echo "=== cyberpanel-remote-install.sh ==="

APP_ROOT="/home/${REMOTE_DOMAIN}/public_html/${REMOTE_APP_DIR}"
PUBLIC_ROOT="/home/${REMOTE_DOMAIN}/public_html"

CHECK_PHP="$(mktemp)"
trap 'rm -f "$CHECK_PHP"' EXIT
cat >"$CHECK_PHP" <<'PHPCHK'
<?php
$need = ['mbstring','intl','bcmath','curl','dom','fileinfo','gd','json','openssl','pdo','pdo_mysql','tokenizer','xml','sodium'];
foreach ($need as $e) {
    if (!extension_loaded($e)) {
        fwrite(STDERR, "missing:$e\n");
        exit(1);
    }
}
exit(0);
PHPCHK

PHP_BIN=""
PATH_PHP="$(command -v php 2>/dev/null || true)"
for candidate in \
  "${GENX_PHP_BIN:-}" \
  /usr/local/lsws/lsphp83/bin/php \
  /usr/local/lsws/lsphp82/bin/php \
  /usr/local/lsws/lsphp84/bin/php \
  /opt/alt/php83/usr/bin/php \
  /opt/alt/php82/usr/bin/php \
  "${PATH_PHP}" \
  php
do
  [ -z "$candidate" ] && continue
  [ ! -x "$candidate" ] && continue
  if "$candidate" "$CHECK_PHP" 2>/dev/null; then
    PHP_BIN="$candidate"
    break
  fi
  echo "Skip PHP: $candidate (missing Laravel extensions)"
done

if [ -z "$PHP_BIN" ]; then
  echo "ERROR: No PHP with required extensions. Set GENX_PHP_BIN (e.g. /usr/local/lsws/lsphp83/bin/php)."
  ls -la /usr/local/lsws/lsphp*/bin/php 2>/dev/null || true
  exit 1
fi

echo "Using PHP: $PHP_BIN"
"$PHP_BIN" -v | head -1

cd "$APP_ROOT"

if [ -n "${GENX_DOTENV_B64:-}" ]; then
  echo "$GENX_DOTENV_B64" | base64 -d > .env
  chmod 600 .env
fi
if [ ! -f .env ]; then
  echo "ERROR: $APP_ROOT/.env is missing."
  exit 1
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views
mkdir -p storage/app/public
mkdir -p bootstrap/cache
mkdir -p public/user-uploads

WEB_GROUP="$(id -gn)"
if getent group www-data >/dev/null 2>&1; then WEB_GROUP="www-data"; fi
if getent group nobody >/dev/null 2>&1; then WEB_GROUP="nobody"; fi
if getent group nogroup >/dev/null 2>&1; then WEB_GROUP="nogroup"; fi
chown -R "$(whoami):$WEB_GROUP" storage bootstrap/cache public/user-uploads 2>/dev/null || true
find storage bootstrap/cache -type d -exec chmod 775 {} \; 2>/dev/null || true
find storage bootstrap/cache -type f -exec chmod 664 {} \; 2>/dev/null || true

if ! command -v composer >/dev/null 2>&1; then
  mkdir -p "$HOME/bin"
  "$PHP_BIN" -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  "$PHP_BIN" composer-setup.php --install-dir="$HOME/bin" --filename=composer
  rm -f composer-setup.php
  export PATH="$HOME/bin:$PATH"
fi

COMPOSER_BIN="$(command -v composer)"
export COMPOSER_ALLOW_SUPERUSER=1
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

"$PHP_BIN" artisan optimize:clear || true
"$PHP_BIN" artisan config:clear --no-interaction || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  # Strip CRLF and surrounding whitespace (Windows-saved .env often leaves \r on the value).
  DB_CONN="$(grep -E '^[[:space:]]*DB_CONNECTION=' .env | tail -1 | cut -d= -f2- | tr -d '\r' | xargs)"
  if [ "$DB_CONN" != "mysql" ]; then
    echo "ERROR: DB_CONNECTION must be mysql in .env (found: [${DB_CONN:-unset}])."
    exit 1
  fi
  if [ "$DB_CONN" = "mysql" ] && [ -f database/database.sqlite ]; then
    rm -f database/database.sqlite
  fi
  "$PHP_BIN" artisan migrate --force --no-interaction
  "$PHP_BIN" artisan module:migrate --force --no-interaction
  "$PHP_BIN" artisan tinker --execute="foreach (['global_settings','pusher_settings','migrations','inventory_item_categories','purchase_locations'] as \$t) { if (!Schema::hasTable(\$t)) { echo 'Missing table: '.\$t.PHP_EOL; exit(1); } } echo 'Database schema OK'.PHP_EOL;"
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
  "$PHP_BIN" artisan module:migrate --force --no-interaction
  "$PHP_BIN" artisan db:seed --force --no-interaction
fi

"$PHP_BIN" artisan storage:link --force --no-interaction || true
"$PHP_BIN" artisan optimize --no-interaction || true
"$PHP_BIN" artisan queue:restart --no-interaction || true

cd "$PUBLIC_ROOT"
rm -rf user-uploads 2>/dev/null || true
ln -sfn "./${REMOTE_APP_DIR}/public/user-uploads" user-uploads
ls -la user-uploads

echo "Deploy finished for https://${REMOTE_DOMAIN}/"
