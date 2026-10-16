"""The SMTP FIXTURE (docs/build-specs/sending-and-oversight.md §11): a small asyncio SUBMISSION server for the proofs — never
MaluMail's — on the standard library alone. EHLO advertises STARTTLS, AUTH PLAIN LOGIN, SIZE, 8BITMIME; STARTTLS under the scratch CA's
certificate; AUTH only after TLS, against the IMAP fixture's users.json (re-read per connection, so a proof may change a user's mode);
535 5.7.8 for a wrong password, counted in stats.json; MAIL FROM must be the authenticated address (MM3's rule); RCPT TO up to 100;
DATA refused over SIZE. Modes per user (`smtp_mode` in users.json): ok · defer (451 4.7.1 every time) · defer_once (451 once, then ok)
· refuse_rcpt (550 5.1.1 for recipients ending @nowhere.example) · drop (the connection closed after DATA's final dot — the crash case)
· drop_once. Every accepted message is written to <spool>/<n>.eml with a line in <spool>/index.jsonl {"from", "rcpt", "message_id",
"size", "user"}; the reply is `250 2.0.0 Ok: queued as <n>`.

  smtp_server.py --users users.json --cert c.pem --key k.pem --port-file p --stats stats.json --spool DIR [--port 0] [--size 10240000]
"""

import argparse
import asyncio
import base64
import json
import re
import ssl
import time
from pathlib import Path

CRLF = b"\r\n"


class Server:
    def __init__(self, users: Path, stats: Path, spool: Path, cert: str, key: str, size: int):
        self.users_path, self.stats_path, self.spool, self.size = users, stats, spool, size
        self.spool.mkdir(parents=True, exist_ok=True)
        self.ctx = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
        self.ctx.load_cert_chain(cert, key)
        self.stats = {"_": {"connections": 0, "logins": 0, "auth_failed": 0, "messages": 0, "deferred": 0, "dropped": 0, "refused_rcpt": 0}, "once": {}}
        self.n = 0
        self._save()

    def users(self) -> dict:
        try:
            return json.loads(self.users_path.read_text())
        except (OSError, ValueError):
            return {}

    def _save(self):
        self.stats_path.write_text(json.dumps(self.stats))

    def mode(self, user: str, consume: bool = True) -> str:
        """The user's mode; a `_once` mode is spent by the DATA that reads it (consume), not by a look at RCPT time. A rewrite of the
        users file (a proof changing a mode) starts every `_once` afresh."""
        try:
            mtime = self.users_path.stat().st_mtime_ns
        except OSError:
            mtime = 0
        if mtime != getattr(self, "_users_mtime", None):
            self._users_mtime = mtime
            self.stats["once"] = {}
        m = (self.users().get(user) or {}).get("smtp_mode", "ok")
        if m.endswith("_once"):
            if self.stats["once"].get(user):
                return "ok"
            if consume:
                self.stats["once"][user] = True
                self._save()
            return m[:-5]
        return m

    async def session(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter):
        self.stats["_"]["connections"] += 1
        self._save()
        tls = False
        user = None
        mail_from = None
        rcpts: list[str] = []

        async def send(line: str):
            writer.write(line.encode() + CRLF)
            await writer.drain()

        await send("220 smtp.fixture ESMTP ready")
        try:
            while True:
                raw = await reader.readline()
                if not raw:
                    return
                line = raw.rstrip(b"\r\n").decode("utf-8", "replace")
                cmd, _, arg = line.partition(" ")
                cmd = cmd.upper()
                if cmd in ("EHLO", "HELO"):
                    await send("250-smtp.fixture")
                    if not tls:
                        await send("250-STARTTLS")
                    else:
                        await send("250-AUTH PLAIN LOGIN")
                    await send(f"250-SIZE {self.size}")
                    await send("250 8BITMIME")
                elif cmd == "STARTTLS":
                    if tls:
                        await send("503 5.5.1 Already in TLS")
                        continue
                    await send("220 2.0.0 Ready to start TLS")
                    await writer.start_tls(self.ctx)
                    tls = True
                elif cmd == "AUTH":
                    if not tls:
                        await send("538 5.7.11 Encryption required for requested authentication mechanism")
                        continue
                    mech, _, initial = arg.partition(" ")
                    mech = mech.upper()
                    if mech == "PLAIN":
                        if not initial:
                            await send("334 ")
                            initial = (await reader.readline()).strip().decode()
                        try:
                            parts = base64.b64decode(initial).split(b"\x00")
                            u, p = parts[-2].decode(), parts[-1].decode()
                        except Exception:  # noqa: BLE001
                            await send("501 5.5.2 Cannot decode response")
                            continue
                    elif mech == "LOGIN":
                        await send("334 VXNlcm5hbWU6")
                        u = base64.b64decode((await reader.readline()).strip()).decode()
                        await send("334 UGFzc3dvcmQ6")
                        p = base64.b64decode((await reader.readline()).strip()).decode()
                    else:
                        await send("504 5.5.4 Unrecognized authentication type")
                        continue
                    users = self.users()
                    if u in users and users[u].get("password") == p and users[u].get("mode", "ok") != "busy":
                        user = u
                        self.stats["_"]["logins"] += 1
                        self._save()
                        await send("235 2.7.0 Authentication successful")
                    else:
                        self.stats["_"]["auth_failed"] += 1
                        self._save()
                        await send("535 5.7.8 Error: authentication failed")
                elif cmd == "MAIL":
                    if user is None:
                        await send("530 5.7.0 Authentication required")
                        continue
                    m = re.search(r"<([^>]*)>", arg)
                    frm = (m.group(1) if m else arg.split(":", 1)[-1].strip()).lower()
                    if frm != user.lower():
                        await send("553 5.7.1 Sender address rejected: not owned by user")
                        continue
                    sz = re.search(r"SIZE=(\d+)", arg, re.I)
                    if sz and int(sz.group(1)) > self.size:
                        await send("552 5.3.4 Message size exceeds fixed limit")
                        continue
                    mail_from, rcpts = frm, []
                    await send("250 2.1.0 Ok")
                elif cmd == "RCPT":
                    if mail_from is None:
                        await send("503 5.5.1 Error: need MAIL command")
                        continue
                    m = re.search(r"<([^>]*)>", arg)
                    to = (m.group(1) if m else arg.split(":", 1)[-1].strip()).lower()
                    if len(rcpts) >= 100:
                        await send("452 4.5.3 Error: too many recipients")
                        continue
                    if self.mode(user, consume=False) == "refuse_rcpt" and to.endswith("@nowhere.example"):
                        self.stats["_"]["refused_rcpt"] += 1
                        self._save()
                        await send("550 5.1.1 Recipient address rejected: User unknown")
                        continue
                    rcpts.append(to)
                    await send("250 2.1.5 Ok")
                elif cmd == "DATA":
                    if not rcpts:
                        await send("554 5.5.1 Error: no valid recipients")
                        continue
                    await send("354 End data with <CR><LF>.<CR><LF>")
                    data = bytearray()
                    while True:
                        chunk = await reader.readline()
                        if not chunk:
                            return
                        if chunk == b".\r\n":
                            break
                        if chunk.startswith(b".."):
                            chunk = chunk[1:]
                        data += chunk
                        if len(data) > self.size + 1024:
                            await send("552 5.3.4 Error: message too large")
                            mail_from, rcpts = None, []
                            data = None
                            break
                    if data is None:
                        continue
                    mode = self.mode(user)
                    if mode == "drop":
                        self.stats["_"]["dropped"] += 1
                        self._save()
                        writer.close()
                        return
                    if mode == "defer":
                        self.stats["_"]["deferred"] += 1
                        self._save()
                        await send("451 4.7.1 Rate limit exceeded, try again later")
                        mail_from, rcpts = None, []
                        continue
                    self.n += 1
                    n = self.n
                    (self.spool / f"{n}.eml").write_bytes(bytes(data))
                    mid = re.search(rb"^Message-ID:\s*(<[^>]+>)", bytes(data), re.M | re.I)
                    with (self.spool / "index.jsonl").open("a") as f:
                        f.write(json.dumps({"n": n, "from": mail_from, "rcpt": rcpts, "message_id": mid.group(1).decode() if mid else None,
                                            "size": len(data), "user": user, "at": time.time()}) + "\n")
                    self.stats["_"]["messages"] += 1
                    self._save()
                    await send(f"250 2.0.0 Ok: queued as {n}")
                    mail_from, rcpts = None, []
                elif cmd == "RSET":
                    mail_from, rcpts = None, []
                    await send("250 2.0.0 Ok")
                elif cmd == "NOOP":
                    await send("250 2.0.0 Ok")
                elif cmd == "QUIT":
                    await send("221 2.0.0 Bye")
                    writer.close()
                    return
                else:
                    await send("502 5.5.2 Error: command not recognized")
        except (ConnectionError, asyncio.IncompleteReadError, ssl.SSLError, OSError):
            return
        finally:
            try:
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
    ap.add_argument("--spool", required=True)
    ap.add_argument("--size", type=int, default=10240000)
    a = ap.parse_args()
    srv = Server(Path(a.users), Path(a.stats), Path(a.spool), a.cert, a.key, a.size)
    s = await asyncio.start_server(srv.session, "127.0.0.1", a.port)
    Path(a.port_file).write_text(str(s.sockets[0].getsockname()[1]))
    async with s:
        await s.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
