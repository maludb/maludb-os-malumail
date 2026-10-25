"""The kernel's own contract, run against this application: the registry wrapper (deploy/kernel-registry-malumail.json) and the action registry
(mcp/action_registry.json), composed as the installer writes mcp/registries/malumail.json — each built action carrying the tool_name K55 gives it —, are
loaded by the KERNEL's loader (application_actions.load_registries, /var/www/mcp, read only) and registered on a FastMCP exactly as the kernel's actions server does
(register()); every resolve entity is answered by THIS application's live records server through the kernel's own resolver (resolve_via_mcp) with the
caller's token; whole actions are driven through the kernel's tool with the approval hook and the handler stubbed — then a post the kernel WOULD make is replayed
against the real handler as the person. Nothing of the kernel is written."""
import hashlib
import hmac
import json
import os
import subprocess
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib import ACTIVITY, DANA, PRIYA, RECORDS, ROOT, SEED, action_token, check, done, person_token, run, sql, tools, world

import httpx  # noqa: E402

W = world()
wrapper = json.load(open(ROOT / "deploy/kernel-registry-malumail.json"))
reg = json.load(open(ROOT / "mcp/action_registry.json"))
manifest = json.load(open(ROOT / "maludb-os.json"))
actions = reg["actions"]
KNOWN_CATEGORIES = {"money_out", "deletion", "external_send", "other"}
APP = f"http://127.0.0.1:{os.environ.get('P_APP', '9201')}"
PR, SA = SEED["priya"], SEED["sales"]


def php(*a):
    return subprocess.run(["php", *a], capture_output=True, text=True, cwd=ROOT)


async def main():
    print("1. The wrapper, the registry and the manifest read right")
    check(wrapper["schema"] == "maludb-os.registry/1" and wrapper["app_key"] == "malumail" and len(wrapper["resolve"]) == 9, "the wrapper: schema maludb-os.registry/1, app_key malumail, nine resolve entities")
    check(php("bin/build_action_registry.php", "--check").returncode == 0, "the registry is current with the action manifest (bin/build_action_registry.php --check)")
    built = [a for a in actions.values() if a.get("built")]
    check(len(actions) == 67 and len(built) == 67 and sum(1 for s in reg["screens"].values() if s.get("built")) == 45, "67 actions, every one built, and 45 screens built")
    cats = {a["approval"] for a in actions.values() if a.get("approval")}
    with_cat = [k for k, a in actions.items() if a.get("approval")]
    check(cats <= KNOWN_CATEGORIES and len(with_cat) == 3, f"every approval category is one the kernel knows ({sorted(cats)}); {len(with_cat)} actions carry one")
    missing = [f"{k} → {a['endpoint']}" for k, a in actions.items() if not (ROOT / "html" / a["endpoint"].lstrip("/")).is_file()]
    check(not missing, "every action's endpoint exists under html/", missing[:4])
    check(not [k for k, a in actions.items() if any(p.get("name") is None for p in a["params"])], "no action carries a phantom parameter")
    check(not [k for k, a in actions.items() if not a.get("log_event")], "every action names the log event the approval hook asks about")
    print("2. Every resolve entity: its tool exists on the records server; no param claimed twice; no name means two things")
    tp = person_token(PRIYA)
    served = set(await tools(RECORDS, tp))
    pm: dict = {}
    for e, spec in wrapper["resolve"].items():
        check(spec["tool"] in served and spec.get("query_param") == "q" and spec["id_field"] and spec["label_field"] and spec["params"], f"{e}: tool {spec['tool']} exists; id {spec['id_field']}, label {spec['label_field']}")
        for p in spec["params"]:
            pm.setdefault(p, []).append(e)
    check(not [p for p, e in pm.items() if len(e) > 1], "no param is claimed by two entities")
    used = {p["name"]: k for k, a in actions.items() for p in a["params"] if p.get("name") in pm}
    check({"mailbox", "thread", "to_folder", "folder", "label", "template", "identity", "person", "agent", "owner", "overseer", "primary_overseer"} <= set(used),
          "the resolved params are really used by actions", sorted(set(pm) - set(used)))
    ids_only = {"message", "outbound", "permission", "draft", "dispatch", "notification", "token", "alias", "matching", "address", "sender", "part"}
    check(not (ids_only & set(pm)), "ids-only params (message, outbound, permission, draft, dispatch, notification, token, alias) are never resolved by name")
    # KNOWN KERNEL BEHAVIOUR: the kernel resolves a resolve entity's params registry-wide — a free-text or enum param named like an entity would be looked up as a record
    clash = [f"{k}.{p['name']}" for k, a in actions.items() for p in a["params"] if p.get("name") in pm and p["name"] not in used]
    check(not clash, "no action's param is named like a resolve entity and means something else")
    print("3. The registry, as the installer composes it, in the kernel's own loader")
    tmp = tempfile.mkdtemp(prefix="ml-p4-")
    comp = os.path.join(tmp, "composed.json")
    r = php("tests/phase4/compose.php", comp, RECORDS, APP)
    info = json.loads(r.stdout.strip().splitlines()[-1]) if r.returncode == 0 and r.stdout.strip() else {}
    check(info.get("clash") == [] and info.get("others", 0) > 5, f"K55: every action can be named against the {info.get('others')} applications installed here; none clashes", r.stderr[-300:] or info)
    composed = json.load(open(comp))
    tool_of = {a["action"]: a.get("tool_name") or a["action"] for a in composed["registry"]["actions"].values()}
    check(len(set(tool_of.values())) == 67 and max(len(t) for t in tool_of.values()) <= 64, "the 67 tool names are distinct and within the 64 characters a model API takes")
    print(f"   (the actions that take the prefix here, because another application or the kernel has the bare name: {', '.join(info.get('prefixed', [])) or 'none'})")
    tkn = action_token(PRIYA)
    resolve = [["mailbox", "sales@business.example", tkn], ["mailbox", "priya@business.example", tkn], ["thread", "Q3 figures PLANT_SUBJ_a1", tkn], ["template", "Thanks", tkn],
               ["identity", "sales@business.example", tkn], ["member", "SMOKE Marco", tkn], ["department", "Sales", tkn], ["correspondent", "attacker@outside.example", tkn],
               ["label", "Urgent P4", tkn], ["folder", "Inbox", tkn], ["member", "nobody at all", tkn]]
    drive = [
        ["mail_archive", {"mailbox": "sales@business.example", "thread": str(W["sales_thread"])}, tkn],
        ["mail_archive", {"mailbox": "priya@business.example", "thread": "Q3 figures PLANT_SUBJ_a1"}, tkn],
        ["mail_move", {"mailbox": "priya@business.example", "thread": str(W["priya_thread"]), "to_folder": "Inbox"}, tkn],
        ["mail_move", {"mailbox": "priya@business.example", "thread": str(W["priya_thread"]), "to_folder": "99999"}, tkn],
        ["mail_delete_forever", {"mailbox": "priya@business.example", "thread": str(W["priya_thread"])}, tkn],
        ["mail_delete_forever", {"mailbox": "priya@business.example", "thread": str(W["priya_thread"]), "confirmed": True}, tkn, "pending_approval"],
        ["mail_delete_forever", {"mailbox": "priya@business.example", "thread": str(W["priya_thread"]), "confirmed": True}, tkn],
        ["mail_permission_grant", {"mailbox": "sales@business.example", "person": "SMOKE Marco", "level": "view", "confirmed": True}, tkn],
        ["mail_label_create", {"mailbox": "sales@business.example", "name": "P4 replay label"}, tkn],
        ["mail_send", {"mailbox": "priya@business.example", "to": "someone@outside.example", "subject": "Hello", "body": "Hi.", "purpose": "say hello"}, tkn],
        ["mail_archive", {"mailbox": "nobody@nowhere.example", "thread": "1"}, tkn],
    ]
    kpy = "/var/www/mcp/venv/bin/python"
    out = subprocess.run([kpy, "-I", os.path.join(os.path.dirname(os.path.abspath(__file__)), "registry_check.py"), comp, RECORDS, json.dumps({"resolve": resolve, "drive": drive})],
                         capture_output=True, text=True)
    try:
        k = json.loads(out.stdout.strip().splitlines()[-1])
    except (ValueError, IndexError):
        check(False, "the kernel's loader and tool factory ran against the composed registry", out.stderr[-500:] + out.stdout[-200:])
        return done("registry")
    check(k["loaded"] == ["malumail"] and k["registered"] == 67, "application_actions.load_registries() accepts it and register() adds 67 tools", (k["loaded"], k["registered"]))
    check(len(k["tools"]) == 67 and not (set(tool_of.values()) - set(k["tools"])), "one tool per action, under the name K55 gave it")
    no_app = [key for key, a in actions.items() if a.get("approval") and f"category: {a['approval']}" not in k["tools"][tool_of[a["action"]]]["description"]]
    check(not no_app, "a tool's description states its approval category (3 of them)", no_app)
    n_conf = [key for key, a in actions.items() if a.get("confirm")]
    no_conf = [key for key in n_conf if "confirmed" not in json.dumps(k["tools"][tool_of[actions[key]["action"]]]["schema"])]
    check(not no_conf and len(n_conf) >= 19, f"every destructive action carries the `confirmed` guard ({len(n_conf)} of them)", no_conf)
    no_param = []
    for key, a in actions.items():
        sc = k["tools"][tool_of[a["action"]]]["schema"]
        defs = sc.get("$defs") or {}
        props = list((defs[next(iter(defs))].get("properties") or {}).keys()) if defs else list((sc.get("properties") or {}).keys())
        no_param += [f"{key}.{p['name']}" for p in a["params"] if p.get("name") and p["name"] not in props]
    check(not no_param, "every manifest parameter is a field of its tool", no_param[:5])
    print("   resolving through the kernel's resolver, as the person who asks:")
    for entity, value, _ in resolve:
        res = k["resolved"].get(f"{entity}|{value}") or {}
        spec = wrapper["resolve"][entity]
        if value == "nobody at all":
            check(res.get("rows") == 0 and res.get("matches") == 0, "resolver: an unknown name matches nothing", res)
        elif entity == "folder":
            check(res.get("matches", 0) >= 2 and spec["id_field"] in res.get("keys", []), f"resolver: folder 'Inbox' is several (one per mailbox the person opens: {res.get('matches')}) — the kernel asks which; an id (find_folders) settles it", res)
        elif entity == "label":
            check(res.get("matches") == 1 and res.get("label") == "Urgent P4", "resolver: label 'Urgent P4' → one match", res)
        else:
            check(res.get("matches") == 1 and spec["id_field"] in res["keys"] and spec["label_field"] in res["keys"], f"resolver: {entity} '{value[:30]}' → one match, {spec['id_field']} {res.get('id')}", res)
    rid = lambda e, v: str(k["resolved"][f"{e}|{v}"]["id"])  # noqa: E731
    check(rid("mailbox", "sales@business.example") == str(SA) and rid("member", "SMOKE Marco") == "27" and rid("department", "Sales") == "8", "…to the ids the database holds (sales@ is mailbox %d, Marco member 27, Sales department 8)" % SA)
    print("4. Whole actions through the kernel's tool")
    d = k["driven"]
    s = lambda i: d[i]["answer"].get("status")  # noqa: E731
    check(s(0) == "success" and len(d[0]["posts"]) == 1 and d[0]["posts"][0]["path"] == "/mail/archive.php" and d[0]["posts"][0]["fields"] == {"mailbox": str(SA), "thread[]": [str(W["sales_thread"])]},
          "mail_archive by mailbox NAME and a thread id: the name resolves, the id is a repeated field, one post (no approval) to /mail/archive.php", d[0])
    check(s(1) == "success" and d[1]["posts"][0]["fields"] == {"mailbox": str(PR), "thread": str(W["priya_thread"])}, "…and by the conversation's SUBJECT: it resolves to the thread's id (the handler reads thread and thread[] alike)", d[1])
    check(s(2) == "error" and "Several match" in d[2]["answer"]["message"] and d[2]["posts"] == [], "mail_move to_folder 'Inbox': several folders match across the person's mailboxes — the kernel lists them and posts nothing", d[2]["answer"])
    check(s(3) == "success" and d[3]["posts"][0]["fields"]["to_folder"] == "99999" and d[3]["posts"][0]["path"] == "/mail/move.php", "…given the folder's id (find_folders) it posts", d[3])
    check(s(4) == "needs_confirmation" and d[4]["posts"] == [], "mail_delete_forever without confirmed=true: needs_confirmation, nothing posted, nothing asked")
    check(s(5) == "pending_approval" and len(d[5]["posts"]) == 1 and d[5]["posts"][0]["path"] == "/approvals/hook.php", "…when the kernel pauses it: pending_approval, nothing posted to MaluMail")
    check(s(6) == "success" and d[6]["posts"][0]["fields"]["action_key"] == "mail_delete_forever" and d[6]["posts"][1]["path"] == "/mail/delete-forever.php", "…approved: the hook, then the handler")
    check(s(7) == "success" and d[7]["posts"][0]["path"] == "/access/grant.php" and d[7]["posts"][0]["fields"] == {"mailbox": str(SA), "person": "27", "level": "view"}, "mail_permission_grant: the mailbox and the person by NAME, resolved to ids", d[7])
    check(s(9) == "success" and d[9]["posts"][0]["path"] == "/mail/send.php" and d[9]["posts"][0]["fields"]["purpose"] == "say hello" and d[9]["posts"][0]["fields"]["mailbox"] == str(PR), "mail_send: the fields and the agent's purpose reach the handler", d[9])
    check(s(10) == "error" and "No mailbox matching" in d[10]["answer"]["message"] and d[10]["posts"] == [], "an unknown mailbox: \"No mailbox matching 'nobody@nowhere.example' in MaluMail that you can see.\"", d[10]["answer"])
    check(sql("SELECT count(*) FROM tags WHERE label = 'P4 replay label'") == "0", "and nothing was written by the stubbed posts")
    print("5. A post the kernel would make, replayed against the real handler as the person")
    post = d[8]["posts"][-1]
    tok = action_token(PRIYA)
    h = {"Accept": "application/json", "X-Action-Token": tok, "X-Action-Relay": hmac.new(os.environ["ACTIONS_RELAY_KEY"].encode(), tok.encode(), hashlib.sha256).hexdigest()}
    rr = httpx.post(APP + post["path"], data={kk: vv for kk, vv in post["fields"].items()}, headers=h, timeout=30)
    check(rr.status_code == 200 and sql("SELECT count(*) FROM tags WHERE label = 'P4 replay label'") == "1", "mail_label_create: the handler makes the label the kernel's post carried", (rr.status_code, rr.text[:200]))
    print("6. maludb-os.json is in step with what the servers offer")
    acts_served = set(await tools(ACTIVITY, tp))
    bad = []
    for a in manifest["agents"]:
        bad += [f"{a['key']} {t}" for t in a["tool_grants"].get("Records MCP", []) if t not in served - {"correspondence_index"}]
        bad += [f"{a['key']} {t}" for t in a["tool_grants"].get("Activity MCP", []) if t not in acts_served]
        bad += [f"{a['key']} {t}" for t in a["tool_grants"].get("Actions MCP", []) if t not in tool_of]
    check(not bad, "every tool an agent is granted exists: a records or activity tool of the surface, an action of the registry", bad)
    ag = {a["key"]: a for a in manifest["agents"]}
    check(list(ag) == ["expert", "clerk"], "two agents hired on install: the Mail Expert and the Mail Clerk")
    grants_expert = set(ag["expert"]["tool_grants"]["Records MCP"])
    check(len(grants_expert) == 33 and not ({"domains_list", "app_roles", "correspondence_index"} & grants_expert) and len(ag["expert"]["tool_grants"]["Actions MCP"]) == 20,
          "the Expert holds 33 records tools (no domains_list, app_roles or the share), 7 activity tools and 20 actions (the surface's 16 + the four it can take back)")
    surface = open(ROOT / "docs/malumail-mcp-tool-surface.md").read()
    check(all(f"`{t}`" in surface for t in ag["clerk"]["tool_grants"]["Records MCP"]) and set(ag["clerk"]["tool_grants"]["Actions MCP"]) == {"mail_label", "mail_mute"} and ag["clerk"]["tool_grants"]["Activity MCP"] == ["review_trail", "agent_activity"],
          "the Clerk's grants are the surface's: ten records tools, review_trail and agent_activity, mail_label and mail_mute")
    cannot = {"mail_review_approve", "mail_review_edit", "mail_review_reject", "mail_review_reply", "mail_policy_set", "mail_mailbox_pause", "mail_mailbox_resume", "mail_permission_grant", "mail_settings_save", "mail_mailbox_create", "mail_alias_create", "mail_unscreened_release"}
    check(not (cannot & {t for a in manifest["agents"] for t in a["tool_grants"]["Actions MCP"]}), "no agent is granted a decision or an administration: reviews, policies, pauses, access, settings, mailboxes, forwarding")
    pause = {t for t in ag["expert"]["tool_grants"]["Actions MCP"] if actions[t].get("approval")}
    check(pause == {"mail_delete_forever", "mail_trash_empty", "mail_folder_delete"}, "the three the Expert is granted that pause for a deletion approval are exactly the surface's three", pause)
    for sk in manifest["skills"]:
        check(os.path.isfile(ROOT / sk / "SKILL.md") or os.path.isfile(ROOT / (sk + ".md")) or os.path.isdir(ROOT / sk), f"skill {sk} exists")
    import glob
    import re
    known = served | acts_served | set(actions) | set(tool_of.values()) | {"message_send", "inbox_read"}
    unknown = []
    for f in glob.glob(str(ROOT / "skills/*/*.md")) + glob.glob(str(ROOT / "os/*.md")):
        for name in set(re.findall(r"`([a-z]+(?:_[a-z]+)+)`", open(f).read())):
            if re.match(r"^(mail|find|review|oversight|unscreened|agent_mail|mailbox|thread|message|attachment|records|record|member|activity|correspondence|domains|notice|my)_", name) and name not in known:
                unknown.append(f"{os.path.basename(os.path.dirname(f))}/{os.path.basename(f)}: {name}")
    check(not unknown, "every tool or action a skill or a job description names exists", unknown[:8])
    return done("registry")


if __name__ == "__main__":
    sys.exit(run(main()))
