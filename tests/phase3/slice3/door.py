"""Slice 3's service-side proof (reading.md Proof 2–6, 8; reading-and-rendering.md §7; mail-service.md §13): the mail service's
`fetch` in every form, the links it mints (one hour, bound to the member and the message), `part` and `raw` streamed, 409 moved on a
stale UIDVALIDITY, every `mutate` op against the fixture's command log (Archive created on first use, nothing but the named ops reaching
the server, no \\Seen on an intake mailbox or from any fetch), the walls the service enforces itself, the image door's refusals, and the
extractor on the benign corpus. Run by tests/phase3/slice3/run.sh after the servers started. Nothing here reaches MaluMail."""

import base64
import email.message
import email.utils
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

from services.mailer import door, extract, mime_read  # noqa: E402

DB = os.environ["ML_DEV_DB"]
CONTROL = pathlib.Path(os.environ["ML_IMAP_CONTROL"])
STATS = pathlib.Path(os.environ["ML_IMAP_STATS"])
MAILER = f"http://127.0.0.1:{os.environ.get('P_MAILER', '9206')}"
IMAGES = f"http://127.0.0.1:{os.environ.get('P_IMAGES', '9207')}"
FIX = f"http://127.0.0.1:{os.environ.get('P_IMAGE_FIXTURE', '9208')}"
KEY = os.environ["ML_MAILER_KEY"].encode()
PART_KEY = os.environ["ML_PART_KEY"].encode()
SEED = json.loads(pathlib.Path(os.environ["ML_P2_SEED"]).read_text())
CORPUS = ROOT / "tests/sanitiser/corpus/benign"
PRIYA, DANA, SALES, EXPERT, INTAKE = SEED["priya"], SEED["dana"], SEED["sales"], SEED["expert"], SEED["intake"]
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
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-v", "ON_ERROR_STOP=1", "-d", DB, "-c", q], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip())
    return r.stdout.strip()


def ctl(**cmd) -> dict:
    name = uuid.uuid4().hex
    (CONTROL / f"{name}.part").write_text(json.dumps(cmd))
    (CONTROL / f"{name}.part").rename(CONTROL / f"{name}.json")      # whole or not at all: the fixture polls *.json
    done = CONTROL / f"{name}.done"
    for _ in range(200):
        if done.exists():
            out = json.loads(done.read_text())
            done.unlink()
            return out
        time.sleep(0.05)
    raise RuntimeError(f"the fixture did not answer {cmd}")


def stats() -> dict:
    time.sleep(0.35)                                           # the fixture saves its stats every 0.2 s
    return json.loads(STATS.read_text())["_"]


def call(path: str, actor: int, args=None, on_behalf=None, raw=False):
    body = json.dumps({"actor": actor, "on_behalf": on_behalf, "args": args or {}}).encode()
    r = httpx.post(MAILER + path, content=body, headers={door.HEADER: door.sign(KEY, "POST", path, body), "content-type": "application/json"}, timeout=60)
    if raw:
        return r.status_code, r.content, dict(r.headers)
    return r.status_code, r.json()


def wait(pred, timeout: float = 15.0, step: float = 0.1):
    t0 = time.time()
    while time.time() - t0 < timeout:
        try:
            if pred():
                return time.time() - t0
        except RuntimeError:
            pass
        time.sleep(step)
    return None


def deliver(user: str, subject: str, html: str | None = None, text: str | None = "A plain message.\n", headers: dict | None = None, attachments=(), related=(), folder: str | None = None) -> int:
    """One message through the fixture's control door, indexed within 15 s; answers the index row's id. text=None: HTML alone."""
    m = email.message.EmailMessage()
    m["From"] = (headers or {}).pop("From", None) or "Outside Person <someone@outside.example>"
    m["To"] = user
    m["Subject"] = subject
    m["Date"] = email.utils.formatdate(usegmt=True)
    m["Message-ID"] = f"<{uuid.uuid4().hex}@outside.example>"
    for k, v in (headers or {}).items():
        m[k] = v
    if text is None:
        m.set_content(html, subtype="html")
    else:
        m.set_content(text)
        if html is not None:
            m.add_alternative(html, subtype="html")
            for cid, data, name in related:
                m.get_payload()[1].add_related(data, "image", "png", cid=f"<{cid}>", filename=name)
    for data, mt, st, name in attachments:
        m.add_attachment(data, maintype=mt, subtype=st, filename=name)
    c = {"op": "deliver", "user": user, "raw": bytes(m).decode("utf-8", "surrogateescape")}
    if folder:
        c["folder"] = folder
    r = ctl(**c)
    if not r.get("ok"):
        raise RuntimeError(f"the fixture refused the delivery of {subject}: {r}")
    mb = SEED[user.split("@")[0]]
    mid_header = m["Message-ID"]
    took = wait(lambda: sql(f"SELECT id FROM mail_messages WHERE mailbox_id = {mb} AND message_id_header = '{mid_header}'") != "", 60)
    if took is None:
        raise RuntimeError(f"not indexed: {subject}")
    return int(sql(f"SELECT id FROM mail_messages WHERE mailbox_id = {mb} AND message_id_header = '{mid_header}'"))


PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP4z8DAwMDAxMDAwMDAAAANBAEB4u2E3wAAAABJRU5ErkJggg==")
DMARC_OK = "default: False [-1.20 / 15.00]; DMARC_POLICY_ALLOW(-0.50)[outside.example,none]"
DMARC_FAIL = "default: False [4.50 / 15.00]; DMARC_POLICY_REJECT(2.00)[outside.example,reject]"
PRIYA_AT = "priya@business.example"

print("the first sync")
took = wait(lambda: sql(f"SELECT count(*) FROM mailboxes WHERE id IN ({PRIYA},{DANA},{SALES},{EXPERT},{INTAKE}) AND sync_state = 'live'") == "5", 120, 0.5)
check(took is not None, "the five mailboxes reach `live`")
check(sql(f"SELECT count(*) FROM mail_folders WHERE mailbox_id = {PRIYA} AND special_use IN ('archive', 'snoozed')") == "2", "Archive and Snoozed made on the first sync")

# ---- 2. fetch in every form ---------------------------------------------------------------------------------------------------------
print("fetch")
inline = (CORPUS / "inline-image.html").read_text().replace(
    "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP4z8DAwMDAxMDAwMDAAAANBAEB4u2E3wAAAABJRU5ErkJggg==", "cid:key1@floorplan")
m_inline = deliver(PRIYA_AT, "Floor plan", html="<html><body>" + inline + f'<img src="{FIX}/logo.png" width="200"><img src="https://track.example/p.gif" width="1" height="1"></body></html>',
                   headers={"From": "Facilities <facilities@harbor-logistics.example>", "X-Spamd-Result": DMARC_OK},
                   related=[("key1@floorplan", PNG, "key.png")], attachments=[(b"%PDF-1.4 plan", "application", "pdf", "plan.pdf"), (b"<html><script>alert(1)</script></html>", "text", "html", "evil.html")])
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_inline, "form": "html"})
r = d.get("result", {})
check(s == 200 and r.get("html"), "form html: the sanitised body of a message with a cid: image, a remote image and attachments")
check(r.get("summary", {}).get("dmarc") == "pass" and r["summary"]["spam_score"] == -1.2 and r["summary"]["from"][0]["address"] == "facilities@harbor-logistics.example",
      "…with the header summary (From, DMARC pass, the spam score as Rspamd reported it)")
parts = {p["part"]: p for p in r.get("parts", [])}
check(any(p["content_id"] == "<key1@floorplan>" and p["type"] == "image/png" and p["size"] == len(PNG) for p in parts.values()), "…and the part list with Content-IDs and DECODED sizes (BINARY.SIZE)", str(parts))
check('src="/p/' in r["html"] and "cid:" not in r["html"], "the cid: image became a part link minted by the service")
check(r["html"].count("/assets/mail/remote-image.svg") == 1 and r.get("remote_images") == 1 and r.get("pixels_dropped") == 1,
      "the remote image became the placeholder (images=ask), the 1x1 pixel dropped", f"{r.get('remote_images')} {r.get('pixels_dropped')}")
import re
tok = re.search(r'src="/p/([^"]+)"', r["html"]).group(1)
link = mime_read.verify_link(PART_KEY, tok)
check(link is not None and link["k"] == "part" and link["m"] == PRIYA and link["a"] == 26 and link["r"] == r["ref"] and link["p"] in parts and 3500 < link["e"] - time.time() <= 3600,
      "the part link verifies under ML_PART_KEY, bound to Priya and this message, one hour", str(link))
check(mime_read.verify_link(PART_KEY, tok[:-4] + "0000") is None and mime_read.verify_link(b"other-key-of-sufficient-length-00", tok) is None, "a tampered or otherwise-keyed link does not verify")
check(mime_read.verify_link(PART_KEY, mime_read.sign_link(PART_KEY, "part", PRIYA, r["ref"], "1", 26, ttl=-5)) is None, "an expired link does not verify")
s2, d2 = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_inline, "form": "html", "images": "show"})
r2 = d2["result"]
imgtok = re.search(r'src="/img/([^"]+)"', r2["html"])
il = mime_read.verify_link(PART_KEY, imgtok.group(1)) if imgtok else None
check(il is not None and il["k"] == "img" and il["u"] == f"{FIX}/logo.png" and il["a"] == 26 and r2.get("images_shown") is True, "images=show: the remote image became an image link carrying its URL, bound to Priya", str(il))
check("track.example" not in r2["html"] and r2.get("pixels_dropped") == 1, "…the tracking pixel stays dropped even when images are shown")
s3, d3 = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_inline, "form": "html"})
check(s3 == 200 and d3["result"]["html"] == r["html"], "a second fetch of the same form answers the same body (the in-memory cache)")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_inline, "form": "headers"})
hdrs = dict(d["result"]["headers"])
check(s == 200 and hdrs.get("Subject") == "Floor plan" and hdrs.get("X-Spamd-Result", "").startswith("default") and "Message-ID" in hdrs, "form headers: every header as [name, value]")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_inline, "form": "text"})
check(s == 200 and d["result"]["text"] == "A plain message." and d["result"]["links"] == [] and d["result"]["hidden_removed"] == 0, "form text: the plain part when it is there (no HTML reaches an agent)", json.dumps(d)[:200])
m_plain = deliver(PRIYA_AT, "Just text", text="Hello Priya,\n\nSee https://docs.example/a and tell me.\n> quoted line\n> another\n")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_plain, "form": "html"})
check(s == 200 and d["result"].get("html") is None and "Hello Priya" in (d["result"].get("text") or ""), "a message with no HTML part: form html answers its text for the page (decision 4)")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_plain, "form": "text"})
check(d["result"]["quoted_removed"] is True and "quoted line" not in d["result"]["text"] and d["result"]["links"][0]["host"] == "docs.example", "form text on plain text: the trailing quoted run cut, the URL's host listed")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_inline, "form": "nope"})
check(s == 422, "an unknown form: 422")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": 999999, "form": "html"})
check(s == 404, "a message that is not there: 404")
# the walls the service enforces itself
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 30, {"message": m_inline, "form": "html"})
check(s == 404 and d["error"]["message"] == "No such mailbox.", "Dana (no level on Priya's mailbox) gets 404 — never 403, never a word of the message")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 1, {"message": m_inline, "form": "html"})
check(s == 404, "the Mail admin (settings.manage, no level) gets 404 on a person's message: the role opens no mailbox (strict)")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 40, {"message": m_inline, "form": "text"})
check(s == 404, "the Expert, with no person behind it, gets 404 on Priya's mailbox")
sql(f"UPDATE mailboxes SET ai_read = true WHERE id = {PRIYA}")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 40, {"message": m_inline, "form": "text"}, on_behalf=26)
check(s == 200 and "<" not in (d["result"]["text"] or "") and d["result"]["text"], "the Expert in Priya's own run (on_behalf, ai_read on) reads her message as TEXT — ml_level_as, computed by the service")
sql(f"UPDATE mailboxes SET ai_read = false WHERE id = {PRIYA}")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 40, {"message": m_inline, "form": "text"}, on_behalf=26)
check(s == 404, "…and not once ai_read is off again")

# ---- deceptive links, hidden content, the corpus through fetch ----------------------------------------------------------------------
print("banners' sources")
m_dec = deliver(PRIYA_AT, "Your account", text=None, html='<p>Sign in at <a href="https://evil.example/login">https://bank.example/login</a> or <a href="https://ok.example/">here</a>.</p><div style="display:none">hidden words</div>',
                headers={"From": "Bank <alerts@bank.example>", "Reply-To": "help@elsewhere.example", "X-Spamd-Result": DMARC_FAIL})
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_dec, "form": "html"})
r = d["result"]
check(r["deceptive_links"] == 1 and "(evil.example)" in r["html"] and 'title="evil.example"' in r["html"] and 'title="ok.example"' in r["html"],
      "a link whose text names another host: counted, the real host appended; every link titled with its host", r["html"][:300])
check(r["hidden_removed"] == 1 and "hidden words" not in r["html"] and r["summary"]["dmarc"] == "fail" and r["summary"]["reply_to"][0]["address"] == "help@elsewhere.example",
      "hidden content removed and counted; DMARC fail and Reply-To elsewhere in the summary")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_dec, "form": "text"})
check(d["result"]["hidden_removed"] == 1 and "hidden words" not in d["result"]["text"] and [l["host"] for l in d["result"]["links"]] == ["evil.example", "ok.example"]
      and "https://bank.example/login [→ evil.example]" in d["result"]["text"], "the agent's text: hidden content flagged and never shown, links as `text [→ host]`", d["result"]["text"])
news = (CORPUS / "newsletter.html").read_text()
m_news = deliver(PRIYA_AT, "The autumn catalogue", text=None, html=news, headers={"From": "Chairs <news@chairs.example>", "List-Id": "<news.chairs.example>", "X-Spamd-Result": DMARC_OK})
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_news, "form": "text"})
check(d["result"]["hidden_removed"] == 1 and "autumn catalogue is here" not in d["result"]["text"] and "Shop the sale" in d["result"]["text"],
      "the newsletter's hidden preheader is flagged and cut from the agent's text", d["result"]["text"][:200])

# ---- 8. the extractor on the corpus (module level: Gmail, Outlook, Apple quotes; the signature) ------------------------------------
print("the extractor")
g = extract.extract((CORPUS / "gmail-thread.html").read_text(), True)
check(g["quoted_removed"] and g["signature_removed"] and "Can we move the call" not in g["text"] and "Marchetti | Operations" not in g["text"] and "Ship the Q4 price list" in g["text"],
      "Gmail: the gmail_quote and the gmail_signature cut, the reply kept")
check(g["links"][0]["host"] == "calendar.example" and "[→ calendar.example]" in g["text"], "…its link listed with its host")
o = extract.extract((CORPUS / "outlook-reply.html").read_text(), True)
check(o["quoted_removed"] and "Could we walk the warehouse" not in o["text"] and "Thursday at 10:00 works" in o["text"], "Outlook: the From:/Sent:/To:/Subject: block and what follows cut")
apple = '<html><body><div>Yes, agreed.</div><br><blockquote type="cite"><div>On 5 Oct 2026, at 09:14, Ana wrote:</div><div>Can we move it?</div></blockquote></body></html>'
a = extract.extract(apple, True)
check(a["quoted_removed"] and "Can we move it" not in a["text"] and "Yes, agreed." in a["text"], "Apple Mail: blockquote[type=cite] cut")
t = extract.extract("Hi\n\nOK\n-- \nLeo\n", False)
check(t["signature_removed"] and t["text"] == "Hi\n\nOK", "plain text: the RFC 3676 `-- ` signature cut")
t = extract.extract("Hi\n\nLe 5 oct. 2026 à 09:14, Ana a écrit :\n> old\n", False)
check(t["quoted_removed"] and t["text"] == "Hi", "plain text: a translated `wrote:` line cut")
q = extract.extract((CORPUS / "gmail-thread.html").read_text(), True, include_quoted=True)
check(not q["quoted_removed"] and "Can we move the call" in q["text"], "include_quoted keeps the history")
long = extract.extract("x" * 30000, False, limit=20000)
check(long["truncated"] and len(long["text"]) == 20000, "the limit cuts and says so")

# ---- 3. part and raw ------------------------------------------------------------------------------------------------------------------
print("part and raw")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_inline, "form": "headers"})
parts = {p["part"]: p for p in d["result"]["parts"]}
png_part = next(p for p, v in parts.items() if v["type"] == "image/png")
pdf_part = next(p for p, v in parts.items() if v["type"] == "application/pdf")
html_att = next(p for p, v in parts.items() if v["name"] == "evil.html")
s, b, h = call(f"/v1/mailboxes/{PRIYA}/part", 26, {"message": m_inline, "part": png_part}, raw=True)
check(s == 200 and b == PNG and h.get("content-type", "").startswith("image/png") and h.get("x-ml-size") == str(len(PNG)) and "key.png" in h.get("x-ml-name", ""),
      "part: the cid image's DECODED bytes, its type, size and name in the headers", str(h))
s, b, h = call(f"/v1/mailboxes/{PRIYA}/part", 26, {"message": m_inline, "part": html_att}, raw=True)
check(s == 200 and b.startswith(b"<html>") and h.get("content-type", "").startswith("text/html"), "part: an HTML attachment's bytes (the DOOR decides it is never inline — PHP's proof)")
s, b, h = call(f"/v1/mailboxes/{PRIYA}/part", 26, {"message": m_inline, "part": "9"}, raw=True)
check(s == 404, "a part that is not there: 404")
s, b, h = call(f"/v1/mailboxes/{PRIYA}/part", 26, {"message": m_inline, "part": "1;x"}, raw=True)
check(s == 422, "a part that is not a section: 422")
s, b, h = call(f"/v1/mailboxes/{PRIYA}/raw", 26, {"message": m_inline}, raw=True)
size = int(sql(f"SELECT size FROM mail_messages WHERE id = {m_inline}"))
check(s == 200 and len(b) == size and b.startswith(b"From: ") and h.get("content-type", "").startswith("message/rfc822"), "raw: the whole message, RFC822.SIZE bytes, as message/rfc822")
big = os.urandom(3 * 1024 * 1024)
m_big = deliver(PRIYA_AT, "A large file", attachments=[(big, "application", "octet-stream", "big.bin")])
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_big, "form": "headers"})
bp = next(p["part"] for p in d["result"]["parts"] if p["name"] == "big.bin")
t0 = time.time()
s, b, h = call(f"/v1/mailboxes/{PRIYA}/part", 26, {"message": m_big, "part": bp}, raw=True)
check(s == 200 and b == big and h.get("content-length") == str(len(big)), f"a 3 MB part streamed whole in 1 MB partials ({time.time() - t0:.1f} s)")
s, b, h = call(f"/v1/mailboxes/{PRIYA}/part", 30, {"message": m_inline, "part": png_part}, raw=True)
check(s == 404, "Dana gets 404 on Priya's part")
check(stats()["seen_by_fetch"] == 0, "no fetch ever set \\Seen on the server (every fetch a PEEK)")

# ---- 409 moved ------------------------------------------------------------------------------------------------------------------------
print("moved")
m_mv = deliver(PRIYA_AT, "Will be reset")
old_v = sql(f"SELECT uidvalidity FROM mail_messages WHERE id = {m_mv}")
ctl(op="reset", user=PRIYA_AT, folder="INBOX")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_mv, "form": "html"})
check(s == 409 and d["error"]["code"] == "moved", "a message whose folder's UIDVALIDITY changed on the server: 409 moved (and the folder is queued for a re-read)")
took = wait(lambda: sql(f"SELECT uidvalidity FROM mail_folders WHERE mailbox_id = {PRIYA} AND special_use = 'inbox'") != old_v and sql(f"SELECT count(*) FROM mail_messages WHERE mailbox_id = {PRIYA} AND folder_id = (SELECT id FROM mail_folders WHERE mailbox_id = {PRIYA} AND special_use = 'inbox')") not in ("", "0"), 70, 0.5)
check(took is not None, f"…the Inbox re-read under the new UIDVALIDITY ({took if took is None else round(took, 1)} s)")

# ---- 5. mutate against the fixture's command log ---------------------------------------------------------------------------------------
print("mutate")
def ref(mid: int):
    return [int(x) for x in sql(f"SELECT folder_id||' '||uidvalidity||' '||uid FROM mail_messages WHERE id = {mid}").split()]
def where(mid: int) -> str:
    return sql(f"SELECT COALESCE(f.special_use, f.name) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.id = {mid}")
def flags(mid: int) -> str:
    return sql(f"SELECT seen::text || ' ' || flagged::text FROM mail_messages WHERE id = {mid}")
def server_folder_of(user: str, subject: str) -> str | None:
    st = ctl(op="state", user=user)
    return None
m1 = deliver(PRIYA_AT, "Archive me")
m2 = deliver(PRIYA_AT, "Archive me too")
st0 = stats()
n_moves, n_stores = len(st0["moves"]), len(st0["stores"])
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "archive", "targets": [ref(m1), ref(m2)]})
st1 = stats()
check(s == 200 and d["result"]["count"] == 2 and where(m1) == "archive" and where(m2) == "archive", "archive: two Inbox messages moved to Archive, the index updated in the same request", json.dumps(d)[:200])
check(len(st1["moves"]) == n_moves + 2 and all(x["to"] == "Archive" for x in st1["moves"][-2:]) and sorted(x["new_uid"] for x in st1["moves"][-2:]) == sorted([ref(m1)[2], ref(m2)[2]]),
      "…ONE UID MOVE on the server answered COPYUID, the new UIDs kept in the index (ids and keys unchanged)", str(st1["moves"][-2:]))
check(sql(f"SELECT message_key_id FROM mail_messages WHERE id = {m1}") != "", "…the row keeps its id and its key")
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "archive", "targets": [ref(m1)]})
check(s == 200 and d["result"]["count"] == 0, "archiving what is already archived changes nothing")
inbox = int(sql(f"SELECT id FROM mail_folders WHERE mailbox_id = {PRIYA} AND special_use = 'inbox'"))
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "move", "targets": [ref(m1)], "to_folder": inbox})
check(s == 200 and where(m1) == "inbox", "move: back to the Inbox by folder id")
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "move", "targets": [ref(m1)], "to_folder": 999999})
check(s == 404, "move to a folder that is not this mailbox's: 404")
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "trash", "targets": [ref(m1)]})
check(s == 200 and where(m1) == "trash", "trash")
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "spam", "targets": [ref(m2)]})
check(s == 200 and where(m2) == "junk", "spam")
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "not_spam", "targets": [ref(m2)]})
check(s == 200 and where(m2) == "inbox", "not spam: back to the Inbox")
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "flag", "targets": [ref(m2)]})
s2, d2 = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "seen", "targets": [ref(m2)]})
st2 = stats()
check(s == 200 and s2 == 200 and flags(m2) == "true true", "flag and seen: the index's flags")
last = st2["stores"][-2:]
check(len(st2["stores"]) == n_stores + 2 and last[0]["op"] == "+FLAGS.SILENT" and last[0]["flags"] == ["\\Flagged"] and last[1]["flags"] == ["\\Seen"],
      "…each ONE UID STORE ±FLAGS.SILENT on the server, and the owner's `seen` is the only \\Seen ever stored", str(last))
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "unflag", "targets": [ref(m2)]})
s2, d2 = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "unseen", "targets": [ref(m2)]})
check(flags(m2) == "false false" and stats()["stores"][-1]["op"] == "-FLAGS.SILENT", "unflag and unseen")
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "delete_forever", "targets": [ref(m2)]})
check(s == 422 and where(m2) == "inbox", "delete forever of an Inbox message: refused (Trash or Spam only)")
n_exp = len(stats()["expunges"])
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "delete_forever", "targets": [ref(m1)]})
check(s == 200 and d["result"]["count"] == 1 and sql(f"SELECT count(*) FROM mail_messages WHERE id = {m1}") == "0" and len(stats()["expunges"]) == n_exp + 1,
      "delete forever from Trash: \\Deleted + UID EXPUNGE of that UID alone, the row gone")
m3 = deliver(PRIYA_AT, "Trash one"); m4 = deliver(PRIYA_AT, "Trash two")
call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "trash", "targets": [ref(m3), ref(m4)]})
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "trash_empty", "messages": [], "which": "trash"})
check(s == 200 and d["result"]["count"] == 2 and sql(f"SELECT count(*) FROM mail_messages WHERE id IN ({m3},{m4})") == "0", "empty Trash: every message of Trash expunged, the count answered")
# the walls
m5 = deliver("sales@business.example", "Shared mail")
s, d = call(f"/v1/mailboxes/{SALES}/mutate", 30, {"op": "archive", "targets": [ref(m5)]})
check(s == 200 and d["result"]["count"] == 0 and d["result"]["refused"][0]["reason"] == "Viewing a mailbox never changes it.", "Dana (view on sales@): archive refused with the sentence, nothing sent to the server")
s, d = call(f"/v1/mailboxes/{SALES}/mutate", 30, {"op": "seen", "targets": [ref(m5)]})
check(s == 200 and d["result"]["count"] == 0, "…and `seen` refused too: a view holder marks read for themselves (PHP's ml_viewer_read), never the mailbox")
s, d = call(f"/v1/mailboxes/{SALES}/mutate", 26, {"op": "archive", "targets": [ref(m5)]})
check(s == 200 and d["result"]["count"] == 1 and where(m5) == "archive", "Priya (triage on sales@) archives it")
m6 = deliver("intake@business.example", "Intake mail")
n_st = len(stats()["stores"])
for op in ("seen", "archive", "trash", "spam"):
    s, d = call(f"/v1/mailboxes/{INTAKE}/mutate", 30, {"op": op, "targets": [ref(m6)]})
    check(s == 200 and d["result"]["count"] == 0 and "intake" in d["result"]["refused"][0]["reason"], f"the intake mailbox: {op} of an INBOX message refused with the intake sentence", json.dumps(d)[:200])
s, d = call(f"/v1/mailboxes/{INTAKE}/mutate", 30, {"op": "delete_forever", "targets": [ref(m6)]})
check(s == 422, "the intake mailbox: delete forever of an INBOX message refused (never from the Inbox, intake or not)")
s, d = call(f"/v1/mailboxes/{INTAKE}/mutate", 30, {"op": "flag", "targets": [ref(m6)]})
check(s == 200 and d["result"]["count"] == 1, "…while a star on it is allowed (it moves nothing and marks nothing read)")
check(len(stats()["stores"]) == n_st + 1 and stats()["stores"][-1]["flags"] == ["\\Flagged"] and not any("\\Seen" in x["flags"] and x["user"].startswith("intake") for x in stats()["stores"]),
      "no \\Seen was ever stored on the intake mailbox")
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 30, {"op": "archive", "targets": [ref(m2)]})
check(s == 404, "Dana on Priya's mailbox: 404")
s, d = call(f"/v1/mailboxes/{PRIYA}/mutate", 26, {"op": "dance", "targets": [ref(m2)]})
check(s == 422, "an op the service does not do: 422")

# ---- 4. the image door --------------------------------------------------------------------------------------------------------------------
print("the image door")
def img(u: str):
    r = httpx.get(IMAGES + "/fetch", params={"u": u}, timeout=30)
    return r.status_code, r.headers.get("content-type", ""), r.content
s, ct, b = img(f"{FIX}/logo.png")
check(s == 200 and ct == "image/png" and b.startswith(b"\x89PNG"), "a PNG from the test-allowed fixture: 200 image/png (no cookie, no referrer reached it)")
s, ct, b = img(f"{FIX}/redir1")
check(s == 200 and ct == "image/png", "two redirects to the PNG: followed")
s, ct, b = img(f"{FIX}/loop0")
check(s == 403 and b.strip() == b"too_many_redirects", "a fourth redirect: too_many_redirects")
s, ct, b = img(f"{FIX}/private")
check(s == 403 and b.strip() == b"refused_address", "a redirect into 10.0.0.1: refused_address")
s, ct, b = img(f"{FIX}/loopback")
check(s == 403 and b.strip() == b"refused_scheme", "a redirect into 127.0.0.1:5432: refused (the test allowance is one address and port; 5432 is no image port)", b[:40].decode())
for u, why in (("http://127.0.0.1/x.png", "refused_address"), ("http://10.0.0.1/x.png", "refused_address"), ("http://169.254.169.254/latest/meta-data", "refused_address"),
               ("http://[::1]/x.png", "refused_address"), ("http://localhost/x.png", "refused_address"), ("ftp://img.example/x.png", "refused_scheme"),
               ("http://img.example:8080/x.png", "refused_scheme"), ("javascript:alert(1)", "refused_scheme")):
    s, ct, b = img(u)
    check(s in (403, 502) and b.strip().decode() == why, f"{u}: {why}", f"{s} {b[:40]!r}")
s, ct, b = img(f"{FIX}/big.png")
check(s == 413 and b.strip() == b"too_large", "6 MB: too_large (counted while reading)")
s, ct, b = img(f"{FIX}/svg")
check(s == 415 and b.strip() == b"not_an_image", "an SVG: not_an_image")
s, ct, b = img(f"{FIX}/page.png")
check(s == 415 and b.strip() == b"not_an_image", "an HTML page calling itself image/png: not_an_image (the bytes decide)")
s, ct, b = img(f"{FIX}/nothing.png")
check(s == 502, "a 404 from the origin: failed")
t0 = time.time()
s, ct, b = img(f"{FIX}/slow.png")
check(s == 504 and b.strip() == b"timeout" and time.time() - t0 < 19, f"a slow origin: timeout within 15 s ({time.time() - t0:.1f} s)")

# ---- the records server's queries, as the Expert and as Priya (Phase 4 serves them; slice 3 writes them) --------------------------------
print("the records queries")
sys.path.insert(0, str(ROOT / "mcp"))
import asyncio  # noqa: E402
import asyncpg  # noqa: E402
import records_server as rs  # noqa: E402

# the Inbox was re-read after the UIDVALIDITY reset above: the rows have new ids — the same messages, found again by their subjects
def by_subject(mb: int, subject: str) -> int:
    return int(sql(f"SELECT id FROM mail_messages WHERE mailbox_id = {mb} AND subject = '{subject}' ORDER BY id DESC LIMIT 1"))
m_inline, m_dec = by_subject(PRIYA, "Floor plan"), by_subject(PRIYA, "Your account")
s, d = call(f"/v1/mailboxes/{PRIYA}/fetch", 26, {"message": m_inline, "form": "headers"})
parts = {p["part"]: p for p in d["result"]["parts"]}
pdf_part = next(p for p, v in parts.items() if v["type"] == "application/pdf")
html_att = next(p for p, v in parts.items() if v["name"] == "evil.html")

async def rq():
    pool = await asyncpg.create_pool(host=os.environ["DB_HOST"], port=int(os.environ["DB_PORT"]), database=DB, user=os.environ["MCP_RECORDS_DB_USER"],
                                     password=os.environ["MCP_RECORDS_DB_PASSWORD"], min_size=1, max_size=2)
    priya = rs.Caller(26)
    out = {}
    out["find_threads"] = await rs.find_threads(pool, priya, PRIYA, "Floor plan")
    tid = out["find_threads"]["threads"][0]["thread_id"]
    out["thread_read"] = await rs.thread_read(pool, priya, PRIYA, thread=tid)
    out["message_read"] = await rs.message_read(pool, priya, PRIYA, m_dec)
    out["message_headers"] = await rs.message_headers(pool, priya, PRIYA, m_inline)
    out["find_attachments"] = await rs.find_attachments(pool, priya, "plan", PRIYA)
    out["attachment_read"] = await rs.attachment_read(pool, priya, PRIYA, m_inline, pdf_part)
    out["attachment_html"] = await rs.attachment_read(pool, priya, PRIYA, m_inline, html_att)
    out["expert_thread"] = await rs.thread_read(pool, rs.Caller(40), PRIYA, thread=tid)
    sql(f"INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, sensitive, sensitive_by) SELECT {EXPERT}, message_key_id, true, 26 FROM mail_messages WHERE mailbox_id = {EXPERT} ORDER BY id LIMIT 1 ON CONFLICT (mailbox_id, anchor_key_id) DO UPDATE SET sensitive = true, sensitive_by = 26")
    sens = sql(f"SELECT thread_id FROM mail_messages WHERE mailbox_id = {EXPERT} ORDER BY id LIMIT 1")
    out["expert_sensitive"] = await rs.thread_read(pool, rs.Caller(40), EXPERT, thread=int(sens))
    out["priya_sensitive"] = await rs.thread_read(pool, rs.Caller(26), EXPERT, thread=int(sens))
    out["log"] = sql(f"SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND mailbox_id = {EXPERT} AND actor_member_id = 26")
    await pool.close()
    return out
o = asyncio.run(rq())
check(o["find_threads"]["ok"] and o["find_threads"]["threads"][0]["subject"] == "Floor plan", "find_threads resolves a conversation by its subject's words")
tr = o["thread_read"]
check(tr["ok"] and tr["messages"][-1]["text"] and "<" not in tr["messages"][-1]["text"] and tr["messages"][-1]["attachments"][0]["name"] in ("plan.pdf", "evil.html", "key.png"),
      "thread_read: the latest message's text (never HTML) with its attachments listed", json.dumps(tr, default=str)[:300])
mr = o["message_read"]
check(mr["ok"] and mr["message"]["deceptive_links"] == 1 and mr["message"]["hidden_removed"] == 1 and mr["message"]["dmarc"] == "fail", "message_read: deceptive_links, hidden content and the DMARC result per message")
check(o["message_headers"]["ok"] and o["message_headers"]["headers"]["dmarc"] == "pass" and o["message_headers"]["parts"], "message_headers: the parsed summary and the part list")
check(o["find_attachments"]["ok"] and o["find_attachments"]["attachments"][0]["name"] == "plan.pdf", "find_attachments by a name's words")
check(o["attachment_read"]["ok"] and o["attachment_read"]["type"] == "application/pdf" and o["attachment_read"]["size"] == 13, "attachment_read of a PDF (pdftotext; this stub has no text — the name and size answered)")
check(o["attachment_html"]["ok"] and o["attachment_html"]["text"] is None and o["attachment_html"]["note"], "attachment_read of an HTML file: the name and size only, never its content")
check(not o["expert_thread"]["ok"] and o["expert_thread"]["error"]["code"] == "not_found", "the Expert on Priya's conversation (no person behind the run): not_found")
check(not o["expert_sensitive"]["ok"] and o["expert_sensitive"]["error"]["code"] == "not_found" and o["priya_sensitive"]["ok"], "a conversation marked sensitive: not_found to the agent, readable by its overseer")
check(o["log"].isdigit() and int(o["log"]) >= 1, "a records read of a mailbox not the caller's own wrote mcp.read")

print(f"{'all ' + str(PASSES) + ' passed' if not FAILS else str(FAILS) + ' FAILED (' + str(PASSES) + ' passed)'}")
sys.exit(1 if FAILS else 0)
