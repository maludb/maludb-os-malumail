"""Drafts in the mailbox's Drafts folder (docs/build-specs/mail-service.md §6; writing.md decision 2): the database row is the
immediate save; this loop APPENDs the draft's MIME to Drafts (\\Draft \\Seen, Message-ID = draft_key) within seconds of a save
(pg_notify ml_drafts, and a sweep every pass), records the new (folder, uidvalidity, uid), and expunges the previous copy — the
Drafts folder holds one copy, so a phone sees the latest. The delete half (`discard`) expunges the current copy alone: used when the
draft is sent or discarded. A failed APPEND leaves the row and the previous copy untouched (the composer keeps its text)."""

from __future__ import annotations

import asyncio
import logging

from services.mailer import imap, mime
from services.mailer.watch import Busy, Stopped

log = logging.getLogger("malumail.mailer.drafts")


class Drafts:
    def __init__(self, cfg, store, sync):
        self.cfg, self.store, self.sync = cfg, store, sync
        self.wake = asyncio.Event()
        self.running: set[int] = set()

    def kick(self) -> None:
        self.wake.set()

    async def loop(self) -> None:
        while True:
            try:
                await asyncio.wait_for(self.wake.wait(), timeout=max(2, self.cfg.pass_seconds))
                await asyncio.sleep(2.2)                        # kicked by a save: wait out the two seconds a draft rests before it is filed
            except asyncio.TimeoutError:
                pass
            self.wake.clear()
            try:
                await self.sweep()
            except Exception as e:  # noqa: BLE001
                log.warning("drafts sweep failed: %s", type(e).__name__)

    async def sweep(self) -> None:
        for did in await self.store.drafts_to_discard():
            if did not in self.running:
                await self.discard(did)
        for did in await self.store.drafts_to_file():
            if did not in self.running:
                await self.file(did)

    def _watcher(self, mailbox_id: int):
        w = self.sync.watchers.get(mailbox_id)
        if w is None or not w.is_alive() or w.stopping.is_set():
            return None
        return w

    async def file(self, draft_id: int) -> dict | None:
        """APPEND the draft's current version, expunge the previous; answers {folder_id, uidvalidity, uid} or None when it could not."""
        d = await self.store.draft(draft_id)
        if d is None or d["discarded_at"] is not None or d["outbound_id"] is not None:
            return None
        w = self._watcher(int(d["mailbox_id"]))
        folder = await self.store.folder_named(int(d["mailbox_id"]), "drafts")
        if w is None or folder is None:
            return None
        row = dict(d)
        identity = {"address": d["identity_address"], "display_name": d["identity_display"], "reply_to": d["identity_reply_to"]} if d["identity_address"] else None
        atts = await self.store.attachments_of("mail_draft", draft_id)
        store_dir = self.cfg.storage_dir

        def read_att(a):
            from pathlib import Path
            p = Path(a["storage_path"])
            if not p.is_absolute():
                p = Path(store_dir) / p
            return p.read_bytes()

        msg = mime.build(row, atts, identity, d["mailbox_address"], d["mailbox_display"], None, None, None, read_att, for_draft=True)
        raw = mime.sent_copy_bytes(msg, list(d["bcc_addresses"] or []))    # a draft keeps its Bcc
        prev = (int(d["uidvalidity"]), int(d["uid"])) if d["uid"] is not None else None
        self.running.add(draft_id)
        try:
            def act(c):
                with w.lock:
                    info = imap.select(c, folder["name"], readonly=False)
                    got = imap.append(c, folder["name"], raw, ["\\Draft", "\\Seen"])
                    if got is None:
                        # no APPENDUID: find it by Message-ID
                        uids = imap.search_header(c, "Message-ID", d["draft_key"])
                        got = (info["uidvalidity"], max(uids)) if uids else None
                    if got is None:
                        return None
                    if prev is not None and prev[0] == got[0] and prev[1] != got[1]:
                        try:
                            imap.expunge_uids(c, [prev[1]])
                        except Exception:  # noqa: BLE001 — the old copy may already be gone
                            pass
                    elif prev is None:
                        # earlier copies by the same Message-ID (a save that lost its row state): keep only the newest
                        for u in imap.search_header(c, "Message-ID", d["draft_key"]):
                            if u != got[1]:
                                try:
                                    imap.expunge_uids(c, [u])
                                except Exception:  # noqa: BLE001
                                    pass
                    return got
            got = await self._on_pool(w, act)
        except (Busy, Stopped):
            return None
        except Exception as e:  # noqa: BLE001
            log.info("draft %s not filed: %s", draft_id, type(e).__name__)
            return None
        finally:
            self.running.discard(draft_id)
        if got is None:
            return None
        await self.store.draft_filed(draft_id, int(folder["id"]), int(got[0]), int(got[1]))
        return {"folder_id": int(folder["id"]), "uidvalidity": int(got[0]), "uid": int(got[1])}

    async def discard(self, draft_id: int) -> bool:
        d = await self.store.draft(draft_id)
        if d is None:
            return False
        if d["uid"] is None:
            await self.store.draft_unfiled(draft_id, discarded=d["discarded_at"] is not None)
            return True
        w = self._watcher(int(d["mailbox_id"]))
        folder = await self.store.folder_named(int(d["mailbox_id"]), "drafts")
        if w is None or folder is None:
            return False
        uid, v = int(d["uid"]), int(d["uidvalidity"])

        def act(c):
            with w.lock:
                info = imap.select(c, folder["name"], readonly=False)
                if info["uidvalidity"] == v:
                    imap.expunge_uids(c, [uid])
                else:
                    for u in imap.search_header(c, "Message-ID", d["draft_key"]):
                        imap.expunge_uids(c, [u])
                return True
        try:
            await self._on_pool(w, act)
        except (Busy, Stopped):
            return False
        except Exception as e:  # noqa: BLE001
            log.info("draft %s not expunged: %s", draft_id, type(e).__name__)
            return False
        await self.store.draft_unfiled(draft_id, discarded=d["discarded_at"] is not None)
        return True

    async def _on_pool(self, w, fn):
        def run():
            c = w.pool.acquire(10)
            broken = True
            try:
                out = fn(c)
                broken = False
                return out
            finally:
                w.pool.give_back(c, broken=broken)
        return await asyncio.to_thread(run)
