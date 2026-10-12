"""A FAKE of MaluMail's Mailbox MCP (research 01 §2) for the mail service's proofs: `POST /mcp`, JSON-RPC `tools/call`, the reply's
JSON as the text of `result.content[0]`, a refusal as `isError: true` with a sentence, 401 for a wrong key. The admin tools only —
the content tools answer as the real server would, but the mail service must never call them (the proof checks the call log).

  fake_mailbox_mcp.py --key <customer key> --port-file p --log calls.jsonl --control dir [--port 0]

Hosted domains: business.example (the proofs serve it) and elsewhere.example (hosted by the same MaluMail customer, NOT served by
the application — the wall). Every password it issues is written to <control>/passwords.jsonl, so a proof can show the sealed box
opens to it and that it appears nowhere else; the IMAP fixture reads the same file
(--passwords), so a reset password logs in as it would at MaluMail. Switches, as files in <control>: `key-refused` (403, a key without Mailbox MCP),
`down` (500). A mailbox created with local part `renamed` answers its password under another name (`initial_password`) — the
fail-closed check.
"""

import argparse
import json
import secrets
import string
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

LOCK = threading.Lock()
STATE = {
    "domains": [{"domain": "business.example", "receiving": True, "spf": True, "dkim": True, "dmarc": True},
                {"domain": "elsewhere.example", "receiving": True, "spf": True, "dkim": False, "dmarc": False}],
    "mailboxes": {"pat@business.example": {"active": True, "quota_gib": 2},
                  "info@business.example": {"active": True, "quota_gib": 2},          # at MaluMail, not yet in the webmail (connect)
                  "desk@business.example": {"active": True, "quota_gib": 2}},          # an intake mailbox: Help Desk's webhook (connect proposes)
    "aliases": {},
    "webhooks": {"pat@business.example": {"url": "https://helpdesk.example/hook", "active": True, "secret": "whsec_fake"},
                 "desk@business.example": {"url": "https://helpdesk.business.example/api/inbound/malumail", "active": True, "secret": "whsec_desk"}},
}
CONTENT_TOOLS = {"list_messages", "read_message", "search_messages", "send_message", "move_message", "mark_message"}


def password() -> str:
    return "".join(secrets.choice(string.ascii_letters + string.digits) for _ in range(24))


def tool_call(name: str, a: dict, control: Path):
    """(result dict, error sentence or None)"""
    if name == "list_hosted_domains":
        return {"domains": STATE["domains"]}, None
    if name == "list_mailboxes":
        d = a.get("domain", "")
        return {"mailboxes": [{"address": k, **v} for k, v in STATE["mailboxes"].items() if k.endswith("@" + d)]}, None
    if name == "create_mailbox":
        address = f"{a.get('local_part')}@{a.get('domain')}"
        if address in STATE["mailboxes"] or address in STATE["aliases"]:
            return None, f"An address {address} already exists as a mailbox or an alias."
        if not any(x["domain"] == a.get("domain") for x in STATE["domains"]):
            return None, f"The domain {a.get('domain')} is not hosted by this customer."
        STATE["mailboxes"][address] = {"active": True, "quota_gib": int(a.get("quota_gib") or 2)}
        pw = password()
        with (control / "passwords.jsonl").open("a") as f:
            f.write(json.dumps({"address": address, "password": pw}) + "\n")
        field = "initial_password" if a.get("local_part") == "renamed" else "password"
        return {"address": address, "quota_gib": STATE["mailboxes"][address]["quota_gib"], "created": "2026-10-11", field: pw}, None
    if name == "reset_mailbox_password":
        if a.get("address") not in STATE["mailboxes"]:
            return None, f"No mailbox {a.get('address')}."
        pw = password()
        with (control / "passwords.jsonl").open("a") as f:
            f.write(json.dumps({"address": a["address"], "password": pw}) + "\n")
        return {"address": a["address"], "password": pw}, None
    if name in ("set_mailbox_quota", "set_mailbox_active"):
        if a.get("address") not in STATE["mailboxes"]:
            return None, f"No mailbox {a.get('address')}."
        STATE["mailboxes"][a["address"]].update({k: v for k, v in a.items() if k != "address"})
        return {"address": a["address"], **STATE["mailboxes"][a["address"]]}, None
    if name == "get_mailbox_webhook":
        hook = STATE["webhooks"].get(a.get("address"))
        return ({"address": a.get("address"), **hook} if hook else {"address": a.get("address"), "url": None}), None
    if name == "list_aliases":
        return {"aliases": [{"address": k, "forwards_to": v} for k, v in STATE["aliases"].items() if k.endswith("@" + a.get("domain", ""))]}, None
    if name == "create_alias":
        address = f"{a.get('local_part')}@{a.get('domain')}"
        if address in STATE["mailboxes"] or address in STATE["aliases"]:
            return None, f"An address {address} already exists as a mailbox or an alias."
        STATE["aliases"][address] = list(a.get("forwards_to") or [])
        return {"address": address, "forwards_to": STATE["aliases"][address]}, None
    if name == "update_alias":
        STATE["aliases"][a.get("address")] = list(a.get("forwards_to") or [])
        return {"address": a.get("address"), "forwards_to": STATE["aliases"][a.get("address")]}, None
    if name == "delete_alias":
        STATE["aliases"].pop(a.get("address"), None)
        return {"address": a.get("address"), "deleted": True}, None
    if name in CONTENT_TOOLS or name.endswith("_webhook"):
        return {"note": "the fake answers, but the mail service must never have asked"}, None
    return None, f"Unknown tool: {name}"


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _send(self, code: int, obj):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        self._send(405, {"error": "GET is not served"})

    def do_POST(self):
        cfg = self.server.cfg
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length") or 0)) or b"{}")
        name = ((body.get("params") or {}).get("name")) if isinstance(body, dict) else None
        args = ((body.get("params") or {}).get("arguments")) or {}
        with LOCK:
            with Path(cfg["log"]).open("a") as f:
                f.write(json.dumps({"tool": name, "args": args, "bearer_ok": self.headers.get("Authorization") == "Bearer " + cfg["key"]}) + "\n")
        if self.path != "/mcp":
            return self._send(404, {"error": "not found"})
        if (cfg["control"] / "down").exists():
            return self._send(500, {"error": "fake outage"})
        if self.headers.get("Authorization") != "Bearer " + cfg["key"]:
            return self._send(401, {"error": "invalid API key"})
        if (cfg["control"] / "key-refused").exists():
            return self._send(403, {"error": "this key has no Mailbox MCP"})
        if body.get("method") != "tools/call":
            return self._send(200, {"jsonrpc": "2.0", "id": body.get("id"), "error": {"code": -32601, "message": "Method not found"}})
        with LOCK:
            result, err = tool_call(str(name), args if isinstance(args, dict) else {}, cfg["control"])
        text = err if err else json.dumps(result)
        self._send(200, {"jsonrpc": "2.0", "id": body.get("id"),
                         "result": {"content": [{"type": "text", "text": text}], "isError": err is not None}})


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--key", required=True)
    ap.add_argument("--port", type=int, default=0)
    ap.add_argument("--port-file", required=True)
    ap.add_argument("--log", required=True)
    ap.add_argument("--control", required=True)
    a = ap.parse_args()
    srv = ThreadingHTTPServer(("127.0.0.1", a.port), Handler)
    srv.cfg = {"key": a.key, "log": a.log, "control": Path(a.control)}
    Path(a.control).mkdir(parents=True, exist_ok=True)
    Path(a.port_file).write_text(str(srv.server_address[1]))
    srv.serve_forever()


if __name__ == "__main__":
    main()
