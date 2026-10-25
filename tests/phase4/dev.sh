#!/usr/bin/env bash
# tests/phase4/dev.sh <proof>... — run proofs against servers left up by ML_P4_HOLD=1 (development only).
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export ML_DEV_DB=${ML_DEV_DB:-ml_dev11} ML_DEV_ENV=${ML_DEV_ENV:-/tmp/ml-dev11.env} ML_DEV_STATE=${ML_DEV_STATE:-/tmp/ml-dev11-state}
set -a; . "$ML_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:9201" OS_INTERNAL_URL="http://127.0.0.1:9202" FAKE_KERNEL_STATE="$ML_DEV_STATE/kernel.json" ML_MAILER_PORT=9206
export ML_P2_SEED="$ML_DEV_STATE/seed.json" ML_IMAP_CONTROL="$ML_DEV_STATE/imap-control" ML_P4_URL_RECORDS="http://127.0.0.1:9204/mcp" ML_P4_URL_ACTIVITY="http://127.0.0.1:9208/mcp"
cd "$ROOT"
for p in "$@"; do echo "== $p"; "$ROOT/mcp/venv/bin/python" -I "tests/phase4/$p.py"; done
