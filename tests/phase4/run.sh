#!/usr/bin/env bash
# Phase 4's proof — THE TWO MCP SERVERS (docs/malumail-mcp-tool-surface.md is the contract), on a fresh SCRATCH database (ml_dev11; never the installed one):
#   tests/phase4/run.sh                 the application under php -S with tests/dev_router.php
#   ML_APP=apache tests/phase4/run.sh   the application from a real Apache (as your user) rendering deploy/apache-malumail.conf — then the proofs also reach the
#                                       servers through the vhost's own /mcp/records and /mcp/activity lines, the K26 headers crossing the proxy
# The servers are Phase 2's (tests/phase2/servers.sh, the IMAP fixture on, a fake kernel :9202 whose run facts the proofs write) plus the two MCP servers
# under test (records :9204, activity :9208; tests/phase4/mcp_servers.sh). Mailboxes seeded with sealed credentials the fixture accepts; mail delivered
# through the fixture's control door (planted instructions in a subject, a body, a display name and an attachment name). Parts, one process each:
#   gate.py (tokens, the run-facts gate, K38, app_roles, the share's caller), tools.py (the walls, the fence, the access log, the errors, paging, every
#   tool called), activity.py (the activity server), registry.php-free registry.py (the kernel's own loader against this application's registry), coverage.py.
# Nothing reaches MaluMail. Needs `sudo -n -u postgres` (scratch ml_* databases and the malumail_* roles), php with sodium, the venv.
#   ML_P4_PROOFS="gate tools" tests/phase4/run.sh   runs some, in order.   ML_P4_HOLD=1  leaves the servers up for development.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export P_APP=${P_APP:-9201} P_KERNEL=${P_KERNEL:-9202} P_FAKE=${P_FAKE:-9203} P_MAILER=${P_MAILER:-9206} P_INTERNAL=${P_INTERNAL:-9205} P_IMAGES=${P_IMAGES:-9207}
export ML_DEV_DB=${ML_DEV_DB:-ml_dev11} ML_DEV_ENV=${ML_DEV_ENV:-/tmp/ml-dev11.env} ML_DEV_STATE=${ML_DEV_STATE:-/tmp/ml-dev11-state}
export ML_DEV_STORAGE=${ML_DEV_STORAGE:-/tmp/ml-dev11-storage} ML_WITH_IMAP=1 ML_WITH_IMAGES=1
export ML_MAILER_PASS_SECONDS=2 ML_MAILER_TEXT_PER_MINUTE=600 ML_IMAP_USERS="$ML_DEV_STATE/seed-users.json"
PY=${ML_PY:-$ROOT/mcp/venv/bin/python}
stop_all() { "$ROOT/tests/phase4/mcp_servers.sh" stop >/dev/null 2>&1; "$ROOT/tests/phase2/servers.sh" stop >/dev/null 2>&1; }
if [ "${ML_P4_STOP:-0}" = 1 ]; then stop_all; echo stopped; exit 0; fi
rm -rf "$ML_DEV_STATE"; mkdir -p "$ML_DEV_STATE"
for port in $P_APP $P_KERNEL $P_FAKE $P_MAILER $P_IMAGES 9204 9208; do
  for i in $(seq 1 30); do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null || break; sleep 0.5; done
  (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }
done
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i "s/^ML_MAILER_PORT=.*/ML_MAILER_PORT=$P_MAILER/; s/^ML_IMAGES_PORT=.*/ML_IMAGES_PORT=$P_IMAGES/" "$ML_DEV_ENV"
cd "$ROOT"
set -a; . "$ML_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$P_APP" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$ML_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$ML_DEV_STATE/maludb.log" ML_MAILER_PORT=$P_MAILER ML_IMAGES_PORT=$P_IMAGES
PUB=$(grep -m1 '^ML_SEAL_PUBLIC_KEY=' "$ML_DEV_ENV" | cut -d= -f2-)
declare -A INBOX=([priya]=60 [dana]=0 [marco]=0 [sales]=0 [expert]=0 [ada]=0)
USERS="{"; SEALS=""
for u in priya dana marco sales expert ada; do
  pw=$(openssl rand -hex 12)
  USERS+="\"$u@business.example\": {\"password\": \"$pw\", \"mode\": \"ok\", \"inbox\": ${INBOX[$u]}},"
  box=$(php -r 'echo bin2hex(sodium_crypto_box_seal($argv[1], base64_decode($argv[2])));' "$pw" "$PUB")
  SEALS+="('$u@business.example', '$box'),"
done
echo "${USERS%,}}" > "$ML_IMAP_USERS"; chmod 600 "$ML_IMAP_USERS"
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap 'if [ "${ML_P4_HOLD:-0}" = 1 ]; then echo "== left running (ML_P4_HOLD=1): ML_P4_STOP=1 bash $0 stops them"; else stop_all; fi' EXIT
php bin/directory_sync.php --full >/dev/null || { echo "the first directory sync failed"; exit 1; }
sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -X -d "$ML_DEV_DB" >/dev/null <<SQL || { echo "seeding failed"; exit 1; }
INSERT INTO ml_domains (domain, served, internal) VALUES ('business.example', true, true);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES
 ('priya@business.example', 'Priya', 'person', 26), ('dana@business.example', 'Dana', 'person', 30), ('marco@business.example', 'Marco', 'person', 27),
 ('sales@business.example', 'Sales', 'shared', NULL), ('expert@business.example', 'Mail Expert', 'agent', 40), ('ada@business.example', 'Ada', 'agent', 43);
UPDATE mailboxes SET ai_read = true WHERE address IN ('priya@business.example', 'dana@business.example');       -- Marco's stays off
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT b.id, v.k, v.p, v.l, 1 FROM (VALUES ('sales', 'member', 26, 'full'), ('sales', 'member', 30, 'view'), ('sales', 'agent', 43, 'view'),
  ('expert', 'member', 26, 'full'), ('expert', 'member', 30, 'triage'), ('expert', 'agent', 42, 'triage'), ('ada', 'member', 30, 'full')) v(a, k, p, l)
  JOIN mailboxes b ON b.address = v.a || '@business.example';
UPDATE mailboxes SET primary_overseer_id = 26 WHERE address = 'expert@business.example';
UPDATE mailboxes SET primary_overseer_id = 30 WHERE address = 'ada@business.example';
INSERT INTO agent_mail_policies (mailbox_id, send_mode, first_contact_review, attachments_review, max_external_per_day, max_per_hour, max_per_thread_per_day, wake_on_mail, wake_from, disclosure, updated_by)
SELECT b.id, s.default_send_mode, s.default_first_contact_review, s.default_attachments_review, s.default_max_external_per_day, s.default_max_per_hour,
       s.default_max_per_thread_per_day, s.default_wake_on_mail, s.default_wake_from, s.default_disclosure, 1
  FROM mailboxes b, ml_settings s WHERE b.kind = 'agent';
UPDATE ml_settings SET expert_member_id = 40, clerk_member_id = 42 WHERE id = 1;
INSERT INTO ml_notice_acknowledgements (member_id, notice_version) SELECT m.id, 1 FROM members m WHERE m.member_kind = 'human' AND m.id <> 30;
INSERT INTO mailbox_credentials (kind, label, last4, ciphertext, mailbox_id)
SELECT 'mailbox_password', 'proof', 'x', decode(v.box, 'hex'), m.id FROM (VALUES ${SEALS%,}) v(address, box) JOIN mailboxes m ON m.address = v.address;
UPDATE mailboxes m SET credential_id = c.id FROM mailbox_credentials c WHERE c.mailbox_id = m.id;
INSERT INTO mail_identities (mailbox_id, address, kind, display_name, is_default, created_by) SELECT id, address, 'own', display_name, true, 1 FROM mailboxes;
INSERT INTO email_templates (name, subject, body, is_shared, owner_member_id) VALUES ('Thanks', 'Thank you', 'Thank you for your note.', true, 26), ('Dana only', 'Private', 'Mine.', false, 30);
INSERT INTO ml_aliases (domain_id, address, destinations, created_by) SELECT d.id, v.a, v.dest::citext[], 1 FROM ml_domains d, (VALUES
   ('info@business.example', '{priya@business.example}'), ('press@business.example', '{dana@business.example,reporter@outside.example}'), ('all@business.example', '{sales@business.example,expert@business.example}')) v(a, dest)
  WHERE d.domain = 'business.example';
SQL
export ML_P2_SEED="$ML_DEV_STATE/seed.json"
sudo -n -u postgres psql -X -At -d "$ML_DEV_DB" -c "SELECT json_object_agg(split_part(address, '@', 1), id) FROM mailboxes" > "$ML_P2_SEED"
export ML_IMAP_CONTROL="$ML_DEV_STATE/imap-control" ML_IMAP_STATS="$ML_DEV_STATE/imap-stats.json"
export ML_P4_URL_RECORDS="http://127.0.0.1:9204/mcp" ML_P4_URL_ACTIVITY="http://127.0.0.1:9208/mcp"
status=0
echo "== world"; "$PY" -I tests/phase4/world.py || status=1
"$ROOT/tests/phase4/mcp_servers.sh" start >/dev/null || { echo "mcp servers failed"; exit 1; }
if [ "${ML_P4_HOLD:-0}" = 1 ]; then echo "held: servers up, seed $(cat "$ML_P2_SEED")"; exit $status; fi
for p in ${ML_P4_PROOFS:-gate tools activity registry coverage}; do
  echo "== $p"
  "$PY" -I "tests/phase4/$p.py" || status=1
done
if grep -q -i 'traceback' "$ML_DEV_STATE"/mcp-*.log 2>/dev/null; then echo "  FAIL a server logged a traceback:"; grep -h -i -m3 -B2 -A8 'traceback' "$ML_DEV_STATE"/mcp-*.log | head -30; status=1; else echo "  ok   no traceback in the servers' logs"; fi
logs=$(ls "$ML_DEV_STATE/app.log" "$ML_DEV_STATE/apache/apache.err" 2>/dev/null)
if [ -n "$logs" ] && grep -q 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; then echo "  FAIL the application logged a PHP warning:"; grep -h -m5 'PHP \(Warning\|Notice\|Fatal\|Deprecated\)' $logs; status=1; else echo "  ok   no PHP warning in the application's log"; fi
[ $status -eq 0 ] && echo "ALL PHASE 4 PROOFS PASSED" || echo "SOME PHASE 4 PROOFS FAILED"
exit $status
