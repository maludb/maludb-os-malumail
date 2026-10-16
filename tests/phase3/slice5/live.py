"""Slice 5's live step (oversight.md Proof 11; design §10's Phase 5 shape, here once): MaluMail's two TEST mailboxes, only through the
owner's handoff file — named by ML_LIVE_CREDENTIALS in the environment of whoever runs the proof (never in this repository; one line per
mailbox, `address password`); without it this prints one line and passes. Real passwords only (never a wrong one: MaluMail's abuse
protection would lock this whole machine out — a refusal stops everything at once, nothing is retried). ONE submission: spike01 is an
AGENT's mailbox here; the agent writes to spike02 (a first contact) → held; a person approves; the mail service's outbox submits it at
MaluMail's port 587; it arrives in spike02's INBOX with X-OS-Agent and without Auto-Submitted (its header read by a direct IMAP fetch of
spike02, nothing written there but the expunge). The message is expunged from every folder of both mailboxes at the end."""

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
LDB = "ml_dev5l"
PORT = 9208
TMP = Path(os.environ.get("ML_DEV_STATE", "/tmp")) / "live5"
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
          (1, 'human', 'SMOKE Owner', 'owner@example.invalid', 'super_admin', 'active', 'admin', '{{admin,user}}'),
          (40, 'agent', 'SMOKE Expert', NULL, 'user', 'active', 'write', '{{user}}');
        INSERT INTO ml_domains (domain, served, internal) VALUES ('{domain}', true, true);
        INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES ('{A1}', 'Spike 01', 'agent', 40), ('{A2}', 'Spike 02', 'shared', NULL);
        UPDATE mailboxes SET primary_overseer_id = 1 WHERE address = '{A1}';
        INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) SELECT id, 'member', 1, 'full', 1 FROM mailboxes WHERE kind = 'shared';
        INSERT INTO agent_mail_policies (mailbox_id, send_mode, first_contact_review, attachments_review, max_external_per_day, max_per_hour, max_per_thread_per_day,
                                         wake_on_mail, wake_from, disclosure, updated_by)
        SELECT b.id, s.default_send_mode, s.default_first_contact_review, s.default_attachments_review, s.default_max_external_per_day, s.default_max_per_hour,
               s.default_max_per_thread_per_day, false, s.default_wake_from, s.default_disclosure, 1 FROM mailboxes b, ml_settings s WHERE b.kind = 'agent';
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


try:
    t0 = time.time()
    check(wait(lambda: sql(f"SELECT count(*) FROM mailboxes WHERE sync_state = 'live'") == "2", 180, 1), f"both mailboxes synced at MaluMail ({round(time.time() - t0)} s)")
    subject = f"MaluMail slice 5 live check {uuid.uuid4().hex[:8]}"
    o1 = int(sql(f"SELECT ml_outbound_submit({M1}, 40, NULL, ARRAY['{A2}'], ARRAY[]::text[], ARRAY[]::text[], '{subject}', "
                 f"'The slice 5 proof: an agent writes, a person approves; removed again at the end.', NULL, NULL, NULL, 'the slice 5 live check', false, NULL, 9599)"))
    st = sql(f"SELECT status || ' ' || array_to_string(hold_reasons, ',') FROM outbound_email WHERE id = {o1}")
    check(st.startswith("held") and "first_contact" in st, "the agent's message to spike02 is held (a first contact)", st)
    time.sleep(3)
    check(sql(f"SELECT status FROM outbound_email WHERE id = {o1}") == "held" and sql("SELECT count(*) FROM outbound_email WHERE status = 'sent'") == "0", "…and nothing leaves while it waits")
    sql(f"SELECT ml_review_decide({o1}, 1, 'approve')")
    check(sql(f"SELECT status || ' ' || COALESCE(auto_submitted, '-') FROM outbound_email WHERE id = {o1}") == "queued -", "a person approves: queued, Auto-Submitted dropped")
    check(wait(lambda: sql(f"SELECT status FROM outbound_email WHERE id = {o1}") == "sent", 90), "submitted through MaluMail's port 587 and recorded sent",
          sql(f"SELECT status || ' ' || COALESCE(smtp_reply, error, '') FROM outbound_email WHERE id = {o1}"))
    mid1 = sql(f"SELECT message_id FROM outbound_email WHERE id = {o1}")
    made.append((M1, mid1))
    made.append((M2, mid1))
    check(wait(lambda: sql(f"SELECT count(*) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = {M2} AND f.special_use = 'inbox' AND m.message_id_header = '{mid1}'") == "1", 90),
          "it arrives in spike02's INBOX (by its Message-ID)")
    c, _r = imap.open_session(HOST, 993, A2, P2, None)
    hdr = ""
    if c is not None:
        try:
            imap.select(c, "INBOX")
            uids = imap.search_header(c, "Message-ID", mid1)
            if uids:
                got = c.fetch(uids[:1], ["BODY.PEEK[HEADER]"])
                hdr = next(iter(got.values())).get(b"BODY[HEADER]", b"").decode("utf-8", "replace")
        finally:
            imap.close(c)
    check("\nX-OS-Agent:" in "\n" + hdr and "\nAuto-Submitted:" not in "\n" + hdr, "…with X-OS-Agent and without Auto-Submitted (its header as spike02 holds it)",
          "\n".join(line for line in hdr.splitlines() if line.lower().startswith(("x-os-agent", "auto-submitted"))))
    check(sql(f"SELECT ml_is_known({M1}, '{A2}')") == "t", "spike02 is now known to the agent's mailbox (a person approved)")
    check(sql("SELECT count(*) FROM outbound_email WHERE status = 'sent'") == "1", "exactly one submission")
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
