#!/usr/bin/env bash
# Slice 5's proof — AGENTS' MAIL AND OVERSIGHT, THE FOURTH EXEMPLAR (docs/build-specs/oversight.md "Proof"; sending-and-oversight.md §12),
# on a fresh SCRATCH database (ml_dev5; never the installed one):
#   tests/phase3/slice5/run.sh                 the application under php -S with tests/dev_router.php
#   ML_APP=apache tests/phase3/slice5/run.sh   the application from a real Apache (as your user) rendering deploy/apache-malumail.conf
# The servers are slice 4's: the IMAP and SMTP fixtures, the mail service submitting to the fixture (never to MaluMail), the fake kernel —
# whose chat endpoint records every turn's utterance, context and conversation in "<state>.chat".
# Mailboxes: priya@ (Priya's) · dana@ (Dana's; the Expert may send from it — D12) · sales@ shared, Priya full, Dana view, the Expert send ·
# expert@ the Expert's (agent 40), Priya full and its primary overseer, Dana triage (no decider), the Clerk (42) triage · ada@ the agent
# Ada's (43), no primary overseer. Parts: checks.php (the hold reasons from real agent sends, refusals, the five decisions, agents never
# decide, a person's mailbox, expiry, Unscreened, pause and resume, the policy — through the pages and with action tokens), tools.py (the
# records and activity queries), browser.mjs (375 and 1280, the sandboxed body), live.py (MaluMail's test mailboxes — only with the owner's
# handoff file), the registry.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export P_APP=${P_APP:-9201} P_KERNEL=${P_KERNEL:-9202} P_FAKE=${P_FAKE:-9203} P_MAILER=${P_MAILER:-9206} P_INTERNAL=${P_INTERNAL:-9205} P_IMAGES=${P_IMAGES:-9207}
export ML_DEV_DB=${ML_DEV_DB:-ml_dev5} ML_DEV_ENV=${ML_DEV_ENV:-/tmp/ml-dev5.env} ML_DEV_STATE=${ML_DEV_STATE:-/tmp/ml-dev5-state} SHOTS=${SHOTS:-/tmp/ml-shots-s5}
export ML_DEV_STORAGE=${ML_DEV_STORAGE:-/tmp/ml-dev5-storage} ML_WITH_IMAP=1 ML_WITH_SMTP=1 ML_WITH_IMAGES=1
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
declare -A INBOX=([priya]=2 [dana]=2 [sales]=2 [expert]=2 [ada]=1)
USERS="{"; SEALS=""
for u in priya dana sales expert ada; do
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
 ('ada@business.example', 'Ada', 'agent', 43);
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT b.id, v.k, v.p, v.l, 1 FROM (VALUES ('sales', 'member', 26, 'full'), ('sales', 'member', 30, 'view'), ('sales', 'agent', 40, 'send'),
  ('expert', 'member', 26, 'full'), ('expert', 'member', 30, 'triage'), ('expert', 'agent', 42, 'triage')) v(a, k, p, l)
  JOIN mailboxes b ON b.address = v.a || '@business.example';
-- Dana lets the Expert send from her own mailbox (D12: an agent reaches a person's mailbox at most at send, only through its owner)
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT b.id, 'agent', 40, 'send', 30 FROM mailboxes b WHERE b.address = 'dana@business.example';
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
SQL
export ML_P2_SEED="$ML_DEV_STATE/seed.json"
sudo -n -u postgres psql -X -At -d "$ML_DEV_DB" -c "SELECT json_object_agg(split_part(address, '@', 1), id) FROM mailboxes" > "$ML_P2_SEED"
export ML_IMAP_CONTROL="$ML_DEV_STATE/imap-control" ML_IMAP_STATS="$ML_DEV_STATE/imap-stats.json" ML_SMTP_STATS="$ML_DEV_STATE/smtp-stats.json" ML_SMTP_SPOOL="$ML_DEV_STATE/spool"
if [ "${ML_S5_HOLD:-0}" = "1" ]; then trap - EXIT; echo "held: servers up, seed $(cat "$ML_P2_SEED")"; exit 0; fi   # development only
status=0
echo "== checks"; php tests/phase3/slice5/checks.php || status=1
echo "== tools"; "$PY" -I tests/phase3/slice5/tools.py || status=1
echo "== browser"; node tests/phase3/slice5/browser.mjs || status=1
echo "== live (spec step 11)"; "$PY" -I tests/phase3/slice5/live.py || status=1
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 5 PROOFS PASSED" || echo "SOME SLICE 5 PROOFS FAILED"
exit $status
