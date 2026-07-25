#!/usr/bin/env bash
# UAPI one-shot server bootstrap (aaPanel/BT or any Linux box with MySQL + PHP CLI).
#
# Creates the database and DB user, writes .env / config/db.php, then runs the
# idempotent PHP installer (schema migration + default admin seeding).
#
# Usage (from the repo root or anywhere):
#   MYSQL_ROOT_PASS=xxx bash scripts/bt_init.sh
# Optional overrides: DB_HOST DB_NAME DB_USER DB_PASS MYSQL_ROOT_USER MYSQL_PORT
# Re-running on a box that already has a .env requires FORCE=1 (it will rotate
# the DB password and rewrite .env / config/db.php consistently).
set -euo pipefail

cd "$(dirname "$0")/.."

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="${DB_NAME:-uapi_db}"
DB_USER="${DB_USER:-uapi}"
# Random DB password by default (shown at the end of this script).
DB_PASS="${DB_PASS:-$(openssl rand -base64 18)}"
MYSQL_ROOT_USER="${MYSQL_ROOT_USER:-root}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_ROOT_PASS="${MYSQL_ROOT_PASS:-}"

if [ -f ".env" ] && [ "${FORCE:-0}" != "1" ]; then
    echo "[ABORT] .env already exists — this looks like an initialized deployment."
    echo "        Re-run with FORCE=1 to rotate the DB password and rewrite .env/config/db.php,"
    echo "        or just run:  php scripts/install.php"
    exit 1
fi

if [ -n "$MYSQL_ROOT_PASS" ]; then PASS_OPT="-p$MYSQL_ROOT_PASS"; else PASS_OPT=""; fi

echo "[1/4] Creating database and user..."
mysql -h "$DB_HOST" -P "$MYSQL_PORT" -u "$MYSQL_ROOT_USER" $PASS_OPT -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -h "$DB_HOST" -P "$MYSQL_PORT" -u "$MYSQL_ROOT_USER" $PASS_OPT -e "CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';"
mysql -h "$DB_HOST" -P "$MYSQL_PORT" -u "$MYSQL_ROOT_USER" $PASS_OPT -e "ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';"
mysql -h "$DB_HOST" -P "$MYSQL_PORT" -u "$MYSQL_ROOT_USER" $PASS_OPT -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1'; FLUSH PRIVILEGES;"

echo "[2/4] Writing .env ..."
cat > ".env" <<EOF
DB_HOST=$DB_HOST
DB_PORT=$MYSQL_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
EOF

echo "[3/4] Writing config/db.php ..."
# openssl-generated password is base64 (no quotes/backslashes), but escape anyway.
DB_PASS_ESC="${DB_PASS//\\/\\\\}"
DB_PASS_ESC="${DB_PASS_ESC//\'/\\\'}"
cat > "config/db.php" <<PHP
<?php
define('DB_HOST', '$DB_HOST');
define('DB_NAME', '$DB_NAME');
define('DB_USER', '$DB_USER');
define('DB_PASS', '$DB_PASS_ESC');
PHP

echo "[4/4] Running schema migration + seeding (php scripts/install.php)..."
php scripts/install.php

echo ""
echo "=================================================="
echo " Bootstrap finished. SAVE THESE CREDENTIALS NOW:"
echo "=================================================="
echo " MySQL database : $DB_NAME"
echo " MySQL user     : $DB_USER"
echo " MySQL password : $DB_PASS"
echo "   (also stored in .env and config/db.php)"
echo ""
echo " Panel login    : admin@example.com / admin123"
echo "   !! Change this default admin password immediately after first login."
echo "=================================================="
