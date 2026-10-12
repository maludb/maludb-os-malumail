"""The mail service's Phase 0 checks, run by tests/mailer/run.sh once the scratch database, the fixtures and the service on 9206 are
up. Signed calls are made with services/mailer/door.py's own sign(); the database is read as postgres (the scratch ml_mailer)."""

import base64
import json
import os
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))

import httpx  # noqa: E402

from services.mailer import door, seal  # noqa: E402
from services.mailer.config import Config, ConfigError, _read_env_file  # noqa: E402

TMP = Path(os.environ["ML_TMP"])
ENV = _read_env_file(Path(os.environ["ML_DEV_ENV"]))
MENV = _read_env_file(TMP / "ml-mailer-mailer.env")
KEY = MENV["ML_MAILER_KEY"].encode()
SECRET = base64.b64decode(MENV["ML_SEAL_SECRET_KEY"])
BASE = "http://127.0.0.1:9206"
passed = failed = 0


def check(cond, label, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"ok   {label}")
    else:
        failed += 1
        print(f"FAIL {label}" + (f" — {detail}" if detail else ""))


def sql(q: str) -> str:
    return subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-d", os.environ["ML_DEV_DB"], "-c", q],
                          capture_output=True, text=True).stdout.strip()


def call(path: str, actor, args=None, *, base=BASE, header=None, body=None, sign_path=None):
    raw = body if body is not None else json.dumps({"actor": actor, "args": args or {}}).encode()
    h = header if header is not None else door.sign(KEY, "POST", sign_path or path, raw)
    r = httpx.post(base + path, content=raw, headers={door.HEADER: h, "Content-Type": "application/json"}, timeout=30)
    try:
        return r.status_code, r.json(), r.text
    except ValueError:
        return r.status_code, {}, r.text


def mcp_calls() -> list:
    p = TMP / "mcp.jsonl"
    return [json.loads(x) for x in p.read_text().splitlines()] if p.exists() else []


def passwords() -> list:
    p = TMP / "mcp" / "passwords.jsonl"
    return [json.loads(x) for x in p.read_text().splitlines()] if p.exists() else []


def stats(name="imap-stats.json") -> dict:
    return json.loads((TMP / name).read_text())


# ---- the door ------------------------------------------------------------------------------------------------------------------
h = httpx.get(BASE + "/health", timeout=10).json()
check(h.get("status") == "up" and h.get("database") and h.get("sealing_key") and h.get("customer_key"),
      "health answers unsigned: up, the database reached, the sealing key and the customer key held", json.dumps(h))
check(not any(k in json.dumps(h).lower() for k in ("password", MENV["MALUMAIL_MAILBOX_API_KEY"].lower())), "health says counts only, no secret")
s, j, _ = call("/v1/admin/list_hosted_domains", 1, header="")
check(s == 401 and j["error"]["code"] == "unsigned", "a call without a signature is refused (401)")
good = json.dumps({"actor": 1, "args": {}}).encode()
s, *_ = call("/v1/admin/list_hosted_domains", 1, body=good, header=door.sign(b"x" * 64, "POST", "/v1/admin/list_hosted_domains", good))
check(s == 401, "a call signed with another key is refused")
s, *_ = call("/v1/admin/list_hosted_domains", 1, body=good, header=door.sign(KEY, "POST", "/v1/admin/list_hosted_domains", good, ts=int(time.time()) - 120))
check(s == 401, "a call signed two minutes ago is refused (the window is 60 s)")
hdr = door.sign(KEY, "POST", "/v1/admin/list_hosted_domains", good)
s1, *_ = call("/v1/admin/list_hosted_domains", 1, body=good, header=hdr)
s2, *_ = call("/v1/admin/list_hosted_domains", 1, body=good, header=hdr)
check(s1 == 200 and s2 == 401, "a signed call is answered once; the same call replayed is refused", f"{s1}, {s2}")
s, *_ = call("/v1/admin/list_mailboxes", 1, body=good, sign_path="/v1/admin/list_hosted_domains")
check(s == 401, "a signature for one path does not open another")
s, *_ = call("/v1/admin/list_hosted_domains", 1, body=json.dumps({"actor": 2, "args": {}}).encode(),
             header=door.sign(KEY, "POST", "/v1/admin/list_hosted_domains", good))
check(s == 401, "a body changed after signing is refused (the actor cannot be swapped)")
s, j, _ = call("/v1/admin/list_hosted_domains", 999)
check(s == 403 and j["error"]["code"] == "unknown_member", "a member the directory does not hold is refused")
s, j, _ = call("/v1/admin/list_hosted_domains", 4)
check(s == 403 and j["error"]["code"] == "unknown_member", "an inactive member is refused, super-admin or not")

# ---- administration ------------------------------------------------------------------------------------------------------------
before = len(mcp_calls())
s, j, _ = call("/v1/admin/list_mailboxes", 2, {"domain": "business.example"})
check(s == 403 and j["error"]["code"] == "forbidden", "a Mail user is refused administration (403)")
s, j, _ = call("/v1/admin/list_mailboxes", 3, {"domain": "business.example"})
check(s == 403, "an agent holding the Mail admin role is still refused: administration is a super-admin's (held_by)")
s, j, _ = call("/v1/admin/read_message", 1, {"address": "pat@business.example", "folder": "INBOX", "uid": 1})
check(s == 422 and "IMAP" in j["error"]["message"], "a content tool is refused: mail goes through IMAP and SMTP here")
s, j, _ = call("/v1/admin/set_mailbox_webhook", 1, {"address": "pat@business.example", "url": "https://x.example/h"})
check(s == 422 and "webhook" in j["error"]["message"], "setting a webhook is refused: a mailbox's one webhook belongs to its intake reader")
s, j, _ = call("/v1/admin/drop_everything", 1, {})
check(s == 422, "a tool MaluMail's administration does not have here is refused")
s, j, _ = call("/v1/admin/list_mailboxes", 1, {"domain": "business.example", "extra": 1})
check(s == 422 and "takes domain" in j["error"]["message"], "an argument the tool does not take is refused")
s, j, _ = call("/v1/admin/list_mailboxes", 1, {"domain": "elsewhere.example"})
check(s == 422 and "does not serve" in j["error"]["message"], "a domain the business does not serve is refused (the wall over the customer key)")
s, j, _ = call("/v1/admin/set_mailbox_active", 1, {"address": "someone@elsewhere.example", "active": False})
check(s == 422, "…and so is an address on it")
check(len(mcp_calls()) == before, "none of the refused calls reached MaluMail", f"{len(mcp_calls()) - before} reached it")

s, j, _ = call("/v1/admin/list_hosted_domains", 1)
check(s == 200 and {d["domain"] for d in j["result"]["domains"]} == {"business.example", "elsewhere.example"},
      "a Mail admin lists the customer's hosted domains (the one call that names unserved ones)")
s, j, _ = call("/v1/admin/list_mailboxes", 1, {"domain": "business.example"})
check(s == 200 and any(m["address"] == "pat@business.example" for m in j["result"]["mailboxes"]), "a Mail admin lists a served domain's mailboxes")
check(all(c["bearer_ok"] for c in mcp_calls()), "every call reached MaluMail with the customer key as its bearer")

s, j, text = call("/v1/admin/create_mailbox", 1, {"domain": "business.example", "local_part": "newagent", "quota_gib": 2})
issued = [p["password"] for p in passwords() if p["address"] == "newagent@business.example"]
cred = (j.get("result") or {}).get("credential") or {}
check(s == 200 and j["result"]["address"] == "newagent@business.example" and cred.get("kind") == "mailbox_password",
      "a Mail admin creates a mailbox", text[:200])
check(issued and issued[0] not in text and "password" not in json.dumps(j["result"]).replace("mailbox_password", ""),
      "the new password is NOT in the reply — only its sealed box")
opened = seal.open_sealed(SECRET, base64.b64decode(cred.get("sealed", ""))) if cred.get("sealed") else None
check(opened == (issued[0] if issued else "x"), "the sealed box opens, with the service's secret key, to exactly the password MaluMail issued")
check(cred.get("last4") == seal.last4(base64.b64decode(cred.get("sealed", ""))), "last4 is of the sealed box's SHA-256, never of the secret")
s, j, _ = call("/v1/admin/create_mailbox", 1, {"domain": "business.example", "local_part": "newagent", "quota_gib": 2})
check(s == 422 and "already exists" in j["error"]["message"], "MaluMail's refusal comes back as its own sentence (422)")
s, j, text = call("/v1/admin/create_mailbox", 1, {"domain": "business.example", "local_part": "renamed", "quota_gib": 2})
renamed = [p["password"] for p in passwords() if p["address"] == "renamed@business.example"]
check(s == 502 and j["error"]["code"] == "protocol" and renamed and renamed[0] not in text,
      "a reply with the password under a name not foreseen is refused, and the password goes nowhere (fail closed)", text[:160])
s, j, text = call("/v1/admin/get_mailbox_webhook", 1, {"address": "pat@business.example"})
check(s == 200 and "secret" not in j["result"] and "whsec_fake" not in text, "a webhook's secret is stripped from any reply")
s, j, _ = call("/v1/admin/create_alias", 1, {"domain": "business.example", "local_part": "sales", "forwards_to": ["pat@business.example"]})
check(s == 200 and j["result"]["forwards_to"] == ["pat@business.example"], "a Mail admin creates a forwarding address")

(TMP / "mcp" / "key-refused").touch()
s, j, _ = call("/v1/admin/list_mailboxes", 1, {"domain": "business.example"})
(TMP / "mcp" / "key-refused").unlink()
check(s == 502 and j["error"]["code"] == "key" and "customer key" in j["error"]["message"], "MaluMail refusing the customer key is said as such (502)")
(TMP / "mcp" / "down").touch()
s, j, _ = call("/v1/admin/list_mailboxes", 1, {"domain": "business.example"})
(TMP / "mcp" / "down").unlink()
check(s == 502 and j["error"]["code"] == "unreachable", "MaluMail failing is 502 unreachable")
logged = sql("select count(*) filter (where after->>'ok' = 'true') || ',' || count(*) filter (where after->>'ok' = 'false') "
             "from activity_log where action like 'mailer.admin.%'")
check(logged.split(",")[0] not in ("", "0") and logged.split(",")[1] not in ("", "0"),
      f"administration calls are in the activity log, done and refused by MaluMail ({logged})")
all_pw = [p["password"] for p in passwords()]
check(not any(pw in sql("select coalesce(string_agg(after::text, ' '), '') from activity_log") for pw in all_pw),
      "no issued password is in the activity log")

# ---- the IMAP probe and the circuit breaker --------------------------------------------------------------------------------------
mb = {a: int(sql(f"select id from mailboxes where address = '{a}'")) for a in
      ("pat@business.example", "clerk@business.example", "busy@business.example", "nocred@business.example")}
s, j, _ = call(f"/v1/mailboxes/{mb['pat@business.example']}/probe", 2)
check(s == 403, "a Mail user cannot test a mailbox's connection (a Mail admin's)")
s, j, text = call(f"/v1/mailboxes/{mb['pat@business.example']}/probe", 1)
r = j.get("result") or {}
check(s == 200 and "CONDSTORE" in r.get("capabilities", []), "the probe logs in over TLS with the sealed credential, opened here", text[:200])
check({f["name"] for f in r.get("folders", [])} == {"INBOX", "Drafts", "Sent", "Junk", "Trash"}
      and any("\\Sent" in f["flags"] for f in r.get("folders", [])), "it reads the five folders with their special-use flags")
check(r.get("inbox", {}).get("messages") == 12 and r["inbox"].get("unseen") == 6 and r["inbox"].get("highestmodseq", 0) > 0,
      "it reads INBOX's counts, HIGHESTMODSEQ with CONDSTORE")
check(sql(f"select last_ok_at is not null from mailbox_credentials where mailbox_id = {mb['pat@business.example']}") == "t",
      "a good login stamps the credential's last_ok_at")

clerk = mb["clerk@business.example"]
s, j, _ = call(f"/v1/mailboxes/{clerk}/probe", 1)
check(s == 409 and j["error"]["code"] == "credential_failed", "a refused credential: 409, never tried again")
check(sql(f"select c.status || ',' || (c.failed_at is not null) || ',' || m.sync_state from mailbox_credentials c join mailboxes m on m.id = c.mailbox_id where m.id = {clerk}")
      == "failed,true,failed", "the circuit breaker: the credential is failed and the mailbox's sync stops")
check(sql(f"select string_agg(member_id::text, ',' order by member_id) from notifications where kind = 'credential_failed' and mailbox_id = {clerk}") == "1",
      "every Mail admin is told — Ada — and no one else (not the Mail user, not the agent)")
check(sql(f"select count(*) from activity_log where action = 'mailbox_credential.failed' and mailbox_id = {clerk}") == "1", "the trip is in the activity log")
s, j, _ = call(f"/v1/mailboxes/{clerk}/probe", 1)
check(s == 409 and stats()["clerk@business.example"]["attempts"] == 1,
      "asked again, the service answers from the breaker: the mail server saw ONE login attempt", json.dumps(stats().get("clerk@business.example")))
check(sql(f"select count(*) from notifications where kind = 'credential_failed' and mailbox_id = {clerk}") == "1", "…and no one is told twice")
rc = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-d", os.environ["ML_DEV_DB"], "-c",
                     f"set role malumail_rw; update mailbox_credentials set status = 'ok', failed_at = null where mailbox_id = {clerk}"],
                    capture_output=True, text=True)
check(rc.returncode != 0 and "never used again" in rc.stderr, "the database refuses to bring a failed credential back (db/006's guard)")

busy = mb["busy@business.example"]
s, j, _ = call(f"/v1/mailboxes/{busy}/probe", 1)
check(s == 502 and j["error"]["code"] == "busy" and j["error"].get("retry_after") == 30, "a busy server ([UNAVAILABLE]) backs off 30 s — the breaker stays shut")
check(sql(f"select status from mailbox_credentials where mailbox_id = {busy}") == "ok", "…the credential stays good")
s, j, _ = call(f"/v1/mailboxes/{busy}/probe", 1)
check(s == 429 and 0 < j["error"].get("retry_after", 0) <= 30 and stats()["busy@business.example"]["attempts"] == 1,
      "asked again at once: 429 with retry_after, and no second login")
s, j, _ = call(f"/v1/mailboxes/{mb['nocred@business.example']}/probe", 1)
check(s == 409 and j["error"]["code"] == "no_credential", "a mailbox with no credential says so")


# ---- TLS and an unreachable server: a second instance on 9207 --------------------------------------------------------------------
def instance(imap_port: str):
    env = {**os.environ, "ML_MAILER_PORT": "9207", "ML_IMAP_PORT": imap_port}
    p = subprocess.Popen([sys.executable, "-I", str(ROOT / "services/mailer/server.py")], env=env,
                         stdout=open(TMP / f"mailer-{imap_port}.log", "w"), stderr=subprocess.STDOUT)
    for _ in range(100):
        try:
            if httpx.get("http://127.0.0.1:9207/health", timeout=1).status_code == 200:
                break
        except httpx.HTTPError:
            time.sleep(0.1)
    return p


p = instance(os.environ["ML_ROGUE_PORT"])
s, j, _ = call(f"/v1/mailboxes/{mb['pat@business.example']}/probe", 1, base="http://127.0.0.1:9207")
p.terminate(); p.wait()
check(s == 502 and j["error"]["code"] == "tls_failed", "a server whose certificate does not check out is refused before any login", json.dumps(j))
check(stats("rogue-stats.json")["pat@business.example"]["attempts"] == 0, "…the impostor never saw the credential")
check(sql(f"select status from mailbox_credentials where mailbox_id = {mb['pat@business.example']}") == "ok", "…and a TLS failure never trips the breaker")
p = instance("1")
s, j, _ = call(f"/v1/mailboxes/{mb['pat@business.example']}/probe", 1, base="http://127.0.0.1:9207")
p.terminate(); p.wait()
check(s == 502 and j["error"]["code"] == "connect_failed", "a server that cannot be reached backs off (connect_failed)")

# ---- slice 1: create, connect and reset decided by the mailbox's kind (admin_flows.py) -----------------------------------------
def issued(address):
    return [p["password"] for p in passwords() if p["address"] == address]


s, j, text = call("/v1/admin/mailboxes/create", 1, {"domain": "business.example", "local_part": "scheduler", "kind": "agent", "display_name": "Scheduler",
                                                    "owner": 3, "primary_overseer": 2, "quota_gib": 3})
r = j.get("result") or {}
sched_pw = issued("scheduler@business.example")
check(s == 200 and r.get("kind") == "agent" and "revealed" not in r and sched_pw and sched_pw[0] not in text,
      "slice 1: an agent's mailbox is created at MaluMail and here, its password never answered", text[:200])
sched = r.get("mailbox_id")
check(sql(f"select b.kind || ',' || b.owner_member_id || ',' || b.primary_overseer_id || ',' || c.status || ',' || (b.quota_bytes / 1073741824) "
          f"from mailboxes b join mailbox_credentials c on c.id = b.credential_id where b.id = {sched or 0}") == "agent,3,2,ok,3",
      "…in one transaction: owner the agent, the primary overseer, the sealed credential live, the quota recorded")
check(seal.open_sealed(SECRET, bytes.fromhex(sql(f"select encode(c.ciphertext, 'hex') from mailboxes b join mailbox_credentials c on c.id = b.credential_id where b.id = {sched or 0}"))) == (sched_pw[0] if sched_pw else ""),
      "…and the stored box opens to the password MaluMail issued")
s, j, text = call(f"/v1/mailboxes/{sched}/probe", 1)
check(s == 200, "…and it logs in at the IMAP fixture (the issued password is the one Dovecot holds)", text[:160])

s, j, text = call("/v1/admin/mailboxes/create", 1, {"domain": "business.example", "local_part": "robin", "kind": "person", "display_name": "Robin",
                                                    "owner": 2, "quota_gib": 2})
r = j.get("result") or {}
robin_pw = issued("robin@business.example")
check(s == 200 and robin_pw and r.get("revealed") == robin_pw[0], "a person's mailbox: the new password answered ONCE as revealed (for their devices)")
check(sql(f"select (credential_id is null)::text || ',' || (select count(*) from mailbox_credentials where mailbox_id = {r.get('mailbox_id') or 0}) from mailboxes where id = {r.get('mailbox_id') or 0}") == "true,0",
      "…and nothing is stored for it (D3: no credential for a person's mailbox until MM1)")

s, j, _ = call("/v1/admin/mailboxes/create", 1, {"domain": "business.example", "local_part": "lonely", "kind": "agent", "owner": 3})
check(s == 422 and "oversees" in j["error"]["message"], "an agent's mailbox with no overseer is refused before MaluMail is asked")
s, j, _ = call("/v1/admin/mailboxes/create", 1, {"domain": "business.example", "local_part": "wrongowner", "kind": "person", "owner": 3})
check(s == 422 and "belongs to a person" in j["error"]["message"], "a person's mailbox owned by an agent is refused before MaluMail is asked")
s, j, _ = call("/v1/admin/mailboxes/create", 1, {"domain": "elsewhere.example", "local_part": "x", "kind": "shared"})
check(s == 422 and "does not serve" in j["error"]["message"], "a mailbox on an unserved domain is refused")
s, j, _ = call("/v1/admin/mailboxes/create", 2, {"domain": "business.example", "local_part": "x", "kind": "shared"})
check(s == 403, "a Mail user cannot create a mailbox")
check(not issued("lonely@business.example") and not issued("wrongowner@business.example") and not issued("x@business.example"),
      "none of the refused creates reached MaluMail")

s, j, text = call("/v1/admin/mailboxes/connect", 1, {"address": "info@business.example", "kind": "shared", "display_name": "Info"})
r = j.get("result") or {}
info_pw = issued("info@business.example")
check(s == 200 and r.get("probe") == "ok" and info_pw and info_pw[-1] not in text,
      "connecting an existing shared mailbox resets its password at MaluMail, stores it sealed and logs in with it", text[:200])
s, j, text = call("/v1/admin/mailboxes/connect", 1, {"address": "desk@business.example", "kind": "shared", "display_name": "Desk"})
r = j.get("result") or {}
check(s == 200 and r.get("intake_proposed") == "helpdesk" and sql(f"select intake_proposed from mailboxes where id = {r.get('mailbox_id') or 0}") == "helpdesk",
      "a mailbox with Help Desk's webhook is PROPOSED as Help Desk's intake (a Mail admin confirms)", text[:200])
s, j, _ = call("/v1/admin/mailboxes/connect", 1, {"address": "ghost@business.example", "kind": "shared"})
check(s == 422 and "has no mailbox" in j["error"]["message"], "connecting a mailbox MaluMail does not have is refused")
info_id = sql("select id from mailboxes where address = 'info@business.example'")
s, j, text = call("/v1/admin/mailboxes/connect", 1, {"address": "info@business.example", "kind": "shared"})
check(s == 200 and (j.get("result") or {}).get("reconnected") and j["result"].get("probe") == "ok" and issued("info@business.example")[-1] not in text
      and sql(f"select string_agg(status, ',' order by id) from mailbox_credentials where mailbox_id = {info_id}") == "revoked,ok",
      "connecting one already here RECONNECTS it: a new password at MaluMail, stored, the old revoked, probed", text[:200])
s, j, _ = call("/v1/admin/mailboxes/connect", 1, {"address": "robin@business.example", "kind": "person"})
check(s == 422 and "MM1" in j["error"]["message"], "a person's mailbox is never reconnected (it waits on MM1)")

s, j, text = call("/v1/admin/reset_mailbox_password", 1, {"address": "pat@business.example"})
reset = issued("pat@business.example")
check(s == 200 and reset and j["result"].get("revealed") == reset[-1] and "credential" not in j["result"],
      "a reset of a PERSON's mailbox (the kind read from the database) reveals the new password once, stores nothing")
before = sql(f"select count(*) from mailbox_credentials where mailbox_id = {sched or 0}")
s, j, text = call("/v1/admin/reset_mailbox_password", 1, {"address": "scheduler@business.example"})
again = issued("scheduler@business.example")
check(s == 200 and again and again[-1] not in text and "revealed" not in j["result"] and j["result"].get("credential", {}).get("stored") is True,
      "a reset of an AGENT's mailbox never answers the password: it is sealed and stored")
check(sql(f"select string_agg(status, ',' order by id) from mailbox_credentials where mailbox_id = {sched or 0}") == "revoked,ok" and before == "1",
      "…the old credential revoked, the new one live")


# ---- configuration, the passes, the listening address, the log ----------------------------------------------------------------
try:
    Config.load({**ENV, **MENV, "APP_ENV": "prod", "ML_TLS_CA_FILE": "/tmp/ca.pem"})
    refused = False
except ConfigError:
    refused = True
check(refused, "a proofs-only certificate authority is refused in production (APP_ENV=prod)")
try:
    Config.load({**ENV, **MENV, "ML_MAILER_KEY": "short"})
    refused = False
except ConfigError:
    refused = True
check(refused, "a door key shorter than 32 characters is refused at start")
time.sleep(1.5)
h = httpx.get(BASE + "/health", timeout=10).json()
syncable = int(sql("select count(*) from mailboxes m join mailbox_credentials c on c.id = m.credential_id where m.active and c.status = 'ok'"))
check(h["passes"]["sync"]["last_run_at"] and h["passes"]["sync"]["mailboxes"] == syncable and syncable >= 2 and h["passes"]["outbox"]["due"] == 0,
      f"the empty passes run: the sync pass counts the {syncable} mailboxes with a live credential (not the failed clerk), the outbox 0 due",
      json.dumps(h["passes"]))
check(h["credentials_failed"] == 1, "health counts the one failed credential")
ss = subprocess.run(["ss", "-ltnH", "sport = :9206"], capture_output=True, text=True).stdout
check("127.0.0.1:9206" in ss and "0.0.0.0:9206" not in ss and "*:9206" not in ss, "the service listens on loopback only", ss.strip())
logs = "".join(f.read_text() for f in TMP.glob("mailer*.log"))
secrets_ = all_pw + [os.environ[k] for k in ("ML_PW_PAT", "ML_PW_CLERK", "ML_PW_WRONG", "ML_PW_BUSY")] + [MENV["MALUMAIL_MAILBOX_API_KEY"], MENV["ML_SEAL_SECRET_KEY"], MENV["ML_MAILER_KEY"]]
check(not any(x in logs for x in secrets_), "no password, customer key, sealing key or door key in the service's log")
check("Traceback" not in logs, "no traceback in the service's log", logs[-400:])

print(f"\n{passed} ok, {failed} failed")
sys.exit(1 if failed else 0)
