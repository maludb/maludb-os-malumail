"""Staying in step (docs/build-specs/mail-service.md §2, §3.1, §3.3): one watcher THREAD per live mailbox and a small pool of
connections per mailbox. IMAPClient blocks, so IMAP work runs in threads; every database write goes back to the service's event loop
(Sync.call), so asyncpg is only ever used from its own loop.

A watcher:
  1. logs in through Sync.login() — the ONE function that opens a credential: auth_failed trips the breaker and the watcher ends (the
     mailbox is no longer live, so the sync pass never starts it again); busy / connect / TLS failures back off 30 s → 30 min;
  2. brings the mailbox up: the FIRST SYNC (§3.1 — folders, Archive and Snoozed made when missing, the quota, the envelope pass INBOX →
     Sent → Drafts → the rest in batches of 500 newest first, ml_threads_rebuild() after INBOX and at the end) or, for a mailbox synced
     before, a catch-up of every folder;
  3. holds the long-lived connection: INBOX selected, NOTIFY SET (selected …)(personal …) — else IDLE alone, re-issued every 25 min —
     and the STATUS round every 60 s EVEN WITH NOTIFY (the safety net: a first NOTIFY on a new mailbox took 28 s, research 04 §B).
     Any event, or a folder whose UIDNEXT / HIGHESTMODSEQ / UIDVALIDITY / count moved, is brought up to date on a POOL connection:
     SELECT … (QRESYNC (uidvalidity modseq)) → VANISHED rows deleted, changed flags applied, new UIDs fetched; a new UIDVALIDITY →
     ml_folder_reset() and the folder re-read (its messages' keys, and everything the application keyed on them, stay).

Every fetch is a PEEK: nothing here ever sets \\Seen. A per-mailbox lock orders the pool's folder work (later: mutations).
"""

from __future__ import annotations

import asyncio
import collections
import logging
import threading
import time

from services.mailer import imap, index, seal

log = logging.getLogger("malumail.mailer.watch")

BATCH = 500
STATUS_EVERY = 60.0
IDLE_RENEW = 25 * 60.0
QUOTA_EVERY = 15 * 60.0
POOL_PER_MAILBOX = 3
POOL_IDLE_CLOSE = 5 * 60.0
FOLDER_ORDER = {"inbox": 0, "sent": 1, "drafts": 2}


class Busy(Exception):
    """Past ML_MAILER_MAX_CONNECTIONS for 10 s."""


class Stopped(Exception):
    pass


class Sync:
    """Every watcher of the service; the connection cap; the bridge from threads to the loop."""

    def __init__(self, cfg, store, backoff, loop: asyncio.AbstractEventLoop):
        self.cfg, self.store, self.backoff, self.loop = cfg, store, backoff, loop
        self.watchers: dict[int, Watcher] = {}
        self.slots = threading.BoundedSemaphore(cfg.max_connections)
        self.open_connections = 0
        self._count_lock = threading.Lock()
        self.logins = 0

    # ---- the bridge -------------------------------------------------------------------------------------------------------------
    def call(self, coro, timeout: float = 300.0):
        return asyncio.run_coroutine_threadsafe(coro, self.loop).result(timeout)

    # ---- connections ------------------------------------------------------------------------------------------------------------
    def login(self, w: "Watcher", wait: float = 10.0):
        """THE login (§2): a connection slot, the credential checked ok in the database at this moment, opened, logged in."""
        if not self.slots.acquire(timeout=wait):
            raise Busy()
        try:
            row = self.call(self.store.credential_row(w.mailbox_id))
            if row is None or row["credential_id"] is None or row["status"] != "ok" or row["credential_id"] != w.credential_id:
                raise Stopped()                       # never a login with a credential that is not ok (the breaker's rule)
            if self.cfg.seal_secret is None:
                raise Stopped()
            now = time.time()
            if self.backoff.wait(w.mailbox_id, now):
                raise imap.IMAPClientError("backing off")
            password = seal.open_sealed(self.cfg.seal_secret, bytes(row["ciphertext"]))
            with self._count_lock:
                self.logins += 1
            client, outcome = imap.open_session(self.cfg.imap_host, self.cfg.imap_port, row["address"], password, self.cfg.tls_ca_file)
            del password
            if client is not None:
                self.backoff.clear(w.mailbox_id)
                with self._count_lock:
                    self.open_connections += 1
                return client
            if outcome.kind == "auth_failed":
                told = self.call(self.store.trip_breaker(w.mailbox_id, row["credential_id"], row["address"], outcome.detail, None))
                log.warning("circuit breaker: mailbox %s's credential failed; %d Mail admin(s) told", w.mailbox_id, told)
                w.stop_all()
                raise Stopped()
            delay = self.backoff.fail(w.mailbox_id, now)
            self.call(self.store.note_error(w.mailbox_id, outcome.detail))
            log.info("mailbox %s: %s; backing off %d s", w.mailbox_id, outcome.kind, delay)
            raise imap.IMAPClientError(outcome.kind)
        except BaseException:
            self.slots.release()
            raise

    def release(self, client) -> None:
        imap.close(client)
        with self._count_lock:
            self.open_connections -= 1
        self.slots.release()

    # ---- the set of watchers ------------------------------------------------------------------------------------------------------
    async def reconcile(self, stagger: float = 0.3) -> int:
        """Start a watcher for each newly live mailbox (a few a second: MaluMail's abuse protection counts logins), stop one whose
        mailbox left that state or whose credential changed."""
        live = {int(r["id"]): r for r in await self.store.live_mailboxes()}
        for mid, w in list(self.watchers.items()):
            if mid not in live or live[mid]["credential_id"] != w.credential_id or not w.is_alive():
                w.stop_all()
                self.watchers.pop(mid, None)
        for mid, r in live.items():
            if mid in self.watchers:
                continue
            w = Watcher(self, mid, r["address"], r["kind"], r["credential_id"])
            self.watchers[mid] = w
            w.start()
            await asyncio.sleep(stagger)
        return len(live)

    def stop(self) -> None:
        for w in self.watchers.values():
            w.stop_all()
        for w in self.watchers.values():
            w.join(timeout=5)

    def request(self, mailbox_id: int, folder: int | None, full: bool, text: bool) -> bool:
        w = self.watchers.get(mailbox_id)
        if w is None:
            return False
        w.requests.append({"folder": folder, "full": full, "text": text})
        return True

    def health(self) -> dict:
        ws = list(self.watchers.values())
        return {"watchers": sum(1 for w in ws if w.mode in ("notify", "idle")), "pool_connections": sum(w.pool.size() for w in ws),
                "connections": self.open_connections}

    def watcher_state(self, mailbox_id: int) -> str:
        w = self.watchers.get(mailbox_id)
        return w.mode if w is not None and w.mode in ("notify", "idle") else "down"


class Pool:
    """At most three connections of one mailbox for fetches (and later mutations), opened on demand, closed after five idle minutes."""

    def __init__(self, sync: Sync, w: "Watcher"):
        self.sync, self.w = sync, w
        self.free: list[tuple[object, float]] = []
        self.busy = 0
        self.cv = threading.Condition()

    def size(self) -> int:
        with self.cv:
            return len(self.free) + self.busy

    def acquire(self, wait: float = 10.0):
        deadline = time.time() + wait
        with self.cv:
            while True:
                if self.free:
                    client, _ = self.free.pop()
                    self.busy += 1
                    return client
                if self.busy < POOL_PER_MAILBOX:
                    self.busy += 1
                    break
                left = deadline - time.time()
                if left <= 0:
                    raise Busy()
                self.cv.wait(left)
        try:
            return self.sync.login(self.w, wait=max(0.1, deadline - time.time()))
        except BaseException:
            with self.cv:
                self.busy -= 1
                self.cv.notify()
            raise

    def give_back(self, client, broken: bool = False) -> None:
        with self.cv:
            self.busy -= 1
            if not broken:
                self.free.append((client, time.time()))
            self.cv.notify()
        if broken:
            self.sync.release(client)

    def close_idle(self, everything: bool = False) -> None:
        now = time.time()
        with self.cv:
            keep, drop = [], []
            for client, since in self.free:
                (drop if everything or now - since > POOL_IDLE_CLOSE else keep).append((client, since))
            self.free = keep
        for client, _ in drop:
            self.sync.release(client)


class Watcher(threading.Thread):
    def __init__(self, sync: Sync, mailbox_id: int, address: str, kind: str, credential_id: int):
        super().__init__(name=f"watch-{mailbox_id}", daemon=True)
        self.sync, self.mailbox_id, self.address, self.kind, self.credential_id = sync, mailbox_id, address, kind, credential_id
        self.mode = "down"                               # starting · notify · idle · down
        self.stopping = threading.Event()
        self.lock = threading.RLock()                    # the per-mailbox lock: folder work, later mutations
        self.pool = Pool(sync, self)
        self.requests: collections.deque = collections.deque()
        self.conn = None
        self.text_times: collections.deque = collections.deque()

    # ---- life --------------------------------------------------------------------------------------------------------------------
    def stop_all(self) -> None:
        self.stopping.set()

    def run(self) -> None:
        while not self.stopping.is_set():
            try:
                self.serve()
            except Stopped:
                break
            except Busy:
                self._sleep(10)
            except Exception as e:  # noqa: BLE001 — a dropped connection, a server's NO: back off and come back
                log.info("mailbox %s watcher: %s", self.mailbox_id, type(e).__name__)
                self.mode = "down"
                self._drop_conn()
                self.pool.close_idle(everything=True)            # the pool's connections may have dropped with it
                wait = self.sync.backoff.wait(self.mailbox_id, time.time())
                self._sleep(wait or 2)
        self.mode = "down"
        self._drop_conn()
        self.pool.close_idle(everything=True)

    def _sleep(self, seconds: float) -> None:
        self.stopping.wait(seconds)

    def _drop_conn(self) -> None:
        if self.conn is not None:
            c, self.conn = self.conn, None
            self.sync.release(c)

    # ---- the long-lived connection ------------------------------------------------------------------------------------------------
    def serve(self) -> None:
        self.mode = "starting"
        self.bring_up()
        self.conn = self.sync.login(self)
        imap.select(self.conn, "INBOX")
        self.mode = "notify" if imap.notify_set(self.conn) else "idle"
        last_status = last_quota = time.time()
        while not self.stopping.is_set():
            self.conn.idle()
            idle_since = time.time()
            events: list = []
            quick = 0
            while not self.stopping.is_set():
                t_check = time.time()
                got = self.conn.idle_check(timeout=1)
                if got:
                    events.extend(got)
                    break
                # IMAPClient's idle_check swallows an EOF: the socket polls readable and nothing comes. Twice in a row at once means
                # the server closed the connection — reconnect now, not at the next STATUS round.
                quick = quick + 1 if time.time() - t_check < 0.5 else 0
                if quick >= 2:
                    raise ConnectionError("the server closed the watcher's connection")
                now = time.time()
                if self.requests or now - last_status >= STATUS_EVERY or now - idle_since >= IDLE_RENEW:
                    break
            events.extend(self.conn.idle_done()[1] or [])
            if self.stopping.is_set():
                break
            if events:
                self.on_events(events)
            while self.requests:
                self.handle(self.requests.popleft())
            if time.time() - last_status >= STATUS_EVERY:
                self.status_round()
                last_status = time.time()
            if time.time() - last_quota >= QUOTA_EVERY:            # §8: every 15 minutes of a live mailbox
                used, limit = imap.quota(self.conn)
                self.sync.call(self.sync.store.save_quota(self.mailbox_id, used, limit))
                last_quota = time.time()
            self.pool.close_idle()

    def on_events(self, events: list) -> None:
        """NOTIFY's and IDLE's untagged responses: the selected INBOX's (EXISTS, FETCH, VANISHED/EXPUNGE) catch INBOX up; a STATUS
        event names another folder, so the STATUS round finds which moved."""
        other = any(isinstance(e, tuple) and e and isinstance(e[0], bytes) and e[0].upper() == b"STATUS" for e in events) \
            or any(b"STATUS" in (e if isinstance(e, bytes) else b"") for e in events)
        self.catch_up_name("INBOX")
        if other:
            self.status_round()

    def status_round(self) -> None:
        """LIST "" "*" RETURN (STATUS (…)) — one command — compared with the index: a folder that moved is caught up; a new folder
        is recorded and read; a folder the server no longer has goes."""
        listed = imap.list_status(self.conn)
        known = {r["name"]: r for r in self.sync.call(self.sync.store.folders(self.mailbox_id))}
        if set(f["name"] for f in listed) != set(known):
            self.record_folders(listed)
            known = {r["name"]: r for r in self.sync.call(self.sync.store.folders(self.mailbox_id))}
        for f in listed:
            row = known.get(f["name"])
            if row is None or "\\Noselect" in f["flags"] or "\\NonExistent" in f["flags"]:
                continue
            if (row["uidvalidity"] != f.get("uidvalidity") or row["uidnext"] != f.get("uidnext")
                    or (f.get("highestmodseq") is not None and row["highestmodseq"] != f.get("highestmodseq"))
                    or row["messages_total"] != f.get("messages")):
                self.catch_up(row)
        self.sync.call(self.sync.store.synced(self.mailbox_id))      # the round confirmed every folder: the worker's 10-minute check

    def handle(self, req: dict) -> None:
        if req.get("text"):
            self.sync.call(self.sync.store.pool.execute(
                "UPDATE mail_messages SET text_indexed_at = NULL WHERE mailbox_id = $1", self.mailbox_id))
        folders = self.sync.call(self.sync.store.folders(self.mailbox_id))
        for row in folders:
            if req.get("folder") and int(req["folder"]) != int(row["id"]):
                continue
            self.catch_up(row, full=bool(req.get("full")))
        if req.get("full") and not req.get("folder"):
            self.sync.call(self.sync.store.rebuild(self.mailbox_id))

    # ---- the first sync and the catch-up ----------------------------------------------------------------------------------------
    def record_folders(self, listed: list[dict]) -> None:
        rows = [{"name": f["name"], "delimiter": f.get("delimiter"), "special_use": index.special_use(f["name"], f["flags"]),
                 "messages": f.get("messages")}
                for f in listed if "\\Noselect" not in f["flags"] and "\\NonExistent" not in f["flags"]]
        self.sync.call(self.sync.store.upsert_folders(self.mailbox_id, rows))

    def bring_up(self) -> None:
        store = self.sync.store
        with self.lock:
            c = self.pool.acquire()
            broken = True
            try:
                folders = self.sync.call(store.folders(self.mailbox_id))
                first = not folders or any(f["uidvalidity"] is None for f in folders)
                if first:
                    self.sync.call(store.set_sync_state(self.mailbox_id, "syncing"))
                listed = imap.list_status(c)
                names = {f["name"] for f in listed}
                made = False
                for name in ("Archive", "Snoozed"):             # no special-use flag exists for them on MaluMail's server (§3.1)
                    if name not in names:
                        made |= imap.create_folder(c, name)
                if made:
                    listed = imap.list_status(c)
                self.record_folders(listed)
                used, limit = imap.quota(c)
                self.sync.call(store.save_quota(self.mailbox_id, used, limit))
                folders = sorted(self.sync.call(store.folders(self.mailbox_id)),
                                 key=lambda r: (FOLDER_ORDER.get(r["special_use"] or "", 3), r["id"]))
                for row in folders:
                    self._catch_up_on(c, row, bulk=first)
                    if first and row["special_use"] == "inbox":
                        self.sync.call(store.rebuild(self.mailbox_id))      # the Inbox usable within a minute (§3.1 step 4)
                if first:
                    self.sync.call(store.rebuild(self.mailbox_id))
                self.sync.call(store.set_sync_state(self.mailbox_id, "live", synced=True))
                broken = False
            finally:
                self.pool.give_back(c, broken=broken)

    def catch_up_name(self, name: str) -> None:
        for row in self.sync.call(self.sync.store.folders(self.mailbox_id)):
            if row["name"] == name:
                self.catch_up(row)
                return

    def catch_up(self, row, full: bool = False) -> None:
        with self.lock:
            c = self.pool.acquire()
            broken = True
            try:
                fresh = [r for r in self.sync.call(self.sync.store.folders(self.mailbox_id)) if r["id"] == row["id"]]
                if fresh:
                    self._catch_up_on(c, fresh[0], full=full)
                broken = False
            finally:
                self.pool.give_back(c, broken=broken)

    def _catch_up_on(self, c, row, bulk: bool = False, full: bool = False) -> None:
        """One folder brought up to date (§3.3). bulk: the first sync's batches (no per-row threading; the caller rebuilds)."""
        store = self.sync.store
        fid, name = int(row["id"]), row["name"]
        known_v, known_m = row["uidvalidity"], row["highestmodseq"]
        info = imap.select(c, name, known_v, 1 if (full and known_v) else known_m)
        v = info["uidvalidity"]
        if known_v is not None and v != known_v:
            n = self.sync.call(store.folder_reset(fid, v))
            log.info("mailbox %s folder %s: UIDVALIDITY %s -> %s, %d rows cleared, re-reading", self.mailbox_id, name, known_v, v, n)
            known_v, bulk_here = None, True
        else:
            bulk_here = bulk
        if known_v is None or known_m is None:            # first read, a reset, or a server without CONDSTORE: by the UID list
            uids = imap.all_uids(c)
            have = self.sync.call(store.known_uids(fid, v))
            new = [u for u in uids if u not in have]
            gone = sorted(have - set(uids))
            if len(new) > BATCH:
                bulk_here = True
        else:
            gone = sorted(info["vanished"])
            have = self.sync.call(store.known_uids(fid, v))
            changed = {u: index.flags_of(d["flags"]) | {"modseq": d["modseq"]} for u, d in info["changed"].items() if u in have}
            self.sync.call(store.update_flags(fid, v, changed))
            start = int(row["uidnext"] or 1)
            above = [int(u) for u in c.search(["UID", f"{start}:*"])] if info["exists"] else []
            new = sorted({u for u in above if u >= start and u not in have} | {u for u in info["changed"] if u not in have},
                         reverse=True)
            if len(new) > BATCH:
                bulk_here = True
        if gone:
            self.sync.call(store.vanish(fid, v, gone))
        written: list[int] = []
        inbox_live = row["special_use"] == "inbox" and not bulk_here and self.kind == "agent"
        correspondents = row["special_use"] not in ("sent", "drafts")
        for i in range(0, len(new), BATCH):
            chunk = new[i:i + BATCH]
            fetched = imap.fetch_envelopes(c, chunk)
            rows = [index.parse(u, d, self.sync.cfg.imap_host) for u, d in fetched.items()]
            rows.sort(key=lambda r: r["uid"])
            for r in rows:
                r.pop("text_part", None)
            written += self.sync.call(store.write_messages(self.mailbox_id, fid, v, rows, bulk_here, correspondents))
        if bulk_here and not bulk and new:
            self.sync.call(store.rebuild(self.mailbox_id))
        if inbox_live and written:
            self.sync.call(store.dispatch_decide(written))
        unseen = len(c.search(["UNSEEN"])) if info["exists"] else 0
        self.sync.call(store.folder_state(fid, v, info["uidnext"], info["highestmodseq"], info["exists"], unseen))
        self.sync.call(store.synced(self.mailbox_id))
