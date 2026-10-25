"""Coverage: every tool of the surface was called and answered (from the other proofs' record), and every question of design §7 — 1 to 21 and A1 to A6 — has a tool that
exists and was called. The surface document's own coverage table is the source."""
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib import ACTIVITY, PRIYA, RECORDS, ROOT, STATE, check, done, person_token, run, tools  # noqa: E402


async def main():
    called = set((STATE / "p4-called.txt").read_text().split())
    tp = person_token(PRIYA)
    rec = set(await tools(RECORDS, tp)) | {"correspondence_index"}
    act = set(await tools(ACTIVITY, tp))
    check(len(rec) == 36 and len(act) == 7, "the servers offer 36 records tools (the share included) and 7 activity tools", (len(rec), len(act)))
    surface = open(ROOT / "docs/malumail-mcp-tool-surface.md").read()
    named = set(re.findall(r"^\| `([a-z_]+)` \|", surface.split("## Activity server")[0].split("## Records server")[1], re.M))
    named_act = set(re.findall(r"^\| `([a-z_]+)` \|", surface.split("## Activity server")[1].split("## The resolvers")[0], re.M))
    check(named == rec and named_act == act, "the surface's tables name exactly the tools the servers offer", (sorted(named ^ rec), sorted(named_act ^ act)))
    miss = sorted([f"records:{t}" for t in rec if f"records:{t}" not in called] + [f"activity:{t}" for t in act if f"activity:{t}" not in called])
    check(not miss, "every tool was called and answered ok in these proofs", miss)
    table = surface.split("## Coverage")[1]
    rows = re.findall(r"^\| ([^|]+) \| ([^|]+) \|$", table, re.M)
    qs = 0
    for q, ts in rows:
        names = re.findall(r"`([a-z_]+)`", ts)
        if not names or q.strip().startswith("Questions"):
            continue
        qs += 1
        check(all(n in rec | act and (f"records:{n}" in called or f"activity:{n}" in called) for n in names), f"question {q.strip()}: {', '.join(names)}", [n for n in names if n not in rec | act])
    check(qs >= 22, f"{qs} rows of the coverage table, every question 1–21 and A1–A6 among them", qs)
    return done("coverage")


if __name__ == "__main__":
    sys.exit(run(main()))
