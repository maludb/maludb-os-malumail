#!/usr/bin/env bash
# The sanitiser's proof (design §6.4 layer one, research 04 Part A): DOMPurify 3.4.16's 223 cases (corpus/dompurify, the owner's
# choice 2026-10-11, read as data) and a benign corpus of business mail through services/mailer/sanitise.py — statically
# (check.py: the allow-lists, a fixed point, the benign expectations, timings) and in headless Chromium (browser.mjs: the raw
# payloads as a control, the sanitised bodies with no help, the raw payloads behind the body response's CSP and sandbox).
# Nothing reaches the network or a database. Needs the application's venv (python3 -m venv mcp/venv &&
# mcp/venv/bin/pip install -r mcp/requirements.txt) and Node with the kernel's web/node_modules (Playwright + Chromium).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PY=${ML_PY:-$ROOT/mcp/venv/bin/python}
if ! "$PY" -c 'import nh3' 2>/dev/null; then echo "FAIL no nh3 in $PY — build mcp/venv from mcp/requirements.txt"; exit 1; fi
TMP=$(mktemp -d /tmp/ml-sanitiser.XXXXXX); trap 'rm -rf "$TMP"' EXIT
status=0
echo "== static"; "$PY" -I "$ROOT/tests/sanitiser/check.py" "$TMP/cases.json" || status=1
if [ -s "$TMP/cases.json" ]; then echo "== browser"; node "$ROOT/tests/sanitiser/browser.mjs" "$TMP/cases.json" || status=1
else echo "FAIL the static step wrote no cases; the browser step did not run"; status=1; fi
exit $status
