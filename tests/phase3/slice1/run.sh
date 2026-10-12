#!/usr/bin/env bash
# Slice 1's proof — domains, mailboxes and forwarding, THE CRUD EXEMPLAR (docs/build-specs/domains-mailboxes.md "Proof"), on a fresh SCRATCH
# database (ml_dev1; never the installed one):
#   tests/phase3/slice1/run.sh                 the application under php -S with tests/dev_router.php
#   ML_APP=apache tests/phase3/slice1/run.sh   the application from a real Apache (as your user) rendering deploy/apache-malumail.conf
# The servers are Phase 2's (tests/phase2/servers.sh) with the IMAP fixture on (ML_WITH_IMAP=1): the mail service against the fake Mailbox MCP,
# whose issued passwords the IMAP fixture accepts as MaluMail's server would. Nothing here reaches MaluMail. Step 9 of the spec (the live
# check on the test domain) runs only with a customer key the infrastructure side scoped to the test domain — none exists yet, so it is
# skipped with one line. Needs `sudo -n -u postgres` (scratch ml_* databases and the malumail_* roles), php, the venv, node + Playwright.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export P_APP=${P_APP:-9201} P_KERNEL=${P_KERNEL:-9202} P_FAKE=${P_FAKE:-9203} P_MAILER=${P_MAILER:-9206} P_INTERNAL=${P_INTERNAL:-9205}
export ML_DEV_DB=${ML_DEV_DB:-ml_dev1} ML_DEV_ENV=${ML_DEV_ENV:-/tmp/ml-dev1.env} ML_DEV_STATE=${ML_DEV_STATE:-/tmp/ml-dev1-state} SHOTS=${SHOTS:-/tmp/ml-shots-s1}
export ML_DEV_STORAGE=${ML_DEV_STORAGE:-/tmp/ml-dev1-storage} ML_P2_SEED=${ML_P2_SEED:-/tmp/ml-dev1-seed.json} ML_WITH_IMAP=1
rm -rf "$ML_DEV_STATE"; mkdir -p "$SHOTS" "$ML_DEV_STATE"
for port in $P_APP $P_KERNEL $P_FAKE $P_MAILER; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i "s/^ML_MAILER_PORT=.*/ML_MAILER_PORT=$P_MAILER/" "$ML_DEV_ENV"
cd "$ROOT"
set -a; . "$ML_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$P_APP" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$ML_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$ML_DEV_STATE/maludb.log" ML_MAILER_PORT=$P_MAILER
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
# Nothing is served yet: the proof serves business.example through the screen. Every person has read the notice.
sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -X -d "$ML_DEV_DB" >/dev/null <<'SQL' || { echo "seeding failed"; exit 1; }
INSERT INTO ml_notice_acknowledgements (member_id, notice_version) SELECT m.id, 1 FROM members m WHERE m.member_kind = 'human';
SQL
echo '{}' > "$ML_P2_SEED"
status=0
echo "== admin"; php tests/phase3/slice1/admin.php || status=1
echo "== browser"; node tests/phase3/slice1/browser.mjs || status=1
echo "== live (spec step 9)"; echo "  skip the live check at MaluMail: no customer key scoped to the test domain is configured (MM7) — never the production key"
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 1 PROOFS PASSED" || echo "SOME SLICE 1 PROOFS FAILED"
exit $status
