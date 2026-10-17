"""The IMAP FIXTURE (design §10 Phase 0; grown for slice 2 per docs/build-specs/mail-service.md §12): a small IMAP server over implicit
TLS for the mail service's proofs, so nothing in a proof logs in to MaluMail's real server — above all no wrong password, which would
trip its abuse protection for this whole machine (research 04). Synthetic mail only.

It answers: CAPABILITY, ID, NOOP, LOGOUT, LOGIN, ENABLE (CONDSTORE, QRESYNC), LIST (SPECIAL-USE flags; `RETURN (SPECIAL-USE STATUS
(…))`), STATUS, SELECT/EXAMINE (with `(QRESYNC (uidvalidity modseq))` → `VANISHED (EARLIER)` and the changed FETCHes), CREATE, RENAME, DELETE,
GETQUOTAROOT, [UID] SEARCH (ALL, UNSEEN, SEEN, UID set), [UID] FETCH (UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE MODSEQ PREVIEW
BODY[.PEEK][section] BINARY[.PEEK][part]<o.l> BINARY.SIZE[part] RFC822 RFC822.HEADER; the `(CHANGEDSINCE m [VANISHED])` modifier),
[UID] STORE, [UID] EXPUNGE, IDLE, NOTIFY SET/NONE. It advertises only what it answers. A BODY[…] or BINARY[…] fetch WITHOUT .PEEK sets
\\Seen as a real server would, and is counted (stats `seen_by_fetch`) — so a proof can show the service never did either.

Mailboxes start as MaluMail's five folders (INBOX, Drafts, Sent, Junk, Trash; research 04 §B), INBOX holding --inbox synthetic messages
of every shape (plain, alternative, an attachment, replies in conversations, lists, flags).

  imap_server.py --users users.json --cert c.pem --key k.pem --port-file p --stats stats.json [--port 0] [--inbox 12]
                 [--passwords mcp/passwords.jsonl] [--control DIR] [--no-notify]

users.json: {"address": {"password": "…", "mode": "ok" | "busy", "inbox": n}} — `busy` answers every login NO [UNAVAILABLE]; `inbox`
overrides --inbox for that address. With --passwords, the fake Mailbox MCP's issued passwords (create, reset) replace users.json's for
their address, as at MaluMail. stats.json: {"address": {"attempts", "ok"}, "_": {"seen_by_fetch", "stores": [...], "notify_sets",
"notify_refused", "idles", "selects", "commands"}}.

The CONTROL directory (--control): a proof writes `<name>.json`, the fixture acts within 50 ms and writes `<name>.done` with its answer.
  {"op": "deliver", "user", "folder"?: "INBOX", "subject"?, "from"?, "body"?, "in_reply_to"?, "flags"?: [], "raw"?: str}  → {"uid"}
  {"op": "expunge", "user", "folder"?, "uid"}            {"op": "flag", "user", "folder"?, "uid", "add"?: [], "remove"?: []}
  {"op": "move", "user", "folder"?, "uid", "to"}  → {"uid"} — gone from one folder (VANISHED), new in the other
  {"op": "create", "user", "folder"} — a folder made by another client
  {"op": "reset",   "user", "folder"?}  — a new UIDVALIDITY, every message renumbered (as a server rebuilt from backup)
  {"op": "notify", "refuse": bool}       {"op": "drop", "user"} — every connection of that user closed      {"op": "state", "user"}
"""

import argparse
import asyncio
import email
import email.message
import email.policy
import email.utils
import json
import re
import ssl
import time
from pathlib import Path

CAPS_PRE = "IMAP4rev1 LITERAL+ ID ENABLE AUTH=PLAIN"
CAPS = ("IMAP4rev1 LITERAL+ ID ENABLE IDLE CONDSTORE QRESYNC SPECIAL-USE LIST-EXTENDED LIST-STATUS NOTIFY BINARY PREVIEW QUOTA "
        "UIDPLUS MOVE")   # + APPEND with APPENDUID and SEARCH HEADER (slice 4)
FOLDERS = [("INBOX", ""), ("Drafts", "\\Drafts"), ("Sent", "\\Sent"), ("Junk", "\\Junk"), ("Trash", "\\Trash")]
QUOTA_KB = 1048576
CRLF = b"\r\n"


class Folder:
    _next_validity = 1000

    def __init__(self, name, special, uidvalidity=None):
        self.name, self.special = name, special
        if uidvalidity is None:
            Folder._next_validity += 1
            uidvalidity = Folder._next_validity
        self.uidvalidity = uidvalidity
        self.messages = []                      # dicts: uid, flags (set), modseq, raw (bytes), internaldate (epoch)
        self.expunged = []                      # (uid, modseq) — for VANISHED (EARLIER)
        self.uidnext, self.highestmodseq = 1, 1

    def add(self, raw: bytes, flags=(), when=None):
        self.highestmodseq += 1
        m = {"uid": self.uidnext, "flags": set(flags), "modseq": self.highestmodseq, "raw": raw,
             "internaldate": when if when is not None else time.time()}
        self.messages.append(m)
        self.uidnext += 1
        return m

    def unseen(self):
        return sum(1 for m in self.messages if "\\Seen" not in m["flags"])

    def status(self):
        return {"MESSAGES": len(self.messages), "UIDNEXT": self.uidnext, "UIDVALIDITY": self.uidvalidity, "UNSEEN": self.unseen(),
                "RECENT": 0, "HIGHESTMODSEQ": self.highestmodseq, "SIZE": sum(len(m["raw"]) for m in self.messages)}

    def by_uid(self, uid):
        for i, m in enumerate(self.messages):
            if m["uid"] == uid:
                return i, m
        return None, None


def crlf(b: bytes) -> bytes:
    return re.sub(rb"\r?\n", b"\r\n", b)


def synthetic(n: int, address: str) -> list[tuple[bytes, set, float]]:
    """n messages of every shape a list must show: plain, alternative (every 7th), an attachment (every 11th), replies in conversations
    (every 10th + 3), mailing lists (every 13th); every other one read, every 17th starred. Each body carries `kumquat<i>`."""
    out = []
    for i in range(n):
        when = 1760000000 + i * 60
        subject = f"Synthetic message {i}"
        if i % 10 == 3 and i > 0:
            m = email.message.EmailMessage()
            m["In-Reply-To"] = f"<fixture-{i - 1}@outside.example>"
            m["References"] = f"<fixture-{i - 1}@outside.example>"
            subject = f"Re: Synthetic message {i - 1}"
            sender = f"Sender {i - 1} <sender{i - 1}@outside.example>"
        else:
            m = email.message.EmailMessage()
            sender = f"Sender {i} <sender{i}@outside.example>"
        m["From"] = sender
        m["To"] = address
        m["Subject"] = subject
        m["Date"] = email.utils.formatdate(when, usegmt=True)
        m["Message-ID"] = f"<fixture-{i}@outside.example>"
        if i % 13 == 0:
            m["List-Id"] = "<news.outside.example>"
        text = f"Synthetic body {i}. kumquat{i}\n"
        m.set_content(text)
        if i % 7 == 0:
            m.add_alternative(f"<html><body><p>Synthetic body {i}. <b>kumquat{i}</b></p></body></html>", subtype="html")
        if i % 11 == 0:
            m.add_attachment(b"%PDF-1.4 synthetic " + str(i).encode(), maintype="application", subtype="pdf", filename=f"report-{i}.pdf")
        flags = set()
        if i % 2:
            flags.add("\\Seen")
        if i % 17 == 0:
            flags.add("\\Flagged")
        out.append((crlf(bytes(m)), flags, float(when)))
    return out


def make_message(user: str, c: dict, uid_hint: int) -> bytes:
    if c.get("raw"):
        return crlf(c["raw"].encode())
    m = email.message.EmailMessage()
    m["From"] = c.get("from") or "Outside Person <someone@outside.example>"
    m["To"] = c.get("to") or user
    m["Subject"] = c.get("subject") or f"Delivered message {uid_hint}"
    m["Date"] = email.utils.formatdate(time.time(), usegmt=True)
    m["Message-ID"] = c.get("message_id") or f"<delivered-{uid_hint}-{time.time_ns()}@outside.example>"
    if c.get("in_reply_to"):
        m["In-Reply-To"] = c["in_reply_to"]
        m["References"] = c["in_reply_to"]
    for k, v in (c.get("headers") or {}).items():
        m[k] = v
    m.set_content(c.get("body") or "A delivered message.\n")
    return crlf(bytes(m))


# ---- BODYSTRUCTURE, sections, PREVIEW -------------------------------------------------------------------------------------------------

def q(s) -> str:
    if s is None:
        return "NIL"
    s = str(s)
    return '"' + s.replace("\\", "\\\\").replace('"', '\\"') + '"'


def _params(part) -> str:
    ps = part.get_params() or []
    pairs = [(k, v) for k, v in ps[1:]] if ps else []
    if not pairs:
        return "NIL"
    return "(" + " ".join(f"{q(k)} {q(v if isinstance(v, str) else email.utils.collapse_rfc2231_value(v))}" for k, v in pairs) + ")"


def _disposition(part) -> str:
    d = part.get_content_disposition()
    if not d:
        return "NIL"
    fn = part.get_param("filename", header="content-disposition")
    return f"({q(d)} " + (f"({q('filename')} {q(fn)})" if fn else "NIL") + ")"


def bodystructure(part) -> str:
    if part.is_multipart():
        subs = "".join(bodystructure(p) for p in part.get_payload())
        b = part.get_param("boundary")
        return f"({subs} {q(part.get_content_subtype())} ({q('boundary')} {q(b)}) NIL NIL NIL)"
    payload = part.get_payload()
    body = payload.encode("utf-8", "surrogateescape") if isinstance(payload, str) else bytes(payload or b"")
    enc = (part.get("Content-Transfer-Encoding") or "7bit").lower()
    maintype, subtype = part.get_content_maintype(), part.get_content_subtype()
    cid = part.get("Content-ID")
    head = f"{q(maintype)} {q(subtype)} {_params(part)} {q(cid) if cid else 'NIL'} NIL {q(enc)} {len(body)}"
    if maintype == "text":
        return f"({head} {body.count(b'\\n')} NIL {_disposition(part)} NIL NIL)"
    return f"({head} NIL {_disposition(part)} NIL NIL)"


def parsed(raw: bytes):
    return email.message_from_bytes(raw, policy=email.policy.compat32)


def section_part(msg, spec: str):
    """A part by its IMAP number ("1", "2.1"); a single-part message's body is "1"."""
    node = msg
    for n in spec.split("."):
        i = int(n)
        if node.is_multipart():
            subs = node.get_payload()
            if not 1 <= i <= len(subs):
                return None
            node = subs[i - 1]
        elif i != 1:
            return None
    return node


def part_bytes(part, decoded: bool) -> bytes:
    if decoded:
        return part.get_payload(decode=True) or b""
    payload = part.get_payload()
    return payload.encode("utf-8", "surrogateescape") if isinstance(payload, str) else b""


def header_fields(raw: bytes, names: list[str], negate: bool = False) -> bytes:
    head = raw.split(b"\r\n\r\n", 1)[0]
    lines, cur = [], b""
    for line in head.split(b"\r\n"):
        if line[:1] in (b" ", b"\t") and cur:
            cur += b"\r\n" + line
        else:
            if cur:
                lines.append(cur)
            cur = line
    if cur:
        lines.append(cur)
    want = {n.lower() for n in names}
    out = [ln for ln in lines if ((ln.split(b":", 1)[0].decode("latin-1").strip().lower() in want) != negate)]
    return b"".join(ln + b"\r\n" for ln in out) + b"\r\n"


def preview(raw: bytes) -> str:
    msg = parsed(raw)
    for part in msg.walk():
        if part.get_content_type() == "text/plain" and part.get_content_disposition() != "attachment":
            t = (part.get_payload(decode=True) or b"").decode(part.get_content_charset() or "utf-8", "replace")
            return re.sub(r"\s+", " ", t).strip()[:200]
    return ""


def imap_date(epoch: float) -> str:
    return time.strftime("%d-%b-%Y %H:%M:%S +0000", time.gmtime(epoch))


# ---- the server --------------------------------------------------------------------------------------------------------------------

class Server:
    def __init__(self, users: dict, stats_path: Path, inbox: int, passwords: Path | None = None, no_notify: bool = False):
        self.users, self.stats_path, self.passwords = users, stats_path, passwords
        self.stats = {a: {"attempts": 0, "ok": 0} for a in users}
        self.stats["_"] = {"seen_by_fetch": 0, "stores": [], "notify_sets": 0, "notify_refused": 0, "idles": 0, "selects": 0,
                           "commands": 0, "connections": 0, "moves": [], "expunges": [], "creates": [], "renames": [], "deletes": []}
        self.refuse_notify = no_notify
        self.boxes = {}
        self.sessions: set = set()
        self.dirty = False
        for address in sorted(users):
            self.boxes[address] = self._new_box(int(users[address].get("inbox", inbox)), address)
        self._save()

    def _new_box(self, n: int, address: str):
        folders = {name: Folder(name, special) for name, special in FOLDERS}
        for raw, flags, when in synthetic(n, address):
            folders["INBOX"].add(raw, flags, when)
        return folders

    def _save(self):
        self.stats_path.write_text(json.dumps(self.stats))
        self.dirty = False

    def mark(self):
        self.dirty = True

    async def saver(self):
        while True:
            await asyncio.sleep(0.2)
            if self.dirty:
                self._save()

    def _issued(self, user: str) -> str | None:
        """The password the fake Mailbox MCP last issued for this address (a create or a reset), which replaces any earlier one."""
        if self.passwords is None or not self.passwords.exists():
            return None
        last = None
        for line in self.passwords.read_text().splitlines():
            try:
                row = json.loads(line)
            except ValueError:
                continue
            if row.get("address") == user:
                last = row.get("password")
        return last

    def login(self, user: str, password: str) -> str:
        """ok · busy · failed"""
        s = self.stats.setdefault(user, {"attempts": 0, "ok": 0})
        s["attempts"] += 1
        u = self.users.get(user)
        issued = self._issued(user)
        if u and u.get("mode") == "busy":
            result = "busy"
        elif (issued is not None and issued == password) or (issued is None and u and u.get("password") == password):
            result = "ok"
            s["ok"] += 1
            if user not in self.boxes:                    # a mailbox MaluMail created or reset since: its five folders, empty
                self.boxes[user] = {name: Folder(name, special) for name, special in FOLDERS}
        else:
            result = "failed"
        self._save()
        return result

    # ---- changes "from another client", pushed to the sessions that should hear of them ----------------------------------------
    def changed(self, user: str, folder: Folder, kind: str, m=None, uid=None, seq=None):
        for s in list(self.sessions):
            if s.user != user:
                continue
            if s.selected is folder:
                if kind == "new":
                    s.push(f"* {len(folder.messages)} EXISTS")
                    if s.notify:
                        s.push(f"* {len(folder.messages)} FETCH (UID {m['uid']} FLAGS ({' '.join(sorted(m['flags']))}))")
                elif kind == "expunge":
                    s.push(f"* VANISHED {uid}" if s.qresync else f"* {seq} EXPUNGE")
                elif kind == "flags":
                    i, _ = folder.by_uid(m["uid"])
                    s.push(f"* {i + 1} FETCH (UID {m['uid']} FLAGS ({' '.join(sorted(m['flags']))}) MODSEQ ({m['modseq']}))")
            elif s.notify and kind in ("new", "expunge"):
                st = folder.status()
                s.push(f"* STATUS {q(folder.name)} (MESSAGES {st['MESSAGES']} UIDNEXT {st['UIDNEXT']} UNSEEN {st['UNSEEN']} "
                       f"HIGHESTMODSEQ {st['HIGHESTMODSEQ']})")

    def control(self, c: dict) -> dict:
        op, user = c.get("op"), c.get("user")
        if op == "notify":
            self.refuse_notify = bool(c.get("refuse"))
            return {"ok": True}
        box = self.boxes.get(user)
        if box is None:
            return {"ok": False, "error": "no such user"}
        if op == "state":
            return {"ok": True, "folders": {n: {**f.status(), "flags": {str(m["uid"]): sorted(m["flags"]) for m in f.messages}}
                                            for n, f in box.items()}}
        if op == "drop":
            n = 0
            for s in list(self.sessions):
                if s.user == user:
                    s.drop()
                    n += 1
            return {"ok": True, "dropped": n}
        if op == "create":                                # another client makes a folder
            name = c.get("folder") or ""
            if not name or name in box:
                return {"ok": False, "error": "exists"}
            box[name] = Folder(name, "")
            return {"ok": True}
        f = box.get(c.get("folder") or "INBOX")
        if f is None:
            return {"ok": False, "error": "no such folder"}
        if op == "deliver":
            m = f.add(make_message(user, c, f.uidnext), set(c.get("flags") or ()))
            self.changed(user, f, "new", m=m)
            return {"ok": True, "uid": m["uid"]}
        if op == "expunge":
            i, m = f.by_uid(int(c["uid"]))
            if m is None:
                return {"ok": False, "error": "no such uid"}
            f.messages.pop(i)
            f.highestmodseq += 1
            f.expunged.append((m["uid"], f.highestmodseq))
            self.changed(user, f, "expunge", uid=m["uid"], seq=i + 1)
            return {"ok": True}
        if op == "flag":
            _, m = f.by_uid(int(c["uid"]))
            if m is None:
                return {"ok": False, "error": "no such uid"}
            m["flags"] |= set(c.get("add") or ())
            m["flags"] -= set(c.get("remove") or ())
            f.highestmodseq += 1
            m["modseq"] = f.highestmodseq
            self.changed(user, f, "flags", m=m)
            return {"ok": True}
        if op == "move":                                  # another client moves a message: gone from one folder, new in another
            i, m = f.by_uid(int(c["uid"]))
            to = box.get(c.get("to") or "")
            if m is None or to is None:
                return {"ok": False, "error": "no such uid or folder"}
            f.messages.pop(i)
            f.highestmodseq += 1
            f.expunged.append((m["uid"], f.highestmodseq))
            self.changed(user, f, "expunge", uid=m["uid"], seq=i + 1)
            n = to.add(m["raw"], set(m["flags"]), m["internaldate"])
            self.changed(user, to, "new", m=n)
            return {"ok": True, "uid": n["uid"]}
        if op == "reset":
            Folder._next_validity += 1
            f.uidvalidity = Folder._next_validity + 5000
            f.expunged = []
            for k, m in enumerate(f.messages, 1):
                m["uid"] = k
                f.highestmodseq += 1
                m["modseq"] = f.highestmodseq
            f.uidnext = len(f.messages) + 1
            return {"ok": True, "uidvalidity": f.uidvalidity}
        return {"ok": False, "error": "unknown op"}

    async def control_loop(self, d: Path):
        d.mkdir(parents=True, exist_ok=True)
        while True:
            for p in sorted(d.glob("*.json")):
                try:
                    try:
                        c = json.loads(p.read_text())
                    except ValueError:
                        # a writer that does not write-then-rename may still be writing (a large delivery): try again next round,
                        # and answer with the error only once the file has stood still for two seconds
                        if time.time() - p.stat().st_mtime < 2:
                            continue
                        raise
                    out = self.control(c)
                except Exception as e:  # noqa: BLE001
                    out = {"ok": False, "error": f"{type(e).__name__}: {e}"}
                done = p.with_suffix(".done")
                done.with_suffix(".tmp").write_text(json.dumps(out))
                done.with_suffix(".tmp").rename(done)
                p.unlink()
                self.mark()
            await asyncio.sleep(0.05)


# ---- the command line ----------------------------------------------------------------------------------------------------------

async def read_command(reader, writer):
    """One command, its literals ({n} / {n+}) read in place. None at end of stream."""
    parts, literals = [], []
    line = await reader.readline()
    if not line:
        return None
    while True:
        m = re.search(rb"\{(\d+)(\+?)\}\r?\n$", line)
        if not m:
            parts.append(line.rstrip(b"\r\n"))
            break
        parts.append(line[: m.start()])
        if not m.group(2):
            writer.write(b"+ Ready\r\n")
            await writer.drain()
        literals.append(await reader.readexactly(int(m.group(1))))
        parts.append(b"\x00%d\x00" % (len(literals) - 1))
        line = await reader.readline()
    return b"".join(parts), literals


def tokens(text: bytes, literals: list):
    """Atoms, quoted strings, literals and parenthesised lists → nested Python lists of str. Brackets are kept inside an atom
    (BODY.PEEK[HEADER.FIELDS (A B)] stays one atom), as IMAP's fetch-att grammar needs."""
    pos = 0

    def atom():
        nonlocal pos
        start, depth = pos, 0
        while pos < len(text):
            c = text[pos:pos + 1]
            if c == b"[":
                depth += 1
            elif c == b"]":
                depth -= 1
            elif depth == 0 and c in (b" ", b"(", b")", b"\x00"):
                break
            pos += 1
        return text[start:pos].decode()

    def parse_list(end):
        nonlocal pos
        out = []
        while pos < len(text):
            c = text[pos:pos + 1]
            if c == b" ":
                pos += 1
            elif c == end:
                pos += 1
                return out
            elif c == b"(":
                pos += 1
                out.append(parse_list(b")"))
            elif c == b'"':
                pos += 1
                buf = bytearray()
                while text[pos:pos + 1] != b'"':
                    if text[pos:pos + 1] == b"\\":
                        pos += 1
                    buf += text[pos:pos + 1]
                    pos += 1
                pos += 1
                out.append(buf.decode())
            elif c == b"\x00":
                close = text.index(b"\x00", pos + 1)
                out.append(literals[int(text[pos + 1:close])].decode("utf-8", "replace"))
                pos = close + 1
            else:
                out.append(atom())
        return out

    return parse_list(None)


def seqset(spec: str, maximum: int) -> set[int]:
    out: set[int] = set()
    for part in str(spec).split(","):
        if ":" in part:
            a, b = part.split(":", 1)
            a = maximum if a == "*" else int(a)
            b = maximum if b == "*" else int(b)
            lo, hi = sorted((a, b))
            out.update(range(lo, hi + 1))
        elif part:
            out.add(maximum if part == "*" else int(part))
    return out


FETCH_ITEM = re.compile(r"^(BODY\.PEEK|BODY|BINARY\.PEEK|BINARY|BINARY\.SIZE)\[([^\]]*)\](?:<(\d+)(?:\.(\d+))?>)?$", re.I)
MACROS = {"ALL": ["FLAGS", "INTERNALDATE", "RFC822.SIZE"], "FAST": ["FLAGS", "INTERNALDATE", "RFC822.SIZE"],
          "FULL": ["FLAGS", "INTERNALDATE", "RFC822.SIZE", "BODYSTRUCTURE"]}


class Session:
    def __init__(self, server: Server, reader, writer):
        self.server, self.reader, self.writer = server, reader, writer
        self.user, self.selected, self.readonly = None, None, False
        self.notify = self.qresync = self.condstore = self.idling = False
        self.pending: list[bytes] = []
        self.literals: list = []

    def send(self, s) -> None:
        self.writer.write((s.encode() if isinstance(s, str) else s) + (b"" if isinstance(s, bytes) and s.endswith(CRLF) else CRLF))

    def push(self, line: str) -> None:
        if self.idling:
            self.send(line)
        else:
            self.pending.append(line.encode())

    def flush(self) -> None:
        for line in self.pending:
            self.send(line)
        self.pending = []

    def drop(self) -> None:
        try:
            self.writer.transport.abort()
        except Exception:  # noqa: BLE001
            pass

    # ---- FETCH ---------------------------------------------------------------------------------------------------------------
    def fetch_response(self, seq: int, m: dict, items: list[str], uid_mode: bool, with_modseq: bool) -> bytes:
        f = self.selected
        out: list[bytes] = []
        names = [i.upper() for i in items]
        if uid_mode and "UID" not in names:
            items = ["UID"] + items
        if with_modseq and "MODSEQ" not in [i.upper() for i in items]:
            items = items + ["MODSEQ"]
        msg = None
        seen_set = False
        for item in items:
            up = item.upper()
            if up == "UID":
                out.append(f"UID {m['uid']}".encode())
            elif up == "FLAGS":
                out.append(f"FLAGS ({' '.join(sorted(m['flags']))})".encode())
            elif up == "INTERNALDATE":
                out.append(f'INTERNALDATE "{imap_date(m["internaldate"])}"'.encode())
            elif up == "RFC822.SIZE":
                out.append(f"RFC822.SIZE {len(m['raw'])}".encode())
            elif up == "MODSEQ":
                out.append(f"MODSEQ ({m['modseq']})".encode())
            elif up in ("BODYSTRUCTURE", "BODY"):
                msg = msg or parsed(m["raw"])
                out.append(f"{up} {bodystructure(msg)}".encode())
            elif up == "PREVIEW":
                p = preview(m["raw"]).encode()
                out.append(b"PREVIEW {%d}\r\n" % len(p) + p)
            elif up == "RFC822":
                out.append(b"RFC822 {%d}\r\n" % len(m["raw"]) + m["raw"])
                seen_set = True
            elif up == "RFC822.HEADER":
                h = m["raw"].split(b"\r\n\r\n", 1)[0] + b"\r\n\r\n"
                out.append(b"RFC822.HEADER {%d}\r\n" % len(h) + h)
            else:
                g = FETCH_ITEM.match(item)
                if not g:
                    raise ValueError(f"unknown fetch item {item}")
                kind, section, start, length = g.group(1).upper(), g.group(2), g.group(3), g.group(4)
                su = section.upper()
                if kind == "BINARY.SIZE":
                    msg = msg or parsed(m["raw"])
                    part = section_part(msg, section)
                    out.append(f"BINARY.SIZE[{section}] {len(part_bytes(part, True)) if part is not None else 0}".encode())
                    continue
                if su == "":
                    data = m["raw"]
                elif su == "HEADER":
                    data = m["raw"].split(b"\r\n\r\n", 1)[0] + b"\r\n\r\n"
                elif su == "TEXT":
                    data = m["raw"].split(b"\r\n\r\n", 1)[1] if b"\r\n\r\n" in m["raw"] else b""
                elif su.startswith("HEADER.FIELDS"):
                    names_ = re.findall(r"[^\s()]+", section[section.index("(") + 1:section.rindex(")")])
                    data = header_fields(m["raw"], names_, negate=su.startswith("HEADER.FIELDS.NOT"))
                else:
                    msg = msg or parsed(m["raw"])
                    part = section_part(msg, section)
                    data = part_bytes(part, kind.startswith("BINARY")) if part is not None else b""
                key = ("BINARY" if kind.startswith("BINARY") else "BODY") + f"[{section}]"
                if start is not None:
                    s0 = int(start)
                    data = data[s0:s0 + int(length)] if length is not None else data[s0:]
                    key += f"<{s0}>"
                out.append(key.encode() + b" {%d}\r\n" % len(data) + data)
                if not kind.endswith(".PEEK"):
                    seen_set = True
        if seen_set and "\\Seen" not in m["flags"] and not self.readonly:
            m["flags"].add("\\Seen")
            f.highestmodseq += 1
            m["modseq"] = f.highestmodseq
            self.server.stats["_"]["seen_by_fetch"] += 1
            self.server.mark()
        return b"* %d FETCH (" % seq + b" ".join(out) + b")\r\n"

    def do_fetch(self, tag: str, args: list, uid_mode: bool, raw_text: bytes) -> None:
        f = self.selected
        if f is None:
            self.send(f"{tag} BAD No mailbox selected")
            return
        spec = args[0]
        items = args[1] if len(args) > 1 else []
        if isinstance(items, str):
            items = MACROS.get(items.upper(), [items])
        mods = args[2] if len(args) > 2 and isinstance(args[2], list) else []
        changedsince, vanished = None, False
        for k, mod in enumerate(mods):
            if str(mod).upper() == "CHANGEDSINCE":
                changedsince = int(mods[k + 1])
            elif str(mod).upper() == "VANISHED":
                vanished = True
        if vanished and (not uid_mode or changedsince is None or not self.qresync):
            self.send(f"{tag} BAD VANISHED needs UID FETCH, CHANGEDSINCE and QRESYNC")
            return
        if uid_mode:
            maxuid = f.messages[-1]["uid"] if f.messages else 0
            wanted = seqset(spec, maxuid) if f.messages else set()
            if vanished:
                gone = sorted(u for u, ms in f.expunged if u in seqset(spec, max(maxuid, f.uidnext)) and ms > changedsince)
                if gone:
                    self.send(f"* VANISHED (EARLIER) {','.join(str(u) for u in gone)}")
            chosen = [(i + 1, m) for i, m in enumerate(f.messages) if m["uid"] in wanted]
        else:
            wanted = seqset(spec, len(f.messages))
            chosen = [(i + 1, m) for i, m in enumerate(f.messages) if (i + 1) in wanted]
        if changedsince is not None:
            chosen = [(s, m) for s, m in chosen if m["modseq"] > changedsince]
        try:
            for s, m in chosen:
                self.writer.write(self.fetch_response(s, m, list(items), uid_mode, changedsince is not None or self.condstore))
        except ValueError as e:
            self.send(f"{tag} BAD {e}")
            return
        self.send(f"{tag} OK FETCH completed")

    def do_search(self, tag: str, args: list, uid_mode: bool) -> None:
        f = self.selected
        if f is None:
            self.send(f"{tag} BAD No mailbox selected")
            return
        result = list(enumerate(f.messages, 1))
        k = 0
        words = [str(a) for a in args]
        if words and words[0].upper() == "CHARSET":
            k = 2
        maxuid = f.messages[-1]["uid"] if f.messages else 0
        while k < len(words):
            w = words[k].upper()
            if w == "ALL":
                pass
            elif w == "UNSEEN":
                result = [(s, m) for s, m in result if "\\Seen" not in m["flags"]]
            elif w == "SEEN":
                result = [(s, m) for s, m in result if "\\Seen" in m["flags"]]
            elif w == "FLAGGED":
                result = [(s, m) for s, m in result if "\\Flagged" in m["flags"]]
            elif w == "UID":
                k += 1
                ids = seqset(words[k], maxuid)
                result = [(s, m) for s, m in result if m["uid"] in ids]
            elif w == "HEADER":                                       # slice 4: SEARCH HEADER Message-ID <id>
                name, value = words[k + 1].lower().encode(), words[k + 2].encode()
                k += 2
                def has(m, name=name, value=value):
                    head = m["raw"].split(b"\r\n\r\n", 1)[0]
                    for ln in re.split(rb"\r\n(?![ \t])", head):
                        if ln.lower().startswith(name + b":") and value in ln:
                            return True
                    return False
                result = [(s, m) for s, m in result if has(m)]
            elif re.match(r"^[\d*:,]+$", w):
                ids = seqset(w, len(f.messages))
                result = [(s, m) for s, m in result if s in ids]
            else:
                self.send(f"{tag} BAD SEARCH key {w} is not answered here")
                return
            k += 1
        self.send("* SEARCH" + "".join(f" {m['uid'] if uid_mode else s}" for s, m in result))
        self.send(f"{tag} OK SEARCH completed")

    def do_store(self, tag: str, args: list, uid_mode: bool) -> None:
        f = self.selected
        if f is None or self.readonly:
            self.send(f"{tag} NO Not selected read-write")
            return
        spec, what, flags = args[0], str(args[1]).upper(), args[2] if isinstance(args[2], list) else [args[2]]
        if uid_mode:
            ids = seqset(spec, f.messages[-1]["uid"] if f.messages else 0)
            chosen = [(i + 1, m) for i, m in enumerate(f.messages) if m["uid"] in ids]
        else:
            ids = seqset(spec, len(f.messages))
            chosen = [(i + 1, m) for i, m in enumerate(f.messages) if (i + 1) in ids]
        for s, m in chosen:
            if what.startswith("+"):
                m["flags"] |= set(flags)
            elif what.startswith("-"):
                m["flags"] -= set(flags)
            else:
                m["flags"] = set(flags)
            f.highestmodseq += 1
            m["modseq"] = f.highestmodseq
            self.server.stats["_"]["stores"].append({"user": self.user, "folder": f.name, "uid": m["uid"], "op": what, "flags": flags})
            if not what.endswith(".SILENT"):
                self.send(f"* {s} FETCH (UID {m['uid']} FLAGS ({' '.join(sorted(m['flags']))}) MODSEQ ({m['modseq']}))")
            for other in self.server.sessions:
                if other is not self and other.user == self.user and other.selected is f:
                    other.push(f"* {s} FETCH (UID {m['uid']} FLAGS ({' '.join(sorted(m['flags']))}) MODSEQ ({m['modseq']}))")
        self.server.mark()
        self.send(f"{tag} OK STORE completed")

    # ---- the loop ------------------------------------------------------------------------------------------------------------
    async def run(self):
        server = self.server
        server.sessions.add(self)
        server.stats["_"]["connections"] += 1
        send = self.send
        send(f"* OK [CAPABILITY {CAPS_PRE}] MaluMail fixture ready.")
        try:
            while True:
                got = await read_command(self.reader, self.writer)
                if got is None:
                    break
                words = tokens(*got)
                self.literals = got[1]                       # the raw literals of this command (APPEND reads the last one as bytes)
                if len(words) < 2:
                    send("* BAD Missing command")
                    continue
                tag, cmd, args = words[0], str(words[1]).upper(), words[2:]
                server.stats["_"]["commands"] += 1
                uid_mode = False
                if cmd == "UID" and args:
                    uid_mode, cmd, args = True, str(args[0]).upper(), args[1:]
                if self.user is not None and cmd not in ("IDLE",):
                    self.flush()
                if cmd == "CAPABILITY":
                    send(f"* CAPABILITY {CAPS if self.user else CAPS_PRE}")
                    send(f"{tag} OK CAPABILITY completed")
                elif cmd == "NOOP":
                    send(f"{tag} OK NOOP completed")
                elif cmd == "ID":
                    send('* ID ("name" "MaluMail fixture")')
                    send(f"{tag} OK ID completed")
                elif cmd == "LOGOUT":
                    send("* BYE Logging out")
                    send(f"{tag} OK LOGOUT completed")
                    break
                elif cmd == "LOGIN":
                    if len(args) != 2:
                        send(f"{tag} BAD LOGIN takes a user and a password")
                        continue
                    result = server.login(args[0], args[1])
                    if result == "ok":
                        self.user = args[0]
                        send(f"{tag} OK [CAPABILITY {CAPS}] Logged in")
                    elif result == "busy":
                        send(f"{tag} NO [UNAVAILABLE] Temporary authentication failure.")
                    else:
                        send(f"{tag} NO [AUTHENTICATIONFAILED] Authentication failed.")
                elif self.user is None:
                    send(f"{tag} NO Not authenticated")
                else:
                    await self.authenticated(tag, cmd, args, uid_mode, got[0])
                await self.writer.drain()
        except (asyncio.IncompleteReadError, ConnectionError, ssl.SSLError, OSError):
            pass
        finally:
            server.sessions.discard(self)
            try:
                await self.writer.drain()
                self.writer.close()
            except Exception:  # noqa: BLE001
                pass

    async def authenticated(self, tag, cmd, args, uid_mode, raw_text):
        server, send = self.server, self.send
        box = server.boxes[self.user]
        if cmd == "ENABLE":
            on = []
            for a in args:
                a = str(a).upper()
                if a == "QRESYNC":
                    self.qresync = self.condstore = True
                    on.append("QRESYNC")
                elif a == "CONDSTORE":
                    self.condstore = True
                    on.append("CONDSTORE")
            send("* ENABLED" + "".join(" " + a for a in on))
            send(f"{tag} OK ENABLE completed")
        elif cmd == "LIST":
            pattern = args[1] if len(args) > 1 else "*"
            ret = []
            if len(args) > 3 and str(args[2]).upper() == "RETURN" and isinstance(args[3], list):
                ret = args[3]
            status_items = []
            for k, r in enumerate(ret):
                if str(r).upper() == "STATUS" and k + 1 < len(ret) and isinstance(ret[k + 1], list):
                    status_items = [str(i).upper() for i in ret[k + 1]]
            for name, f in box.items():
                if pattern in ("*", "%") or pattern == name:
                    flags = " ".join(x for x in ("\\HasNoChildren", f.special) if x)
                    send(f'* LIST ({flags}) "." {q(name)}')
                    if status_items:
                        st = f.status()
                        send(f"* STATUS {q(name)} (" + " ".join(f"{i} {st[i]}" for i in status_items if i in st) + ")")
            send(f"{tag} OK LIST completed")
        elif cmd == "STATUS":
            f = box.get(args[0]) if args else None
            if f is None:
                send(f"{tag} NO [NONEXISTENT] No such mailbox")
                return
            st = f.status()
            items = [str(i).upper() for i in (args[1] if len(args) > 1 and isinstance(args[1], list) else [])]
            send(f"* STATUS {q(f.name)} (" + " ".join(f"{i} {st[i]}" for i in items if i in st) + ")")
            send(f"{tag} OK STATUS completed")
        elif cmd == "CREATE":
            name = args[0] if args else ""
            if not name or name in box:
                send(f"{tag} NO [ALREADYEXISTS] Mailbox exists")
                return
            box[name] = Folder(name, "")
            server.stats["_"]["creates"].append({"user": self.user, "folder": name})
            server.mark()
            send(f"{tag} OK CREATE completed")
        elif cmd == "RENAME":                            # slice 6: a folder and the folders under it change name; the messages go with them
            old, new = (args[0], args[1]) if len(args) > 1 else ("", "")
            if old not in box or old.upper() == "INBOX" or box[old].special:
                send(f"{tag} NO [CANNOT] A system folder keeps its name")
                return
            if not new or new in box:
                send(f"{tag} NO [ALREADYEXISTS] Mailbox exists")
                return
            for n in [n for n in box if n == old or n.startswith(old + ".")]:
                f = box.pop(n)
                f.name = new + n[len(old):]
                box[f.name] = f
            server.stats["_"]["renames"].append({"user": self.user, "from": old, "to": new})
            server.mark()
            send(f"{tag} OK RENAME completed")
        elif cmd == "DELETE":                            # slice 6: a folder goes with its messages, as Dovecot does (the mail service asks first)
            name = args[0] if args else ""
            if name not in box or name.upper() == "INBOX" or box[name].special:
                send(f"{tag} NO [CANNOT] No such folder, or a system one")
                return
            if self.selected is box[name]:
                self.selected = None
            del box[name]
            server.stats["_"]["deletes"].append({"user": self.user, "folder": name})
            server.mark()
            send(f"{tag} OK DELETE completed")
        elif cmd in ("SELECT", "EXAMINE"):
            f = box.get(args[0]) if args else None
            if f is None:
                send(f"{tag} NO [NONEXISTENT] No such mailbox")
                return
            self.selected, self.readonly = f, cmd == "EXAMINE"
            server.stats["_"]["selects"] += 1
            send("* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)")
            send(f"* {len(f.messages)} EXISTS")
            send("* 0 RECENT")
            send(f"* OK [UIDVALIDITY {f.uidvalidity}] UIDs valid")
            send(f"* OK [UIDNEXT {f.uidnext}] Predicted next UID")
            send(f"* OK [HIGHESTMODSEQ {f.highestmodseq}] Highest")
            params = args[1] if len(args) > 1 and isinstance(args[1], list) else []
            if len(params) >= 2 and str(params[0]).upper() == "QRESYNC" and isinstance(params[1], list) and self.qresync:
                v, ms = int(params[1][0]), int(params[1][1])
                if v == f.uidvalidity:
                    gone = sorted(u for u, x in f.expunged if x > ms)
                    if gone:
                        send(f"* VANISHED (EARLIER) {','.join(str(u) for u in gone)}")
                    for i, m in enumerate(f.messages, 1):
                        if m["modseq"] > ms:
                            send(f"* {i} FETCH (UID {m['uid']} FLAGS ({' '.join(sorted(m['flags']))}) MODSEQ ({m['modseq']}))")
            send(f"{tag} OK [{'READ-ONLY' if self.readonly else 'READ-WRITE'}] {cmd} completed")
        elif cmd == "GETQUOTAROOT":
            used = sum(len(m["raw"]) for f in box.values() for m in f.messages) // 1024
            send(f'* QUOTAROOT {q(args[0] if args else "INBOX")} ""')
            send(f'* QUOTA "" (STORAGE {used} {QUOTA_KB})')
            send(f"{tag} OK GETQUOTAROOT completed")
        elif cmd == "FETCH":
            self.do_fetch(tag, args, uid_mode, raw_text)
        elif cmd == "SEARCH":
            self.do_search(tag, args, uid_mode)
        elif cmd == "STORE":
            self.do_store(tag, args, uid_mode)
        elif cmd == "EXPUNGE":
            f = self.selected
            if f is None or self.readonly:
                send(f"{tag} NO Not selected read-write")
                return
            ids = seqset(args[0], f.messages[-1]["uid"] if f.messages else 0) if (uid_mode and args) else None
            keep, removed = [], 0
            for i, m in enumerate(f.messages, 1):
                if "\\Deleted" in m["flags"] and (ids is None or m["uid"] in ids):
                    f.highestmodseq += 1
                    f.expunged.append((m["uid"], f.highestmodseq))
                    send(f"* VANISHED {m['uid']}" if self.qresync else f"* {i - removed} EXPUNGE")
                    server.stats["_"]["expunges"].append({"user": self.user, "folder": f.name, "uid": m["uid"]})
                    removed += 1
                else:
                    keep.append(m)
            f.messages = keep
            for other in server.sessions:
                if other is not self and other.user == self.user and other.selected is f:
                    pass                                          # a real server tells them at their next command; QRESYNC catches up
            server.mark()
            send(f"{tag} OK EXPUNGE completed")
        elif cmd == "MOVE":                                       # slice 3: UID MOVE set "folder" → [COPYUID validity src dst] (RFC 6851, 4315)
            f = self.selected
            if f is None or self.readonly or not uid_mode or len(args) < 2:
                send(f"{tag} NO Not selected read-write, or not a UID MOVE")
                return
            to = box.get(args[1])
            if to is None:
                send(f"{tag} NO [TRYCREATE] No such mailbox")
                return
            ids = seqset(args[0], f.messages[-1]["uid"] if f.messages else 0)
            chosen = [(i + 1, m) for i, m in enumerate(f.messages) if m["uid"] in ids]
            src, dst = [], []
            for i, m in reversed(chosen):
                f.messages.pop(i - 1)
                f.highestmodseq += 1
                f.expunged.append((m["uid"], f.highestmodseq))
                n = to.add(m["raw"], set(m["flags"]), m["internaldate"])
                src.append(m["uid"]); dst.append(n["uid"])
                server.stats["_"]["moves"].append({"user": self.user, "from": f.name, "to": to.name, "uid": m["uid"], "new_uid": n["uid"]})
                for other in list(server.sessions):          # the other sessions (the watcher) hear of it as from another client
                    if other is self or other.user != self.user:
                        continue
                    if other.selected is f:
                        other.push(f"* VANISHED {m['uid']}" if other.qresync else f"* {i} EXPUNGE")
                    elif other.notify:
                        st = f.status()
                        other.push(f"* STATUS {q(f.name)} (MESSAGES {st['MESSAGES']} UIDNEXT {st['UIDNEXT']} UNSEEN {st['UNSEEN']} HIGHESTMODSEQ {st['HIGHESTMODSEQ']})")
                    if other.selected is to:
                        other.push(f"* {len(to.messages)} EXISTS")
                    elif other.notify:
                        st = to.status()
                        other.push(f"* STATUS {q(to.name)} (MESSAGES {st['MESSAGES']} UIDNEXT {st['UIDNEXT']} UNSEEN {st['UNSEEN']} HIGHESTMODSEQ {st['HIGHESTMODSEQ']})")
            src.reverse(); dst.reverse()
            if src and self.qresync:
                send(f"* VANISHED {','.join(map(str, src))}")
            server.mark()
            if src:
                send(f"{tag} OK [COPYUID {to.uidvalidity} {','.join(map(str, src))} {','.join(map(str, dst))}] Move completed")
            else:
                send(f"{tag} OK Move completed (nothing matched)")
        elif cmd == "APPEND":                                     # slice 4: APPEND folder [(flags)] ["date"] {literal} → [APPENDUID v uid]
            name = args[0] if args else ""
            f = box.get(name)
            if f is None:
                send(f"{tag} NO [TRYCREATE] No such mailbox")
                return
            flags = set()
            when = None
            for a in args[1:]:
                if isinstance(a, list):
                    flags = set(str(x) for x in a)
                elif isinstance(a, str) and re.match(r"^\d{1,2}-[A-Za-z]{3}-\d{4} ", a):
                    try:
                        when = time.mktime(time.strptime(a[:20].strip(), "%d-%b-%Y %H:%M:%S")) - time.timezone
                    except ValueError:
                        when = None
            raw = self.literals[-1] if self.literals else b""
            m = f.add(crlf(raw), flags, when)
            server.stats["_"].setdefault("appends", []).append({"user": self.user, "folder": f.name, "uid": m["uid"], "flags": sorted(flags), "size": len(raw)})
            server.mark()
            for other in list(server.sessions):
                if other is not self and other.user == self.user:
                    if other.selected is f:
                        other.push(f"* {len(f.messages)} EXISTS")
                    elif other.notify:
                        st = f.status()
                        other.push(f"* STATUS {q(f.name)} (MESSAGES {st['MESSAGES']} UIDNEXT {st['UIDNEXT']} UNSEEN {st['UNSEEN']} HIGHESTMODSEQ {st['HIGHESTMODSEQ']})")
            send(f"{tag} OK [APPENDUID {f.uidvalidity} {m['uid']}] Append completed")
        elif cmd == "NOTIFY":
            if args and str(args[0]).upper() == "NONE":
                self.notify = False
                send(f"{tag} OK NOTIFY completed")
            elif server.refuse_notify:
                server.stats["_"]["notify_refused"] += 1
                server.mark()
                send(f"{tag} NO [BADEVENT] NOTIFY is not available here")
            else:
                self.notify = True
                server.stats["_"]["notify_sets"] += 1
                server.mark()
                send(f"{tag} OK NOTIFY completed")
        elif cmd == "IDLE":
            server.stats["_"]["idles"] += 1
            server.mark()
            send("+ idling")
            self.idling = True
            self.flush()
            await self.writer.drain()
            try:
                while True:
                    line = await self.reader.readline()
                    if not line:
                        raise ConnectionError()
                    if line.strip().upper() == b"DONE":
                        break
            finally:
                self.idling = False
            send(f"{tag} OK IDLE terminated")
        else:
            send(f"{tag} BAD {cmd} is not something this fixture answers yet")


async def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--users", required=True)
    ap.add_argument("--cert", required=True)
    ap.add_argument("--key", required=True)
    ap.add_argument("--port", type=int, default=0)
    ap.add_argument("--port-file", required=True)
    ap.add_argument("--stats", required=True)
    ap.add_argument("--inbox", type=int, default=12)
    ap.add_argument("--passwords", default=None, help="the fake Mailbox MCP's passwords.jsonl: the latest issued per address wins")
    ap.add_argument("--control", default=None, help="a directory of control files (see the module's docstring)")
    ap.add_argument("--no-notify", action="store_true", help="answer NOTIFY with NO, as a server without it would")
    a = ap.parse_args()
    server = Server(json.loads(Path(a.users).read_text()), Path(a.stats), a.inbox, Path(a.passwords) if a.passwords else None,
                    a.no_notify)
    ctx = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    ctx.load_cert_chain(a.cert, a.key)
    srv = await asyncio.start_server(lambda r, w: Session(server, r, w).run(), "127.0.0.1", a.port, ssl=ctx)
    Path(a.port_file).write_text(str(srv.sockets[0].getsockname()[1]))
    keep = {asyncio.create_task(server.saver())}
    if a.control:
        keep.add(asyncio.create_task(server.control_loop(Path(a.control))))
    async with srv:
        await srv.serve_forever()
    del keep


if __name__ == "__main__":
    asyncio.run(main())
