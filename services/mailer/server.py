"""MaluMail's mail service (design §6.1) — Phase 0's skeleton. The only process that holds an open mailbox credential, the sealing
secret key and MaluMail's customer key. It listens on loopback, 127.0.0.1:ML_MAILER_PORT, behind the signed door (door.py).

  GET  /health                          up, the database, credential and outbox counts, the passes' last runs — counts only, unsigned
  POST /v1/admin/{tool}                 one Mailbox MCP administration call for the acting member (mailbox_mcp.py's policy);
                                        a reset answers what the mailbox's kind allows (admin_flows.after_reset)
  POST /v1/admin/mailboxes/create      slice 1: MaluMail's create_mailbox + the rows in one transaction (admin_flows.create)
  POST /v1/admin/mailboxes/connect     slice 1: an existing mailbox registered, reset if agent/shared, probed (admin_flows.connect)
  POST /v1/mailboxes/{id}/probe         log in to the mailbox with its sealed credential: capabilities, folders, INBOX's counts;
                                        an authentication failure trips the circuit breaker (store.trip_breaker)
  POST /v1/mailboxes/{id}/sync          slice 2: queue a re-read for the mailbox's watcher (folder, full, text) — settings.manage or full
  POST /v1/mailboxes/{id}/status        slice 2: where the mailbox's sync stands (mail-service.md §9) — view on it, or settings.manage
  POST /v1/mailboxes/{id}/quota         slice 2: GETQUOTAROOT read now (the stored figures when the mailbox has no watcher) — view
  POST /v1/mailboxes/{id}/fetch         slice 3: one message — the header summary, the part list with decoded sizes, and by `form`:
                                        html (the sanitiser's fields, the part and image links minted here), text (the agent's extracted
                                        text), plain (a message with no HTML part), headers (the raw header list too) — view, strictly
  POST /v1/mailboxes/{id}/part          slice 3: one part's decoded bytes, streamed in 1 MB partials — view
  POST /v1/mailboxes/{id}/raw           slice 3: the whole message, streamed — view
  POST /v1/mailboxes/{id}/mutate        slice 3: one op on a set of messages — seen, unseen, flag, unflag, move, archive, trash, spam,
                                        not_spam, delete_forever, trash_empty — ml_mutation_refusal() per folder, one IMAP command per
                                        folder, the index updated in the same request (mail-service.md §5)

Every POST body is JSON: {"actor": <member id>, "on_behalf": <member id or null>, "args": {...}} — on_behalf is the person behind an
agent's run (slice 2); the service computes levels itself, ml_level_as(mailbox, actor, on_behalf), never from a caller's word. Answers: {"ok": true, "result": …} or {"ok": false, "error":
{"code", "message"}} with 401 (not a signed call), 403 (the member may not), 409 (the credential is failed: a person stores a new
one), 422 (MaluMail or the policy said no, in a sentence), 429 (backing off: `retry_after`), 502 (MaluMail or the mail server
unreachable or refusing the key), 503 (not configured).

Passes every ML_MAILER_PASS_SECONDS: the SYNC pass (slice 2) starts a watcher thread for each newly live mailbox and stops one whose
mailbox left that state (watch.py); the text pass runs every second at its gentle rate (textpass.py); the outbox pass counts the due
rows it will send (slice 4).

Run: mcp/venv/bin/python -I services/mailer/server.py (deploy/malumail-mailer.service).
"""

from __future__ import annotations

import asyncio
import contextlib
import ipaddress
import json
import logging
import re
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import httpx  # noqa: E402
import uvicorn  # noqa: E402
from starlette.applications import Starlette  # noqa: E402
from starlette.requests import Request  # noqa: E402
from starlette.responses import JSONResponse, StreamingResponse  # noqa: E402
from starlette.routing import Route  # noqa: E402

from services.mailer import door, imap, mailbox_mcp, mime_read, seal  # noqa: E402
from services.mailer.admin_flows import Flows  # noqa: E402
from services.mailer.config import Config, ConfigError  # noqa: E402
from services.mailer.store import Store  # noqa: E402
from services.mailer.textpass import TextPass  # noqa: E402
from services.mailer.watch import Busy, Stopped, Sync  # noqa: E402

VERSION = "0.3.0-slice3"
log = logging.getLogger("malumail.mailer")
BACKOFF_FIRST, BACKOFF_MAX = 30, 1800


class Backoff:
    """Per mailbox, connection trouble waits 30 s, then twice as long each time, up to 30 min (§6.1). In memory: a restart starts
    over, which costs one more attempt — never a login with a refused credential, which the database's breaker stops."""

    def __init__(self):
        self.state: dict[int, tuple[float, int]] = {}

    def wait(self, mailbox_id: int, now: float) -> int:
        until, _ = self.state.get(mailbox_id, (0.0, 0))
        return max(0, int(until - now + 0.999))

    def fail(self, mailbox_id: int, now: float) -> int:
        _, n = self.state.get(mailbox_id, (0.0, 0))
        delay = min(BACKOFF_MAX, BACKOFF_FIRST * (2 ** n))
        self.state[mailbox_id] = (now + delay, n + 1)
        return delay

    def clear(self, mailbox_id: int) -> None:
        self.state.pop(mailbox_id, None)


def refuse(status: int, code: str, message: str, **extra) -> JSONResponse:
    return JSONResponse({"ok": False, "error": {"code": code, "message": message, **extra}}, status_code=status)


def build_app(cfg: Config) -> Starlette:
    nonces = door.Nonces()
    backoff = Backoff()
    passes = {"sync": {"last_run_at": None, "mailboxes": None}, "outbox": {"last_run_at": None, "due": None}}
    state: dict = {}
    public_key = seal.public_of(cfg.seal_secret) if cfg.seal_secret else None

    async def signed_full(request: Request):
        """(actor, on_behalf, args) for a good call, or the refusal to answer."""
        host = request.client.host if request.client else ""
        try:
            loopback = ipaddress.ip_address(host).is_loopback
        except ValueError:
            loopback = False
        if not loopback:
            return refuse(403, "not_loopback", "The mail service answers this machine only.")
        body = await request.body()
        why = door.verify(cfg.mailer_key, nonces, request.headers.get(door.HEADER, ""), request.method, request.url.path, body)
        if why is not None:
            log.warning("refused a call to %s: %s", request.url.path, why)
            return refuse(401, "unsigned", "Not a signed call.")
        try:
            data = json.loads(body or b"{}")
            actor = int(data.get("actor"))
            on_behalf = data.get("on_behalf")
            on_behalf = None if on_behalf in (None, "", 0) else int(on_behalf)
        except (ValueError, TypeError):
            return refuse(422, "bad_request", "A call names its acting member: {\"actor\": <member id>, \"args\": {…}}.")
        if not await state["store"].active_member(actor):
            return refuse(403, "unknown_member", "That member is not an active member here.")
        if on_behalf is not None and not await state["store"].active_member(on_behalf):
            on_behalf = None                                   # ml_level_as() admits only an active person anyway
        args = data.get("args") or {}
        if not isinstance(args, dict):
            return refuse(422, "bad_request", "args is an object.")
        return actor, on_behalf, args

    async def signed(request: Request):
        """(actor, args) for a good call, or the refusal to answer."""
        got = await signed_full(request)
        if isinstance(got, JSONResponse):
            return got
        return got[0], got[2]

    async def health(request: Request):
        store: Store = state["store"]
        db_ok = await store.ping()
        counts = await store.health_counts() if db_ok else {}
        sync = state.get("sync")
        sc = await store.sync_counts() if db_ok else {}
        sh = sync.health() if sync is not None else {"watchers": 0, "pool_connections": 0}
        return JSONResponse({"status": "up" if db_ok else "degraded", "version": VERSION, "database": db_ok,
                             "sealing_key": cfg.seal_secret is not None, "customer_key": cfg.customer_key is not None,
                             "credentials_failed": counts.get("failed"), "passes": passes,
                             "watchers": sh["watchers"], "pool_connections": sh["pool_connections"], "text_due": sc.get("text_due"),
                             "mailboxes_live": passes["sync"]["mailboxes"], "mailboxes_failing": sc.get("failing")})

    async def admin(request: Request):
        got = await signed(request)
        if isinstance(got, JSONResponse):
            return got
        actor, args = got
        tool = request.path_params["tool"]
        store: Store = state["store"]
        target = None
        if tool in mailbox_mcp.TOOLS:
            target = mailbox_mcp.domain_of(mailbox_mcp.TOOLS[tool], args)
        try:
            result = await mailbox_mcp.admin_call(state["http"], cfg.mcp_url, cfg.customer_key, public_key, tool, args,
                                                  may=lambda right: store.member_may(actor, right), served=store.domain_served)
            if tool == "reset_mailbox_password":           # what a reset reveals: the mailbox's kind decides (slice 1, admin_flows.py)
                result = await state["flows"].after_reset(actor, str(args.get("address", "")).strip().lower(), result)
        except mailbox_mcp.McpError as e:
            if e.kind not in ("forbidden", "policy"):
                await store.log_admin_call(actor, tool, target, False, str(e)[:300])
            log.info("admin %s for member %s refused: %s", tool, actor, e.kind)
            status = {"forbidden": 403, "policy": 422, "refused": 422, "unconfigured": 503}.get(e.kind, 502)
            return refuse(status, e.kind, str(e))
        await store.log_admin_call(actor, tool, target, True, None)
        log.info("admin %s for member %s", tool, actor)
        return JSONResponse({"ok": True, "result": result})

    async def flow(request: Request, name: str):
        got = await signed(request)
        if isinstance(got, JSONResponse):
            return got
        actor, args = got
        store: Store = state["store"]
        target = str(args.get("domain") or args.get("address") or "")[:200]
        try:
            result = await getattr(state["flows"], name)(actor, args)
        except mailbox_mcp.McpError as e:
            if e.kind not in ("forbidden", "policy"):
                await store.log_admin_call(actor, f"mailboxes_{name}", target, False, str(e)[:300])
            log.info("admin mailboxes/%s for member %s refused: %s", name, actor, e.kind)
            status = {"forbidden": 403, "policy": 422, "refused": 422, "unconfigured": 503, "created_at_malumail_only": 409}.get(e.kind, 502)
            return refuse(status, e.kind, str(e))
        await store.log_admin_call(actor, f"mailboxes_{name}", target, True, None)
        log.info("admin mailboxes/%s for member %s", name, actor)
        return JSONResponse({"ok": True, "result": result})

    async def create(request: Request):
        return await flow(request, "create")

    async def connect(request: Request):
        return await flow(request, "connect")

    async def probe(request: Request):
        got = await signed(request)
        if isinstance(got, JSONResponse):
            return got
        actor, _args = got
        store: Store = state["store"]
        if not await store.member_may(actor, "mailboxes.manage"):
            return refuse(403, "forbidden", "Testing a mailbox's connection is a Mail admin's.")
        mailbox_id = int(request.path_params["mailbox"])
        row = await store.mailbox_credential(mailbox_id)
        if row is None:
            return refuse(404, "not_found", "No such mailbox.")
        if row["credential_id"] is None:
            return refuse(409, "no_credential", "The mailbox has no credential yet: a Mail admin connects it.")
        if row["credential_status"] != "ok":
            return refuse(409, "credential_failed", "The mail server refused this mailbox's credential, so it is never tried again. "
                                                    "Store a new one to start again.")
        if cfg.seal_secret is None:
            return refuse(503, "unconfigured", "The mail service holds no sealing key, so it cannot open a credential.")
        now = time.time()
        wait = backoff.wait(mailbox_id, now)
        if wait:
            return refuse(429, "backing_off", f"The mail server could not be reached a moment ago; trying again in {wait} s.", retry_after=wait)
        try:
            password = seal.open_sealed(cfg.seal_secret, bytes(row["ciphertext"]))
        except Exception:  # noqa: BLE001 — a box this key cannot open: never retried with anything else
            return refuse(409, "unopenable", "This credential was sealed under another key: store it again.")
        outcome = await asyncio.to_thread(imap.probe, cfg.imap_host, cfg.imap_port, row["address"], password, cfg.tls_ca_file)
        del password
        if outcome.kind == "ok":
            backoff.clear(mailbox_id)
            await store.note_ok(mailbox_id, row["credential_id"])
            return JSONResponse({"ok": True, "result": {"capabilities": outcome.capabilities, "folders": outcome.folders, "inbox": outcome.inbox}})
        if outcome.kind == "auth_failed":
            told = await store.trip_breaker(mailbox_id, row["credential_id"], row["address"], outcome.detail, actor)
            log.warning("circuit breaker: mailbox %s's credential failed; %d Mail admin(s) told", mailbox_id, told)
            return refuse(409, "credential_failed", "The mail server refused the credential. It is never tried again; the Mail admins "
                                                    "are told. Store a new one to start again.")
        delay = backoff.fail(mailbox_id, now)
        await store.note_error(mailbox_id, outcome.detail)
        log.info("mailbox %s: %s (%s); backing off %d s", mailbox_id, outcome.kind, outcome.detail, delay)
        return refuse(502, outcome.kind, f"The mail server could not be used: {outcome.detail}. Trying again in {delay} s.", retry_after=delay)

    async def mailbox_call(request: Request, need: str, strict: bool = False):
        """(actor, on_behalf, args, mailbox_id, level) for a call on one mailbox the caller reaches; 404 below view (design §3).
        `strict`: the content calls (slice 3) — a Mail admin's role opens no mailbox; only a level at or above view does."""
        got = await signed_full(request)
        if isinstance(got, JSONResponse):
            return got
        actor, on_behalf, args = got
        store: Store = state["store"]
        mailbox_id = int(request.path_params["mailbox"])
        level = await store.level_as(mailbox_id, actor, on_behalf)
        admin = (not strict) and await store.member_may(actor, "settings.manage")
        rank = {"none": 0, "view": 1, "triage": 2, "send": 3, "full": 4}
        if rank.get(level, 0) < 1 and not admin:
            return refuse(404, "not_found", "No such mailbox.")
        if need == "full" and level != "full" and not admin:
            return refuse(403, "forbidden", "Re-reading a mailbox is for someone with full access to it, or a Mail admin.")
        return actor, on_behalf, args, mailbox_id, level

    async def sync_call(request: Request):
        got = await mailbox_call(request, "full")
        if isinstance(got, JSONResponse):
            return got
        _actor, _ob, args, mailbox_id, _level = got
        try:
            folder = int(args["folder"]) if args.get("folder") not in (None, "") else None
        except (TypeError, ValueError):
            return refuse(422, "bad_request", "folder is a folder's id.")
        if not state["sync"].request(mailbox_id, folder, bool(args.get("full")), bool(args.get("text"))):
            return refuse(409, "not_live", "This mailbox is not being kept in step (it has no working credential, or it is paused or "
                                           "suspended), so there is nothing to re-read.")
        return JSONResponse({"ok": True, "result": {"queued": True}})

    async def status_call(request: Request):
        got = await mailbox_call(request, "view")
        if isinstance(got, JSONResponse):
            return got
        mailbox_id = got[3]
        store: Store = state["store"]
        result = await store.status(mailbox_id)
        if not result:
            return refuse(404, "not_found", "No such mailbox.")
        result["watcher"] = state["sync"].watcher_state(mailbox_id)
        result["backoff_seconds"] = backoff.wait(mailbox_id, time.time())
        return JSONResponse({"ok": True, "result": result})

    async def quota_call(request: Request):
        got = await mailbox_call(request, "view")
        if isinstance(got, JSONResponse):
            return got
        mailbox_id = got[3]
        store: Store = state["store"]
        w = state["sync"].watchers.get(mailbox_id)
        read_at = None
        if w is not None and w.mode in ("notify", "idle"):
            def read():
                c = w.pool.acquire()
                broken = True
                try:
                    q = imap.quota(c)
                    broken = False
                    return q
                finally:
                    w.pool.give_back(c, broken=broken)
            try:
                used, limit = await asyncio.to_thread(read)
                await store.save_quota(mailbox_id, used, limit)
                read_at = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
            except (Busy, Stopped, Exception) as e:  # noqa: BLE001 — the stored figures answer
                log.info("quota for mailbox %s not read: %s", mailbox_id, type(e).__name__)
        row = await store.pool.fetchrow("SELECT quota_used_bytes, quota_bytes FROM mailboxes WHERE id = $1", mailbox_id)
        return JSONResponse({"ok": True, "result": {"used_bytes": row["quota_used_bytes"], "limit_bytes": row["quota_bytes"],
                                                    "read_at": read_at}})

    # ---- SLICE 3: reading and mutations (mail-service.md §4–§5) ---------------------------------------------------------------------
    bodies = mime_read.BodyCache()

    class NotLive(Exception):
        pass

    def watcher_of(mailbox_id: int):
        w = state["sync"].watchers.get(mailbox_id)
        if w is None or not w.is_alive() or w.stopping.is_set():
            raise NotLive()
        return w

    def on_pool(w, fn, lock: bool = False, wait: float = 10.0):
        """Run fn(connection) on one of the mailbox's pool connections, in a thread; under the mailbox's lock for a mutation."""
        def run():
            ctx = w.lock if lock else contextlib.nullcontext()
            with ctx:
                c = w.pool.acquire(wait)
                broken = True
                try:
                    out = fn(c)
                    broken = False
                    return out
                finally:
                    w.pool.give_back(c, broken=broken)
        return asyncio.to_thread(run)

    def not_live():
        return refuse(409, "not_live", "This mailbox is not connected right now (no working credential, or it is paused or suspended), so "
                                       "its messages cannot be opened.")

    async def resolve_ref(store: Store, mailbox_id: int, args: dict):
        """The message a call names — `message` (the index row's id) or `ref` folder.uidvalidity.uid — or a refusal."""
        row = None
        if args.get("message") not in (None, ""):
            try:
                row = await store.message_ref(mailbox_id, int(args["message"]))
            except (TypeError, ValueError):
                return refuse(422, "bad_request", "message is a message's id.")
        elif args.get("ref"):
            try:
                f, v, u = str(args["ref"]).split(".")
                row = await store.ref_by_parts(mailbox_id, int(f), int(v), int(u))
            except (TypeError, ValueError):
                return refuse(422, "bad_request", "ref is folder.uidvalidity.uid.")
        else:
            return refuse(422, "bad_request", "A call names a message (its id) or a ref.")
        if row is None:
            return refuse(404, "not_found", "No such message.")
        if row["folder_uidvalidity"] is not None and int(row["uidvalidity"]) != int(row["folder_uidvalidity"]):
            return refuse(409, "moved", "This message moved — reload.")
        return row

    def ref_text(row) -> str:
        return f"{int(row['folder_id'])}.{int(row['uidvalidity'])}.{int(row['uid'])}"

    async def fetch_call(request: Request):
        got = await mailbox_call(request, "view", strict=True)
        if isinstance(got, JSONResponse):
            return got
        actor, on_behalf, args, mailbox_id, _level = got
        store: Store = state["store"]
        form = str(args.get("form") or "html")
        images = "show" if str(args.get("images") or "ask") == "show" else "ask"
        profile = "compose" if str(args.get("profile") or "reading") == "compose" else "reading"
        if form not in ("html", "text", "plain", "headers"):
            return refuse(422, "bad_request", "form is html, text, plain or headers.")
        row = await resolve_ref(store, mailbox_id, args)
        if isinstance(row, JSONResponse):
            return row
        try:
            w = watcher_of(mailbox_id)
        except NotLive:
            return not_live()
        ref = ref_text(row)
        uid, folder = int(row["uid"]), row["folder"]
        key = (mailbox_id, ref, images, profile, actor, form, bool(args.get("include_quoted")))
        cached = bodies.get(key) if form in ("html", "plain") else None
        if cached is not None:
            return JSONResponse({"ok": True, "result": cached})

        def read(c):
            info = imap.select(c, folder)
            if info["uidvalidity"] != int(row["uidvalidity"]):
                return "moved"
            got = mime_read.structure_and_header(c, uid)
            if got is None:
                return "gone"
            summary, parts, raw_header = got
            mime_read.decoded_sizes(c, uid, parts)
            out = {"message": int(row["id"]), "key": int(row["message_key_id"]), "thread_id": row["thread_id"], "ref": ref,
                   "folder_id": int(row["folder_id"]), "summary": summary, "parts": parts}
            if form == "headers":
                out["headers"] = mime_read.raw_headers_list(raw_header)
            elif form == "html":
                out.update(mime_read.html_form(c, uid, parts, cfg.part_key, mailbox_id, ref, actor, images, profile))
                if out.get("html") is None and not out.get("html_too_large"):
                    out.update(mime_read.plain_form(c, uid, parts))
            elif form == "plain":
                out.update(mime_read.plain_form(c, uid, parts))
            else:
                limit = int(args.get("limit") or mime_read.TEXT_MAX)
                out.update(mime_read.text_form(c, uid, parts, bool(args.get("include_quoted")), max(1000, min(limit, mime_read.TEXT_MAX))))
            return out
        try:
            result = await on_pool(w, read)
        except Busy:
            return refuse(503, "busy", "The mail server is busy for this mailbox; try again in a moment.", retry_after=5)
        except Stopped:
            return not_live()
        except Exception as e:  # noqa: BLE001 — a dropped connection mid-read
            log.info("fetch for mailbox %s failed: %s", mailbox_id, type(e).__name__)
            return refuse(502, "failed", "The message could not be read from the mail server; try again.")
        if result in ("moved", "gone"):
            state["sync"].request(mailbox_id, int(row["folder_id"]), False, False)
            return refuse(409, "moved", "This message moved — reload.")
        if form in ("html", "plain"):
            bodies.put(key, result)
        return JSONResponse({"ok": True, "result": result})

    async def stream_call(request: Request, whole: bool):
        got = await mailbox_call(request, "view", strict=True)
        if isinstance(got, JSONResponse):
            return got
        actor, on_behalf, args, mailbox_id, _level = got
        store: Store = state["store"]
        row = await resolve_ref(store, mailbox_id, args)
        if isinstance(row, JSONResponse):
            return row
        part = str(args.get("part") or "").strip()
        if not whole and not re.fullmatch(r"[0-9]+(\.[0-9]+)*", part):
            return refuse(422, "bad_request", "part is a section like 2 or 1.2.")
        try:
            w = watcher_of(mailbox_id)
        except NotLive:
            return not_live()
        uid, folder = int(row["uid"]), row["folder"]

        def head(c):
            info = imap.select(c, folder)
            if info["uidvalidity"] != int(row["uidvalidity"]):
                return "moved"
            got = mime_read.structure_and_header(c, uid)
            if got is None:
                return "gone"
            _summary, parts, _raw = got
            if whole:
                size = int(await_size_raw(c, uid))
                return {"type": "message/rfc822", "size": size, "name": None}
            leaf = next((p for p in parts if p["part"] == part), None)
            if leaf is None:
                return "nopart"
            mime_read.decoded_sizes(c, uid, [leaf])
            return {"type": leaf["type"], "size": int(leaf.get("size") or 0), "name": leaf.get("name"), "charset": leaf.get("charset")}

        def await_size_raw(c, uid):
            res = c.fetch([uid], ["RFC822.SIZE"])
            return (res.get(uid) or {}).get(b"RFC822.SIZE") or 0

        try:
            meta = await on_pool(w, head)
        except Busy:
            return refuse(503, "busy", "The mail server is busy for this mailbox; try again in a moment.", retry_after=5)
        except Stopped:
            return not_live()
        except Exception as e:  # noqa: BLE001
            log.info("part for mailbox %s failed: %s", mailbox_id, type(e).__name__)
            return refuse(502, "failed", "The part could not be read from the mail server; try again.")
        if meta == "nopart":
            return refuse(404, "not_found", "No such part.")
        if meta in ("moved", "gone"):
            state["sync"].request(mailbox_id, int(row["folder_id"]), False, False)
            return refuse(409, "moved", "This message moved — reload.")

        def body():
            # the connection is held for the stream; a failure mid-stream ends it short (the PHP door answers 502 — §1)
            c = w.pool.acquire(10)
            broken = True
            try:
                imap.select(c, folder)
                if whole:
                    for chunk in mime_read.stream_part(c, uid, "", meta["size"], binary=False):
                        yield chunk
                else:
                    for chunk in mime_read.stream_part(c, uid, part, meta["size"] or None, binary=True):
                        yield chunk
                broken = False
            finally:
                w.pool.give_back(c, broken=broken)

        headers = {"X-ML-Size": str(meta["size"]), "Cache-Control": "no-store"}
        if meta.get("name"):
            from urllib.parse import quote
            headers["X-ML-Name"] = quote(str(meta["name"]), safe="")
        if meta.get("charset"):
            headers["X-ML-Charset"] = str(meta["charset"])[:40]
        if whole or meta["size"]:
            headers["Content-Length"] = str(meta["size"])
        return StreamingResponse(body(), media_type=meta["type"], headers=headers)

    async def part_call(request: Request):
        return await stream_call(request, whole=False)

    async def raw_call(request: Request):
        return await stream_call(request, whole=True)

    MUTATE_OPS = ("seen", "unseen", "flag", "unflag", "move", "archive", "trash", "spam", "not_spam", "delete_forever", "trash_empty")
    REFUSAL_OP = {"seen": "seen", "unseen": "unseen", "flag": "flag", "unflag": "unflag", "move": "move", "archive": "archive",
                  "trash": "trash", "spam": "spam", "not_spam": "move", "delete_forever": "delete_forever", "trash_empty": "delete_forever"}

    async def mutate_call(request: Request):
        got = await mailbox_call(request, "view", strict=True)
        if isinstance(got, JSONResponse):
            return got
        actor, on_behalf, args, mailbox_id, _level = got
        store: Store = state["store"]
        op = str(args.get("op") or "")
        if op not in MUTATE_OPS:
            return refuse(422, "bad_request", "op is one of " + ", ".join(MUTATE_OPS) + ".")
        folders = await store.folder_rows_by_id(mailbox_id)
        by_special = {r["special_use"]: int(fid) for fid, r in folders.items() if r["special_use"]}
        rows: list = []
        if op == "trash_empty":
            which = "junk" if str(args.get("which") or "trash") == "spam" else "trash"
            fid = by_special.get(which)
            if fid is None:
                return refuse(422, "bad_request", "This mailbox has no such folder yet.")
            rows = [{"id": int(r["id"]), "folder_id": fid, "uidvalidity": int(r["uidvalidity"]), "uid": int(r["uid"]), "folder": folders[fid]["name"],
                     "special_use": which, "folder_uidvalidity": folders[fid]["uidvalidity"]} for r in await store.folder_uids(fid)]
        elif isinstance(args.get("targets"), list):
            rows = [dict(r) for r in await store.rows_for_targets(mailbox_id, args["targets"])]
        elif isinstance(args.get("messages"), list):
            try:
                rows = [dict(r) for r in await store.rows_for_messages(mailbox_id, [int(x) for x in args["messages"]])]
            except (TypeError, ValueError):
                return refuse(422, "bad_request", "messages is a list of message ids.")
        else:
            return refuse(422, "bad_request", "A mutation names targets [[folder, uidvalidity, uid]] or messages [ids].")
        if not rows and op != "trash_empty":
            return JSONResponse({"ok": True, "result": {"done": True, "count": 0, "moved": [], "refused": []}})
        # where a move goes
        dest = None
        if op in ("move", "archive", "trash", "spam", "not_spam"):
            if op == "move":
                try:
                    dest = int(args.get("to_folder"))
                except (TypeError, ValueError):
                    return refuse(422, "bad_request", "to_folder is a folder's id.")
                if dest not in folders:
                    return refuse(404, "not_found", "No such folder in this mailbox.")
            else:
                want = {"archive": "archive", "trash": "trash", "spam": "junk", "not_spam": "inbox"}[op]
                dest = by_special.get(want)
                if dest is None and want == "archive":
                    dest = None                              # created on first use below
                elif dest is None:
                    return refuse(422, "bad_request", "This mailbox has no such folder yet.")
        if op == "delete_forever":
            rows = [r for r in rows if r.get("special_use") in ("trash", "junk")]
            if not rows:
                return refuse(422, "refused", "Delete forever takes mail from Trash or Spam only.")
        # the refusal per folder, BEFORE anything reaches the server
        refused: list[dict] = []
        allowed: dict[int, list] = {}
        for r in rows:
            fid = int(r["folder_id"])
            if r["folder_uidvalidity"] is not None and int(r["uidvalidity"]) != int(r["folder_uidvalidity"]):
                refused.append({"reason": "This message moved — reload.", "count": 1})
                continue
            if fid == dest:
                continue                                         # already there
            allowed.setdefault(fid, []).append(r)
        kept: dict[int, list] = {}
        for fid, group in allowed.items():
            why = await store.mutation_refusal(mailbox_id, actor, on_behalf, fid, REFUSAL_OP[op])
            if why is None and op == "seen" and await store.read_mode(mailbox_id, actor, on_behalf) != "store_seen":
                why = "Reading here is per person: nothing is marked read on the server."
            if why is not None:
                refused.append({"reason": why, "count": len(group)})
                continue
            kept[fid] = group
        if not kept:
            if refused and all(x["reason"] == "No such mailbox." for x in refused):
                return refuse(404, "not_found", "No such mailbox.")
            return JSONResponse({"ok": True, "result": {"done": True, "count": 0, "moved": [], "refused": _merge(refused)}})
        try:
            w = watcher_of(mailbox_id)
        except NotLive:
            return not_live()
        dest_name = folders[dest]["name"] if dest is not None else "Archive"

        def act(c):
            moved_out: list[tuple[int, int, int, int]] = []
            flagged: list[int] = []
            deleted: list[int] = []
            dest_id = dest
            dest_validity = None
            if op in ("move", "archive", "trash", "spam", "not_spam") and dest_id is None:
                imap.create_folder(c, "Archive")
                dest_id = state["sync"].call(store.ensure_folder_row(mailbox_id, "Archive", "archive"))
            for fid, group in kept.items():
                imap.select(c, folders[fid]["name"] if fid in folders else group[0]["folder"], readonly=False)
                uids = [int(r["uid"]) for r in group]
                if op in ("seen", "unseen"):
                    imap.store_flags(c, uids, "\\Seen", op == "seen")
                    flagged += [int(r["id"]) for r in group]
                elif op in ("flag", "unflag"):
                    imap.store_flags(c, uids, "\\Flagged", op == "flag")
                    flagged += [int(r["id"]) for r in group]
                elif op in ("delete_forever", "trash_empty"):
                    imap.expunge_uids(c, uids)
                    deleted += [int(r["id"]) for r in group]
                else:
                    mapping = imap.move(c, uids, dest_name)
                    if dest_validity is None:
                        info = imap.select(c, dest_name)
                        dest_validity = info["uidvalidity"]
                        state["sync"].call(store.set_folder_uidvalidity(dest_id, dest_validity))
                    for r in group:
                        new_uid = mapping.get(int(r["uid"]))
                        if new_uid is None:
                            deleted.append(int(r["id"]))         # no COPYUID: the row goes; the destination's catch-up re-makes it
                        else:
                            moved_out.append((int(r["id"]), dest_id, int(dest_validity), int(new_uid)))
            return moved_out, flagged, deleted, dest_id

        try:
            moved_out, flagged, deleted, dest_id = await on_pool(w, act, lock=True)
        except Busy:
            return refuse(503, "busy", "The mail server is busy for this mailbox; try again in a moment.", retry_after=5)
        except Stopped:
            return not_live()
        except Exception as e:  # noqa: BLE001
            log.info("mutate %s for mailbox %s failed: %s", op, mailbox_id, type(e).__name__)
            for fid in kept:
                state["sync"].request(mailbox_id, fid, False, False)
            return refuse(502, "failed", "The change could not be made on the mail server; the folder will be re-read.")
        # the index, in the same request (decision 7)
        if flagged:
            await store.set_flag(flagged, "seen" if op in ("seen", "unseen") else "flagged", op in ("seen", "flag"))
        if deleted:
            await store.delete_rows(deleted)
            if dest_id is not None:
                state["sync"].request(mailbox_id, dest_id, False, False)
        if moved_out:
            await store.messages_moved(moved_out)
        bodies.invalidate(mailbox_id)
        count = len(flagged) + len(deleted) + len(moved_out)
        return JSONResponse({"ok": True, "result": {"done": True, "count": count, "op": op, "to_folder": dest_id,
                                                    "moved": [{"message": m, "ref": f"{f}.{v}.{u}"} for m, f, v, u in moved_out],
                                                    "deleted": deleted, "refused": _merge(refused)}})

    def _merge(refused: list[dict]) -> list[dict]:
        out: dict[str, int] = {}
        for r in refused:
            out[r["reason"]] = out.get(r["reason"], 0) + int(r["count"])
        return [{"reason": k, "count": v} for k, v in out.items()]

    async def run_passes():
        while True:
            store: Store = state["store"]
            try:
                counts = await store.health_counts()
                live = await state["sync"].reconcile()
                stamp = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
                passes["sync"].update(last_run_at=stamp, mailboxes=live)
                passes["outbox"].update(last_run_at=stamp, due=counts["outbox_due"])
            except Exception as e:  # noqa: BLE001 — a pass never takes the service down
                log.warning("pass failed: %s", type(e).__name__)
            await asyncio.sleep(cfg.pass_seconds)

    async def run_text(tasks: set):
        while True:
            try:
                await state["text"].tick(tasks)
            except Exception as e:  # noqa: BLE001
                log.warning("text pass failed: %s", type(e).__name__)
            await asyncio.sleep(1)

    @contextlib.asynccontextmanager
    async def lifespan(app):
        state["store"] = await Store.open(cfg.db_dsn)
        state["http"] = httpx.AsyncClient(trust_env=False)
        state["flows"] = Flows(cfg, state["store"], state["http"], public_key)
        state["sync"] = Sync(cfg, state["store"], backoff, asyncio.get_running_loop())
        state["text"] = TextPass(state["sync"], cfg.text_per_minute)
        tasks: set = set()
        tasks.add(asyncio.create_task(run_passes()))           # held, or the loop may collect it (Boards' finding)
        tasks.add(asyncio.create_task(run_text(tasks)))
        try:
            yield
        finally:
            for t in list(tasks):
                t.cancel()
            await asyncio.to_thread(state["sync"].stop)
            await state["http"].aclose()
            await state["store"].close()

    return Starlette(routes=[
        Route("/health", health, methods=["GET"]),
        Route("/v1/admin/mailboxes/create", create, methods=["POST"]),
        Route("/v1/admin/mailboxes/connect", connect, methods=["POST"]),
        Route("/v1/admin/{tool:str}", admin, methods=["POST"]),
        Route("/v1/mailboxes/{mailbox:int}/probe", probe, methods=["POST"]),
        Route("/v1/mailboxes/{mailbox:int}/sync", sync_call, methods=["POST"]),
        Route("/v1/mailboxes/{mailbox:int}/status", status_call, methods=["POST"]),
        Route("/v1/mailboxes/{mailbox:int}/quota", quota_call, methods=["POST"]),
        Route("/v1/mailboxes/{mailbox:int}/fetch", fetch_call, methods=["POST"]),
        Route("/v1/mailboxes/{mailbox:int}/part", part_call, methods=["POST"]),
        Route("/v1/mailboxes/{mailbox:int}/raw", raw_call, methods=["POST"]),
        Route("/v1/mailboxes/{mailbox:int}/mutate", mutate_call, methods=["POST"]),
    ], lifespan=lifespan)


def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s: %(message)s")
    try:
        cfg = Config.load()
    except (ConfigError, ValueError) as e:
        log.error("not started: %s", e)
        return 2
    uvicorn.run(build_app(cfg), host="127.0.0.1", port=cfg.port, log_level="warning", access_log=False)
    return 0


if __name__ == "__main__":
    sys.exit(main())
