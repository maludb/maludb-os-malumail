#!/usr/bin/env bash
# The schema proof (docs/malumail-design.md §10, Phase 0): a fresh scratch database `ml_proof`, every db/[0-9]*.sql applied in order as
# postgres, then db/proof/phase0_proof.sql (one transaction, rolled back). Re-runnable from nothing; never touches an installed database.
# The three installer roles are made here when missing (cluster-wide, NOLOGIN — the installer gives them passwords).
# `sudo -n -u postgres` is used for scratch ml_* databases and these roles only.
set -euo pipefail
cd "$(dirname "$0")/../.."
DB=${ML_PROOF_DB:-ml_proof}
case "$DB" in ml_*) ;; *) echo "refused: the proof database must be named ml_*"; exit 2 ;; esac
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -X"
sudo -n -u postgres dropdb --if-exists "$DB" >/dev/null 2>&1
sudo -n -u postgres createdb "$DB"
$PSQL -d postgres >/dev/null <<'SQL'
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'malumail_rw') THEN CREATE ROLE malumail_rw NOLOGIN; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'malumail_records_ro') THEN CREATE ROLE malumail_records_ro NOLOGIN; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'malumail_activity_ro') THEN CREATE ROLE malumail_activity_ro NOLOGIN; END IF;
END $$;
SQL
n=0
for f in db/[0-9]*.sql; do
  $PSQL -d "$DB" -f "$f" >/dev/null || { echo "MIGRATION FAILED: $f"; exit 1; }
  n=$((n + 1))
done
echo " $n migrations applied to $DB"
$PSQL -d "$DB" -f db/proof/phase0_proof.sql
