#!/usr/bin/env bash
# Slice 6's proof — SEARCH, LABELS, SNOOZE, FOLDERS AND TEMPLATES (docs/build-specs/search-labels-snooze.md "Proof"), on a fresh SCRATCH
# database (ml_dev6; never the installed one):
#   tests/phase3/slice6/run.sh                 the application under php -S with tests/dev_router.php
#   ML_APP=apache tests/phase3/slice6/run.sh   the application from a real Apache (as your user) rendering deploy/apache-malumail.conf
# Servers: slice 5's — the IMAP fixture (now with CREATE, RENAME, DELETE, UID MOVE, UID STORE) and the SMTP fixture, the mail service against them
# (never MaluMail), the fake kernel (its chat endpoint records every turn in "<state>.chat"). Mailboxes: priya@ (Priya, time zone Europe/Berlin) ·
# dana@ · sales@ shared (Priya full, Dana view) · expert@ the Expert's (agent 40; Priya full and overseer) · intake@ shared, Help Desk's intake
# (Priya triage) — all live on the IMAP fixture — and, seeded straight into the index (2,000 synthetic messages, no credential, never synced):
# big1@ (Priya full, Dana view, 700) · big2@ (Priya triage, 700) · big3@ (Priya full, 600) · hush@ (Dana full only, 30) · agentbox@ (the Expert's,
# Priya full, 24 with one conversation marked sensitive). Parts: checks.php (operators against an independent SQL truth, the cursor over every
# result, the wall, labels, snooze and the worker's wake, folders, templates, the log vocabulary), tools.py (the records queries), browser.mjs
# (375 and 1280), the registry. ML_S6_HOLD=1 leaves the servers up.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export P_APP=${P_APP:-9201} P_KERNEL=${P_KERNEL:-9202} P_FAKE=${P_FAKE:-9203} P_MAILER=${P_MAILER:-9206} P_INTERNAL=${P_INTERNAL:-9205} P_IMAGES=${P_IMAGES:-9207}
export ML_DEV_DB=${ML_DEV_DB:-ml_dev6} ML_DEV_ENV=${ML_DEV_ENV:-/tmp/ml-dev6.env} ML_DEV_STATE=${ML_DEV_STATE:-/tmp/ml-dev6-state} SHOTS=${SHOTS:-/tmp/ml-shots-s6}
export ML_DEV_STORAGE=${ML_DEV_STORAGE:-/tmp/ml-dev6-storage} ML_WITH_IMAP=1 ML_WITH_SMTP=1 ML_WITH_IMAGES=1
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
declare -A INBOX=([priya]=6 [dana]=2 [sales]=3 [expert]=3 [intake]=3 [bulk]=60)
USERS="{"; SEALS=""
for u in priya dana sales expert intake bulk; do
  pw=$(openssl rand -hex 12)
  USERS+="\"$u@business.example\": {\"password\": \"$pw\", \"mode\": \"ok\", \"inbox\": ${INBOX[$u]}, \"smtp_mode\": \"ok\"},"
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
 ('sales@business.example', 'Sales', 'shared', NULL), ('expert@business.example', 'Mail Expert', 'agent', 40),
 ('intake@business.example', 'Intake', 'shared', NULL), ('bulk@business.example', 'Bulk', 'shared', NULL);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id, sync_state, last_sync_at) VALUES
 ('big1@business.example', 'Big one', 'shared', NULL, 'live', now()), ('big2@business.example', 'Big two', 'shared', NULL, 'live', now()),
 ('big3@business.example', 'Big three', 'shared', NULL, 'live', now()), ('hush@business.example', 'Hush', 'shared', NULL, 'live', now()),
 ('agentbox@business.example', 'Agent box', 'agent', 40, 'live', now()), ('big4@business.example', 'Big four', 'shared', NULL, 'live', now());
UPDATE mailboxes SET intake_owner = 'helpdesk' WHERE address = 'intake@business.example';
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT b.id, v.k, v.p, v.l, 1 FROM (VALUES ('sales', 'member', 26, 'full'), ('sales', 'member', 30, 'view'), ('expert', 'member', 26, 'full'),
  ('intake', 'member', 26, 'triage'), ('intake', 'member', 30, 'triage'), ('big1', 'member', 26, 'full'), ('big1', 'member', 30, 'view'), ('big2', 'member', 26, 'triage'),
  ('big3', 'member', 26, 'full'), ('hush', 'member', 30, 'full'), ('agentbox', 'member', 26, 'full'), ('bulk', 'member', 26, 'full'), ('big4', 'member', 28, 'full')) v(a, k, p, l)
  JOIN mailboxes b ON b.address = v.a || '@business.example';
UPDATE mailboxes SET primary_overseer_id = 26 WHERE address IN ('expert@business.example', 'agentbox@business.example');
UPDATE members SET timezone = 'Europe/Berlin' WHERE id = 26;
UPDATE ml_settings SET expert_member_id = 40, clerk_member_id = 42 WHERE id = 1;
INSERT INTO agent_mail_policies (mailbox_id, send_mode, first_contact_review, attachments_review, max_external_per_day, max_per_hour, max_per_thread_per_day, wake_on_mail, wake_from, disclosure, updated_by)
SELECT b.id, s.default_send_mode, s.default_first_contact_review, s.default_attachments_review, s.default_max_external_per_day, s.default_max_per_hour,
       s.default_max_per_thread_per_day, s.default_wake_on_mail, s.default_wake_from, s.default_disclosure, 1
  FROM mailboxes b, ml_settings s WHERE b.kind = 'agent';
INSERT INTO ml_notice_acknowledgements (member_id, notice_version) SELECT m.id, 1 FROM members m WHERE m.member_kind = 'human';
INSERT INTO mailbox_credentials (kind, label, last4, ciphertext, mailbox_id)
SELECT 'mailbox_password', 'proof', 'x', decode(v.box, 'hex'), m.id FROM (VALUES ${SEALS%,}) v(address, box) JOIN mailboxes m ON m.address = v.address;
UPDATE mailboxes m SET credential_id = c.id FROM mailbox_credentials c WHERE c.mailbox_id = m.id;
INSERT INTO mail_identities (mailbox_id, address, kind, display_name, is_default, created_by) SELECT id, address, 'own', display_name, true, 1 FROM mailboxes;
-- the seeded index: folders, then the messages (every few a reply to one nine before, so conversations of several messages exist)
INSERT INTO mail_folders (mailbox_id, name, special_use, uidvalidity, uidnext)
SELECT b.id, f.n, f.s, 1, 100000 FROM mailboxes b, (VALUES ('INBOX', 'inbox'), ('Sent', 'sent'), ('Junk', 'junk'), ('Trash', 'trash')) f(n, s)
 WHERE b.address ~ '^(big[1234]|hush|agentbox)@';
CREATE TEMP TABLE seedmsgs AS
SELECT b.id AS mb, b.address AS addr, g, f.id AS folder
  FROM mailboxes b CROSS JOIN LATERAL generate_series(1, CASE split_part(b.address, '@', 1) WHEN 'big1' THEN 700 WHEN 'big2' THEN 700 WHEN 'big3' THEN 600 WHEN 'big4' THEN 5000 WHEN 'hush' THEN 30 ELSE 24 END) g
  JOIN mail_folders f ON f.mailbox_id = b.id AND f.special_use = CASE WHEN g % 23 = 0 THEN 'junk' WHEN g % 17 = 0 THEN 'trash' WHEN g % 9 = 0 THEN 'sent' ELSE 'inbox' END
 WHERE b.address ~ '^(big[1234]|hush|agentbox)@';
INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, in_reply_to, references_ids, from_address, from_name, to_addresses, cc_addresses, bcc_addresses,
                           subject, sent_at, received_at, size, seen, flagged, answered, has_attachments, attachment_names, dmarc_pass, snippet, body_text)
SELECT m.folder, 1, m.g, ml_message_key_for(m.mb, '<s6-' || m.mb || '-' || m.g || '@x>', encode(digest('s6' || m.mb || '-' || m.g, 'sha256'), 'hex')), '<s6-' || m.mb || '-' || m.g || '@x>',
       CASE WHEN m.g % 9 = 1 AND m.g > 9 THEN '<s6-' || m.mb || '-' || (m.g - 9) || '@x>' END,
       CASE WHEN m.g % 9 = 1 AND m.g > 9 THEN ARRAY['<s6-' || m.mb || '-' || (m.g - 9) || '@x>'] ELSE '{}' END,
       CASE WHEN m.g % 9 = 0 THEN m.addr ELSE 'u' || (m.g % 7) || '@outside.example' END, CASE WHEN m.g % 3 = 0 THEN 'Person ' || (m.g % 7) END,
       ARRAY[m.addr], CASE WHEN m.g % 11 = 0 THEN ARRAY['copy@outside.example'] ELSE '{}' END, CASE WHEN m.g % 31 = 0 THEN ARRAY['hidden@outside.example'] ELSE '{}' END,
       (ARRAY['Invoice', 'Report', 'Meeting', 'Lunch', 'Photos'])[m.g % 5 + 1] || ' ' || (m.g % 13), now() - m.g * interval '7 minutes', now() - m.g * interval '7 minutes',
       (m.g % 20) * 150000, m.g % 2 = 0, m.g % 10 = 0, m.g % 15 = 0, m.g % 5 = 0, CASE WHEN m.g % 5 = 0 THEN ARRAY['report' || (m.g % 3) || '.pdf'] ELSE '{}' END, true,
       'Snippet of message ' || m.g, CASE WHEN m.g % 50 = 0 THEN 'The quarterly review is attached.' ELSE 'Plain text ' || m.g END
  FROM seedmsgs m;
UPDATE mail_messages SET subject = 'Zebra crossing', snippet = 'Zebra' WHERE message_id_header = (SELECT '<s6-' || id || '-4@x>' FROM mailboxes WHERE address = 'big1@business.example');
-- the agent's mailbox: the conversation of message 5 is marked sensitive (a person marked it)
INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, sensitive, sensitive_by)
SELECT m.mailbox_id, m.message_key_id, true, 26 FROM mail_messages m JOIN mailboxes b ON b.id = m.mailbox_id WHERE b.address = 'agentbox@business.example' AND m.uid = 5;
SQL
export ML_P2_SEED="$ML_DEV_STATE/seed.json"
sudo -n -u postgres psql -X -At -d "$ML_DEV_DB" -c "SELECT json_object_agg(split_part(address, '@', 1), id) FROM mailboxes" > "$ML_P2_SEED"
export ML_IMAP_CONTROL="$ML_DEV_STATE/imap-control" ML_IMAP_STATS="$ML_DEV_STATE/imap-stats.json" ML_SMTP_STATS="$ML_DEV_STATE/smtp-stats.json" ML_SMTP_SPOOL="$ML_DEV_STATE/spool"
if [ "${ML_S6_HOLD:-0}" = "1" ]; then trap - EXIT; echo "held: servers up, seed $(cat "$ML_P2_SEED")"; exit 0; fi   # development only
status=0
echo "== checks"; php tests/phase3/slice6/checks.php || status=1
echo "== tools"; "$PY" -I tests/phase3/slice6/tools.py || status=1
echo "== browser"; node tests/phase3/slice6/browser.mjs || status=1
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 6 PROOFS PASSED" || echo "SOME SLICE 6 PROOFS FAILED"
exit $status
