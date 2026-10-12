"""MaluMail's mail service (design §6.1) — Phase 0's skeleton. The only process that holds an open mailbox credential, the sealing
secret key and MaluMail's customer key. It listens on loopback, 127.0.0.1:ML_MAILER_PORT, behind the signed door (door.py).

  GET  /health                          up, the database, credential and outbox counts, the passes' last runs — counts only, unsigned
  POST /v1/admin/{tool}                 one Mailbox MCP administration call for the acting member (mailbox_mcp.py's policy);
                                        a reset answers what the mailbox's kind allows (admin_flows.after_reset)
  POST /v1/admin/mailboxes/create      slice 1: MaluMail's create_mailbox + the rows in one transaction (admin_flows.create)
  POST /v1/admin/mailboxes/connect     slice 1: an existing mailbox registered, reset if agent/shared, probed (admin_flows.connect)
  POST /v1/mailboxes/{id}/probe         log in to the mailbox with its sealed credential: capabilities, folders, INBOX's counts;
                                        an authentication failure trips the circuit breaker (store.trip_breaker)

Every POST body is JSON: {"actor": <member id>, "args": {...}}. Answers: {"ok": true, "result": …} or {"ok": false, "error":
{"code", "message"}} with 401 (not a signed call), 403 (the member may not), 409 (the credential is failed: a person stores a new
one), 422 (MaluMail or the policy said no, in a sentence), 429 (backing off: `retry_after`), 502 (MaluMail or the mail server
unreachable or refusing the key), 503 (not configured).

Two passes run every ML_MAILER_PASS_SECONDS and are EMPTY in Phase 0: the sync pass counts the mailboxes it will keep in step
(slice 2), the outbox pass counts the due rows it will send (slice 4). Neither connects to anything yet.

Run: mcp/venv/bin/python -I services/mailer/server.py (deploy/malumail-mailer.service).
"""

from __future__ import annotations

import asyncio
import contextlib
import ipaddress
import json
import logging
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
from starlette.responses import JSONResponse  # noqa: E402
from starlette.routing import Route  # noqa: E402

from services.mailer import door, imap, mailbox_mcp, seal  # noqa: E402
from services.mailer.admin_flows import Flows  # noqa: E402
from services.mailer.config import Config, ConfigError  # noqa: E402
from services.mailer.store import Store  # noqa: E402

VERSION = "0.1.0-phase0"
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

    async def signed(request: Request):
        """(actor, args) for a good call, or the refusal to answer."""
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
        except (ValueError, TypeError):
            return refuse(422, "bad_request", "A call names its acting member: {\"actor\": <member id>, \"args\": {…}}.")
        if not await state["store"].active_member(actor):
            return refuse(403, "unknown_member", "That member is not an active member here.")
        args = data.get("args") or {}
        if not isinstance(args, dict):
            return refuse(422, "bad_request", "args is an object.")
        return actor, args

    async def health(request: Request):
        store: Store = state["store"]
        db_ok = await store.ping()
        counts = await store.health_counts() if db_ok else {}
        return JSONResponse({"status": "up" if db_ok else "degraded", "version": VERSION, "database": db_ok,
                             "sealing_key": cfg.seal_secret is not None, "customer_key": cfg.customer_key is not None,
                             "credentials_failed": counts.get("failed"), "passes": passes})

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

    async def run_passes():
        while True:
            store: Store = state["store"]
            try:
                counts = await store.health_counts()
                stamp = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
                passes["sync"].update(last_run_at=stamp, mailboxes=counts["syncable"])
                passes["outbox"].update(last_run_at=stamp, due=counts["outbox_due"])
            except Exception as e:  # noqa: BLE001 — a pass never takes the service down
                log.warning("pass failed: %s", type(e).__name__)
            await asyncio.sleep(cfg.pass_seconds)

    @contextlib.asynccontextmanager
    async def lifespan(app):
        state["store"] = await Store.open(cfg.db_dsn)
        state["http"] = httpx.AsyncClient(trust_env=False)
        state["flows"] = Flows(cfg, state["store"], state["http"], public_key)
        tasks = {asyncio.create_task(run_passes())}            # held, or the loop may collect it (Boards' finding)
        try:
            yield
        finally:
            for t in tasks:
                t.cancel()
            await state["http"].aclose()
            await state["store"].close()

    return Starlette(routes=[
        Route("/health", health, methods=["GET"]),
        Route("/v1/admin/mailboxes/create", create, methods=["POST"]),
        Route("/v1/admin/mailboxes/connect", connect, methods=["POST"]),
        Route("/v1/admin/{tool:str}", admin, methods=["POST"]),
        Route("/v1/mailboxes/{mailbox:int}/probe", probe, methods=["POST"]),
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
