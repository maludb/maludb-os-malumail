#!/usr/bin/env bash
# tests/phase4/kernel_ctl.sh stop|start — the fake kernel on :9202 for the fail-closed proofs (the state file is kept, so a restart answers what it answered).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
STATE=${ML_DEV_STATE:-/tmp/ml-dev11-state}
case "${1:-}" in
  stop) kill "$(cat "$STATE/kernel.pid" 2>/dev/null)" 2>/dev/null; fuser -k -n tcp 9202 >/dev/null 2>&1; sleep 0.4 ;;
  start)
    set -a; . "${ML_DEV_ENV:-/tmp/ml-dev11.env}"; set +a
    export FAKE_KERNEL_STATE="$STATE/kernel.json"
    ( cd "$ROOT" && exec php -S 127.0.0.1:9202 tests/fake_kernel.php >>"$STATE/kernel.log" 2>&1 ) & echo $! > "$STATE/kernel.pid"
    for i in $(seq 1 30); do curl -s -o /dev/null -m 1 http://127.0.0.1:9202/x && break; sleep 0.2; done ;;
  *) echo "usage: $0 stop|start" >&2; exit 2 ;;
esac
