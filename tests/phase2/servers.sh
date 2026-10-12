#!/usr/bin/env bash
# tests/phase2/servers.sh start|stop — the Phase 2 proofs' servers on loopback, from the scratch environment ($ML_DEV_ENV):
#   the application on :9201, a fake kernel on :9202, a fake MaluDB + MaluMail REST on :9203, the mail service's skeleton on :9206 against
#   the fake Mailbox MCP (tests/fixtures/fake_mailbox_mcp.py, a port of its own). With ML_APP=apache the application is a REAL Apache (as
#   your user, mod_php) serving deploy/apache-malumail.conf rendered as the installer renders it (the internal vhost on :9205) — the proofs
#   then exercise the vhost's own rewrites. Ported from Restaurant Inventory's. State: $ML_DEV_STATE (default /tmp/ml-dev2-state).
set -euo pipefail
export P_APP=${P_APP:-9201} P_KERNEL=${P_KERNEL:-9202} P_FAKE=${P_FAKE:-9203} P_MAILER=${P_MAILER:-9206} P_INTERNAL=${P_INTERNAL:-9205}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${ML_DEV_ENV:-/tmp/ml-dev2.env}
STATE=${ML_DEV_STATE:-/tmp/ml-dev2-state}
PY=${ML_PY:-$ROOT/mcp/venv/bin/python}
mkdir -p "$STATE"
stop() {
  for f in "$STATE"/*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  for port in "$P_APP" "$P_KERNEL" "$P_FAKE"; do pkill -f "php -S 127.0.0.1:${port} " 2>/dev/null || true; done   # only this block's ports
  [ -f "$STATE/apache/main.conf" ] && apache2 -f "$STATE/apache/main.conf" -k stop 2>/dev/null || true
  sleep 0.3
}
case "${1:-}" in
  stop) stop ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    export APP_URL="http://127.0.0.1:$P_APP" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
    export FAKE_KERNEL_STATE="$STATE/kernel.json" FAKE_MALUDB_LOG="$STATE/maludb.log" FAKE_MALUMAIL_LOG="$STATE/malumail.log" ML_MAILER_PORT=$P_MAILER
    : > "$FAKE_MALUDB_LOG"; : > "$FAKE_MALUMAIL_LOG"
    php -r '$f=json_decode(file_get_contents($argv[1]),true); file_put_contents($argv[2], json_encode(["feed"=>$f["feed"]]));' "$ROOT/bin/dev_directory.json" "$FAKE_KERNEL_STATE"
    php -S 127.0.0.1:$P_KERNEL "$ROOT/tests/fake_kernel.php" >"$STATE/kernel.log" 2>&1 & echo $! > "$STATE/kernel.pid"
    php -S 127.0.0.1:$P_FAKE "$ROOT/tests/fake_maludb.php" >"$STATE/maludb.log.srv" 2>&1 & echo $! > "$STATE/maludb.pid"
    # the mail service's skeleton, against the fake Mailbox MCP (its customer key from the scratch mailer env)
    MENV="${ENVF%.env}-mailer.env"
    KEY=$(grep -m1 '^MALUMAIL_MAILBOX_API_KEY=' "$MENV" | cut -d= -f2-)
    rm -f "$STATE/mcp.port"
    "$PY" -I "$ROOT/tests/fixtures/fake_mailbox_mcp.py" --key "$KEY" --port-file "$STATE/mcp.port" --log "$STATE/mcp.jsonl" --control "$STATE/mcp" >"$STATE/mcp.out" 2>&1 & echo $! > "$STATE/mcp.pid"
    for i in $(seq 1 50); do [ -s "$STATE/mcp.port" ] && break; sleep 0.1; done
    IMAPENV=()
    if [ "${ML_WITH_IMAP:-0}" = "1" ]; then
      # slice 1 on: the IMAP fixture under a scratch certificate authority; a password the fake Mailbox MCP issues (create, reset) is the one
      # it accepts, as at MaluMail (tests/fixtures/imap_server.py --passwords)
      mkdir -p "$STATE/tls"
      if [ ! -s "$STATE/tls/imap.pem" ]; then
        openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=MaluMail proof CA" -keyout "$STATE/tls/ca.key" -out "$STATE/tls/ca.pem" 2>/dev/null
        openssl req -newkey rsa:2048 -nodes -subj "/CN=127.0.0.1" -keyout "$STATE/tls/imap.key" -out "$STATE/tls/imap.csr" 2>/dev/null
        printf 'subjectAltName=IP:127.0.0.1,DNS:localhost\n' > "$STATE/tls/san.ext"
        openssl x509 -req -in "$STATE/tls/imap.csr" -CA "$STATE/tls/ca.pem" -CAkey "$STATE/tls/ca.key" -CAcreateserial -days 2 -extfile "$STATE/tls/san.ext" -out "$STATE/tls/imap.pem" 2>/dev/null
      fi
      echo '{}' > "$STATE/imap-users.json"; rm -f "$STATE/imap.port"
      "$PY" -I "$ROOT/tests/fixtures/imap_server.py" --users "$STATE/imap-users.json" --cert "$STATE/tls/imap.pem" --key "$STATE/tls/imap.key" \
        --port-file "$STATE/imap.port" --stats "$STATE/imap-stats.json" --passwords "$STATE/mcp/passwords.jsonl" >"$STATE/imap.out" 2>&1 & echo $! > "$STATE/imap.pid"
      for i in $(seq 1 50); do [ -s "$STATE/imap.port" ] && break; sleep 0.1; done
      IMAPENV=(ML_IMAP_HOST=127.0.0.1 "ML_IMAP_PORT=$(cat "$STATE/imap.port")" "ML_TLS_CA_FILE=$STATE/tls/ca.pem")
    fi
    env "${IMAPENV[@]}" ML_MAILER_PORT=$P_MAILER MALUMAIL_MCP_URL="http://127.0.0.1:$(cat "$STATE/mcp.port")/mcp" ML_DEV_ENV="$ENVF" \
      "$PY" -I "$ROOT/services/mailer/server.py" >"$STATE/mailer.log" 2>&1 & echo $! > "$STATE/mailer.pid"
    if [ "${ML_APP:-php}" = "apache" ]; then
      mkdir -p "$STATE/apache"
      python3 - "$ROOT" "$STATE/apache" <<'PY'
import os, sys
root, out = sys.argv[1], sys.argv[2]
v = open(root + '/deploy/apache-malumail.conf').read()
for k, val in {'APP_FQDN': 'webmail.subello.com', 'DOMAIN': 'subello.com', 'APP_KEY': 'malumail', 'APP_DIR': root, 'APP_INTERNAL_PORT': os.environ.get('P_INTERNAL', '9205'),
               'MCP_RECORDS_PORT': '9204', 'MCP_ACTIVITY_PORT': '9204', 'ML_MAILER_PORT': os.environ.get('P_MAILER', '9206'), 'ML_IMAGES_PORT': '9207'}.items():
    v = v.replace('{{%s}}' % k, val)
assert '{{' not in v, 'unfilled placeholder in the vhost template'
v = v.replace('<VirtualHost *:80>', '<VirtualHost *:%s>' % os.environ.get('P_APP', '9201'))
# the scratch environment reaches PHP through Apache's own environment (SetEnv), as config/.env does in production
env = 'SetEnv ML_DEV_ENV %s\nSetEnv APP_ENV dev\nSetEnv APP_URL %s\nSetEnv OS_INTERNAL_URL %s\nSetEnv MALUDB_API_URL %s\nSetEnv MALUMAIL_API_URL %s\nSetEnv ML_MAILER_PORT %s\nSetEnv FAKE_KERNEL_STATE %s\n' % (
    os.environ['ML_DEV_ENV'], os.environ['APP_URL'], os.environ['OS_INTERNAL_URL'], os.environ['MALUDB_API_URL'], os.environ['MALUMAIL_API_URL'], os.environ.get('P_MAILER', '9206'), os.environ['FAKE_KERNEL_STATE'])
open(out + '/site.conf', 'w').write(env + v)
open(out + '/main.conf', 'w').write('ServerRoot "/etc/apache2"\nDefaultRuntimeDir %s\nPidFile %s/apache.pid\nErrorLog %s/apache.err\nServerName localhost\nLogLevel warn\n'
    'Include /etc/apache2/mods-enabled/*.load\nInclude /etc/apache2/mods-enabled/*.conf\nDefine APACHE_LOG_DIR %s\nListen %s\nInclude %s/site.conf\n' % ((out,) * 4 + (os.environ.get('P_APP', '9201'), out)))
PY
      apache2 -f "$STATE/apache/main.conf" -k start
    else
      ( cd "$ROOT" && PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$P_APP -t html tests/dev_router.php >"$STATE/app.log" 2>&1 & echo $! > "$STATE/app.pid" )
    fi
    for i in $(seq 1 50); do curl -s -o /dev/null http://127.0.0.1:$P_APP/api/v1/health && curl -s -o /dev/null http://127.0.0.1:$P_MAILER/health && break; sleep 0.2; done
    echo "servers up (app :$P_APP ${ML_APP:-php}, fake kernel :$P_KERNEL, fake MaluDB :$P_FAKE, mail service :$P_MAILER)" ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
