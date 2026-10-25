"""The kernel's own contract, run against this application (tests/phase4/registry.py): a registry file composed the way the installer writes mcp/registries/malumail.json (the wrapper deploy/kernel-registry-malumail.json + mcp/action_registry.json,
each built action carrying the tool_name K55 gives it — composed by registry.php with the KERNEL's own assign_registry_tool_names()), loaded by the KERNEL's loader (application_actions.load_registries), every built action registered as a tool on a
FastMCP exactly as the kernel's actions server does (application_actions.register), every resolve entity answered by THIS application's live records server through the kernel's own resolver (application_actions.resolve_via_mcp), and whole actions
driven through the kernel's tool with a stub for the two posts (the approval hook, the application's handler). Nothing is written anywhere: the kernel's registry directory is never touched (and no bytecode is written beside its source).
Usage: registry_check.py <composed registry file> <records mcp url> <json: {"resolve": [[entity, value, token], ...], "drive": [[action, params, token, hook?], ...]}>"""
import asyncio
import json
import pathlib
import shutil
import sys
import tempfile

sys.dont_write_bytecode = True
KERNEL_MCP = "/var/www/mcp"
sys.path.insert(0, KERNEL_MCP)
import application_actions as aa  # noqa: E402  (the kernel's module, read only)
from mcp.server.fastmcp import FastMCP  # noqa: E402

composed_path, records_url, plan = pathlib.Path(sys.argv[1]), sys.argv[2], json.loads(sys.argv[3])
composed = json.loads(composed_path.read_text())
composed["records_url"] = records_url
out: dict = {}
tmp = pathlib.Path(tempfile.mkdtemp(prefix="ml-registry-"))
(tmp / "malumail.json").write_text(json.dumps(composed))
aa.REGISTRIES_DIR = tmp
regs = aa.load_registries()
out["loaded"] = [r["app_key"] for r in regs]

posts: list = []
hook = {"v": "success"}


async def app_post(path, fields, base=None):
    posts.append({"path": path, "fields": fields, "base": base})
    if path == "/approvals/hook.php":
        return {"status": hook["v"], "message": "waiting for approval" if hook["v"] == "pending_approval" else ""}
    return {"status": "success", "did": "stub"}


token_now = {"v": ""}
mcp = FastMCP("kernel-actions-sim")
taken: set = set()
count = aa.register(mcp, app_post, lambda: token_now["v"], taken)
out["registered"] = count
out["taken"] = sorted(taken)


async def main():
    tools = await mcp.list_tools()
    out["tools"] = {t.name: {"description": t.description, "schema": t.inputSchema} for t in tools}
    out["resolved"] = {}
    for entity, value, token in plan["resolve"]:
        spec = composed["resolve"][entity]
        try:
            rows = await aa.resolve_via_mcp(records_url, token, spec["tool"], {spec.get("query_param", "q"): value})
            idf, lab = spec["id_field"], spec["label_field"]
            matches = [r for r in rows if isinstance(r, dict) and r.get(idf) is not None]
            out["resolved"][entity + "|" + value] = {"value": value, "rows": len(rows), "matches": len(matches), "id": matches[0].get(idf) if matches else None, "label": matches[0].get(lab) if matches else None,
                                       "keys": sorted(matches[0].keys()) if matches else []}
        except Exception as exc:  # noqa: BLE001
            out["resolved"][entity + "|" + value] = {"error": str(exc)}
    out["driven"] = []
    for entry in plan["drive"]:
        action, params, token = entry[:3]
        hook["v"] = entry[3] if len(entry) > 3 else "success"
        token_now["v"] = token
        del posts[:]
        name = next((a.get("tool_name") or a["action"] for a in composed["registry"]["actions"].values() if a["action"] == action), action)
        res = await mcp.call_tool(name, {"params": params})
        text = res[0][0].text if isinstance(res, tuple) else res[0].text
        out["driven"].append({"action": action, "answer": json.loads(text), "posts": list(posts)})


try:
    asyncio.run(main())
finally:
    shutil.rmtree(tmp, ignore_errors=True)
print(json.dumps(out, default=str))
