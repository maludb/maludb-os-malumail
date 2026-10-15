#!/usr/bin/env bash
# Slice 3's proof — READING, THE SECOND EXEMPLAR (docs/build-specs/reading.md "Proof"; reading-and-rendering.md §7; mail-service.md §13),
# on a fresh SCRATCH database (ml_dev3; never the installed one):
#   tests/phase3/slice3/run.sh                 the application under php -S with tests/dev_router.php
#   ML_APP=apache tests/phase3/slice3/run.sh   the application from a real Apache (as your user) rendering deploy/apache-malumail.conf
# The servers are Phase 2's (tests/phase2/servers.sh) with the IMAP fixture on, the image door on 9207 under its socket stand-in, and a
# loopback image fixture the door admits through ML_IMAGES_TEST_ALLOW. Five mailboxes seeded with sealed credentials the fixture accepts;
# the sanitiser's benign corpus and messages built for each banner delivered through the fixture's control door. Parts: door.py (the mail
# service's fetch in every form, the links, part and raw streaming, 409 moved, every mutate op against the fixture's command log, the
# image door's refusals, the extractor on the corpus), reading.php (the page, the body's headers byte for byte, the part door's 404s, the
# eleven actions through the page and JSON, the walls, the access log), browser.mjs (375 and 1280, the frame's sandbox, the height line,
# the banners, Show images, a DOMPurify payload delivered as mail runs nothing, the keyboard, axe), live.py (MaluMail's test mailboxes —
# only with the owner's handoff file, else one line), the registry. Nothing else reaches MaluMail.
# Needs `sudo -n -u postgres` (scratch ml_* databases and the malumail_* roles), php with sodium, the venv, node + Playwright.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export P_APP=${P_APP:-9201} P_KERNEL=${P_KERNEL:-9202} P_FAKE=${P_FAKE:-9203} P_MAILER=${P_MAILER:-9206} P_INTERNAL=${P_INTERNAL:-9205} P_IMAGES=${P_IMAGES:-9207}
export P_IMAGE_FIXTURE=${P_IMAGE_FIXTURE:-9208}
export ML_DEV_DB=${ML_DEV_DB:-ml_dev3} ML_DEV_ENV=${ML_DEV_ENV:-/tmp/ml-dev3.env} ML_DEV_STATE=${ML_DEV_STATE:-/tmp/ml-dev3-state} SHOTS=${SHOTS:-/tmp/ml-shots-s3}
export ML_DEV_STORAGE=${ML_DEV_STORAGE:-/tmp/ml-dev3-storage} ML_WITH_IMAP=1 ML_WITH_IMAGES=1
export ML_MAILER_PASS_SECONDS=2 ML_MAILER_TEXT_PER_MINUTE=600 ML_IMAP_USERS="$ML_DEV_STATE/seed-users.json"
PY=${ML_PY:-$ROOT/mcp/venv/bin/python}
rm -rf "$ML_DEV_STATE"; mkdir -p "$SHOTS" "$ML_DEV_STATE"
for port in $P_APP $P_KERNEL $P_FAKE $P_MAILER $P_IMAGES $P_IMAGE_FIXTURE; do
  for i in $(seq 1 30); do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null || break; sleep 0.5; done
  (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }
done
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i "s/^ML_MAILER_PORT=.*/ML_MAILER_PORT=$P_MAILER/; s/^ML_IMAGES_PORT=.*/ML_IMAGES_PORT=$P_IMAGES/" "$ML_DEV_ENV"
cd "$ROOT"
set -a; . "$ML_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$P_APP" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$ML_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$ML_DEV_STATE/maludb.log" ML_MAILER_PORT=$P_MAILER ML_IMAGES_PORT=$P_IMAGES

# The mailboxes: priya@ Priya's own · dana@ Dana's own · sales@ shared — Priya triage, Dana view · expert@ the Expert's, Priya overseer
# at full · intake@ shared, Help Desk's intake, Dana triage. Each with a random password the fixture accepts, sealed in the database.
PUB=$(grep -m1 '^ML_SEAL_PUBLIC_KEY=' "$ML_DEV_ENV" | cut -d= -f2-)
declare -A INBOX=([priya]=40 [dana]=12 [sales]=20 [expert]=12 [intake]=10)
USERS="{"; SEALS=""
for u in priya dana sales expert intake; do
  pw=$(openssl rand -hex 12)
  USERS+="\"$u@business.example\": {\"password\": \"$pw\", \"mode\": \"ok\", \"inbox\": ${INBOX[$u]}},"
  box=$(php -r 'echo bin2hex(sodium_crypto_box_seal($argv[1], base64_decode($argv[2])));' "$pw" "$PUB")
  SEALS+="('$u@business.example', '$box'),"
done
echo "${USERS%,}}" > "$ML_IMAP_USERS"; chmod 600 "$ML_IMAP_USERS"
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
# The image fixture (the door's test-allowed loopback address; the door learned its port from P_IMAGE_FIXTURE at start) — started after
# the servers, whose start kills every pid the state directory holds.
"$PY" -I tests/phase3/slice3/image_fixture.py "$P_IMAGE_FIXTURE" >"$ML_DEV_STATE/image-fixture.log" 2>&1 & echo $! > "$ML_DEV_STATE/image-fixture.pid"
cleanup() { "$ROOT/tests/phase2/servers.sh" stop; [ -f "$ML_DEV_STATE/image-fixture.pid" ] && kill "$(cat "$ML_DEV_STATE/image-fixture.pid")" 2>/dev/null; true; }
trap cleanup EXIT
php bin/directory_sync.php --full >/dev/null || { echo "the first directory sync failed"; exit 1; }
sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -X -d "$ML_DEV_DB" >/dev/null <<SQL || { echo "seeding failed"; exit 1; }
INSERT INTO ml_domains (domain, served, internal) VALUES ('business.example', true, true);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES
 ('priya@business.example', 'Priya', 'person', 26), ('dana@business.example', 'Dana', 'person', 30),
 ('sales@business.example', 'Sales', 'shared', NULL), ('expert@business.example', 'Mail Expert', 'agent', 40), ('intake@business.example', 'Intake', 'shared', NULL);
UPDATE mailboxes SET intake_owner = 'helpdesk' WHERE address = 'intake@business.example';
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT b.id, v.k, v.p, v.l, 1 FROM (VALUES ('sales', 'member', 26, 'triage'), ('sales', 'member', 30, 'view'), ('expert', 'member', 26, 'full'),
  ('intake', 'member', 30, 'triage'), ('intake', 'member', 26, 'view')) v(a, k, p, l)
  JOIN mailboxes b ON b.address = v.a || '@business.example';
UPDATE mailboxes SET primary_overseer_id = 26 WHERE address = 'expert@business.example';
UPDATE ml_settings SET expert_member_id = 40 WHERE id = 1;
INSERT INTO ml_notice_acknowledgements (member_id, notice_version) SELECT m.id, 1 FROM members m WHERE m.member_kind = 'human';
INSERT INTO mailbox_credentials (kind, label, last4, ciphertext, mailbox_id)
SELECT 'mailbox_password', 'proof', 'x', decode(v.box, 'hex'), m.id FROM (VALUES ${SEALS%,}) v(address, box) JOIN mailboxes m ON m.address = v.address;
UPDATE mailboxes m SET credential_id = c.id FROM mailbox_credentials c WHERE c.mailbox_id = m.id;
SQL
export ML_P2_SEED="$ML_DEV_STATE/seed.json"
sudo -n -u postgres psql -X -At -d "$ML_DEV_DB" -c "SELECT json_object_agg(split_part(address, '@', 1), id) FROM mailboxes" > "$ML_P2_SEED"
export ML_IMAP_CONTROL="$ML_DEV_STATE/imap-control" ML_IMAP_STATS="$ML_DEV_STATE/imap-stats.json"
if [ "${ML_S3_HOLD:-0}" = "1" ]; then trap - EXIT; echo "held: servers up, seed $(cat "$ML_P2_SEED")"; exit 0; fi   # development only: leave the servers up
status=0
echo "== door"; "$PY" -I tests/phase3/slice3/door.py || status=1
echo "== reading"; php tests/phase3/slice3/reading.php || status=1
echo "== browser"; node tests/phase3/slice3/browser.mjs || status=1
echo "== live (spec step 9)"; "$PY" -I tests/phase3/slice3/live.py || status=1
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 3 PROOFS PASSED" || echo "SOME SLICE 3 PROOFS FAILED"
exit $status
