"""Slice 3's live step (reading.md Proof 9; design §10 Phase 3): MaluMail's two TEST mailboxes, only through the owner's handoff file —
named by ML_LIVE_CREDENTIALS in the environment of whoever runs the proof (never in this repository; one line per mailbox, `address
password`); without it this prints one line and passes. Real passwords only (never a wrong one: MaluMail's abuse protection would lock
this whole machine out — a refusal stops everything at once, nothing is retried); at most ONE submission (spike02 → spike01: one HTML
message with an inline cid: image and an attachment); spike01 left as found (our message expunged by its own UID at the end; a folder the
sync made where none was, removed when empty — nothing else is touched, never a \\Seen but the one this proof's own `seen` stores on OUR
message before it is expunged).

What it proves, against MaluMail's own server through a second instance of the mail service (its own scratch database ml_dev3l, port
9209): spike01's conversation opens through `fetch` (the cid: image became a part link, the attachment is in the part list), the cid:
image and the attachment come through the mail service's `part` with the right bytes, the whole message through `raw`, archive moves it
on the real server (UID MOVE with COPYUID; Archive created by name when absent) and a move back returns it to INBOX, and at the end it is
expunged. The PHP doors are proven on the fixture (reading.php); here the service's calls are proven on the real server."""

import base64
import email.message
import email.utils
import json
import os
import shutil
import smtplib
import subprocess
import sys
import time
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT))

FAILS = PASSES = 0


def check(cond, label, detail=""):
    global FAILS, PASSES
    if cond:
        PASSES += 1
        print(f"  ok   {label}")
    else:
        FAILS += 1
        print(f"  FAIL {label}" + (f" — {detail}" if detail else ""))


handoff = os.environ.get("ML_LIVE_CREDENTIALS", "")
if not handoff or not Path(handoff).is_file():
    print("  skip the live check at MaluMail: no handoff credential file named (ML_LIVE_CREDENTIALS) — never a guessed one")
    sys.exit(0)
creds = {}
for line in Path(handoff).read_text().splitlines():
    parts = line.split()
    if len(parts) == 2 and "@" in parts[0]:
        creds[parts[0].split("@")[0]] = (parts[0].lower(), parts[1])
if "spike01" not in creds or "spike02" not in creds:
    print("  skip the live check: the handoff file does not name spike01 and spike02")
    sys.exit(0)

import httpx  # noqa: E402
from nacl.public import PublicKey, SealedBox  # noqa: E402

from services.mailer import door, imap  # noqa: E402

HOST = os.environ.get("ML_LIVE_IMAP_HOST", "mbox01.malumail.com")
SMTP_HOST = os.environ.get("ML_LIVE_SMTP_HOST", HOST)
A1, P1 = creds["spike01"]
A2, P2 = creds["spike02"]
LDB = "ml_dev3l"
PORT = 9209
TMP = Path(os.environ.get("ML_DEV_STATE", "/tmp")) / "live3"
shutil.rmtree(TMP, ignore_errors=True)
TMP.mkdir(parents=True)
PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP4z8DAwMDAxMDAwMDAAAANBAEB4u2E3wAAAABJRU5ErkJggg==")


def sql(q: str, db: str = LDB) -> str:
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-v", "ON_ERROR_STOP=1", "-d", db, "-c", q], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip())
    return r.stdout.strip()


def snapshot(c) -> dict:
    folders = sorted(f["name"] for f in imap.list_status(c))
    st = c.folder_status("INBOX", ["MESSAGES", "UIDNEXT", "UNSEEN"])
    return {"folders": folders, "messages": int(st[b"MESSAGES"]), "unseen": int(st[b"UNSEEN"])}


# ---- 0. as found ---------------------------------------------------------------------------------------------------------------------
c, refused = imap.open_session(HOST, 993, A1, P1, None)
if c is None:
    check(False, "spike01's credential from the handoff file", f"{refused.kind}: stopped at once, nothing retried")
    sys.exit(1)
before = snapshot(c)
imap.close(c)
print(f"       (spike01 as found: {len(before['folders'])} folders, {before['messages']} messages in INBOX)")

# ---- 1. a scratch database of its own and a second instance of the service ----------------------------------------------------------
sql(f"DROP DATABASE IF EXISTS {LDB}", "postgres")
sql(f"CREATE DATABASE {LDB}", "postgres")
for f in sorted((ROOT / "db").glob("[0-9]*.sql")):
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-q", "-v", "ON_ERROR_STOP=1", "-d", LDB, "-f", str(f)], capture_output=True, text=True)
    if r.returncode != 0:
        print(f"  FAIL applying {f.name}: {r.stderr.strip()[:200]}")
        sys.exit(1)
env_src = Path(os.environ["ML_DEV_ENV"])
env_live = TMP / "live.env"
lines = [ln for ln in env_src.read_text().splitlines() if not ln.startswith(("DB_NAME=", "ML_MAILER_PORT="))]
env_live.write_text("\n".join(lines + [f"DB_NAME={LDB}", f"ML_MAILER_PORT={PORT}"]) + "\n")
shutil.copy(env_src.with_name(env_src.name[:-len(".env")] + "-mailer.env"), TMP / "live-mailer.env")
os.chmod(env_live, 0o600)
pub = next(ln.split("=", 1)[1] for ln in lines if ln.startswith("ML_SEAL_PUBLIC_KEY="))
KEY = next(ln.split("=", 1)[1] for ln in lines if ln.startswith("ML_MAILER_KEY=")).encode()
sealed = SealedBox(PublicKey(base64.b64decode(pub))).encrypt(P1.encode()).hex()
domain = A1.split("@")[1]
sql(f"""INSERT INTO members (id, member_kind, display_name, email, business_role, status, capability, roles) VALUES
          (1, 'human', 'SMOKE Owner', 'owner@example.invalid', 'super_admin', 'active', 'admin', '{{admin,user}}');
        INSERT INTO ml_domains (domain, served, internal) VALUES ('{domain}', true, true);
        INSERT INTO mailboxes (address, display_name, kind) VALUES ('{A1}', 'Spike 01', 'shared');
        INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) SELECT id, 'member', 1, 'full', 1 FROM mailboxes;
        INSERT INTO mailbox_credentials (kind, label, last4, ciphertext, mailbox_id) SELECT 'mailbox_password', 'live proof', 'x', decode('{sealed}', 'hex'), id FROM mailboxes;
        UPDATE mailboxes m SET credential_id = c.id FROM mailbox_credentials c WHERE c.mailbox_id = m.id;""")
MB = int(sql("SELECT id FROM mailboxes"))
env = dict(os.environ, ML_DEV_ENV=str(env_live), ML_MAILER_PORT=str(PORT), ML_IMAP_HOST=HOST, ML_IMAP_PORT="993", ML_MAILER_PASS_SECONDS="2",
           ML_MAILER_TEXT_PER_MINUTE="4", DB_NAME=LDB)
env.pop("ML_TLS_CA_FILE", None)
log = open(TMP / "mailer.log", "w")
proc = subprocess.Popen([str(ROOT / "mcp" / "venv" / "bin" / "python"), "-I", str(ROOT / "services" / "mailer" / "server.py")], env=env, stdout=log, stderr=log)
created_folders: list[str] = []
msg = None


def call(path: str, args: dict, raw: bool = False):
    body = json.dumps({"actor": 1, "on_behalf": None, "args": args}).encode()
    r = httpx.post(f"http://127.0.0.1:{PORT}{path}", content=body, headers={door.HEADER: door.sign(KEY, "POST", path, body), "content-type": "application/json"}, timeout=120)
    return (r.status_code, r.content, dict(r.headers)) if raw else (r.status_code, r.json())


try:
    t0 = time.time()
    state = None
    while time.time() - t0 < 180:
        state = sql(f"SELECT sync_state || ',' || COALESCE((SELECT status FROM mailbox_credentials WHERE mailbox_id = {MB}), '?') FROM mailboxes WHERE id = {MB}")
        if state.startswith(("live,", "failed,")) or state.endswith(",failed"):
            break
        time.sleep(1)
    check(state == "live,ok", f"the first sync of spike01 at MaluMail ({round(time.time() - t0)} s, the credential still ok)", state or "")
    created_folders = [r for r in sql(f"SELECT name FROM mail_folders WHERE mailbox_id = {MB}").splitlines() if r not in before["folders"]]

    # ---- 2. ONE submission, spike02 → spike01: HTML with an inline image and an attachment ---------------------------------------
    subject = f"MaluMail slice 3 live check {uuid.uuid4().hex[:8]}"
    msg = email.message.EmailMessage()
    msg["From"], msg["To"], msg["Subject"] = A2, A1, subject
    msg["Date"] = email.utils.formatdate(localtime=False, usegmt=True)
    msg["Message-ID"] = f"<{uuid.uuid4().hex}@{domain}>"
    msg.set_content("The slice 3 proof's one submission. It is removed again at the end of the proof.\n")
    msg.add_alternative('<p>The key: <img src="cid:key1@live" width="2" height="2" alt="key"></p><p>Removed again at the end of the proof.</p>', subtype="html")
    msg.get_payload()[1].add_related(PNG, "image", "png", cid="<key1@live>", filename="key.png")
    msg.add_attachment(b"%PDF-1.4 live proof", maintype="application", subtype="pdf", filename="proof.pdf")
    with smtplib.SMTP(SMTP_HOST, 587, timeout=30) as s:
        s.starttls(context=imap.tls_context(None))
        s.login(A2, P2)
        s.send_message(msg)
    sent_at = time.time()
    mid = None
    while time.time() - sent_at < 30:
        mid = sql(f"SELECT id FROM mail_messages WHERE mailbox_id = {MB} AND message_id_header = '{msg['Message-ID']}'")
        if mid:
            break
        time.sleep(0.25)
    check(bool(mid), f"the submission reaches spike01's index ({round(time.time() - sent_at, 1)} s)")
    if not mid:
        raise SystemExit(1)
    mid = int(mid)
    s, d = call(f"/v1/mailboxes/{MB}/fetch", {"message": mid, "form": "html"})
    r = d.get("result", {})
    check(s == 200 and r.get("html") and 'src="/p/' in r["html"] and "cid:" not in r["html"], "fetch on the real server: the sanitised body, the cid: image a part link")
    parts = {p["part"]: p for p in r.get("parts", [])}
    png_part = next((p for p, v in parts.items() if v["type"] == "image/png"), None)
    pdf_part = next((p for p, v in parts.items() if v["type"] == "application/pdf"), None)
    check(png_part is not None and pdf_part is not None and parts[png_part]["size"] == len(PNG), "…the part list with the inline image and the attachment at their DECODED sizes (BINARY.SIZE)", str(parts))
    s, b, h = call(f"/v1/mailboxes/{MB}/part", {"message": mid, "part": png_part}, raw=True)
    check(s == 200 and b == PNG and h.get("content-type", "").startswith("image/png"), "the cid: image's bytes through `part`")
    s, b, h = call(f"/v1/mailboxes/{MB}/part", {"message": mid, "part": pdf_part}, raw=True)
    check(s == 200 and b == b"%PDF-1.4 live proof" and "proof.pdf" in h.get("x-ml-name", ""), "the attachment's bytes and name through `part`")
    s, b, h = call(f"/v1/mailboxes/{MB}/raw", {"message": mid}, raw=True)
    check(s == 200 and b.startswith((b"Return-Path", b"Received", b"Delivered-To", b"From", b"X-")) and len(b) == int(sql(f"SELECT size FROM mail_messages WHERE id = {mid}")), "the whole message through `raw`, RFC822.SIZE bytes")
    s, d = call(f"/v1/mailboxes/{MB}/fetch", {"message": mid, "form": "text"})
    check(s == 200 and "one submission" in (d.get("result", {}).get("text") or ""), "the agent's text form")

    def ref(m):
        return [int(x) for x in sql(f"SELECT folder_id||' '||uidvalidity||' '||uid FROM mail_messages WHERE id = {m}").split()]
    s, d = call(f"/v1/mailboxes/{MB}/mutate", {"op": "archive", "targets": [ref(mid)]})
    check(s == 200 and d["result"]["count"] == 1 and sql(f"SELECT f.special_use FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.id = {mid}") == "archive",
          "archive moves it on the real server (UID MOVE answered COPYUID, the index updated)", json.dumps(d)[:200])
    inbox = int(sql(f"SELECT id FROM mail_folders WHERE mailbox_id = {MB} AND special_use = 'inbox'"))
    s, d = call(f"/v1/mailboxes/{MB}/mutate", {"op": "move", "targets": [ref(mid)], "to_folder": inbox})
    check(s == 200 and d["result"]["count"] == 1 and sql(f"SELECT f.special_use FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.id = {mid}") == "inbox", "…and the undo (a move back) returns it to INBOX")
    s, d = call(f"/v1/mailboxes/{MB}/mutate", {"op": "trash", "targets": [ref(mid)]})
    s2, d2 = call(f"/v1/mailboxes/{MB}/mutate", {"op": "delete_forever", "targets": [ref(mid)]})
    check(s == 200 and s2 == 200 and d2["result"]["count"] == 1 and sql(f"SELECT count(*) FROM mail_messages WHERE id = {mid}") == "0", "trash, then delete forever: expunged on the real server")
finally:
    proc.terminate()
    try:
        proc.wait(timeout=10)
    except subprocess.TimeoutExpired:
        proc.kill()
    log.close()

# ---- 3. as found again ---------------------------------------------------------------------------------------------------------------
c, refused = imap.open_session(HOST, 993, A1, P1, None)
if c is None:
    check(False, "cleaning up spike01", refused.kind)
    sys.exit(1)
if msg is not None:
    for name in ("INBOX", "Archive", "Trash"):
        try:
            c.select_folder(name)
        except Exception:  # noqa: BLE001
            continue
        uids = c.search(["HEADER", "Message-ID", msg["Message-ID"]])
        if uids:
            c.add_flags(uids, [b"\\Deleted"], silent=True)
            c.expunge(uids)
for name in created_folders:
    st = c.folder_status(name, ["MESSAGES"])
    if int(st[b"MESSAGES"]) == 0:
        c.delete_folder(name)
after = snapshot(c)
imap.close(c)
check(after["folders"] == before["folders"] and after["messages"] == before["messages"] and after["unseen"] == before["unseen"],
      f"spike01 left as found: {len(after['folders'])} folders, {after['messages']} messages, {after['unseen']} unseen",
      json.dumps({"before": before, "after": after}))
sql(f"DROP DATABASE IF EXISTS {LDB}", "postgres")
shutil.rmtree(TMP, ignore_errors=True)
print(f"all {PASSES} passed" if not FAILS else f"{FAILS} FAILED ({PASSES} passed)")
sys.exit(1 if FAILS else 0)
