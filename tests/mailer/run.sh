#!/usr/bin/env bash
# The mail service's Phase 0 proof (design §6.1, §10 Phase 0): services/mailer/ — the signed door, the administration plane over a
# FAKE Mailbox MCP (tests/fixtures/fake_mailbox_mcp.py) and the IMAP probe with the circuit breaker over the IMAP FIXTURE
# (tests/fixtures/imap_server.py, TLS with a scratch certificate authority) — on a scratch database (ml_mailer). Nothing reaches
# MaluMail. Prints ok/FAIL per check; exits non-zero on any failure.
# Needs `sudo -n -u postgres` (scratch ml_* databases and the malumail_* roles only), php with sodium, openssl, and the application's
# venv (python3 -m venv mcp/venv && mcp/venv/bin/pip install -r mcp/requirements.txt). Ports: the service on 9206, a second instance
# on 9207 (the image door's scratch port, unused here); the fixtures take ports the system gives them.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PY=${ML_PY:-$ROOT/mcp/venv/bin/python}
"$PY" -c 'import nacl, imapclient, nh3, starlette, uvicorn, asyncpg, httpx' 2>/dev/null || { echo "FAIL $PY lacks mcp/requirements.txt"; exit 1; }
TMP=$(mktemp -d /tmp/ml-mailer.XXXXXX)
PIDS=()
cleanup() { local rc=$?; for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done; wait 2>/dev/null; rm -rf "$TMP"; exit $rc; }
trap cleanup EXIT

export ML_DEV_DB=ml_mailer ML_DEV_ENV=$TMP/ml-mailer.env ML_DEV_STORAGE=$TMP/storage
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "FAIL the scratch database"; exit 1; }
val() { grep -m1 "^$2=" "$1" | cut -d= -f2-; }
MENV=$TMP/ml-mailer-mailer.env

# A scratch certificate authority and the fixture's certificate (127.0.0.1); a second, self-signed certificate nobody trusts.
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=MaluMail proof CA" -keyout "$TMP/ca.key" -out "$TMP/ca.pem" 2>/dev/null
openssl req -newkey rsa:2048 -nodes -subj "/CN=127.0.0.1" -keyout "$TMP/imap.key" -out "$TMP/imap.csr" 2>/dev/null
printf 'subjectAltName=IP:127.0.0.1,DNS:localhost\n' > "$TMP/san.ext"
openssl x509 -req -in "$TMP/imap.csr" -CA "$TMP/ca.pem" -CAkey "$TMP/ca.key" -CAcreateserial -days 2 -extfile "$TMP/san.ext" -out "$TMP/imap.pem" 2>/dev/null
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=127.0.0.1" -addext "subjectAltName=IP:127.0.0.1" -keyout "$TMP/rogue.key" -out "$TMP/rogue.pem" 2>/dev/null

# The fixture's users; the database's credentials are sealed by PHP with the public key, as the admin handlers will (§6.2).
PW_PAT=$(openssl rand -hex 12); PW_CLERK=$(openssl rand -hex 12); PW_WRONG=$(openssl rand -hex 12); PW_BUSY=$(openssl rand -hex 12)
cat > "$TMP/users.json" <<JSON
{"pat@business.example": {"password": "$PW_PAT", "mode": "ok"}, "clerk@business.example": {"password": "$PW_CLERK", "mode": "ok"},
 "busy@business.example": {"password": "$PW_BUSY", "mode": "busy"}}
JSON
PUB=$(val "$ML_DEV_ENV" ML_SEAL_PUBLIC_KEY)
sealhex() { php -r 'echo bin2hex(sodium_crypto_box_seal($argv[1], base64_decode($argv[2])));' "$1" "$PUB"; }
SQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -d $ML_DEV_DB"
$SQL <<SQL || { echo "FAIL seeding"; exit 1; }
INSERT INTO members (id, member_kind, display_name, email, business_role, status, capability, roles) VALUES
 (1, 'human', 'Ada Admin', 'ada@business.example', 'super_admin', 'active', 'admin', '{admin,user}'),
 (2, 'human', 'Pat Person', 'pat@business.example', 'user', 'active', 'write', '{user}'),
 (3, 'agent', 'SMOKE Clerk', NULL, 'user', 'active', 'admin', '{admin,user}'),
 (4, 'human', 'Old Admin', 'old@business.example', 'super_admin', 'inactive', 'admin', '{admin,user}');
INSERT INTO ml_domains (domain, served, internal) VALUES ('business.example', true, true);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES
 ('pat@business.example', 'Pat Person', 'person', 2), ('clerk@business.example', 'SMOKE Clerk', 'agent', 3),
 ('busy@business.example', 'Busy', 'shared', NULL), ('nocred@business.example', 'No credential', 'shared', NULL);
INSERT INTO mailbox_credentials (kind, label, last4, ciphertext, mailbox_id)
SELECT 'mailbox_password', 'proof', 'x', decode(v.box, 'hex'), m.id FROM (VALUES
 ('pat@business.example', '$(sealhex "$PW_PAT")'), ('clerk@business.example', '$(sealhex "$PW_WRONG")'),
 ('busy@business.example', '$(sealhex "$PW_BUSY")')) v(address, box) JOIN mailboxes m ON m.address = v.address;
UPDATE mailboxes m SET credential_id = c.id FROM mailbox_credentials c WHERE c.mailbox_id = m.id;
SQL

KEY=$(val "$MENV" MALUMAIL_MAILBOX_API_KEY)
"$PY" -I "$ROOT/tests/fixtures/fake_mailbox_mcp.py" --key "$KEY" --port-file "$TMP/mcp.port" --log "$TMP/mcp.jsonl" --control "$TMP/mcp" & PIDS+=($!)
"$PY" -I "$ROOT/tests/fixtures/imap_server.py" --users "$TMP/users.json" --cert "$TMP/imap.pem" --key "$TMP/imap.key" --port-file "$TMP/imap.port" --stats "$TMP/imap-stats.json" & PIDS+=($!)
"$PY" -I "$ROOT/tests/fixtures/imap_server.py" --users "$TMP/users.json" --cert "$TMP/rogue.pem" --key "$TMP/rogue.key" --port-file "$TMP/rogue.port" --stats "$TMP/rogue-stats.json" & PIDS+=($!)
for f in mcp.port imap.port rogue.port; do for _ in $(seq 50); do [ -s "$TMP/$f" ] && break; sleep 0.1; done; done

export ML_IMAP_PORT=$(cat "$TMP/imap.port") MALUMAIL_MCP_URL=http://127.0.0.1:$(cat "$TMP/mcp.port")/mcp ML_TLS_CA_FILE=$TMP/ca.pem ML_MAILER_PASS_SECONDS=1
ML_MAILER_PORT=9206 "$PY" -I "$ROOT/services/mailer/server.py" > "$TMP/mailer.log" 2>&1 & PIDS+=($!)
for _ in $(seq 100); do curl -fs http://127.0.0.1:9206/health >/dev/null 2>&1 && break; sleep 0.1; done

export ML_TMP=$TMP ML_PW_PAT=$PW_PAT ML_PW_CLERK=$PW_CLERK ML_PW_WRONG=$PW_WRONG ML_PW_BUSY=$PW_BUSY ML_ROGUE_PORT=$(cat "$TMP/rogue.port")
"$PY" -I "$ROOT/tests/mailer/check.py"
