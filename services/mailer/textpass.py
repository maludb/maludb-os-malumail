"""The text pass (docs/build-specs/mail-service.md §3.4): the words of every message into the index, gently.

For rows with text_indexed_at IS NULL, newest first (db/008's partial index), up to 200 a command: the message's BODYSTRUCTURE picks
the first text/plain part (else the first text/html, turned to text by nh3 with no tag allowed), then BINARY.PEEK[<part>]<0.N> —
N = ml_settings.index_text_max_kb × 1024 — grouped by part number. A message with no text part is marked indexed with no text.
body_text and text_indexed_at are written; db/008's trigger caps the text and rebuilds tsv.

A gentle rate: at most four commands a minute per mailbox (ML_MAILER_TEXT_PER_MINUTE; the proofs raise it) and four mailboxes at once
across the service. Every fetch is a PEEK. The work runs on the mailbox's pool under its lock, in a thread.
"""

from __future__ import annotations

import asyncio
import html as html_lib
import logging
import re
import time

import nh3

from services.mailer import imap

log = logging.getLogger("malumail.mailer.text")

PER_COMMAND = 200
MAILBOXES_AT_ONCE = 4
_WS = re.compile(r"[ \t\r\f\v]+")
_NL = re.compile(r"\n{3,}")


def html_to_text(raw: str) -> str:
    cleaned = nh3.clean(raw, tags=set(), clean_content_tags={"script", "style", "head", "title"})
    return html_lib.unescape(cleaned)


def tidy(text: str) -> str:
    text = "\n".join(_WS.sub(" ", line).strip() for line in text.replace("\r\n", "\n").split("\n"))
    return _NL.sub("\n\n", text).strip()


def decode(data: bytes, charset: str) -> str:
    try:
        return data.decode(charset or "utf-8", errors="replace")
    except LookupError:
        return data.decode("utf-8", errors="replace")


def step(w, cap_bytes: int) -> int:
    """One command's worth for one mailbox (called in a thread). Answers how many messages it indexed."""
    sync = w.sync
    due = sync.call(sync.store.text_due(w.mailbox_id, PER_COMMAND))
    if not due:
        return 0
    by_folder: dict[tuple[int, str, int], list] = {}
    for r in due:
        by_folder.setdefault((int(r["folder_id"]), r["folder"], int(r["uidvalidity"])), []).append(r)
    done = 0
    with w.lock:
        c = w.pool.acquire()
        broken = True
        try:
            for (_fid, name, v), rows in by_folder.items():
                info = imap.select(c, name)
                if info["uidvalidity"] != v:
                    continue                                   # the watcher resets the folder; these rows go with it
                uid_to_id = {int(r["uid"]): int(r["id"]) for r in rows}
                structures = c.fetch(list(uid_to_id), ["BODYSTRUCTURE"])
                parts: dict[int, tuple[str, str, str]] = {}
                texts: dict[int, str | None] = {}
                for uid, d in structures.items():
                    bs = d.get(b"BODYSTRUCTURE")
                    pick = imap.text_part_of(imap.walk_structure(bs)) if bs is not None else None
                    if pick is None:
                        texts[uid_to_id[uid]] = None
                    else:
                        parts[uid] = pick
                bodies = imap.fetch_body_text(c, {u: p[0] for u, p in parts.items()}, cap_bytes)
                for uid, (_part, subtype, charset) in parts.items():
                    raw = bodies.get(uid)
                    if raw is None:
                        texts[uid_to_id[uid]] = None
                        continue
                    t = decode(raw, charset)
                    texts[uid_to_id[uid]] = tidy(html_to_text(t) if subtype == "html" else t)
                sync.call(sync.store.write_text(texts))
                done += len(texts)
            broken = False
        finally:
            w.pool.give_back(c, broken=broken)
    return done


class TextPass:
    """The scheduler: every second, up to four mailboxes with text due, each at most `per_minute` commands in any sixty seconds."""

    def __init__(self, sync, per_minute: int):
        self.sync, self.per_minute = sync, max(1, per_minute)
        self.running: set[int] = set()
        self.indexed = 0

    async def cap_bytes(self) -> int:
        kb = await self.sync.store.pool.fetchval("SELECT index_text_max_kb FROM ml_settings WHERE id = 1")
        return int(kb or 100) * 1024

    async def tick(self, tasks: set) -> None:
        if len(self.running) >= MAILBOXES_AT_ONCE:
            return
        due = await self.sync.store.text_due_mailboxes()
        cap = None
        now = time.time()
        for mid in due:
            if len(self.running) >= MAILBOXES_AT_ONCE:
                break
            w = self.sync.watchers.get(mid)
            if w is None or mid in self.running or w.mode not in ("notify", "idle"):
                continue
            while w.text_times and now - w.text_times[0] > 60:
                w.text_times.popleft()
            if len(w.text_times) >= self.per_minute:
                continue
            w.text_times.append(now)
            if cap is None:
                cap = await self.cap_bytes()
            self.running.add(mid)
            t = asyncio.create_task(self._run(w, cap))
            tasks.add(t)
            t.add_done_callback(tasks.discard)

    async def _run(self, w, cap: int) -> None:
        try:
            self.indexed += await asyncio.to_thread(step, w, cap)
        except Exception as e:  # noqa: BLE001 — the next tick tries again; the watcher handles a dropped connection
            log.info("text pass, mailbox %s: %s", w.mailbox_id, type(e).__name__)
        finally:
            self.running.discard(w.mailbox_id)
