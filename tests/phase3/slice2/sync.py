"""Slice 2's sync proof (mail-service.md §13; mail-list.md Proof 1): the mail service against the IMAP fixture, run by
tests/phase3/slice2/run.sh after the servers started and the seven mailboxes were seeded (one of 5,000 messages). Prints ok/FAIL per
check; exits non-zero on any failure. Nothing here reaches MaluMail.

The fixture is driven through its control directory (another client's flag, expunge, move; a delivery; a UIDVALIDITY reset; NOTIFY
refused; a dropped connection) and read through its stats (every login, every STORE, any \\Seen a non-PEEK fetch set)."""

import asyncio
import importlib.util
import json
import os
import subprocess
import sys
import time
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(ROOT / "mcp"))

import asyncpg  # noqa: E402
import httpx  # noqa: E402

from services.mailer import door, index  # noqa: E402

DB = os.environ["ML_DEV_DB"]
CONTROL = Path(os.environ["ML_IMAP_CONTROL"])
STATS = Path(os.environ["ML_IMAP_STATS"])
MAILER = f"http://127.0.0.1:{os.environ.get('P_MAILER', '9206')}"
KEY = os.environ["ML_MAILER_KEY"].encode()
SEED = json.loads(Path(os.environ["ML_P2_SEED"]).read_text())
FAILS = PASSES = 0


def check(cond, label, detail=""):
    global FAILS, PASSES
    if cond:
        PASSES += 1
        print(f"  ok   {label}")
    else:
        FAILS += 1
        print(f"  FAIL {label}" + (f" — {detail}" if detail else ""))


def sql(q: str) -> str:
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-v", "ON_ERROR_STOP=1", "-d", DB, "-c", q],
                       capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip())
    return r.stdout.strip()


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
    raise RuntimeError(f"the fixture did not answer {cmd}")


def stats() -> dict:
    return json.loads(STATS.read_text())


def call(path: str, actor: int, args=None, on_behalf=None):
    body = json.dumps({"actor": actor, "on_behalf": on_behalf, "args": args or {}}).encode()
    r = httpx.post(MAILER + path, content=body, headers={door.HEADER: door.sign(KEY, "POST", path, body), "content-type": "application/json"},
                   timeout=30)
    return r.status_code, r.json()


def wait(pred, timeout: float, step: float = 0.05):
    t0 = time.time()
    while time.time() - t0 < timeout:
        try:
            if pred():
                return time.time() - t0
        except RuntimeError:
            pass
        time.sleep(step)
    return None


def seq(mb: int) -> int:
    return int(sql(f"SELECT COALESCE((SELECT seq FROM mail_mailbox_changes WHERE mailbox_id = {mb}), 0)"))


def folder(mb: int, special: str) -> int:
    return int(sql(f"SELECT id FROM mail_folders WHERE mailbox_id = {mb} AND special_use = '{special}'"))


BIG, PRIYA, DANA, EXPERT = SEED["big"], SEED["priya"], SEED["dana"], SEED["expert"]
LIVE = [SEED[k] for k in ("priya", "dana", "sales", "team", "expert", "intake", "big")]

# ---- 1. the first sync ------------------------------------------------------------------------------------------------------------
print("the first sync")
took = wait(lambda: sql(f"SELECT count(*) FROM mailboxes WHERE id IN ({','.join(map(str, LIVE))}) AND sync_state = 'live'") == "7", 240, 0.5)
check(took is not None, "all seven mailboxes reach `live` (new → syncing → live)")
row = sql(f"""SELECT count(*), count(DISTINCT (folder_id, uidvalidity, uid)), count(DISTINCT message_key_id),
                     count(*) FILTER (WHERE seen), count(*) FILTER (WHERE flagged) FROM mail_messages WHERE mailbox_id = {BIG}""").split("|")
check(row[0] == "5000" and row[1] == "5000", "a 5,000-message mailbox: every message indexed once", "|".join(row))
check(row[2] == "5000", "…each with its own message key")
check(row[3] == "2500" and row[4] == str(len([i for i in range(5000) if i % 17 == 0])), "…flags as the server holds them (every other one read, every 17th starred)",
      "|".join(row))
check(sql(f"SELECT count(*) FROM mail_threads WHERE mailbox_id = {BIG}") == "4500", "…threaded: replies joined their conversations (4,500 of 5,000)")
check(sql(f"""SELECT count(*) FROM mail_messages m JOIN mail_message_keys k ON k.id = m.message_key_id
               WHERE m.mailbox_id = {BIG} AND k.message_key = m.message_id_header""") == "5000", "every key is the message's Message-ID")
el = sql(f"SELECT extract(epoch FROM last_sync_at - created_at)::int FROM mailboxes WHERE id = {BIG}")
print(f"       (the first sync of 5,000 messages, from the seed to `live`: about {el} s)")
order = sql(f"""SELECT string_agg(COALESCE(special_use, name), ',' ORDER BY synced_at, id) FROM mail_folders WHERE mailbox_id = {BIG}""")
check(order.split(",")[0] == "inbox", "INBOX is read before the other folders (its conversations usable first)", order)
check(sql(f"SELECT string_agg(name || ':' || special_use, ',' ORDER BY name) FROM mail_folders WHERE mailbox_id = {BIG} AND name IN ('Archive', 'Snoozed')")
      == "Archive:archive,Snoozed:snoozed", "Archive and Snoozed made by name where missing and recorded as such")
check(sql(f"SELECT quota_used_bytes > 0 AND quota_bytes > 0 FROM mailboxes WHERE id = {BIG}") == "t", "GETQUOTAROOT recorded the quota and its use")
check(sql(f"SELECT count(*) FROM mail_folders WHERE mailbox_id = {BIG} AND (uidvalidity IS NULL OR uidnext IS NULL OR highestmodseq IS NULL OR synced_at IS NULL)") == "0",
      "every folder's UIDVALIDITY, UIDNEXT, HIGHESTMODSEQ and synced_at recorded")

# the digest: Python's and SQL's agree, and agree with the key the index holds
spec = importlib.util.spec_from_file_location("fixture", ROOT / "tests" / "fixtures" / "imap_server.py")
fx = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fx)
from email.parser import BytesHeaderParser  # noqa: E402
from email.policy import default as POLICY  # noqa: E402

agree = held = 0
samples = fx.synthetic(60, "big@business.example")
for i in (0, 3, 7, 11, 13, 17, 23, 42, 59):
    raw, _f, _w = samples[i]
    h = BytesHeaderParser(policy=POLICY).parsebytes(raw)
    vals = [str(h[k]) if h[k] is not None else None for k in ("Date", "From", "To", "Cc", "Subject")]
    py = index.envelope_digest(*vals)
    lit = ", ".join("NULL" if v is None else "'" + v.replace("'", "''") + "'" for v in vals)
    agree += py == sql(f"SELECT ml_envelope_digest({lit})")
    held += py == sql(f"SELECT k.envelope_digest FROM mail_message_keys k WHERE k.mailbox_id = {BIG} AND k.message_id_header = '<fixture-{i}@outside.example>'")
check(agree == 9, "ml_envelope_digest computed in Python equals SQL's (nine messages of every shape)", f"{agree}/9")
check(held == 9, "…and equals the digest of the key the index holds", f"{held}/9")
check(sql(f"""SELECT count(*) FROM mail_messages WHERE mailbox_id = {BIG} AND message_id_header IN ('<fixture-0@outside.example>', '<fixture-11@outside.example>')
               AND has_attachments AND attachment_names[1] LIKE 'report-%.pdf'""") == "2", "attachments read from BODYSTRUCTURE (mixed, and mixed around alternative)")
check(sql(f"SELECT snippet FROM mail_messages WHERE mailbox_id = {BIG} AND message_id_header = '<fixture-7@outside.example>'") == "Synthetic body 7. kumquat7",
      "the snippet is the server's PREVIEW")
check(sql(f"SELECT count(*) FROM mail_messages WHERE mailbox_id = {BIG} AND is_list") == str(len([i for i in range(5000) if i % 13 == 0])),
      "mailing lists (List-Id) marked")

# ---- the service's door: status, health ------------------------------------------------------------------------------------------
s, j = call(f"/v1/mailboxes/{BIG}/status", 26)
r = j.get("result", {})
check(s == 200 and r.get("sync_state") == "live" and r.get("watcher") == "notify" and r.get("credential") == "ok"
      and any(f["special_use"] == "inbox" and f["messages_total"] == 5000 for f in r.get("folders", [])),
      "`status` answers the sync state, the watcher (NOTIFY), the credential and the folders", json.dumps(r)[:300])
s, j = call(f"/v1/mailboxes/{BIG}/status", 30)
check(s == 404, "…and 404 to someone who cannot open the mailbox (never 403)", str(s))
s, j = call(f"/v1/mailboxes/{BIG}/status", 1)
check(s == 200, "…and to a Mail admin (settings.manage)")
s, j = call(f"/v1/mailboxes/{BIG}/sync", 30, {})
check(s == 404, "`sync` refuses someone who cannot open the mailbox — 404")
s, j = call(f"/v1/mailboxes/{SEED['sales']}/sync", 30, {})
check(s == 403, "…and someone who only views it — 403 (full or a Mail admin)", str(s))
s, j = call(f"/v1/mailboxes/{BIG}/quota", 26)
check(s == 200 and (j.get("result") or {}).get("used_bytes", 0) > 0 and j["result"].get("read_at"), "`quota` reads GETQUOTAROOT now", json.dumps(j)[:200])
h = httpx.get(MAILER + "/health").json()
check(h.get("watchers") == 7 and h.get("mailboxes_live") == 7 and h.get("mailboxes_failing") == 0 and "pool_connections" in h and "text_due" in h,
      "/health counts watchers, pool connections, text due, live and failing mailboxes", json.dumps(h)[:300])

# ---- the text pass ------------------------------------------------------------------------------------------------------------------
print("the text pass")
check(wait(lambda: sql("SELECT count(*) FROM mail_messages WHERE text_indexed_at IS NULL") == "0", 180, 0.5) is not None,
      "every message's text indexed by the gentle pass")
sql("UPDATE ml_settings SET index_text_max_kb = 10 WHERE id = 1")
long_body = "headword " + ("filler " * 4000) + " tailword"
ctl(op="deliver", user="priya@business.example", subject="A long message", body=long_body)
ctl(op="deliver", user="priya@business.example",
    raw="From: Html Only <html@outside.example>\r\nTo: priya@business.example\r\nSubject: Html only\r\nDate: Mon, 12 Oct 2026 10:00:00 +0000\r\n"
        "Message-ID: <html-only@outside.example>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
        "<html><body><p>Quince <b>marmalade</b></p><script>alert(1)</script></body></html>\r\n")
check(wait(lambda: sql(f"SELECT count(*) FROM mail_messages WHERE mailbox_id = {PRIYA} AND subject IN ('A long message', 'Html only') AND text_indexed_at IS NOT NULL") == "2", 30)
      is not None, "new mail's text indexed within seconds")
blen = int(sql(f"SELECT length(body_text) FROM mail_messages WHERE mailbox_id = {PRIYA} AND subject = 'A long message'"))
check(0 < blen <= 10 * 1024, f"…capped at index_text_max_kb (10 kB: {blen} characters)")
check(sql(f"SELECT body_text FROM mail_messages WHERE mailbox_id = {PRIYA} AND subject = 'Html only'") == "Quince marmalade",
      "an HTML-only message's text is its words — no tag, no script")


def search(q: str, mb: int, member: int = 26) -> str:
    return sql(f"SET app.member_id = '{member}'; SELECT count(*) FROM ml_search('{q}', {mb})").splitlines()[-1]


check(search("kumquat4321", BIG) == "1", "ml_search finds a word of the indexed text (one message of 5,000)")
check(search("headword", PRIYA) == "1" and search("tailword", PRIYA) == "0", "…and nothing past the cap")
check(search("marmalade", PRIYA) == "1", "…and the words of an HTML-only message")
sql("UPDATE ml_settings SET index_text_max_kb = 100 WHERE id = 1")

# ---- NOTIFY and the change counter ---------------------------------------------------------------------------------------------
print("staying in step")
before = seq(BIG)
time.sleep(3)
check(seq(BIG) == before, "the change counter stays still while nothing changes (3 s, the watcher idling)")
t0 = time.time()
ctl(op="deliver", user="big@business.example", subject="Notify me")
took = wait(lambda: sql(f"SELECT count(*) FROM mail_messages WHERE mailbox_id = {BIG} AND subject = 'Notify me'") == "1", 10)
check(took is not None and took <= 2, f"NOTIFY: a new message is in the index within 2 s ({took and round(took, 2)} s)")
check(seq(BIG) > before, "…and the change counter moved")
d0 = int(sql(f"SELECT count(*) FROM agent_dispatches WHERE mailbox_id = {EXPERT}"))
ctl(op="deliver", user="expert@business.example", subject="For the agent")
check(wait(lambda: int(sql(f"SELECT count(*) FROM agent_dispatches WHERE mailbox_id = {EXPERT}")) > d0, 10) is not None,
      "a new INBOX message of an agent's mailbox goes to ml_dispatch_decide() (a dispatch row)")

uid_flag = int(sql(f"SELECT uid FROM mail_messages WHERE mailbox_id = {BIG} AND message_id_header = '<fixture-4998@outside.example>'"))
before = seq(BIG)
ctl(op="flag", user="big@business.example", uid=uid_flag, add=["\\Flagged"])
took = wait(lambda: sql(f"SELECT flagged FROM mail_messages WHERE mailbox_id = {BIG} AND uid = {uid_flag} AND folder_id = {folder(BIG, 'inbox')}") == "t", 10)
check(took is not None and took <= 2, f"another client's flag reaches the index through QRESYNC ({took and round(took, 2)} s)")
check(sql(f"SELECT t.flagged FROM mail_threads t JOIN mail_messages m ON m.thread_id = t.id WHERE m.mailbox_id = {BIG} AND m.uid = {uid_flag}") == "t"
      and seq(BIG) > before, "…its conversation is starred and the counter moved")

uid_gone = int(sql(f"SELECT uid FROM mail_messages WHERE mailbox_id = {BIG} AND message_id_header = '<fixture-4997@outside.example>'"))
key_gone = sql(f"SELECT message_key_id FROM mail_messages WHERE mailbox_id = {BIG} AND uid = {uid_gone}")
ctl(op="expunge", user="big@business.example", uid=uid_gone)
took = wait(lambda: sql(f"SELECT count(*) FROM mail_messages WHERE mailbox_id = {BIG} AND uid = {uid_gone} AND folder_id = {folder(BIG, 'inbox')}") == "0", 10)
check(took is not None and took <= 2, f"another client's expunge: VANISHED deletes the row ({took and round(took, 2)} s)")
check(sql(f"SELECT count(*) FROM mail_message_keys WHERE id = {key_gone}") == "1", "…and its key stays")

uid_move = int(sql(f"SELECT uid FROM mail_messages WHERE mailbox_id = {BIG} AND message_id_header = '<fixture-4996@outside.example>'"))
key_move = sql(f"SELECT message_key_id FROM mail_messages WHERE mailbox_id = {BIG} AND uid = {uid_move} AND folder_id = {folder(BIG, 'inbox')}")
ctl(op="move", user="big@business.example", uid=uid_move, to="Archive")
took = wait(lambda: sql(f"SELECT count(*) FROM mail_messages WHERE message_key_id = {key_move} AND folder_id = {folder(BIG, 'archive')}") == "1"
            and sql(f"SELECT count(*) FROM mail_messages WHERE message_key_id = {key_move} AND folder_id = {folder(BIG, 'inbox')}") == "0", 10)
check(took is not None, f"another client's move: gone from INBOX, made again in Archive under the SAME key ({took and round(took, 2)} s)")

# ---- a UIDVALIDITY reset keeps what the application keyed on the message ------------------------------------------------------------
print("a UIDVALIDITY reset")
key = sql(f"SELECT message_key_id FROM mail_messages WHERE mailbox_id = {BIG} AND message_id_header = '<fixture-4990@outside.example>'")
sql(f"""INSERT INTO tags (slug, label, mailbox_id) VALUES ('proof-s2', 'Proof', {BIG});
        INSERT INTO record_tags (record_type, record_id, tag_id) SELECT 'mail_message', {key}, id FROM tags WHERE slug = 'proof-s2' AND mailbox_id = {BIG};
        INSERT INTO mail_viewer_reads (member_id, message_key_id) VALUES (26, {key});
        INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, muted, muted_by) VALUES ({BIG}, {key}, true, 26);""")
old_v = int(sql(f"SELECT uidvalidity FROM mail_folders WHERE id = {folder(BIG, 'inbox')}"))
new_v = ctl(op="reset", user="big@business.example", folder="INBOX")["uidvalidity"]
s, j = call(f"/v1/mailboxes/{BIG}/sync", 26, {"folder": folder(BIG, "inbox")})
check(s == 200 and j["result"]["queued"], "`sync` queues a folder's re-read for the watcher")
took = wait(lambda: sql(f"SELECT uidvalidity FROM mail_folders WHERE id = {folder(BIG, 'inbox')}") == str(new_v)
            and int(sql(f"SELECT count(*) FROM mail_messages WHERE folder_id = {folder(BIG, 'inbox')}")) >= 4998, 120, 0.2)
check(took is not None and new_v != old_v, f"a new UIDVALIDITY: ml_folder_reset() and the folder re-read ({took and round(took, 1)} s)")
check(sql(f"SELECT count(*) FROM mail_messages WHERE message_key_id = {key}") == "1", "…the message found again by its key")
check(sql(f"""SELECT (SELECT count(*) FROM record_tags WHERE record_id = {key}) || ',' || (SELECT count(*) FROM mail_viewer_reads WHERE message_key_id = {key})
               || ',' || (SELECT count(*) FROM mail_thread_states WHERE anchor_key_id = {key} AND muted)""") == "1,1,1",
      "…and its label, a person's read mark and its conversation's state survive (by key)")
check(wait(lambda: sql(f"""SELECT (SELECT st.muted FROM ml_thread_state(m.thread_id) st) FROM mail_messages m WHERE m.message_key_id = {key}""") == "t",
           30, 0.2) is not None, "…the state found by the re-made conversation (once the rebuild has threaded it)")

# ---- a dropped watcher reconnects and catches up --------------------------------------------------------------------------------
print("a dropped connection")
ctl(op="drop", user="big@business.example")
ctl(op="deliver", user="big@business.example", subject="While down")
took = wait(lambda: sql(f"SELECT count(*) FROM mail_messages WHERE mailbox_id = {BIG} AND subject = 'While down'") == "1", 30, 0.2)
check(took is not None, f"the watcher reconnects and SELECT … QRESYNC catches up what arrived while it was down ({took and round(took, 1)} s)")

# ---- without NOTIFY: IDLE and the STATUS round -----------------------------------------------------------------------------------
print("without NOTIFY")
ctl(op="notify", refuse=True)
ctl(op="drop", user="dana@business.example")
check(wait(lambda: call(f"/v1/mailboxes/{DANA}/status", 30)[1]["result"].get("watcher") == "idle", 30, 0.3) is not None,
      "NOTIFY refused: the watcher falls back to IDLE on INBOX")
ctl(op="deliver", user="dana@business.example", subject="By idle")
took = wait(lambda: sql(f"SELECT count(*) FROM mail_messages WHERE mailbox_id = {DANA} AND subject = 'By idle'") == "1", 10)
check(took is not None and took <= 2, f"…a new INBOX message within 2 s ({took and round(took, 2)} s)")
t0 = time.time()
ctl(op="deliver", user="dana@business.example", folder="Sent", subject="In another folder", **{"from": "Only Sent <sentonly@outside.example>"})
took = wait(lambda: sql(f"SELECT count(*) FROM mail_messages WHERE mailbox_id = {DANA} AND subject = 'In another folder'") == "1", 75, 0.5)
check(took is not None and took <= 70, f"…another folder's within 70 s, by the STATUS round ({took and round(took, 1)} s)")
check(sql(f"SELECT count(*) FROM mail_correspondents WHERE mailbox_id = {DANA} AND address IN ('someone@outside.example', 'sentonly@outside.example')") == "1"
      and sql(f"SELECT count(*) FROM mail_correspondents WHERE mailbox_id = {DANA} AND address = 'sentonly@outside.example'") == "0",
      "received mail counts its sender as a correspondent; mail in Sent does not (only the outbox makes an address known)")
ctl(op="notify", refuse=False)
ctl(op="drop", user="dana@business.example")
check(wait(lambda: call(f"/v1/mailboxes/{DANA}/status", 30)[1]["result"].get("watcher") == "notify", 30, 0.3) is not None,
      "NOTIFY offered again: the next connection uses it")

# ---- what a message proves about itself -----------------------------------------------------------------------------------------
print("what a message says about itself")
D = "Date: Mon, 12 Oct 2026 10:00:00 +0000\r\nTo: priya@business.example\r\n"
ctl(op="deliver", user="priya@business.example", raw=D + "From: Out <out@outside.example>\r\nSubject: P outside\r\nMessage-ID: <p1@outside.example>\r\n"
    "X-Spamd-Result: default: False [1.50 / 15.00]; DMARC_POLICY_ALLOW(-0.50)[outside.example,none]\r\n\r\nHello\r\n")
ctl(op="deliver", user="priya@business.example", raw=D + "From: Dana <dana@business.example>\r\nSubject: P internal\r\nMessage-ID: <p2@business.example>\r\n"
    "Received: from client (unknown [10.0.0.5]) by 127.0.0.1 (Postfix) with ESMTPSA id ABC for <priya@business.example>; Mon, 12 Oct 2026 10:00:00 +0000\r\n\r\nHi\r\n")
ctl(op="deliver", user="priya@business.example", raw=D + "From: Dana <dana@business.example>\r\nSubject: P judged\r\nMessage-ID: <p3@business.example>\r\n"
    "Received: from client (unknown [10.0.0.5]) by 127.0.0.1 (Postfix) with ESMTPSA id ABD for <priya@business.example>; Mon, 12 Oct 2026 10:00:00 +0000\r\n"
    "Authentication-Results: 127.0.0.1; dkim=pass\r\n\r\nHi\r\n")
ctl(op="deliver", user="priya@business.example", raw=D + "From: Robot <robot@outside.example>\r\nSubject: P auto\r\nMessage-ID: <p4@outside.example>\r\n"
    "Auto-Submitted: auto-replied\r\nList-Unsubscribe: <mailto:u@outside.example>\r\nReturn-Path: <>\r\n\r\nAway\r\n")
wait(lambda: sql(f"SELECT count(*) FROM mail_messages WHERE mailbox_id = {PRIYA} AND subject LIKE 'P %'") == "4", 10)
facts = dict(line.split("|", 1) for line in sql(f"""SELECT subject, concat_ws(',', COALESCE(dmarc_pass::text, 'null'), COALESCE(spam_score::text, 'null'),
                 local_submission, COALESCE(auto_submitted, 'null'), is_list, return_path_null) FROM mail_messages WHERE mailbox_id = {PRIYA} AND subject LIKE 'P %'""").splitlines())
check(facts.get("P outside") == "true,1.50,f,null,f,f", "X-Spamd-Result: dmarc_pass and the spam score", facts.get("P outside", ""))
check(facts.get("P internal") == "null,null,t,null,f,f", "local_submission only for the ESMTPSA shape with no verdict (and no DMARC asked of it)",
      facts.get("P internal", ""))
check(facts.get("P judged", "").split(",")[2] == "f", "…never when Authentication-Results is there", facts.get("P judged", ""))
check(facts.get("P auto") == "null,null,f,auto-replied,t,t", "Auto-Submitted, List-*, Return-Path: <> read", facts.get("P auto", ""))

# ---- the worker's catch-up --------------------------------------------------------------------------------------------------------
print("the worker's index_catch_up")
sql(f"""UPDATE mailboxes SET last_sync_at = now() - interval '20 minutes' WHERE id = {SEED['team']};
        INSERT INTO mail_thread_tombstones (mailbox_id, thread_id, change_seq, at) VALUES ({SEED['team']}, 999999, 1, now() - interval '2 days')""")
out = subprocess.run(["php", str(ROOT / "bin" / "worker.php"), "--passes=index_catch_up"], capture_output=True, text=True, env=os.environ)
try:
    ran = json.loads(out.stdout)["ran"]["index_catch_up"]
except (ValueError, KeyError):
    ran = {}
check(ran.get("asked", 0) >= 1 and ran.get("pruned", 0) >= 1, "a live mailbox not confirmed for ten minutes is asked to re-read; day-old tombstones pruned",
      out.stdout[-300:] + out.stderr[-300:])
check(wait(lambda: sql(f"SELECT last_sync_at > now() - interval '1 minute' FROM mailboxes WHERE id = {SEED['team']}") == "t", 20) is not None,
      "…and the mail service confirms it")

# ---- PHP's signed calls ---------------------------------------------------------------------------------------------------------
print("PHP's door")


def php(code: str) -> str:
    return subprocess.run(["php", "-r", "require 'app/bootstrap.php'; " + code], capture_output=True, text=True, cwd=ROOT, env=os.environ).stdout.strip()


check(php(f"echo mailer_call('/v1/mailboxes/{BIG}/status', 26)['sync_state'];") == "live", "PHP's mailer_call() signature is accepted by the Python door")
sql(f"UPDATE mailboxes SET ai_read = false WHERE id = {PRIYA}")
check(php(f"try {{ mailer_call('/v1/mailboxes/{PRIYA}/status', 40, [], 26); echo 'opened'; }} catch (MailerRefused $e) {{ echo $e->status; }}") == "404",
      "…on_behalf signed in: the Expert for Priya cannot open her mailbox while its ai_read is off (404)")
sql(f"UPDATE mailboxes SET ai_read = true WHERE id = {PRIYA}")
check(php(f"echo mailer_call('/v1/mailboxes/{PRIYA}/status', 40, [], 26)['sync_state'];") == "live",
      "…and can once she switches it on (ml_level_as, computed by the service)")
check(php(f"try {{ mailer_call('/v1/mailboxes/{PRIYA}/status', 40, [], 30); echo 'opened'; }} catch (MailerRefused $e) {{ echo $e->status; }}") == "404",
      "…but never for someone else (Dana): a person's mailbox only for its owner's turn")
sql(f"UPDATE mailboxes SET ai_read = false WHERE id = {PRIYA}")


# ---- the records tools' queries ---------------------------------------------------------------------------------------------------
async def records():
    print("the records tools' queries")
    rs = importlib.util.spec_from_file_location("records_server", ROOT / "mcp" / "records_server.py")
    rec = importlib.util.module_from_spec(rs)
    rs.loader.exec_module(rec)
    pool = await asyncpg.create_pool(host=os.environ["DB_HOST"], port=int(os.environ["DB_PORT"]), database=os.environ["DB_NAME"],
                                     user=os.environ["MCP_RECORDS_DB_USER"], password=os.environ["MCP_RECORDS_DB_PASSWORD"], min_size=1, max_size=2)
    try:
        r = await rec.find_mailboxes(pool, rec.Caller(30))
        check(r["ok"] and sorted(m["address"].split("@")[0] for m in r["mailboxes"]) == ["dana", "intake", "sales", "team"],
              "find_mailboxes: the mailboxes the caller opens (Dana: her own, sales@ by a row, team@ by her department, intake@)", json.dumps(r)[:200])
        r = await rec.mail_list(pool, rec.Caller(26), PRIYA, "inbox", limit=10)
        check(r["ok"] and len(r["conversations"]) == 10 and r["before"], "mail_list reads ml_mail_list() — the screens' own gatherer — with a cursor")
        r2 = await rec.mail_list(pool, rec.Caller(26), PRIYA, "inbox", before=r["before"], limit=10)
        check(r2["ok"] and not ({c["thread_id"] for c in r["conversations"]} & {c["thread_id"] for c in r2["conversations"]}), "…the next page shares no row")
        n0 = int(sql("SELECT count(*) FROM activity_log WHERE action = 'mcp.read'"))
        r = await rec.mail_list(pool, rec.Caller(40, 26), PRIYA, "inbox")
        check(not r["ok"] and r["error"]["code"] == "not_found", "the Expert for Priya: not_found while her mailbox's ai_read is off")
        sql(f"UPDATE mailboxes SET ai_read = true WHERE id = {PRIYA}")
        r = await rec.mail_list(pool, rec.Caller(40, 26), PRIYA, "inbox", limit=100)
        check(r["ok"] and len(r["conversations"]) > 0, "…her conversations once it is on (ml_level_as)")
        check(int(sql("SELECT count(*) FROM activity_log WHERE action = 'mcp.read'")) == n0 + 1, "…a read of a mailbox not the caller's own logs mcp.read once")
        r = await rec.mail_list(pool, rec.Caller(40, 30), PRIYA, "inbox")
        check(not r["ok"], "…never for Dana's turn")
        thread = r_first = (await rec.mail_list(pool, rec.Caller(26), PRIYA, "inbox", limit=1))["conversations"][0]
        anchor = sql(f"SELECT message_key_id FROM mail_messages WHERE thread_id = {thread['thread_id']} LIMIT 1")
        sql(f"INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, sensitive, sensitive_by) VALUES ({PRIYA}, {anchor}, true, 26)")
        mine = {c["thread_id"] for c in (await rec.mail_list(pool, rec.Caller(26), PRIYA, "inbox", limit=100))["conversations"]}
        agent = {c["thread_id"] for c in (await rec.mail_list(pool, rec.Caller(40, 26), PRIYA, "inbox", limit=100))["conversations"]}
        check(r_first["thread_id"] in mine and r_first["thread_id"] not in agent, "a conversation marked sensitive: Priya sees it, her Expert never does")
        sql(f"DELETE FROM mail_thread_states WHERE anchor_key_id = {anchor}; UPDATE mailboxes SET ai_read = false WHERE id = {PRIYA}")
        r = await rec.mailbox_overview(pool, rec.Caller(26), BIG)
        check(r["ok"] and r["counts"]["inbox_total"] >= 4498 and "inbox_unread" in r["counts"] and "held" not in r["counts"],
              "mailbox_overview answers ml_mailbox_counts() (no review counts on a shared mailbox)", json.dumps(r)[:200])
        r = await rec.mailbox_overview(pool, rec.Caller(30), BIG)
        check(not r["ok"] and r["error"]["code"] == "not_found", "…and not_found to someone who cannot open it")
        r = await rec.find_folders(pool, rec.Caller(26), BIG)
        check(r["ok"] and [f["special_use"] for f in r["folders"]][:1] in (["archive"], ["drafts"], ["inbox"], ["junk"], ["sent"], ["snoozed"], ["trash"])
              and len(r["folders"]) == 7, "find_folders: the mailbox's seven folders")
        r = await rec.mailbox_get(pool, rec.Caller(26), EXPERT)
        check(r["ok"] and r["mailbox"]["level"] == "full" and [o["member_id"] for o in r["mailbox"]["overseers"]] == [26],
              "mailbox_get: the level and the agent mailbox's overseers")
    finally:
        await pool.close()


asyncio.run(records())

# ---- never \Seen ------------------------------------------------------------------------------------------------------------------------
st = stats()["_"]
check(st["stores"] == [] and st["seen_by_fetch"] == 0, "no \\Seen reached the fixture from the sync: no STORE, no fetch without PEEK",
      json.dumps({"stores": st["stores"][:3], "seen_by_fetch": st["seen_by_fetch"]}))
print(f"       (the fixture saw {st['connections']} connections, {st['selects']} SELECTs, {st['idles']} IDLEs, {st['notify_sets']} NOTIFY SETs)")
print(f"all {PASSES} passed" if not FAILS else f"{FAILS} FAILED ({PASSES} passed)")
sys.exit(1 if FAILS else 0)
