#!/usr/bin/env bash
# Phase 2's proof (docs/build-specs/sso-shell.md "Proof"), on a fresh SCRATCH database (ml_dev2; never the installed one):
#   tests/phase2/run.sh                 the application under php -S with tests/dev_router.php
#   ML_APP=apache tests/phase2/run.sh   the application from a real Apache (as your user) rendering deploy/apache-malumail.conf
# Needs `sudo -n -u postgres` (scratch ml_* databases and the malumail_* roles only), php, the application's venv (mcp/venv, for the mail
# service), and node with the kernel's web/node_modules (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS.
# Nothing here reaches MaluMail: the Mailbox MCP is the fixture's fake, the kernel a fake.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export P_APP=${P_APP:-9201} P_KERNEL=${P_KERNEL:-9202} P_FAKE=${P_FAKE:-9203} P_MAILER=${P_MAILER:-9206} P_INTERNAL=${P_INTERNAL:-9205}
export ML_DEV_DB=${ML_DEV_DB:-ml_dev2} ML_DEV_ENV=${ML_DEV_ENV:-/tmp/ml-dev2.env} ML_DEV_STATE=${ML_DEV_STATE:-/tmp/ml-dev2-state} SHOTS=${SHOTS:-/tmp/ml-shots}
export ML_DEV_STORAGE=${ML_DEV_STORAGE:-/tmp/ml-dev2-storage} ML_P2_SEED=${ML_P2_SEED:-/tmp/ml-dev2-seed.json}
mkdir -p "$SHOTS" "$ML_DEV_STATE"
for port in $P_APP $P_KERNEL $P_FAKE $P_MAILER; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
# the scratch mail service talks to this run's own ports
sed -i "s/^ML_MAILER_PORT=.*/ML_MAILER_PORT=$P_MAILER/" "$ML_DEV_ENV"
cd "$ROOT"
set -a; . "$ML_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$P_APP" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$ML_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$ML_DEV_STATE/maludb.log" ML_MAILER_PORT=$P_MAILER
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
# the mailboxes (design §3): Priya's own, Dana's own, sales@ shared with Dana at view, the Expert's agent mailbox overseen by Priya; everyone
# but Dana (30) has read the notice
sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -X -d "$ML_DEV_DB" >/dev/null <<'SQL' || { echo "seeding failed"; exit 1; }
INSERT INTO ml_domains (domain, served, internal) VALUES ('business.example', true, true);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES
 ('priya@business.example', 'SMOKE Priya', 'person', 26), ('dana@business.example', 'SMOKE Dana', 'person', 30),
 ('sales@business.example', 'SMOKE Sales', 'shared', NULL), ('expert@business.example', 'SMOKE Expert', 'agent', 40);
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT id, 'member', 30, 'view', 1 FROM mailboxes WHERE address = 'sales@business.example';
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT id, 'member', 26, 'full', 1 FROM mailboxes WHERE address = 'expert@business.example';
UPDATE mailboxes SET primary_overseer_id = 26 WHERE address = 'expert@business.example';
-- an agent's mailbox has its policy (ml_mailbox_create makes it; slice 5's policy page reads it)
INSERT INTO agent_mail_policies (mailbox_id, send_mode, first_contact_review, attachments_review, max_external_per_day, max_per_hour, max_per_thread_per_day, wake_on_mail, wake_from, disclosure, updated_by)
SELECT b.id, s.default_send_mode, s.default_first_contact_review, s.default_attachments_review, s.default_max_external_per_day, s.default_max_per_hour,
       s.default_max_per_thread_per_day, s.default_wake_on_mail, s.default_wake_from, s.default_disclosure, 1 FROM mailboxes b, ml_settings s WHERE b.kind = 'agent';
INSERT INTO ml_notice_acknowledgements (member_id, notice_version) SELECT m.id, 1 FROM members m WHERE m.member_kind = 'human' AND m.id <> 30;
SQL
sudo -n -u postgres psql -X -At -d "$ML_DEV_DB" -c "SELECT json_object_agg(split_part(address, '@', 1), id) FROM mailboxes" > "$ML_P2_SEED"
status=0
for p in shell vhost; do
  echo "== $p"; php "tests/phase2/$p.php" || status=1
done
echo "== browser"; node tests/phase2/browser.mjs || status=1
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
echo "== approvals"; php bin/sync_approvals.php --check >/dev/null 2>&1 && echo "  ok   maludb-os.json approvals[] matches the manifest" || { echo "  FAIL approvals[] is stale"; status=1; }
[ $status -eq 0 ] && echo "ALL PHASE 2 PROOFS PASSED" || echo "SOME PHASE 2 PROOFS FAILED"
exit $status
