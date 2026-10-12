"""The IMAP FIXTURE (design §10 Phase 0): a small IMAP server over implicit TLS for the mail service's proofs, so nothing in a
proof logs in to MaluMail's real server — above all no wrong password, which would trip its abuse protection for this whole
machine (research 04). Synthetic mail only.

Phase 0 speaks what the skeleton's probe uses and grows with slice 2 (QRESYNC, NOTIFY/IDLE, FETCH, STORE, MOVE, APPEND):
CAPABILITY, ID, NOOP, LOGOUT, LOGIN, ENABLE, LIST (SPECIAL-USE flags), STATUS (incl. HIGHESTMODSEQ), SELECT/EXAMINE. It advertises
only what it answers. Mailboxes are five folders like MaluMail's (INBOX, Drafts, Sent, Junk, Trash; research 04 §B).

  imap_server.py --users users.json --cert c.pem --key k.pem --port-file p --stats stats.json [--port 0] [--inbox 12]

users.json: {"address": {"password": "…", "mode": "ok" | "busy"}} — `busy` answers every login NO [UNAVAILABLE]. With
--passwords, the fake Mailbox MCP's issued passwords (create, reset) replace users.json's for their address, as at MaluMail. Every login
attempt is counted in stats.json ({"address": {"attempts": n, "ok": n}}), so a proof can show a refused credential was tried once.
"""

import argparse
import asyncio
import email.message
import email.utils
import json
import re
import ssl
from pathlib import Path

CAPS_PRE = "IMAP4rev1 LITERAL+ ID ENABLE AUTH=PLAIN"
CAPS = "IMAP4rev1 LITERAL+ ID ENABLE CONDSTORE SPECIAL-USE"
FOLDERS = [("INBOX", ""), ("Drafts", "\\Drafts"), ("Sent", "\\Sent"), ("Junk", "\\Junk"), ("Trash", "\\Trash")]


class Folder:
    def __init__(self, name, special, uidvalidity):
        self.name, self.special, self.uidvalidity = name, special, uidvalidity
        self.messages = []                      # dicts: uid, flags (set), modseq, raw (bytes)
        self.uidnext, self.highestmodseq = 1, 1

    def add(self, raw: bytes, flags=()):
        self.highestmodseq += 1
        self.messages.append({"uid": self.uidnext, "flags": set(flags), "modseq": self.highestmodseq, "raw": raw})
        self.uidnext += 1


def synthetic(n: int, address: str) -> list[bytes]:
    out = []
    for i in range(n):
        m = email.message.EmailMessage()
        m["From"] = f"Sender {i} <sender{i}@outside.example>"
        m["To"] = address
        m["Subject"] = f"Synthetic message {i}"
        m["Date"] = email.utils.formatdate(1760000000 + i * 60, usegmt=True)
        m["Message-ID"] = f"<fixture-{i}@outside.example>"
        m.set_content(f"Synthetic body {i}.\n")
        out.append(bytes(m))
    return out


class Server:
    def __init__(self, users: dict, stats_path: Path, inbox: int, passwords: Path | None = None):
        self.users, self.stats_path, self.passwords = users, stats_path, passwords
        self.stats = {a: {"attempts": 0, "ok": 0} for a in users}
        self.boxes = {}
        for i, address in enumerate(sorted(users)):
            folders = {name: Folder(name, special, 1000 + i) for name, special in FOLDERS}
            for k, raw in enumerate(synthetic(inbox, address)):
                folders["INBOX"].add(raw, {"\\Seen"} if k % 2 else set())
            self.boxes[address] = folders
        self._save()

    def _save(self):
        self.stats_path.write_text(json.dumps(self.stats))

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
                self.boxes[user] = {name: Folder(name, special, 2000 + len(self.boxes)) for name, special in FOLDERS}
        else:
            result = "failed"
        self._save()
        return result


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
    """Atoms, quoted strings, literals and parenthesised lists → nested Python lists of str."""
    pos = 0

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
                out.append(literals[int(text[pos + 1:close])].decode())
                pos = close + 1
            else:
                m = re.match(rb"[^ ()\x00]+", text[pos:])
                out.append(m.group(0).decode())
                pos += len(m.group(0))
        return out

    return parse_list(None)


def quote(s: str) -> str:
    return '"' + s.replace("\\", "\\\\").replace('"', '\\"') + '"'


async def session(server: Server, reader, writer):
    send = lambda s: writer.write(s.encode() + b"\r\n")  # noqa: E731
    send(f"* OK [CAPABILITY {CAPS_PRE}] MaluMail fixture ready.")
    user, selected = None, None
    try:
        while True:
            got = await read_command(reader, writer)
            if got is None:
                break
            words = tokens(*got)
            if len(words) < 2:
                send("* BAD Missing command")
                continue
            tag, cmd, args = words[0], str(words[1]).upper(), words[2:]
            if cmd == "CAPABILITY":
                send(f"* CAPABILITY {CAPS if user else CAPS_PRE}")
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
                    user = args[0]
                    send(f"{tag} OK [CAPABILITY {CAPS}] Logged in")
                elif result == "busy":
                    send(f"{tag} NO [UNAVAILABLE] Temporary authentication failure.")
                else:
                    send(f"{tag} NO [AUTHENTICATIONFAILED] Authentication failed.")
            elif user is None:
                send(f"{tag} NO Not authenticated")
            elif cmd == "ENABLE":
                on = [a for a in args if str(a).upper() == "CONDSTORE"]
                send("* ENABLED" + "".join(" " + a for a in on))
                send(f"{tag} OK ENABLE completed")
            elif cmd == "LIST":
                pattern = args[1] if len(args) > 1 else "*"
                for name, special in FOLDERS:
                    if pattern in ("*", "%") or pattern == name:
                        flags = " ".join(f for f in ("\\HasNoChildren", special) if f)
                        send(f'* LIST ({flags}) "/" {quote(name)}')
                send(f"{tag} OK LIST completed")
            elif cmd == "STATUS":
                f = server.boxes[user].get(args[0]) if args else None
                if f is None:
                    send(f"{tag} NO [NONEXISTENT] No such mailbox")
                    continue
                values = {"MESSAGES": len(f.messages), "UIDNEXT": f.uidnext, "UIDVALIDITY": f.uidvalidity,
                          "UNSEEN": sum(1 for m in f.messages if "\\Seen" not in m["flags"]), "RECENT": 0,
                          "HIGHESTMODSEQ": f.highestmodseq}
                items = [str(i).upper() for i in (args[1] if len(args) > 1 and isinstance(args[1], list) else [])]
                send(f"* STATUS {quote(f.name)} (" + " ".join(f"{i} {values[i]}" for i in items if i in values) + ")")
                send(f"{tag} OK STATUS completed")
            elif cmd in ("SELECT", "EXAMINE"):
                f = server.boxes[user].get(args[0]) if args else None
                if f is None:
                    send(f"{tag} NO [NONEXISTENT] No such mailbox")
                    continue
                selected = f
                send("* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)")
                send(f"* {len(f.messages)} EXISTS")
                send("* 0 RECENT")
                send(f"* OK [UIDVALIDITY {f.uidvalidity}] UIDs valid")
                send(f"* OK [UIDNEXT {f.uidnext}] Predicted next UID")
                send(f"* OK [HIGHESTMODSEQ {f.highestmodseq}] Highest")
                send(f"{tag} OK [{'READ-ONLY' if cmd == 'EXAMINE' else 'READ-WRITE'}] {cmd} completed")
            else:
                send(f"{tag} BAD {cmd} is not something this fixture answers yet")
            await writer.drain()
    except (asyncio.IncompleteReadError, ConnectionError, ssl.SSLError):
        pass
    finally:
        try:
            await writer.drain()
            writer.close()
        except Exception:  # noqa: BLE001
            pass


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
    a = ap.parse_args()
    server = Server(json.loads(Path(a.users).read_text()), Path(a.stats), a.inbox, Path(a.passwords) if a.passwords else None)
    ctx = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    ctx.load_cert_chain(a.cert, a.key)
    srv = await asyncio.start_server(lambda r, w: session(server, r, w), "127.0.0.1", a.port, ssl=ctx)
    Path(a.port_file).write_text(str(srv.sockets[0].getsockname()[1]))
    async with srv:
        await srv.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
