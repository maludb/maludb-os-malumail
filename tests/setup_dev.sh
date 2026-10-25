#!/usr/bin/env bash
# Builds the SCRATCH database the proofs run against — never the installed application's database.
#   tests/setup_dev.sh            (needs `sudo -n -u postgres` — for scratch ml_* databases and the three malumail_* roles only;
#                                  recreates $ML_DEV_DB, default ml_dev0)
# 1. drops and creates the scratch database; 2. applies db/[0-9]*.sql in order as postgres (the same files the installer applies) —
#    ML_MIGRATIONS_UPTO=005 (or ML_DEV_UPTO) stops after the kit's db/001–005 while the schema's later files are being written;
#    3. leaves the cluster roles' passwords ALONE when the app is installed (reads them from config/.env); else gives them a scratch
#    password (the kernel's installer sets fresh ones at `apply`); 4. makes a scratch attachment store ($ML_DEV_STORAGE, default
#    /tmp/<db>-storage: files/ tmp/); 5. writes the proofs' environment to $ML_DEV_ENV (default /tmp/ml-dev0.env, mode 600) and a
#    scratch sealing key pair — the public half in that file, the secret half in its stand-in for /etc/malumail/mailer.env
#    (<env>-mailer.env, mode 600) — app/bootstrap.php reads $ML_DEV_ENV instead of config/.env when it is set (never in production).
#    Nothing here is committed or installed, and nothing here reaches MaluMail: the mail hosts point at the loopback fixtures.
#    Ports 9201 (the app), 9202 (the fake kernel), 9203 (the fake MaluDB + MaluMail REST), 9204 and 9208 (the MCP servers, Phase 4),
#    9206 (the mail service), 9207 (the image door).
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${ML_DEV_DB:-ml_dev0}
case "$DB" in ml_*) ;; *) echo "refused: the scratch database must be named ml_* (got $DB)"; exit 2 ;; esac
ENVF=${ML_DEV_ENV:-/tmp/ml-dev0.env}
PW=$(openssl rand -hex 16)
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
UPTO=${ML_MIGRATIONS_UPTO:-${ML_DEV_UPTO:-999}}
for f in "$ROOT"/db/[0-9]*.sql; do n=$(basename "$f" | cut -c1-3); [ "$((10#$n))" -le "$((10#$UPTO))" ] 2>/dev/null || continue; $PSQL -d "$DB" -f "$f" >/dev/null || { echo "FAILED: $f"; exit 1; }; done
LIVEENV="$ROOT/config/.env"
lv() { grep -m1 "^$1=" "$LIVEENV" | cut -d= -f2-; }
if [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ] && [ -n "$(lv MCP_RECORDS_DB_PASSWORD)" ] && [ -n "$(lv MCP_ACTIVITY_DB_PASSWORD)" ]; then
  PW_RW=$(lv DB_PASSWORD); PW_REC=$(lv MCP_RECORDS_DB_PASSWORD); PW_ACT=$(lv MCP_ACTIVITY_DB_PASSWORD)
else
  PW_RW=$PW; PW_REC=$PW; PW_ACT=$PW
  for r in malumail_rw malumail_records_ro malumail_activity_ro; do $PSQL -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
fi
STORE=${ML_DEV_STORAGE:-/tmp/${DB}-storage}          # the attachment store: a scratch one, emptied
rm -rf "$STORE"; mkdir -p "$STORE"/{files,tmp}
umask 077
# a scratch sealing pair (design §6.2): the public half for PHP, the secret half only in the mail service's stand-in file
SEAL=$(php -r '$k = sodium_crypto_box_keypair(); echo base64_encode(sodium_crypto_box_publickey($k)), " ", base64_encode(sodium_crypto_box_secretkey($k));')
MAILER_KEY=$(openssl rand -hex 32)
cat > "${ENVF%.env}-mailer.env" <<MENV
ML_SEAL_SECRET_KEY=${SEAL#* }
ML_MAILER_KEY=$MAILER_KEY
MALUMAIL_MAILBOX_API_KEY=dev-customer-$(openssl rand -hex 8)
MENV
cat > "$ENVF" <<ENV
APP_ENV=dev
APP_DEBUG=1
APP_NAME="MaluMail"
APP_KEY=malumail
APP_URL=http://127.0.0.1:9201
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=malumail_rw
DB_PASSWORD=$PW_RW
MCP_RECORDS_DB_USER=malumail_records_ro
MCP_RECORDS_DB_PASSWORD=$PW_REC
MCP_ACTIVITY_DB_USER=malumail_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW_ACT
APP_INTERNAL_PORT=9201
MCP_RECORDS_PORT=9204
MCP_ACTIVITY_PORT=9208
ML_MAILER_PORT=9206
ML_IMAGES_PORT=9207
ML_MAILER_KEY=$MAILER_KEY
ML_SEAL_PUBLIC_KEY=${SEAL%% *}
ML_PART_KEY=$(openssl rand -hex 32)
ML_MAILER_MAX_CONNECTIONS=40
MALUMAIL_MCP_URL=http://127.0.0.1:9203/mcp
ML_IMAP_HOST=127.0.0.1
ML_IMAP_PORT=0
ML_SMTP_HOST=127.0.0.1
ML_SMTP_PORT=0
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:9202
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:9203
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
MALUMAIL_API_URL=http://127.0.0.1:9203
MALUMAIL_API_KEY=dev-malumail-$(openssl rand -hex 8)
MAIL_FROM=webmail@example.invalid
MAIL_FROM_NAME="MaluMail"
ML_STORAGE_DIR=$STORE
ENV
echo "scratch database $DB ready; environment in $ENVF"
