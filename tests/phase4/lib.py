"""Phase 4's proof library: the environment, the seed, tokens of every kind (a person's, an agent's run, the kernel's), the fake kernel's run facts, an MCP
client for either server (directly or through the vhost) and the checks. Nothing here reaches MaluMail."""
import asyncio
import hashlib
import hmac
import json
import os
import pathlib
import secrets
import subprocess
import sys
import time

ROOT = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "mcp"))
sys.path.insert(0, str(ROOT))

from mcp import ClientSession  # noqa: E402
from mcp.client.streamable_http import streamablehttp_client  # noqa: E402

DB = os.environ["ML_DEV_DB"]
STATE = pathlib.Path(os.environ["ML_DEV_STATE"])
KEY = os.environ["ACTION_TOKEN_KEY"].encode()
SEED = json.loads(pathlib.Path(os.environ["ML_P2_SEED"]).read_text())
RECORDS = os.environ["ML_P4_URL_RECORDS"]
ACTIVITY = os.environ["ML_P4_URL_ACTIVITY"]
VHOST = f"http://127.0.0.1:{os.environ.get('P_APP', '9201')}"
APACHE = os.environ.get("ML_APP", "php") == "apache"
OWNER, PRIYA, MARCO, BEA, ANN, DANA, LEE, EXPERT, WATCHER, CLERK, ADA = 1, 26, 27, 28, 29, 30, 31, 40, 41, 42, 43
FAILS = PASSES = 0
WORLD_FILE = STATE / "world.json"


def check(cond, label, detail=""):
    global FAILS, PASSES
    if cond:
        PASSES += 1
        print(f"  ok   {label}")
    else:
        FAILS += 1
        print(f"  FAIL {label}" + (f" — {str(detail)[:500]}" if detail != "" else ""))


def done(name: str) -> int:
    print(f"{name}: {PASSES} checks, {FAILS} failed")
    return 1 if FAILS else 0


def sql(q: str) -> str:
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-v", "ON_ERROR_STOP=1", "-d", DB, "-c", q], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip())
    return r.stdout.strip()


def world() -> dict:
    return json.loads(WORLD_FILE.read_text())


# ---- tokens ---------------------------------------------------------------------------------------------------------------------------
def person_token(member: int, label: str = "p4") -> str:
    raw = "mcp_" + secrets.token_hex(24)
    sql(f"INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES ({member}, '{label}', '{hashlib.sha256(raw.encode()).hexdigest()}', 'mcp')")
    return raw


def revoke_token(raw: str) -> None:
    sql(f"UPDATE mcp_access_tokens SET revoked_at = now() WHERE token_hash = '{hashlib.sha256(raw.encode()).hexdigest()}'")


def run_token(member: int, run: int, ttl: int = 3600) -> str:
    exp = int(time.time()) + ttl
    sig = hmac.new(KEY, f"run:{member}.{exp}.{run}".encode(), hashlib.sha256).hexdigest()
    return f"{member}.{exp}.{run}.{sig}"


def kernel_token(ttl: int = 60, app: str = "malumail") -> str:
    exp = int(time.time()) + ttl
    nonce = secrets.token_hex(16)
    return f"kernel.{exp}.{app}.{nonce}." + hmac.new(KEY, f"kernel:{exp}.{app}.{nonce}".encode(), hashlib.sha256).hexdigest()


def action_token(member: int, ttl: int = 600) -> str:
    exp = int(time.time()) + ttl
    return f"{member}.{exp}." + hmac.new(KEY, f"{member}.{exp}".encode(), hashlib.sha256).hexdigest()


# ---- the fake kernel's run facts -------------------------------------------------------------------------------------------------------
GRANTS = json.loads((ROOT / "maludb-os.json").read_text())["agents"]


def grants_of(agent_key: str) -> dict:
    a = next(x for x in GRANTS if x["key"] == agent_key)
    return {"Records MCP": {t: {} for t in a["tool_grants"]["Records MCP"]}, "Activity MCP": {t: {} for t in a["tool_grants"]["Activity MCP"]}}


def facts(run: int, member: int, grants: dict | None = None, by: int | None = None, kind: str | None = "human", holds: bool = True, is_eval: bool = False,
          endpoints: bool = True, valid: bool = True) -> None:
    """Write the fake kernel's answer for a run (state.facts[<run>]); `by` is K38's requested_by_member_id."""
    f = STATE / "kernel.json"
    state = json.loads(f.read_text())
    g = grants if grants is not None else grants_of("expert")
    doc = {"valid": valid, "is_agent": True, "member_id": member, "run_id": run, "request_id": f"req-{run}", "is_eval": is_eval,
           "endpoints": [{"id": 1, "name": n, "url": "x", "tools": t} for n, t in g.items()] if endpoints else [],
           "capability": "write", "role": "user", "roles": ["user"], "rights": [], "scopes": [],
           "requested_by_member_id": by, "requested_by_kind": kind if by is not None else None, "requested_by_holds": bool(holds) if by is not None else False}
    state.setdefault("facts", {})[str(run)] = doc
    f.write_text(json.dumps(state))


# ---- the MCP client -------------------------------------------------------------------------------------------------------------------
class Refused(Exception):
    pass


async def _session(url, token, headers, fn):
    h = {"Authorization": f"Bearer {token}"} if token is not None else {}
    h.update(headers or {})
    async with streamablehttp_client(url, headers=h) as (r, w, _):
        async with ClientSession(r, w) as s:
            await s.initialize()
            return await fn(s)


def _unwrap(e: BaseException) -> BaseException:
    while isinstance(e, BaseExceptionGroup) and e.exceptions:
        e = e.exceptions[0]
    return e


def _note(url: str, tool: str, parsed) -> None:
    """The coverage proof reads which tools were really called and answered (an ok answer, or a plain list for a resolver)."""
    ok = isinstance(parsed, list) or (isinstance(parsed, dict) and (parsed.get("ok") is True or "schema" in parsed))
    if ok:
        with open(STATE / "p4-called.txt", "a") as f:
            f.write(("activity:" if url.startswith(ACTIVITY[:22]) and ":9208" in url else "records:") + tool + "\n")


async def call(url: str, token: str | None, tool: str, params: dict | None = None, headers: dict | None = None):
    """The tool's answer as the model sees it: (parsed JSON or None, raw text, is_error). A refusal at the door raises Refused."""
    async def go(s):
        res = await s.call_tool(tool, {"params": params} if params is not None else {})
        text = res.content[0].text if res.content else ""
        try:
            parsed = json.loads(text)
        except ValueError:
            return None, text, bool(res.isError)
        _note(url, tool, parsed)
        return parsed, text, bool(res.isError)
    try:
        return await _session(url, token, headers, go)
    except BaseException as e:  # noqa: BLE001
        raise Refused(str(_unwrap(e))[:300]) from None


async def raw_call(url, token, tool, arguments, headers=None):
    async def go(s):
        res = await s.call_tool(tool, arguments)
        text = res.content[0].text if res.content else ""
        try:
            parsed = json.loads(text)
        except ValueError:
            return None, text, bool(res.isError)
        _note(url, tool, parsed)
        return parsed, text, bool(res.isError)
    try:
        return await _session(url, token, headers, go)
    except BaseException as e:  # noqa: BLE001
        raise Refused(str(_unwrap(e))[:300]) from None


async def tools(url: str, token: str | None, headers: dict | None = None) -> list[str]:
    async def go(s):
        return [t.name for t in (await s.list_tools()).tools]
    try:
        return await _session(url, token, headers, go)
    except BaseException as e:  # noqa: BLE001
        raise Refused(str(_unwrap(e))[:300]) from None


async def schemas(url: str, token: str) -> dict:
    async def go(s):
        return {t.name: (t.inputSchema, t.annotations) for t in (await s.list_tools()).tools}
    return await _session(url, token, None, go)


def code(doc) -> str | None:
    return ((doc or {}).get("error") or {}).get("code") if isinstance(doc, dict) else None


def run(coro):
    return asyncio.run(coro)


def mcp_reads(mailbox: int, actor: int | None = None, tool: str | None = None) -> int:
    w = f"action = 'mcp.read' AND mailbox_id = {mailbox}" + (f" AND actor_member_id = {actor}" if actor else "") + (f" AND after->>'tool' = '{tool}'" if tool else "")
    return int(sql(f"SELECT count(*) FROM activity_log WHERE {w}"))


def walk_strings(obj, path=()):
    if isinstance(obj, dict):
        for k, v in obj.items():
            yield from walk_strings(v, path + (k,))
    elif isinstance(obj, list):
        for i, v in enumerate(obj):
            yield from walk_strings(v, path + (i,))
    elif isinstance(obj, str):
        yield path, obj


def unfenced(obj, needles) -> list:
    """Every string that holds one of the needles but is not exactly one <untrusted-mail> block — the fence's proof."""
    bad = []
    for path, s in walk_strings(obj):
        if any(n in s for n in needles) and not (s.startswith("<untrusted-mail ") and s.rstrip().endswith("</untrusted-mail>") and s.count("<untrusted-mail ") == 1):
            bad.append((path, s[:80]))
    return bad
