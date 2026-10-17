"""Slice 4's service-side proof (writing.md Proof 1–3, 5–7 in their service half; sending-and-oversight.md §4–§5, §12; mail-service.md §13):
the outbox loop against the SMTP fixture's spool and the IMAP fixture — the MIME and its headers, Bcc only in the Sent copy, \\Seen on
it and \\Answered on an answered message (never \\Seen), the draft's Drafts copy replaced and expunged, undo inside the window and refused
after it, a schedule sent once and a cancelled one never, the server's answers (451 → deferred → sent, five deferrals → failed, a
recipient's 550 → sent with the refusal named and a send_failed notice, a drop during DATA → failed with decision 1's sentence and never
a second copy, the crash search after a restart, 535 → the breaker with the fixture's one attempt), the encoded-size refusal, and an
agent's headers, disclosure and signature. Rows are submitted through ml_outbound_submit() as the members themselves (the page half is
checks.php). Run by tests/phase3/slice4/run.sh. Nothing here reaches MaluMail."""

import email
import email.policy
import json
import os
import pathlib
import subprocess
import sys
import time
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT))

import httpx  # noqa: E402

from services.mailer import door  # noqa: E402

DB = os.environ["ML_DEV_DB"]
STATE = pathlib.Path(os.environ["ML_DEV_STATE"])
CONTROL = pathlib.Path(os.environ["ML_IMAP_CONTROL"])
SPOOL = pathlib.Path(os.environ["ML_SMTP_SPOOL"])
MAILER = f"http://127.0.0.1:{os.environ.get('P_MAILER', '9206')}"
KEY = os.environ["ML_MAILER_KEY"].encode()
SEED = json.loads(pathlib.Path(os.environ["ML_P2_SEED"]).read_text())
USERS = STATE / "imap-users.json"
PRIYA, DANA, SALES, EXPERT, MARCO, NINA = (SEED[k] for k in ("priya", "dana", "sales", "expert", "marco", "nina"))
RUN = uuid.uuid4().hex[:6]
FAILS = PASSES = 0


def check(cond, label, detail=""):
    global FAILS, PASSES
    if cond:
        PASSES += 1
        print(f"  ok   {label}")
    else:
        FAILS += 1
        print(f"  FAIL {label}" + (f" — {detail}" if detail else ""))


def sql(*qs: str) -> str:
    cmd = ["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-v", "ON_ERROR_STOP=1", "-d", DB]
    for q in qs:
        cmd += ["-c", q]
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip())
    return r.stdout.strip()


def sql_error(q: str) -> str:
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-v", "ON_ERROR_STOP=1", "-d", DB, "-c", q], capture_output=True, text=True)
    return r.stderr.strip() if r.returncode != 0 else ""


def ctl(**cmd) -> dict:
    name = uuid.uuid4().hex
    (CONTROL / f"{name}.json").write_text(json.dumps(cmd))
    done = CONTROL / f"{name}.done"
    for _ in range(200):
        if done.exists():
            out = json.loads(done.read_text())
            done.unlink()
            return out
        time.sleep(0.05)
    raise RuntimeError("the fixture did not answer")


def call(path: str, actor: int, args=None, on_behalf=None):
    body = json.dumps({"actor": actor, "on_behalf": on_behalf, "args": args or {}}).encode()
    r = httpx.post(MAILER + path, content=body, headers={door.HEADER: door.sign(KEY, "POST", path, body), "content-type": "application/json"}, timeout=60)
    return r.status_code, r.json()


def wait(pred, timeout: float = 20.0, step: float = 0.2):
    t0 = time.time()
    while time.time() - t0 < timeout:
        try:
            if pred():
                return time.time() - t0
        except RuntimeError:
            pass
        time.sleep(step)
    return None


def status(oid: int) -> str:
    return sql(f"SELECT status FROM outbound_email WHERE id = {oid}")


def row(oid: int, cols: str) -> list[str]:
    return sql(f"SELECT {cols} FROM outbound_email WHERE id = {oid}").split("|")


def spool_index() -> list[dict]:
    f = SPOOL / "index.jsonl"
    return [json.loads(line) for line in f.read_text().splitlines() if line.strip()] if f.exists() else []


def spooled(message_id: str) -> list[dict]:
    return [e for e in spool_index() if e["message_id"] == message_id]


def spool_message(entry: dict):
    return email.message_from_bytes((SPOOL / f"{entry['n']}.eml").read_bytes(), policy=email.policy.default)


def smtp_stats() -> dict:
    time.sleep(0.3)
    return json.loads((STATE / "smtp-stats.json").read_text())["_"]


def imap_stats() -> dict:
    time.sleep(0.3)
    return json.loads((STATE / "imap-stats.json").read_text())["_"]


def set_mode(user: str, mode: str) -> None:
    u = json.loads(USERS.read_text())
    u[user]["smtp_mode"] = mode
    USERS.write_text(json.dumps(u))


def set_password(user: str, pw: str) -> None:
    u = json.loads(USERS.read_text())
    u[user]["password"] = pw
    USERS.write_text(json.dumps(u))


def lit(xs: list[str]) -> str:
    return "ARRAY[" + ",".join("'" + x.replace("'", "''") + "'" for x in xs) + "]::text[]" if xs else "ARRAY[]::text[]"


def q(s: str | None) -> str:
    return "NULL" if s is None else "'" + s.replace("'", "''") + "'"


def submit(mailbox: int, sender: int, to: list[str], subject: str, text: str = "Hello from the proof.", html: str | None = None, cc=(), bcc=(),
           reply_to=None, draft=None, purpose=None, has_att=False, send_at=None, identity=None) -> int:
    return int(sql(f"SELECT ml_outbound_submit({mailbox}, {sender}, {identity or 'NULL'}, {lit(to)}, {lit(list(cc))}, {lit(list(bcc))}, {q(subject)}, {q(text)}, {q(html)}, "
                   f"{reply_to or 'NULL'}, {draft or 'NULL'}, {q(purpose)}, {'true' if has_att else 'false'}, {q(send_at)}, NULL)"))


def submit_error(mailbox: int, sender: int, to: list[str], subject: str, purpose=None, text="Hello") -> str:
    return sql_error(f"SELECT ml_outbound_submit({mailbox}, {sender}, NULL, {lit(to)}, ARRAY[]::text[], ARRAY[]::text[], {q(subject)}, {q(text)}, NULL, NULL, NULL, {q(purpose)}, false, NULL, NULL)")


def message_id(oid: int) -> str:
    return sql(f"SELECT message_id FROM outbound_email WHERE id = {oid}")


def wait_sent(oid: int, timeout=20.0):
    return wait(lambda: status(oid) in ("sent", "failed"), timeout)


def flags(user: str, folder: str) -> dict:
    return ctl(op="state", user=user)["folders"][folder]["flags"]


def new_draft(mailbox: int, member: int, subject: str, to=("someone@outside.example",), text="a draft") -> int:
    key = f"<{uuid.uuid4().hex}.draft.malumail@business.example>"
    out = sql(f"INSERT INTO mail_drafts (mailbox_id, member_id, draft_key, to_addresses, subject, body_text, mode) VALUES ({mailbox}, {member}, '{key}', {lit(list(to))}, {q(subject)}, {q(text)}, 'new') RETURNING id")
    return int(out.split("\n")[0])


# the six mailboxes' first sync (their watchers) before anything is filed or sent
if wait(lambda: sql("SELECT count(*) FROM mailboxes WHERE sync_state = 'live'") == "6", 90, 0.5) is None:
    print("  FAIL the six mailboxes did not come live within 90 s: " + sql("SELECT string_agg(address || '=' || sync_state, ', ') FROM mailboxes"))
    sys.exit(1)

print("1. Compose and send: the spool, the Sent copy, the draft")
d1 = new_draft(PRIYA, 26, f"First {RUN}")
s, j = call(f"/v1/mailboxes/{PRIYA}/append_draft", 26, {"draft": d1})
check(s == 200 and j["ok"] and j["result"]["uid"], "the draft is filed in Drafts through append_draft (APPENDUID answered)", str(j))
draft_uid = str(j["result"]["uid"])
check(draft_uid in flags("priya@business.example", "Drafts") and "\\Draft" in flags("priya@business.example", "Drafts")[draft_uid], "…the fixture holds it with \\Draft")
o1 = submit(PRIYA, 26, ["outside@customer.example"], f"First {RUN}", "Hello from the proof.\nTwo lines.", "<div>Hello from the <b>proof</b>.<br>Two lines.</div>",
            cc=["second@customer.example"], bcc=["hidden@customer.example"], draft=d1, identity=1)
check(status(o1) == "queued" and float(sql(f"SELECT extract(epoch FROM send_after - now()) FROM outbound_email WHERE id = {o1}")) > 2, "a person's send is queued with the undo window ahead")
took = wait_sent(o1)
check(took is not None and status(o1) == "sent", "…sent once the window passed", status(o1))
mid1 = message_id(o1)
ent = spooled(mid1)
check(len(ent) == 1, "the spool holds exactly one copy", str(len(ent)))
if ent:
    m = spool_message(ent[0])
    name = sql("SELECT display_name FROM mail_identities WHERE id = 1")
    check(m["From"] == f"{name} <priya@business.example>" and m["To"] == "outside@customer.example" and m["Cc"] == "second@customer.example", "From the identity (its display name), To and Cc as written", f"{m['From']} | {m['To']} | {m['Cc']}")
    check(m["Bcc"] is None and "hidden@customer.example" not in (SPOOL / f"{ent[0]['n']}.eml").read_text(), "no Bcc header and no blind address anywhere in the submitted bytes")
    check(m["Message-ID"] == mid1 and m["Date"] is not None and m["MIME-Version"] == "1.0", "Date, the stored Message-ID, MIME-Version")
    check(m.get_content_type() == "multipart/alternative" and [p.get_content_type() for p in m.iter_parts()] == ["text/plain", "text/html"], "multipart/alternative with the plain and the HTML parts")
    check(sorted(ent[0]["rcpt"]) == ["hidden@customer.example", "outside@customer.example", "second@customer.example"], "every recipient — the Bcc one too — in RCPT TO")
    check(not any(p["MIME-Version"] for p in m.iter_parts()), "no MIME-Version on a part")
sent_flags = flags("priya@business.example", "Sent")
appends = [a for a in imap_stats().get("appends", []) if a["user"] == "priya@business.example" and a["folder"] == "Sent"]
check(appends and appends[-1]["flags"] == ["\\Seen"], "the Sent copy APPENDed with \\Seen", str(appends[-1:]))
check(wait(lambda: sql(f"SELECT filed_at IS NOT NULL FROM outbound_email WHERE id = {o1}") == "t", 10) is not None, "the row records the filing (filed_at)")
check(wait(lambda: sql(f"SELECT bcc_addresses FROM mail_messages WHERE mailbox_id = {PRIYA} AND message_id_header = '{mid1}'") == "{hidden@customer.example}", 20) is not None,
      "the Sent copy indexed by the sync WITH its Bcc (the sender sees whom they blind-copied)")
check(wait(lambda: draft_uid not in flags("priya@business.example", "Drafts"), 10) is not None, "the draft's Drafts copy expunged after the send")
check(sql(f"SELECT uid IS NULL AND outbound_id = {o1} FROM mail_drafts WHERE id = {d1}") == "t", "…and the draft row points at its send")

print("2. Undo and schedule")
d2 = new_draft(PRIYA, 26, f"Undo me {RUN}")
o2 = submit(PRIYA, 26, ["outside@customer.example"], f"Undo me {RUN}", draft=d2)
got = sql(f"SELECT ml_outbound_cancel({o2}, 26)")
check(status(o2) == "cancelled" and got == str(d2), "Undo inside the window: cancelled, the draft handed back")
check(sql(f"SELECT outbound_id IS NULL FROM mail_drafts WHERE id = {d2}") == "t", "…the draft reopened (no send on it)")
time.sleep(5)
check(not spooled(message_id(o2)), "…never submitted")
err = sql_error(f"SELECT ml_outbound_cancel({o2}, 30)")
check("writer" in err, "another member cannot undo it", err)
o3 = submit(PRIYA, 26, ["outside@customer.example"], f"Scheduled {RUN}", send_at=sql("SELECT (now() + interval '2 minutes')::text"))
time.sleep(4)
check(status(o3) == "queued" and not spooled(message_id(o3)), "a send scheduled two minutes ahead waits")
sql(f"UPDATE outbound_email SET send_after = now() - interval '1 second' WHERE id = {o3}")
err = sql_error(f"SELECT ml_outbound_cancel({o3}, 26)")
check("Too late to undo" in err, "after the window: refused with the guard's sentence", err)
check(wait_sent(o3) is not None and status(o3) == "sent" and len(spooled(message_id(o3))) == 1, "…and sent exactly once when its time came (the clock moved in the database)")
o4 = submit(PRIYA, 26, ["outside@customer.example"], f"Cancelled schedule {RUN}", send_at=sql("SELECT (now() + interval '2 minutes')::text"))
sql(f"SELECT ml_outbound_cancel({o4}, 26)")
sql(f"UPDATE outbound_email SET send_after = now() - interval '1 second' WHERE id = {o4}")
time.sleep(4)
check(status(o4) == "cancelled" and not spooled(message_id(o4)), "a scheduled send cancelled before its time is never sent")

print("3. A reply: threading headers, \\Answered on the original, never \\Seen")
orig = sql(f"SELECT m.message_key_id || '|' || m.uid || '|' || m.message_id_header FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = {PRIYA} AND f.special_use = 'inbox' AND NOT m.seen ORDER BY m.id LIMIT 1").split("|")
okey, ouid, omid = int(orig[0]), orig[1], orig[2]
stores_before = len(imap_stats().get("stores", []))
o5 = submit(PRIYA, 26, ["sender@outside.example"], f"Re: Synthetic {RUN}", "Thanks.", reply_to=okey)
check(wait_sent(o5) is not None and status(o5) == "sent", "the reply is sent")
m = spool_message(spooled(message_id(o5))[0])
check(m["In-Reply-To"] == omid and omid in (m["References"] or ""), "In-Reply-To and References carry the original's Message-ID", f"{m['In-Reply-To']} | {m['References']}")
check(wait(lambda: "\\Answered" in flags("priya@business.example", "INBOX").get(ouid, []), 10) is not None, "the original flagged \\Answered in the fixture")
check("\\Seen" not in flags("priya@business.example", "INBOX").get(ouid, []), "…and not \\Seen")
stores = imap_stats().get("stores", [])[stores_before:]
check(stores and all("\\Seen" not in s["flags"] for s in stores), "no STORE of \\Seen reached the server", str(stores))
check(sql(f"SELECT thread_anchor_key IS NOT NULL FROM outbound_email WHERE id = {o5}") == "t", "the reply belongs to the conversation (its anchor key)")

print("4. Drafts: one copy at a time; a draft written elsewhere")
d3 = new_draft(PRIYA, 26, f"Saved thrice {RUN}")
uids = []
for i in range(3):
    sql(f"UPDATE mail_drafts SET body_text = 'version {i}', saved_at = clock_timestamp() WHERE id = {d3}")
    s, j = call(f"/v1/mailboxes/{PRIYA}/append_draft", 26, {"draft": d3})
    uids.append(str(j["result"]["uid"]))
key3 = sql(f"SELECT draft_key FROM mail_drafts WHERE id = {d3}")
dflags = flags("priya@business.example", "Drafts")
check(len(set(uids)) == 3 and uids[-1] in dflags and uids[0] not in dflags and uids[1] not in dflags, "three saves: the UID changes each time and only the last copy remains", str(uids))
check(sql(f"SELECT uid FROM mail_drafts WHERE id = {d3}") == uids[-1] and sql(f"SELECT draft_key FROM mail_drafts WHERE id = {d3}") == key3, "…the row holds the last UID; its draft_key never changed")
sql(f"UPDATE mail_drafts SET body_text = 'version 4', saved_at = clock_timestamp() WHERE id = {d3}")
sql(f"NOTIFY ml_drafts, '{d3}'")
check(wait(lambda: sql(f"SELECT uid FROM mail_drafts WHERE id = {d3}") not in (uids[-1], ""), 15) is not None, "a NOTIFY ml_drafts wakes the Drafts loop, which files the newer version by itself")
s, j = call(f"/v1/mailboxes/{PRIYA}/append_draft", 26, {"draft": d3, "discard": True})
check(s == 200 and sql(f"SELECT uid IS NULL FROM mail_drafts WHERE id = {d3}") == "t" and not any(u in flags("priya@business.example", "Drafts") for u in uids), "discard: the copy expunged, the row cleared")
phone_mid = f"<phone-{RUN}@business.example>"
raw = f"From: Priya <priya@business.example>\r\nTo: friend@outside.example\r\nSubject: From my phone {RUN}\r\nDate: {email.utils.formatdate(usegmt=True)}\r\nMessage-ID: {phone_mid}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nTyped on the train.\r\n"
ctl(op="deliver", user="priya@business.example", folder="Drafts", raw=raw, flags=["\\Draft"])
check(wait(lambda: sql(f"SELECT count(*) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = {PRIYA} AND f.special_use = 'drafts' AND m.message_id_header = '{phone_mid}'") == "1", 20) is not None,
      "a draft a phone APPENDed to Drafts is found by the sync (the composer opens it by its key — checks.php)")
pathlib.Path(STATE / "phone-draft.txt").write_text(phone_mid)

print("5. The encoded-size refusal")
d4 = new_draft(PRIYA, 26, f"Too big {RUN}")
store = pathlib.Path(os.environ.get("ML_STORAGE_DIR", str(STATE / "storage")))
(store / "files").mkdir(parents=True, exist_ok=True)
for i in range(2):
    p = store / "files" / f"big{i}-{RUN}.bin"
    p.write_bytes(os.urandom(4 * 1024 * 1024))
    sql(f"INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('mail_draft', {d4}, 'big{i}.bin', 'application/octet-stream', {p.stat().st_size}, '{'0' * 64}', 'files/{p.name}', 26)")
o6 = submit(PRIYA, 26, ["outside@customer.example"], f"Too big {RUN}", draft=d4, has_att=True)
sql(f"UPDATE attachments SET record_type = 'outbound_email', record_id = {o6} WHERE record_type = 'mail_draft' AND record_id = {d4}")
check(wait_sent(o6) is not None and status(o6) == "failed" and "larger than MaluMail accepts" in row(o6, "error")[0], "two 4 MB files encode past 9.8 MiB: failed before submission with the sentence", "|".join(row(o6, "status, error")))
check(not spooled(message_id(o6)), "…nothing reached the server")

print("6. The server's answers")
set_mode("dana@business.example", "defer_once")             # a rewrite of the users file starts the `_once` modes afresh
o7 = submit(DANA, 30, ["outside@customer.example"], f"Deferred once {RUN}")
check(wait(lambda: status(o7) == "deferred", 20) is not None, "Dana's server answers 451 once: the row is deferred")
r = row(o7, "smtp_reply, attempts, (retry_at > now())::text")
check(r[0].startswith("451") and r[1] == "1" and r[2] == "true", "…with the reply kept, one attempt counted and the next try ahead (shown on the Outbox)", "|".join(r))
sql(f"UPDATE outbound_email SET retry_at = now() - interval '1 second', send_after = now() - interval '1 second' WHERE id = {o7}")
check(wait_sent(o7) is not None and status(o7) == "sent" and row(o7, "attempts")[0] == "2" and len(spooled(message_id(o7))) == 1, "…then sent on the second attempt, once")
o8 = submit(NINA, 26, ["outside@customer.example"], f"Always deferred {RUN}")
for i in range(5):
    check(wait(lambda: int(row(o8, "attempts")[0]) == i + 1 and status(o8) in ("deferred", "failed"), 25) is not None, f"Nina's server defers every time: deferral {i + 1}", "|".join(row(o8, "status, attempts")))
    if status(o8) == "failed":
        break
    sql(f"UPDATE outbound_email SET retry_at = now() - interval '1 second', send_after = now() - interval '1 second' WHERE id = {o8}")
check(wait(lambda: status(o8) == "failed", 20) is not None and "kept deferring" in row(o8, "error")[0] and row(o8, "attempts")[0] == "5", "the fifth deferral fails the row with the sentence", "|".join(row(o8, "status, attempts, error")))
notices_before = int(sql("SELECT count(*) FROM notifications WHERE kind = 'send_failed'"))
o9 = submit(SALES, 26, ["good@customer.example", "nobody@nowhere.example"], f"Partly refused {RUN}")
check(wait_sent(o9) is not None and status(o9) == "sent", "Sales' server refuses one of two recipients: the message is sent to the other")
r = row(o9, "smtp_reply")
check("refused" in r[0] and "nobody@nowhere.example" in r[0] and "550" in r[0], "…the refusal named in the reply", r[0])
check(spooled(message_id(o9))[0]["rcpt"] == ["good@customer.example"], "…the spool shows the one accepted recipient")
check(wait(lambda: int(sql("SELECT count(*) FROM notifications WHERE kind = 'send_failed'")) == notices_before + 1, 10) is not None
      and sql(f"SELECT member_id || '|' || body FROM notifications WHERE kind = 'send_failed' ORDER BY id DESC LIMIT 1") == f"26|The server refused 1 recipient at nowhere.example.", "…a send_failed notice to the writer naming the refused domain, never the address")
dropped_before = smtp_stats()["dropped"]
set_mode("marco@business.example", "drop_once")
o10 = submit(MARCO, 27, ["outside@customer.example"], f"Dropped {RUN}")
check(wait_sent(o10) is not None and status(o10) == "failed", "Marco's server closes the connection during DATA: the row is failed, not deferred", status(o10))
check("may have gone" in row(o10, "error")[0] and row(o10, "attempts")[0] == "1", "…with decision 1's sentence and ONE attempt", "|".join(row(o10, "attempts, error")))
time.sleep(5)
check(smtp_stats()["dropped"] == dropped_before + 1 and row(o10, "attempts")[0] == "1" and not spooled(message_id(o10)), "…never submitted again by itself")
err = sql_error(f"UPDATE outbound_email SET status = 'queued' WHERE id = {o10} AND status = 'sending'")
check(sql(f"SELECT ml_outbound_retry({o10}, 27)") in ("", "0") and status(o10) in ("queued", "sending", "sent"),
      "…a PERSON may retry it after checking with the recipient (ml_outbound_retry — queued again; the loop may already have taken it)", status(o10))
sql_error(f"SELECT ml_outbound_cancel({o10}, 27)")
print("   the crash rule (a restart of the mail service)")
lost_mid = f"<lost-{RUN}@business.example>"
# Two rows left in `sending` by a crash, written straight into that state with the triggers paused, so the running loop can never claim
# them first (a `queued` row is claimed within a second): one whose Message-ID is in Sent (step 1's), one that never reached the server.
ids = [x for x in sql(
    "ALTER TABLE outbound_email DISABLE TRIGGER USER",
    f"INSERT INTO outbound_email (mailbox_id, sender_member_id, to_address, to_addresses, subject, body_text, message_id, status, send_after, attempts, body_digest, updated_at) VALUES ({PRIYA}, 26, 'outside@customer.example', ARRAY['outside@customer.example'], 'Was in Sent {RUN}', 'x', '{mid1}', 'sending', now() - interval '1 hour', 1, md5('a{RUN}'), now() - interval '11 minutes'), ({PRIYA}, 26, 'outside@customer.example', ARRAY['outside@customer.example'], 'Was lost {RUN}', 'x', '{lost_mid}', 'sending', now() - interval '1 hour', 1, md5('b{RUN}'), now() - interval '11 minutes') RETURNING id",
    "ALTER TABLE outbound_email ENABLE TRIGGER USER").split("\n") if x.strip().isdigit()]
sub = subprocess.run(["bash", str(ROOT / "tests/phase2/servers.sh"), "restart-mailer"], capture_output=True, text=True, env={**os.environ})
check(sub.returncode == 0 and httpx.get(MAILER + "/health", timeout=5).status_code == 200, "the mail service restarted alone", sub.stderr[-300:])
found = int(ids[0]) if "Was in Sent" in sql(f"SELECT subject FROM outbound_email WHERE id = {ids[0]}") else int(ids[1])
lost = int(ids[1]) if found == int(ids[0]) else int(ids[0])
check(wait(lambda: status(found) == "sent", 20) is not None and "found in Sent" in row(found, "smtp_reply")[0], "a row left in `sending` whose Message-ID is in Sent: sent (the restart's search)", "|".join(row(found, "status, smtp_reply")))
check(wait(lambda: status(lost) == "failed", 20) is not None and "may have gone" in row(lost, "error")[0], "a row left in `sending` and not in Sent: failed with the sentence", "|".join(row(lost, "status, error")))
check(len(spooled(mid1)) == 1 and not spooled(lost_mid), "…one copy in the spool, never two")
print("   the breaker")
set_password("nina@business.example", "wrong-" + RUN)
auth_before = smtp_stats()["auth_failed"]
o11 = submit(NINA, 26, ["outside@customer.example"], f"Breaker {RUN}")
check(wait_sent(o11) is not None and status(o11) == "failed" and "credential was refused" in row(o11, "error")[0], "535 at login: the row failed with the credential sentence", "|".join(row(o11, "status, error")))
check(smtp_stats()["auth_failed"] == auth_before + 1, "…the fixture saw ONE attempt")
check(sql(f"SELECT c.status FROM mailbox_credentials c JOIN mailboxes b ON b.credential_id = c.id WHERE b.id = {NINA}") == "failed", "…the credential is failed (the breaker tripped)")
o12 = submit(NINA, 26, ["outside@customer.example"], f"Breaker again {RUN}")
check(wait_sent(o12) is not None and status(o12) == "failed" and smtp_stats()["auth_failed"] == auth_before + 1, "a second send fails at once — no further login attempt")

print("7. An agent's message")
err = submit_error(EXPERT, 40, ["dana@business.example"], f"No purpose {RUN}")
check("purpose" in err, "an agent's send without a purpose is refused in SQL", err)
sql(f"UPDATE mail_identities SET signature_text = 'Mail Expert, an AI agent', signature_html = '<p>Mail Expert, an AI agent</p>' WHERE mailbox_id = {EXPERT}")
o13 = submit(EXPERT, 40, ["stranger@customer.example"], f"Held {RUN}", purpose="answer a question")
check(status(o13) == "held" and set(sql(f"SELECT array_to_string(hold_reasons, ',') FROM outbound_email WHERE id = {o13}").split(",")) >= {"mode", "first_contact"}, "under hold_all to a stranger: held with its reasons", sql(f"SELECT status || ' ' || array_to_string(hold_reasons, ',') FROM outbound_email WHERE id = {o13}"))
check(sql(f"SELECT ml_outbound_cancel({o13}, 40)") in ("", "0") or status(o13) == "cancelled", "the agent withdraws its own held message")
sql(f"UPDATE agent_mail_policies SET send_mode = 'internal_auto', first_contact_review = false, updated_by = 1 WHERE mailbox_id = {EXPERT}")
o14 = submit(EXPERT, 40, ["dana@business.example"], f"Internal {RUN}", "Here is the answer.", purpose="answer a question")
check(status(o14) in ("queued", "sending", "sent") and float(sql(f"SELECT extract(epoch FROM send_after - created_at) FROM outbound_email WHERE id = {o14}")) <= 0.5,
      "internal_auto to a colleague: queued with NO undo window (send_after = the moment it was made; the loop may already have taken it)", status(o14))
check(wait_sent(o14) is not None and status(o14) == "sent", "…and sent")
m = spool_message(spooled(message_id(o14))[0])
agent_name = sql("SELECT display_name FROM members WHERE id = 40")
check((m["X-OS-Agent"] or "").startswith(agent_name + ";") and "business=" in (m["X-OS-Agent"] or "") and m["Auto-Submitted"] == "auto-generated",
      "X-OS-Agent (the agent's own name; business=…) and Auto-Submitted in the submitted message", f"{m['X-OS-Agent']} | {m['Auto-Submitted']}")
body = m.get_body(preferencelist=("plain",)).get_content()
check("Mail Expert, an AI agent" in body and "-- " in body, "the identity's signature appended by the service")
check("AI agent" in body.split("-- ")[-1] or "automated" in body.lower() or "agent" in body.lower(), "the disclosure footer follows", body[-200:])
o15 = submit(EXPERT, 40, ["dana@business.example"], f"Internal {RUN}", "Here is the answer.", purpose="answer a question")
check(status(o15) == "refused" and row(o15, "refusal")[0] == "same_answer", "the same answer to the same recipient within seven days: refused (same_answer)", "|".join(row(o15, "status, refusal")))
sql(f"UPDATE agent_mail_policies SET send_mode = 'hold_all', first_contact_review = true, updated_by = 1 WHERE mailbox_id = {EXPERT}")

print(f"{FAILS} FAILED ({PASSES} passed)" if FAILS else f"all {PASSES} passed")
sys.exit(1 if FAILS else 0)
