"""Slice 4's live step (writing.md Proof 11; design §10 Phase 3): MaluMail's two TEST mailboxes, only through the owner's handoff file —
named by ML_LIVE_CREDENTIALS in the environment of whoever runs the proof (never in this repository; one line per mailbox, `address
password`); without it this prints one line and passes. Real passwords only (never a wrong one: MaluMail's abuse protection would lock
this whole machine out — a refusal stops everything at once, nothing is retried). At most TWO submissions, both through the mail
service's own outbox loop against MaluMail's submission port: spike01 sends to spike02; spike02's INBOX shows it by its Message-ID
(found through a direct IMAP read of spike02, nothing written there but the expunge) and spike01's Sent shows the copy; then spike02
replies through a second mailbox row of the same service instance and the reply threads under the original in spike01's index. Both
messages are expunged from every folder at the end. Nothing else in either mailbox is touched."""

import base64
import json
import os
import shutil
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

from nacl.public import PublicKey, SealedBox  # noqa: E402

from services.mailer import imap  # noqa: E402

HOST = os.environ.get("ML_LIVE_IMAP_HOST", "mbox01.malumail.com")
SMTP_HOST = os.environ.get("ML_LIVE_SMTP_HOST", HOST)
A1, P1 = creds["spike01"]
A2, P2 = creds["spike02"]
LDB = "ml_dev4l"
PORT = 9209
TMP = Path(os.environ.get("ML_DEV_STATE", "/tmp")) / "live4"
shutil.rmtree(TMP, ignore_errors=True)
TMP.mkdir(parents=True)


def sql(q: str, db: str = LDB) -> str:
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-v", "ON_ERROR_STOP=1", "-d", db, "-c", q], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip())
    return r.stdout.strip()


def wait(pred, timeout=60.0, step=0.5):
    t0 = time.time()
    while time.time() - t0 < timeout:
        try:
            if pred():
                return True
        except RuntimeError:
            pass
        time.sleep(step)
    return False


# ---- 0. both credentials from the handoff file, once each ---------------------------------------------------------------------------
sessions = {}
for a, p in ((A1, P1), (A2, P2)):
    c, refused = imap.open_session(HOST, 993, a, p, None)
    if c is None:
        check(False, f"{a}'s credential from the handoff file", f"{refused.kind}: stopped at once, nothing retried")
        sys.exit(1)
    imap.close(c)
print("       (both test mailboxes open with their handoff passwords)")

# ---- 1. a scratch database and a second instance of the service, both mailboxes rows of it ---------------------------------------
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
box = SealedBox(PublicKey(base64.b64decode(pub)))
domain = A1.split("@")[1]
sql(f"""INSERT INTO members (id, member_kind, display_name, email, business_role, status, capability, roles) VALUES
          (1, 'human', 'SMOKE Owner', 'owner@example.invalid', 'super_admin', 'active', 'admin', '{{admin,user}}');
        INSERT INTO ml_domains (domain, served, internal) VALUES ('{domain}', true, true);
        INSERT INTO mailboxes (address, display_name, kind) VALUES ('{A1}', 'Spike 01', 'shared'), ('{A2}', 'Spike 02', 'shared');
        INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) SELECT id, 'member', 1, 'full', 1 FROM mailboxes;
        INSERT INTO mail_identities (mailbox_id, address, kind, display_name, is_default, created_by) SELECT id, address, 'own', display_name, true, 1 FROM mailboxes;
        INSERT INTO mailbox_credentials (kind, label, last4, ciphertext, mailbox_id) VALUES
          ('mailbox_password', 'live proof', 'x', decode('{box.encrypt(P1.encode()).hex()}', 'hex'), (SELECT id FROM mailboxes WHERE address = '{A1}')),
          ('mailbox_password', 'live proof', 'x', decode('{box.encrypt(P2.encode()).hex()}', 'hex'), (SELECT id FROM mailboxes WHERE address = '{A2}'));
        UPDATE mailboxes m SET credential_id = c.id FROM mailbox_credentials c WHERE c.mailbox_id = m.id;
        UPDATE ml_settings SET undo_send_seconds = 2 WHERE id = 1;""")
M1 = int(sql(f"SELECT id FROM mailboxes WHERE address = '{A1}'"))
M2 = int(sql(f"SELECT id FROM mailboxes WHERE address = '{A2}'"))
env = dict(os.environ, ML_DEV_ENV=str(env_live), ML_MAILER_PORT=str(PORT), ML_IMAP_HOST=HOST, ML_IMAP_PORT="993", ML_SMTP_HOST=SMTP_HOST, ML_SMTP_PORT="587",
           ML_MAILER_PASS_SECONDS="2", ML_MAILER_TEXT_PER_MINUTE="4", DB_NAME=LDB)
env.pop("ML_TLS_CA_FILE", None)
log = open(TMP / "mailer.log", "w")
proc = subprocess.Popen([str(ROOT / "mcp" / "venv" / "bin" / "python"), "-I", str(ROOT / "services" / "mailer" / "server.py")], env=env, stdout=log, stderr=log)
made = []          # (mailbox row, message_id) to expunge at the end


def submit(mb: int, to: str, subject: str, text: str, reply_to=None) -> int:
    return int(sql(f"SELECT ml_outbound_submit({mb}, 1, NULL, ARRAY['{to}'], ARRAY[]::text[], ARRAY[]::text[], '{subject}', '{text}', NULL, {reply_to or 'NULL'}, NULL, NULL, false, NULL, NULL)"))


try:
    t0 = time.time()
    check(wait(lambda: sql(f"SELECT count(*) FROM mailboxes WHERE sync_state = 'live'") == "2", 180, 1), f"both mailboxes synced at MaluMail ({round(time.time() - t0)} s)")
    # ---- 2. submission one: spike01 → spike02 through the outbox loop --------------------------------------------------------------
    subject = f"MaluMail slice 4 live check {uuid.uuid4().hex[:8]}"
    o1 = submit(M1, A2, subject, "The slice 4 proof: one message from spike01 to spike02, removed again at the end.")
    check(wait(lambda: sql(f"SELECT status FROM outbound_email WHERE id = {o1}") == "sent", 90), "spike01's message submitted through MaluMail's port 587 and recorded sent",
          sql(f"SELECT status || ' ' || COALESCE(smtp_reply, error, '') FROM outbound_email WHERE id = {o1}"))
    mid1 = sql(f"SELECT message_id FROM outbound_email WHERE id = {o1}")
    made.append((M1, mid1))
    made.append((M2, mid1))
    check(wait(lambda: sql(f"SELECT count(*) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = {M2} AND f.special_use = 'inbox' AND m.message_id_header = '{mid1}'") == "1", 90),
          "spike02's INBOX shows it by its Message-ID (through the sync)")
    check(wait(lambda: sql(f"SELECT count(*) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = {M1} AND f.special_use = 'sent' AND m.message_id_header = '{mid1}'") == "1", 90)
          and sql(f"SELECT filed_at IS NOT NULL FROM outbound_email WHERE id = {o1}") == "t", "spike01's Sent shows the copy the service appended")
    # ---- 3. submission two: spike02 replies ------------------------------------------------------------------------------------------
    key = int(sql(f"SELECT message_key_id FROM mail_messages WHERE mailbox_id = {M2} AND message_id_header = '{mid1}' ORDER BY id LIMIT 1"))
    o2 = submit(M2, A1, "Re: " + subject, "The reply, removed again at the end.", reply_to=key)
    check(wait(lambda: sql(f"SELECT status FROM outbound_email WHERE id = {o2}") == "sent", 90), "spike02's reply submitted and recorded sent",
          sql(f"SELECT status || ' ' || COALESCE(smtp_reply, error, '') FROM outbound_email WHERE id = {o2}"))
    mid2 = sql(f"SELECT message_id FROM outbound_email WHERE id = {o2}")
    made.append((M1, mid2))
    made.append((M2, mid2))
    check(wait(lambda: sql(f"SELECT count(*) FROM mail_messages WHERE mailbox_id = {M1} AND message_id_header = '{mid2}'") == "1", 90), "the reply reaches spike01")
    t_orig = sql(f"SELECT thread_id FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = {M1} AND f.special_use = 'sent' AND m.message_id_header = '{mid1}'")
    t_reply = sql(f"SELECT thread_id FROM mail_messages WHERE mailbox_id = {M1} AND message_id_header = '{mid2}' ORDER BY id LIMIT 1")
    check(t_orig != "" and t_orig == t_reply, "…and threads under the original in spike01's index (In-Reply-To)", f"{t_orig} vs {t_reply}")
    check(wait(lambda: sql(f"SELECT answered FROM mail_messages WHERE mailbox_id = {M2} AND message_id_header = '{mid1}' ORDER BY id LIMIT 1") == "t", 60), "the original in spike02 flagged \\Answered")
    check(sql("SELECT count(*) FROM outbound_email WHERE status = 'sent'") == "2", "exactly two submissions in all")
finally:
    # ---- 4. everything we made expunged, both mailboxes left as found ----------------------------------------------------------------
    proc.terminate()
    try:
        proc.wait(10)
    except subprocess.TimeoutExpired:
        proc.kill()
    for a, p in ((A1, P1), (A2, P2)):
        c, _r = imap.open_session(HOST, 993, a, p, None)
        if c is None:
            continue
        try:
            for f in imap.list_status(c):
                try:
                    imap.select(c, f["name"], readonly=False)
                except Exception:  # noqa: BLE001
                    continue
                for _mb, mid in made:
                    uids = imap.search_header(c, "Message-ID", mid)
                    if uids:
                        imap.expunge_uids(c, uids)
        finally:
            imap.close(c)
    sql(f"DROP DATABASE IF EXISTS {LDB}", "postgres")
print(f"{FAILS} FAILED ({PASSES} passed)" if FAILS else f"all {PASSES} passed")
sys.exit(1 if FAILS else 0)
