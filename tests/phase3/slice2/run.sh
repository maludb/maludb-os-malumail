#!/usr/bin/env bash
# Slice 2's proof — the mail service and the list, THE FIRST EXEMPLAR (docs/build-specs/mail-list.md "Proof", mail-service.md §13), on a
# fresh SCRATCH database (ml_dev2; never the installed one):
#   tests/phase3/slice2/run.sh                 the application under php -S with tests/dev_router.php
#   ML_APP=apache tests/phase3/slice2/run.sh   the application from a real Apache (as your user) rendering deploy/apache-malumail.conf
# The servers are Phase 2's (tests/phase2/servers.sh) with the IMAP fixture on: seven mailboxes seeded with sealed credentials the
# fixture accepts (one of 5,000 messages), the mail service keeping them in step. Parts: sync.py (the service against the fixture:
# first sync, keys, NOTIFY, IDLE + STATUS, QRESYNC, UIDVALIDITY, the text pass, no \Seen), list.php (the wall, the ten views, unread,
# paging, the poll, the access log, timings), browser.mjs (375 and 1280, the poll's cadence, the title and the favicon, axe), live.py
# (MaluMail's test mailboxes — only with the owner's handoff file, else one line), the registry. Nothing else reaches MaluMail.
# Needs `sudo -n -u postgres` (scratch ml_* databases and the malumail_* roles), php with sodium, the venv, node + Playwright.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export P_APP=${P_APP:-9201} P_KERNEL=${P_KERNEL:-9202} P_FAKE=${P_FAKE:-9203} P_MAILER=${P_MAILER:-9206} P_INTERNAL=${P_INTERNAL:-9205}
export ML_DEV_DB=${ML_DEV_DB:-ml_dev2} ML_DEV_ENV=${ML_DEV_ENV:-/tmp/ml-dev2s.env} ML_DEV_STATE=${ML_DEV_STATE:-/tmp/ml-dev2s-state} SHOTS=${SHOTS:-/tmp/ml-shots-s2}
export ML_DEV_STORAGE=${ML_DEV_STORAGE:-/tmp/ml-dev2s-storage} ML_WITH_IMAP=1
export ML_MAILER_PASS_SECONDS=2 ML_MAILER_TEXT_PER_MINUTE=600 ML_IMAP_USERS="$ML_DEV_STATE/seed-users.json"
PY=${ML_PY:-$ROOT/mcp/venv/bin/python}
rm -rf "$ML_DEV_STATE"; mkdir -p "$SHOTS" "$ML_DEV_STATE"
for port in $P_APP $P_KERNEL $P_FAKE $P_MAILER; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i "s/^ML_MAILER_PORT=.*/ML_MAILER_PORT=$P_MAILER/" "$ML_DEV_ENV"
cd "$ROOT"
set -a; . "$ML_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$P_APP" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$ML_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$ML_DEV_STATE/maludb.log" ML_MAILER_PORT=$P_MAILER

# The mailboxes, each with a random password the fixture accepts and the database holds sealed (as the admin handlers seal it):
#   priya@ Priya's own (60 messages) · dana@ Dana's own · sales@ shared — Priya triage, Dana view · team@ shared with the Sales department
#   at view (Priya, Dana; the agent Ada is put in the department and still gets 404) · expert@ the Expert's, Priya overseer at full ·
#   intake@ shared, Help Desk's intake, Dana triage · big@ shared, Priya full (5,000 messages) · bulk01…bulk30@ unsynced, Bea at view.
PUB=$(grep -m1 '^ML_SEAL_PUBLIC_KEY=' "$ML_DEV_ENV" | cut -d= -f2-)
declare -A INBOX=([priya]=60 [dana]=12 [sales]=30 [team]=8 [expert]=12 [intake]=10 [big]=5000)
USERS="{"; SEALS=""
for u in priya dana sales team expert intake big; do
  pw=$(openssl rand -hex 12)
  USERS+="\"$u@business.example\": {\"password\": \"$pw\", \"mode\": \"ok\", \"inbox\": ${INBOX[$u]}},"
  box=$(php -r 'echo bin2hex(sodium_crypto_box_seal($argv[1], base64_decode($argv[2])));' "$pw" "$PUB")
  SEALS+="('$u@business.example', '$box'),"
done
echo "${USERS%,}}" > "$ML_IMAP_USERS"; chmod 600 "$ML_IMAP_USERS"
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
php bin/directory_sync.php --full >/dev/null || { echo "the first directory sync failed"; exit 1; }
sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -X -d "$ML_DEV_DB" >/dev/null <<SQL || { echo "seeding failed"; exit 1; }
INSERT INTO ml_domains (domain, served, internal) VALUES ('business.example', true, true);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES
 ('priya@business.example', 'Priya', 'person', 26), ('dana@business.example', 'Dana', 'person', 30),
 ('sales@business.example', 'Sales', 'shared', NULL), ('team@business.example', 'Sales team', 'shared', NULL),
 ('expert@business.example', 'Mail Expert', 'agent', 40), ('intake@business.example', 'Intake', 'shared', NULL),
 ('big@business.example', 'Big', 'shared', NULL);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id, sync_state, last_sync_at)
SELECT format('bulk%s@business.example', lpad(g::text, 2, '0')), format('Bulk %s', g), 'shared', NULL, 'live', now() FROM generate_series(1, 30) g;
UPDATE mailboxes SET intake_owner = 'helpdesk' WHERE address = 'intake@business.example';
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT b.id, v.k, v.p, v.l, 1 FROM (VALUES ('sales', 'member', 26, 'triage'), ('sales', 'member', 30, 'view'), ('team', 'department', 8, 'view'),
  ('expert', 'member', 26, 'full'), ('intake', 'member', 30, 'triage'), ('big', 'member', 26, 'full')) v(a, k, p, l)
  JOIN mailboxes b ON b.address = v.a || '@business.example';
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT id, 'member', 28, 'view', 1 FROM mailboxes WHERE address LIKE 'bulk%';
UPDATE mailboxes SET primary_overseer_id = 26 WHERE address = 'expert@business.example';
INSERT INTO department_members (member_id, department_id) VALUES (43, 8) ON CONFLICT DO NOTHING;
UPDATE ml_settings SET expert_member_id = 40 WHERE id = 1;
INSERT INTO ml_notice_acknowledgements (member_id, notice_version) SELECT m.id, 1 FROM members m WHERE m.member_kind = 'human';
INSERT INTO mailbox_credentials (kind, label, last4, ciphertext, mailbox_id)
SELECT 'mailbox_password', 'proof', 'x', decode(v.box, 'hex'), m.id FROM (VALUES ${SEALS%,}) v(address, box) JOIN mailboxes m ON m.address = v.address;
UPDATE mailboxes m SET credential_id = c.id FROM mailbox_credentials c WHERE c.mailbox_id = m.id;
SQL
export ML_P2_SEED="$ML_DEV_STATE/seed.json"
sudo -n -u postgres psql -X -At -d "$ML_DEV_DB" -c "SELECT json_object_agg(split_part(address, '@', 1), id) FROM mailboxes" > "$ML_P2_SEED"
export ML_IMAP_CONTROL="$ML_DEV_STATE/imap-control" ML_IMAP_STATS="$ML_DEV_STATE/imap-stats.json"
if [ "${ML_S2_HOLD:-0}" = "1" ]; then trap - EXIT; echo "held: servers up, seed $(cat "$ML_P2_SEED")"; exit 0; fi   # development only: leave the servers up
status=0
echo "== sync"; "$PY" -I tests/phase3/slice2/sync.py || status=1
echo "== list"; php tests/phase3/slice2/list.php || status=1
echo "== browser"; node tests/phase3/slice2/browser.mjs || status=1
echo "== live (spec step 10)"; "$PY" -I tests/phase3/slice2/live.py || status=1
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 2 PROOFS PASSED" || echo "SOME SLICE 2 PROOFS FAILED"
exit $status
