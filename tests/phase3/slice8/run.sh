#!/usr/bin/env bash
# Slice 8's proof — CORRESPONDENTS, AUTOCOMPLETE AND THE K7 SHARE (docs/build-specs/correspondents.md "Proof"), on a fresh SCRATCH database (ml_dev8; never the installed one):
#   tests/phase3/slice8/run.sh                 the application under php -S with tests/dev_router.php
#   ML_APP=apache tests/phase3/slice8/run.sh   the application from a real Apache (as your user) rendering deploy/apache-malumail.conf
# Mailboxes: priya@ (Pat — Priya 26, owner; LIVE on the IMAP fixture so upkeep runs through the real mail service; 5,000 correspondents seeded) ·
# dana@ (Dana 30) · sales@ shared (Priya full, Dana view; 500 correspondents) · expert@ the Expert's (agent 40; Priya full and primary overseer, Dana
# triage; 500) · support@ shared (Dana full only — Priya cannot open it) · Dana also holds VIEW on priya@ (a view-only holder). People: 1 the Mail
# admin, 26 Priya, 27 Marco (no mailbox), 30 Dana. Parts: checks.php (the pages, JSON, the door, upkeep, the access log), k7.py (a stand-in for the
# kernel's K7 call: the share's handler and its log), tools.py (the three queries), browser.mjs (375 and 1280, autocomplete in the composer), the
# registry. Nothing reaches MaluMail.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export P_APP=${P_APP:-9201} P_KERNEL=${P_KERNEL:-9202} P_FAKE=${P_FAKE:-9203} P_MAILER=${P_MAILER:-9206} P_INTERNAL=${P_INTERNAL:-9205} P_IMAGES=${P_IMAGES:-9207}
export ML_DEV_DB=${ML_DEV_DB:-ml_dev8} ML_DEV_ENV=${ML_DEV_ENV:-/tmp/ml-dev8.env} ML_DEV_STATE=${ML_DEV_STATE:-/tmp/ml-dev8-state} SHOTS=${SHOTS:-/tmp/ml-shots-s8}
export ML_DEV_STORAGE=${ML_DEV_STORAGE:-/tmp/ml-dev8-storage} ML_WITH_IMAP=1 ML_WITH_IMAGES=1
export ML_MAILER_PASS_SECONDS=2 ML_MAILER_TEXT_PER_MINUTE=600 ML_IMAP_USERS="$ML_DEV_STATE/seed-users.json"
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
export FAKE_KERNEL_STATE="$ML_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$ML_DEV_STATE/maludb.log" ML_MAILER_PORT=$P_MAILER ML_IMAGES_PORT=$P_IMAGES
PUB=$(grep -m1 '^ML_SEAL_PUBLIC_KEY=' "$ML_DEV_ENV" | cut -d= -f2-)
declare -A INBOX=([priya]=0 [dana]=0 [sales]=0 [expert]=0)
USERS="{"; SEALS=""
for u in priya dana sales expert; do
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
 ('sales@business.example', 'Sales', 'shared', NULL), ('expert@business.example', 'Mail Expert', 'agent', 40);
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT b.id, v.k, v.p, v.l, 1 FROM (VALUES ('sales', 'member', 26, 'full'), ('sales', 'member', 30, 'view'),
  ('expert', 'member', 26, 'full'), ('expert', 'member', 30, 'triage'), ('expert', 'agent', 42, 'triage')) v(a, k, p, l)
  JOIN mailboxes b ON b.address = v.a || '@business.example';
UPDATE mailboxes SET primary_overseer_id = 26 WHERE address = 'expert@business.example';
INSERT INTO agent_mail_policies (mailbox_id, send_mode, first_contact_review, attachments_review, max_external_per_day, max_per_hour, max_per_thread_per_day, wake_on_mail, wake_from, disclosure, updated_by)
SELECT b.id, s.default_send_mode, s.default_first_contact_review, s.default_attachments_review, s.default_max_external_per_day, s.default_max_per_hour,
       s.default_max_per_thread_per_day, s.default_wake_on_mail, s.default_wake_from, s.default_disclosure, 1
  FROM mailboxes b, ml_settings s WHERE b.kind = 'agent';
UPDATE ml_settings SET expert_member_id = 40, clerk_member_id = 42, undo_send_seconds = 3, draft_save_seconds = 5 WHERE id = 1;
INSERT INTO ml_notice_acknowledgements (member_id, notice_version) SELECT m.id, 1 FROM members m WHERE m.member_kind = 'human';
INSERT INTO mailbox_credentials (kind, label, last4, ciphertext, mailbox_id)
SELECT 'mailbox_password', 'proof', 'x', decode(v.box, 'hex'), m.id FROM (VALUES ${SEALS%,}) v(address, box) JOIN mailboxes m ON m.address = v.address;
UPDATE mailboxes m SET credential_id = c.id FROM mailbox_credentials c WHERE c.mailbox_id = m.id;
INSERT INTO mail_identities (mailbox_id, address, kind, display_name, is_default, created_by)
SELECT id, address, 'own', display_name, true, 1 FROM mailboxes;
INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES ('support@business.example', 'Support', 'shared', NULL);
INSERT INTO mail_identities (mailbox_id, address, kind, display_name, is_default, created_by) SELECT id, address, 'own', display_name, true, 1 FROM mailboxes WHERE address = 'support@business.example';
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) SELECT id, 'member', 30, 'full', 1 FROM mailboxes WHERE address = 'support@business.example';
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by, reason) SELECT id, 'member', 30, 'view', 26, NULL FROM mailboxes WHERE address = 'priya@business.example';
-- correspondents seeded straight into the index: 5,000 for Priya, 500 each for sales@ and the Expert's mailbox
INSERT INTO mail_correspondents (mailbox_id, address, name, first_seen_at, last_seen_at, sent_count, received_count, is_internal, known_at, known_by, known_how)
SELECT b.id, 'seed' || g || '@' || CASE WHEN g % 10 = 0 THEN 'business.example' ELSE 'seeded' || (g % 50) || '.example' END, 'Seeded Person ' || g,
       now() - (g || ' minutes')::interval - interval '60 days', now() - (g || ' minutes')::interval, g % 7, g % 11 + 1, g % 10 = 0,
       CASE WHEN g % 3 = 0 AND b.kind = 'agent' THEN now() END, CASE WHEN g % 3 = 0 AND b.kind = 'agent' THEN 26 END, CASE WHEN g % 3 = 0 AND b.kind = 'agent' THEN 'marked' END
  FROM mailboxes b CROSS JOIN LATERAL generate_series(1, CASE b.address WHEN 'priya@business.example' THEN 5000 WHEN 'sales@business.example' THEN 500 WHEN 'expert@business.example' THEN 500 ELSE 0 END) g;
-- two known names the autocomplete order is checked on, and a correspondent the mailbox only heard from
INSERT INTO mail_correspondents (mailbox_id, address, name, first_seen_at, last_seen_at, sent_count, received_count, is_internal, known_at, known_by, known_how)
SELECT id, v.a, v.n, now() - interval '10 days', now() - interval '1 day' * v.d, v.s, v.r, false, CASE WHEN v.s > 0 THEN now() END, CASE WHEN v.s > 0 THEN 26 END, CASE WHEN v.s > 0 THEN 'sent' END
  FROM mailboxes, (VALUES ('zed.sent@zedco.example', 'Zed Sent', 5, 3, 1), ('zed.heard@zedco.example', 'Zed Heard', 0, 40, 0), ('zoe@zedco.example', 'Zoe Zedco', 2, 2, 2)) v(a, n, s, r, d)
 WHERE address = 'priya@business.example';
-- the Expert is in Sales (a department row admits people only)
INSERT INTO department_members (member_id, department_id, is_primary) SELECT 40, id, false FROM departments WHERE name = 'Sales' ON CONFLICT DO NOTHING;
SQL
export ML_P2_SEED="$ML_DEV_STATE/seed.json"
sudo -n -u postgres psql -X -At -d "$ML_DEV_DB" -c "SELECT json_object_agg(split_part(address, '@', 1), id) FROM mailboxes" > "$ML_P2_SEED"
export ML_IMAP_CONTROL="$ML_DEV_STATE/imap-control" ML_IMAP_STATS="$ML_DEV_STATE/imap-stats.json"
if [ "${ML_S8_HOLD:-0}" = "1" ]; then trap - EXIT; echo "held: servers up, seed $(cat "$ML_P2_SEED")"; exit 0; fi   # development only
status=0
echo "== checks"; php tests/phase3/slice8/checks.php || status=1
echo "== tools"; "$PY" -I tests/phase3/slice8/tools.py || status=1
echo "== k7"; "$PY" -I tests/phase3/slice8/k7.py || status=1
echo "== browser"; node tests/phase3/slice8/browser.mjs || status=1
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 8 PROOFS PASSED" || echo "SOME SLICE 8 PROOFS FAILED"
exit $status
