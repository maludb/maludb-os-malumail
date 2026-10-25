#!/usr/bin/env bash
# tests/phase4/mcp_servers.sh start|stop — the two MCP servers on SCRATCH loopback ports from the scratch environment ($ML_DEV_ENV):
# records :9204, activity :9208 (never the installed application's ports or services). Pid files live in $ML_DEV_STATE; stopped by port, never by a pattern.
# The Python is the application's own mcp/venv. The records server is started WITHOUT the MaluDB token in its environment (it never needs it).
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${ML_DEV_ENV:-/tmp/ml-dev11.env}
STATE=${ML_DEV_STATE:-/tmp/ml-dev11-state}
PY=${ML_PY:-$ROOT/mcp/venv/bin/python}
mkdir -p "$STATE"
stop() {
  for f in "$STATE"/mcp-*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  for i in $(seq 1 30); do ss -ltn 2>/dev/null | grep -qE ':(9204|9208) ' || break; sleep 0.2; done
  fuser -k -n tcp 9204 >/dev/null 2>&1 || true; fuser -k -n tcp 9208 >/dev/null 2>&1 || true
}
case "${1:-}" in
  stop) stop ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    export ML_DEV_ENV="$ENVF" OS_INTERNAL_URL="http://127.0.0.1:9202" MCP_RECORDS_PORT=9204 MCP_ACTIVITY_PORT=9208 ML_MAILER_PORT=${P_MAILER:-9206}
    ( cd "$ROOT/mcp" && exec env -u MALUDB_API_TOKEN -u MALUDB_API_URL "$PY" "$ROOT/mcp/records_mcp.py" >"$STATE/mcp-records.log" 2>&1 ) & echo $! > "$STATE/mcp-records.pid"
    ( cd "$ROOT/mcp" && exec "$PY" "$ROOT/mcp/activity_mcp.py" >"$STATE/mcp-activity.log" 2>&1 ) & echo $! > "$STATE/mcp-activity.pid"
    for p in 9204 9208; do
      for i in $(seq 1 100); do curl -s -o /dev/null -m 1 "http://127.0.0.1:$p/mcp" && break; sleep 0.2; done
    done
    echo "mcp servers up (records :9204, activity :9208)" ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
