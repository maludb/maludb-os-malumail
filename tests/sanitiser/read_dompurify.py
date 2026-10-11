"""DOMPurify's test cases (corpus/dompurify/expect.mjs) read as DATA — never executed. The file is `export default [ … ];` with
trailing commas; outside strings those are dropped and the rest is JSON. See corpus/dompurify/SOURCE.md."""

import json


def cases(path: str) -> list[dict]:
    body = open(path, encoding="utf-8").read().strip()
    if not body.startswith("export default ["):
        raise ValueError("not the expected shape: " + body[:40])
    body = body[len("export default "):].rstrip().rstrip(";").rstrip()
    out, i, n, in_str = [], 0, len(body), False
    while i < n:
        c = body[i]
        if in_str:
            out.append(c)
            if c == "\\":
                out.append(body[i + 1])
                i += 2
                continue
            if c == '"':
                in_str = False
            i += 1
            continue
        if c == '"':
            in_str = True
        elif c == ",":
            j = i + 1
            while j < n and body[j] in " \t\r\n":
                j += 1
            if j < n and body[j] in "]}":
                i += 1
                continue
        out.append(c)
        i += 1
    data = json.loads("".join(out))
    return [{"n": k + 1, "title": c.get("title") or "", "payload": c["payload"]} for k, c in enumerate(data)]
