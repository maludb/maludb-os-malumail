#!/usr/bin/env bash
# Slice 9's proof — NOTIFICATIONS (docs/build-specs/notifications.md "Proof"), on a fresh SCRATCH database (ml_dev9; never the installed one):
#   tests/phase3/slice9/run.sh                 the application under php -S with tests/dev_router.php
#   ML_APP=apache tests/phase3/slice9/run.sh   the application from a real Apache (as your user) rendering deploy/apache-malumail.conf
# No mail server of any kind: the index is seeded straight (mail that "arrives" is a row in an INBOX), mail for notices goes to the FAKE /v1/send on
# :9203 (tests/fake_malumail.php, which logs every body), texts to the FAKE kernel's notify/sms.php on :9202 (its modes), the Clerk's note runs at a
# date the worker is told (ML_WORKER_NOW). Mailboxes: priya@ (person, Priya 26) · dana@ (person, Dana 30; the Expert may send from it — D12) · sales@ shared
# (Priya full, Dana view) · expert@ the Expert's (agent 40; Priya full and primary overseer, Dana triage, the Clerk 42 triage) · ada@ (agent 43; Dana
# full, no primary overseer) · cred@ shared (Priya full; a credential to trip). People: 1 the Mail admin, 26 Priya, 27 Marco, 30 Dana. Parts: checks.php,
# browser.mjs (375 and 1280, the bell's menu, a row opened), the registry. Nothing reaches MaluMail.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export P_APP=${P_APP:-9201} P_KERNEL=${P_KERNEL:-9202} P_FAKE=${P_FAKE:-9203} P_MAILER=${P_MAILER:-9206} P_INTERNAL=${P_INTERNAL:-9205} P_IMAGES=${P_IMAGES:-9207}
export ML_DEV_DB=${ML_DEV_DB:-ml_dev9} ML_DEV_ENV=${ML_DEV_ENV:-/tmp/ml-dev9.env} ML_DEV_STATE=${ML_DEV_STATE:-/tmp/ml-dev9-state} SHOTS=${SHOTS:-/tmp/ml-shots-s9}
export ML_DEV_STORAGE=${ML_DEV_STORAGE:-/tmp/ml-dev9-storage}
PY=${ML_PY:-$ROOT/mcp/venv/bin/python}
rm -rf "$ML_DEV_STATE"; mkdir -p "$SHOTS" "$ML_DEV_STATE"
for port in $P_APP $P_KERNEL $P_FAKE $P_MAILER $P_IMAGES; do
  for i in $(seq 1 30); do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null || break; sleep 0.5; done
  (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }
done
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i "s/^ML_MAILER_PORT=.*/ML_MAILER_PORT=$P_MAILER/; s/^ML_IMAGES_PORT=.*/ML_IMAGES_PORT=$P_IMAGES/" "$ML_DEV_ENV"
cd "$ROOT"
set -a; . "$ML_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$P_APP" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$ML_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$ML_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$ML_DEV_STATE/malumail.log" ML_MAILER_PORT=$P_MAILER ML_IMAGES_PORT=$P_IMAGES
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
php bin/directory_sync.php --full >/dev/null || { echo "the first directory sync failed"; exit 1; }
sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -X -d "$ML_DEV_DB" >/dev/null <<SQL || { echo "seeding failed"; exit 1; }
INSERT INTO ml_domains (domain, served, internal) VALUES ('business.example', true, true);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES
 ('priya@business.example', 'Priya', 'person', 26), ('dana@business.example', 'Dana', 'person', 30),
 ('sales@business.example', 'Sales', 'shared', NULL), ('expert@business.example', 'Mail Expert', 'agent', 40),
 ('ada@business.example', 'Ada', 'agent', 43), ('cred@business.example', 'Credential proof', 'shared', NULL);
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT b.id, v.k, v.p, v.l, 1 FROM (VALUES ('sales', 'member', 26, 'full'), ('sales', 'member', 30, 'view'),
  ('expert', 'member', 26, 'full'), ('expert', 'member', 30, 'triage'), ('expert', 'agent', 42, 'triage'),
  ('ada', 'member', 30, 'full'), ('cred', 'member', 26, 'full')) v(a, k, p, l)
  JOIN mailboxes b ON b.address = v.a || '@business.example';
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT b.id, 'agent', 40, 'send', 30 FROM mailboxes b WHERE b.address = 'dana@business.example';
UPDATE mailboxes SET primary_overseer_id = 26 WHERE address = 'expert@business.example';
INSERT INTO agent_mail_policies (mailbox_id, send_mode, first_contact_review, attachments_review, max_external_per_day, max_per_hour, max_per_thread_per_day, wake_on_mail, wake_from, disclosure, updated_by)
SELECT b.id, s.default_send_mode, s.default_first_contact_review, s.default_attachments_review, s.default_max_external_per_day, s.default_max_per_hour,
       s.default_max_per_thread_per_day, s.default_wake_on_mail, s.default_wake_from, s.default_disclosure, 1
  FROM mailboxes b, ml_settings s WHERE b.kind = 'agent';
UPDATE ml_settings SET expert_member_id = 40, clerk_member_id = 42, undo_send_seconds = 3, draft_save_seconds = 5 WHERE id = 1;
INSERT INTO ml_notice_acknowledgements (member_id, notice_version) SELECT m.id, 1 FROM members m WHERE m.member_kind = 'human';
INSERT INTO mail_identities (mailbox_id, address, kind, display_name, is_default, created_by)
SELECT id, address, 'own', display_name, true, 1 FROM mailboxes;
INSERT INTO mail_folders (mailbox_id, name, special_use, uidvalidity, uidnext) SELECT id, 'INBOX', 'inbox', 1, 100000 FROM mailboxes;
INSERT INTO mailbox_credentials (kind, label, last4, ciphertext, mailbox_id) SELECT 'mailbox_password', 'proof', 'x', decode(repeat('ab', 64), 'hex'), id FROM mailboxes WHERE address = 'cred@business.example';
UPDATE mailboxes m SET credential_id = c.id FROM mailbox_credentials c WHERE c.mailbox_id = m.id;
UPDATE members SET phone = '+15550100' WHERE id = 26;
SQL
export ML_P2_SEED="$ML_DEV_STATE/seed.json"
sudo -n -u postgres psql -X -At -d "$ML_DEV_DB" -c "SELECT json_object_agg(split_part(address, '@', 1), id) FROM mailboxes" > "$ML_P2_SEED"
if [ "${ML_S9_HOLD:-0}" = "1" ]; then trap - EXIT; echo "held: servers up, seed $(cat "$ML_P2_SEED")"; exit 0; fi   # development only
status=0
echo "== checks"; php tests/phase3/slice9/checks.php || status=1
echo "== browser"; node tests/phase3/slice9/browser.mjs || status=1
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 9 PROOFS PASSED" || echo "SOME SLICE 9 PROOFS FAILED"
exit $status
