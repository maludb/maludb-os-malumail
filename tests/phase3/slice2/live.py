"""Slice 2's live step (mail-list.md Proof 10; mail-service.md §13): MaluMail's two TEST mailboxes, only through the owner's handoff file —
named by ML_LIVE_CREDENTIALS in the environment of whoever runs the proof (never in this repository); without it this prints one line and
passes. Real passwords only (never a wrong one: MaluMail's abuse protection would lock this whole machine out — a refusal stops everything
at once, nothing is retried); at most ONE submission (spike02 → spike01); both mailboxes left as found (our message expunged by its own
UID; a folder the sync made where none was, removed when empty — nothing else is touched, never a \\Seen).

What it proves: the mail service's first sync of spike01 from MaluMail's own server (a scratch database of its own, ml_dev2l, and a second
instance of the service on 9207, so nothing of the fixture's run is mixed in), and a message submitted from spike02 reaching spike01's
index through NOTIFY and its Inbox list through the poll (mail_changes(), the poll door's own function) within 20 s.
File format: one line per mailbox, `address password`."""

import json
import os
import shutil
import smtplib
import subprocess
import sys
import time
import uuid
from email.message import EmailMessage
from email.utils import formatdate
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

from nacl.public import PublicKey, SealedBox  # noqa: E402

from services.mailer import imap  # noqa: E402

HOST = os.environ.get("ML_LIVE_IMAP_HOST", "mbox01.malumail.com")
SMTP_HOST = os.environ.get("ML_LIVE_SMTP_HOST", HOST)
A1, P1 = creds["spike01"]
A2, P2 = creds["spike02"]
LDB = "ml_dev2l"
PORT = 9207
TMP = Path(os.environ.get("ML_DEV_STATE", "/tmp")) / "live"
shutil.rmtree(TMP, ignore_errors=True)
TMP.mkdir(parents=True)


def sql(q: str, db: str = LDB) -> str:
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-v", "ON_ERROR_STOP=1", "-d", db, "-c", q], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip())
    return r.stdout.strip()


def snapshot(c) -> dict:
    """What 'as found' means: the folder names, and INBOX's count and next UID."""
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
import base64  # noqa: E402

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
try:
    t0 = time.time()
    state = None
    while time.time() - t0 < 180:
        state = sql(f"SELECT sync_state || ',' || COALESCE((SELECT status FROM mailbox_credentials WHERE mailbox_id = {MB}), '?') FROM mailboxes WHERE id = {MB}")
        if state.startswith(("live,", "failed,")) or state.endswith(",failed"):
            break
        time.sleep(1)
    check(state == "live,ok", f"the first sync of spike01 at MaluMail ({round(time.time() - t0)} s, the credential still ok)", state or "")
    n = int(sql(f"SELECT count(*) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = {MB} AND f.special_use = 'inbox'"))
    check(n == before["messages"], f"…every INBOX message indexed ({n} of {before['messages']})")
    created_folders = [r for r in sql(f"SELECT name FROM mail_folders WHERE mailbox_id = {MB}").splitlines() if r not in before["folders"]]

    def php_changes(since: int, top: str) -> dict:
        code = ("require 'app/bootstrap.php'; $_SESSION = ['member_id' => 1]; $p = db(); $p->exec(\"SELECT set_config('app.member_id', '1', false)\"); "
                f"$c = mail_changes($p, {MB}, {since}, 'inbox', null, [], '{top}' === '' ? null : '{top}', 50); "
                f"$pg = mail_list_page($p, {MB}, 'inbox', null, null, false, 50); "
                "echo json_encode(['changes' => $c, 'first' => $pg['rows'][0]['subject'] ?? null, 'top' => $pg['rows'][0]['cursor'] ?? '', 'seq' => $pg['seq']]);")
        r = subprocess.run(["php", "-r", code], capture_output=True, text=True, cwd=ROOT, env=dict(os.environ, ML_DEV_ENV=str(env_live), DB_NAME=LDB))
        return json.loads(r.stdout or "{}")

    page0 = php_changes(0, "")
    since0, top0 = int(page0.get("seq") or 0), page0.get("top") or ""

    # ---- 2. ONE submission, spike02 → spike01 -------------------------------------------------------------------------------------
    subject = f"MaluMail slice 2 live check {uuid.uuid4().hex[:8]}"
    msg = EmailMessage()
    msg["From"], msg["To"], msg["Subject"] = A2, A1, subject
    msg["Date"] = formatdate(localtime=False, usegmt=True)
    msg["Message-ID"] = f"<{uuid.uuid4().hex}@{domain}>"
    msg.set_content("The slice 2 proof's one submission. It is removed again at the end of the proof.\n")
    with smtplib.SMTP(SMTP_HOST, 587, timeout=30) as s:
        s.starttls(context=imap.tls_context(None))
        s.login(A2, P2)
        s.send_message(msg)
    sent_at = time.time()
    t_index = t_poll = None
    while time.time() - sent_at < 20:
        if t_index is None and sql(f"SELECT count(*) FROM mail_messages WHERE mailbox_id = {MB} AND subject = '{subject}'") == "1":
            t_index = time.time() - sent_at
        if t_index is not None:
            ch = php_changes(since0, top0)
            if (ch.get("changes") or {}).get("new", 0) >= 1 and ch.get("first") == subject:
                t_poll = time.time() - sent_at
                break
        time.sleep(0.25)
    check(t_index is not None and t_index < 2, f"the submission reaches spike01's index through NOTIFY ({t_index and round(t_index, 2)} s after the 250)")
    check(t_poll is not None and t_poll <= 20, f"…and spike01's Inbox list through the poll: \"1 new\", on top ({t_poll and round(t_poll, 2)} s)")
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
c.select_folder("INBOX")
uids = c.search(["HEADER", "Message-ID", msg["Message-ID"]]) if "msg" in globals() else []
if uids:
    c.add_flags(uids, [b"\\Deleted"], silent=True)
    c.expunge(uids)                                          # UID EXPUNGE of our message alone (UIDPLUS)
for name in created_folders:                                   # a folder this proof's sync made where none was, if still empty
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
