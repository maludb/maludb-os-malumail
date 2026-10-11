"""
The sanitiser's static proof (design §6.4, layer one; research 04 Part A). Run with `mcp/venv/bin/python -I` from
tests/sanitiser/run.sh, which then hands the JSON this writes to browser.mjs.

  1. DOMPurify 3.4.16's 223 cases (corpus/dompurify, read as data) each through `sanitise()`: no exception; the output walked
     by an independent parser against the allow-lists and against rules of its own (no handler, no element outside the list, a
     link only http/https/mailto, an image only from the URLs the sanitiser wrote or a raster data URI, no CSS that loads or
     executes); and a FIXED POINT — the final pass over the output gives the output (the parser-differential rule).
  2. The corpus covers every attack family the reading spec names (counted on the raw payloads).
  3. The benign corpus (corpus/benign — ordinary business mail): what a person should see survives, what should go goes.
  4. Timings at about 20 kB, 200 kB and 2 MB.

Prints ok/FAIL per check; exits non-zero on any failure. argv[1]: where to write the cases for the browser.
"""

import json
import os
import re
import statistics
import sys
import time
from html.parser import HTMLParser
from urllib.parse import quote

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, ROOT)
sys.path.insert(0, HERE)

from services.mailer import sanitise as S  # noqa: E402
from read_dompurify import cases  # noqa: E402

passed = failed = 0


def check(cond: bool, label: str, detail: str = "") -> None:
    global passed, failed
    if cond:
        passed += 1
        print(f"ok   {label}")
    else:
        failed += 1
        print(f"FAIL {label}" + (f" — {detail}" if detail else ""))


class Images:
    """The callbacks a caller gives: the part door for cid:, a numbered placeholder for each remote image."""

    def __init__(self):
        self.n = 0

    def cid(self, cid: str) -> str:
        return "/m/1/part/" + quote(cid, safe="")

    def remote(self, url: str) -> str:
        self.n += 1
        return f"/assets/mail/remote-image.svg?n={self.n}"


def clean(html: str) -> S.Sanitised:
    im = Images()
    return S.sanitise(html, cid_url=im.cid, remote_image_url=im.remote)


# ---- an independent walk of the output ------------------------------------------------------------------------------------------

_BAD_CSS = re.compile(r"url\s*\(|expression|javascript|vbscript|\\|@import|binding|behavior|/\*|position\s*:|z-index", re.I)
_LINK = re.compile(r"(https?:|mailto:|#)", re.I)
_RASTER = re.compile(r"data:image/(png|gif|jpeg|jpg|webp)[;,]", re.I)


class Walk(HTMLParser):
    def __init__(self, image_urls):
        super().__init__(convert_charrefs=True)
        self.images, self.problems = image_urls, []

    def handle_starttag(self, tag, attrs):
        if tag not in S.TAGS:
            self.problems.append(f"element <{tag}>")
        for name, value in attrs:
            value = value or ""
            allowed = name in S.GENERIC_ATTRIBUTES or name in S.ATTRIBUTES.get(tag, ()) or (tag == "a" and name in ("target", "rel"))
            if name.startswith("on") or not allowed:
                self.problems.append(f"attribute {tag}[{name}]")
            if name == "href" and not _LINK.match(value):
                self.problems.append(f"href {value[:40]!r}")
            if name == "src" and value not in self.images and not _RASTER.match(value):
                self.problems.append(f"src {value[:40]!r}")
            if name == "style":
                if _BAD_CSS.search(value):
                    self.problems.append(f"style {value[:60]!r}")
                for decl in filter(None, (d.strip() for d in value.split(";"))):
                    prop = decl.split(":", 1)[0].strip().lower()
                    if prop not in S.CSS_PROPERTIES:
                        self.problems.append(f"css property {prop!r}")
            if tag == "a" and name == "target" and value != "_blank":
                self.problems.append("link target")

    handle_startendtag = handle_starttag

    def handle_comment(self, data):
        self.problems.append("comment")

    def handle_decl(self, decl):
        self.problems.append("declaration")

    def handle_pi(self, data):
        self.problems.append("processing instruction")

    def unknown_decl(self, data):
        self.problems.append("CDATA")


def walk(r: S.Sanitised) -> list[str]:
    w = Walk(r.image_urls)
    w.feed(r.html)
    w.close()
    return w.problems


# ---- 1. DOMPurify's cases -------------------------------------------------------------------------------------------------------

corpus = cases(os.path.join(HERE, "corpus", "dompurify", "expect.mjs"))
check(len(corpus) == 223, f"DOMPurify 3.4.16's cases read as data: {len(corpus)}")
out_cases, errors, dirty, moving, unstable = [], [], [], [], []
for c in corpus:
    try:
        r = clean(c["payload"])
    except Exception as e:  # noqa: BLE001
        errors.append(f"#{c['n']}: {e}")
        continue
    p = walk(r)
    if p:
        dirty.append(f"#{c['n']}: {p[:3]}")
    if S.final_pass(r.html, r.image_urls) != r.html:
        moving.append(f"#{c['n']}")
    if r.unstable:
        unstable.append(f"#{c['n']}")
    out_cases.append({"id": f"d{c['n']}", "title": c["title"], "raw": c["payload"], "clean": r.html, "images": sorted(r.image_urls)})
check(not errors, "every case sanitises without an exception", "; ".join(errors[:5]))
check(not dirty, "every output holds only allowed elements, attributes, links, image sources and CSS", "; ".join(dirty[:5]))
check(not moving, "every output is a fixed point of the final pass (re-parses to itself)", ", ".join(moving[:10]))
print(f"     {len(unstable)} case(s) never settled and kept their text alone" + (f": {', '.join(unstable)}" if unstable else ""))
emptied = sum(1 for o in out_cases if not o["clean"].strip())
print(f"     {emptied} of {len(out_cases)} outputs are empty; {sum(1 for o in out_cases if o['clean'] == o['raw'])} unchanged")

# ---- 2. the families the reading spec names, on the raw payloads ---------------------------------------------------------------

families = {
    "script elements": r"<script",
    "event attributes": r"\son[a-z]+\s*=",
    "dangerous URL schemes": r"javascript:|vbscript:|data:text/html",
    "CSS that loads or executes": r"url\s*\(|expression\s*\(|@import|-moz-binding|behavior\s*:",
    "embedded documents": r"<(iframe|object|embed|frame)\b",
    "SVG": r"<svg",
    "MathML": r"<math",
    "forms": r"<(form|input|button|textarea|select)\b",
    "base and meta": r"<(base|meta)\b",
    "parser differentials (noscript, template, style, CDATA, comments in foreign content)": r"<noscript|<template|<!\[CDATA\[|<!--|<style",
}
for name, rx in families.items():
    n = sum(1 for c in corpus if re.search(rx, c["payload"], re.I))
    check(n > 0, f"the corpus covers {name}: {n} case(s)")

# ---- 3. the benign corpus --------------------------------------------------------------------------------------------------------

def benign(name: str):
    raw = open(os.path.join(HERE, "corpus", "benign", name), encoding="utf-8").read()
    r = clean(raw)
    out_cases.append({"id": "b-" + name.removesuffix(".html"), "title": name, "raw": raw, "clean": r.html, "images": sorted(r.image_urls)})
    check(not walk(r) and S.final_pass(r.html, r.image_urls) == r.html and not r.unstable,
          f"{name}: clean by the walk, a fixed point, stable", "; ".join(walk(r)[:3]))
    return raw, r


raw, r = benign("invoice.html")
check(r.cid_refs == ["logo.png@01DA1C2B"] and 'src="/m/1/part/logo.png%4001DA1C2B"' in r.html, "invoice: the cid: logo is shown through the part door")
check(r.html.count("<td") == raw.count("<td") and r.html.count("<tr") == raw.count("<tr"), "invoice: every table row and cell is kept")
check("Total due by 2026-11-10" in r.html and "$234.50" in r.html and 'bgcolor="#f4f4f4"' in r.html, "invoice: the totals and the colours are kept")
check('href="mailto:billing@northwind.example?subject=INV-20431"' in r.html and r.html.count('target="_blank"') == 2
      and r.html.count('rel="noopener noreferrer"') == 2, "invoice: both links kept, each opening a new tab with noopener noreferrer")
check(not r.remote_images and r.pixels_dropped == 0 and r.hidden_removed == 0, "invoice: nothing remote, nothing hidden")

raw, r = benign("newsletter.html")
check(r.hidden_removed == 1 and "autumn catalogue is here" not in r.html, "newsletter: the hidden preheader is removed and counted")
check("@media" not in r.html and "!important" not in r.html and "<style" not in r.html, "newsletter: the <style> element goes with its content")
check(r.pixels_dropped == 1 and "track.chairs.example" not in r.html, "newsletter: the 1×1 tracking pixel is dropped and its URL is gone")
check(r.remote_images == ["https://img.chairs.example/autumn-hero.jpg", "https://img.chairs.example/fold-lamp.png",
                          "https://img.chairs.example/standing-desk.png"] and "img.chairs.example" not in r.html
      and r.html.count("/assets/mail/remote-image.svg?n=") == 3, "newsletter: three remote images listed in order, each showing a placeholder")
check("Shop the sale" in r.html and 'bgcolor="#1a73e8"' in r.html and "border-radius" in r.html, "newsletter: the button keeps its colour and shape")
check("You receive this because" in r.html and "Unsubscribe" in r.html and "font-size:11px" in r.html.replace(" ", ""),
      "newsletter: the 11px footer and the unsubscribe link are kept (small is not hidden)")

raw, r = benign("outlook-reply.html")
check("Thursday at 10:00 works for us" in r.html and "Could we walk the warehouse floor" in r.html, "outlook: the reply and the quoted message are kept")
check("border-top" in r.html and "<b>From:</b>" in r.html, "outlook: the reply separator and the quoted headers are kept")
check(not re.search(r"<o:p|mso-|@font-face|\[if gte|WordSection", r.html), "outlook: Word's tags, mso- styles and conditional comments are gone")

raw, r = benign("gmail-thread.html")
check(r.html.count("<li>") == 3 and "<blockquote" in r.html and "border-left" in r.html, "gmail: the list and the quoted reply's border are kept")
check(r.remote_images == ["https://ci3.googleusercontent.example/signature/logo.png"] and 'href="mailto:ana@marchetti.example"' in r.html,
      "gmail: the signature image waits behind a placeholder; the mailto link is kept")

raw, r = benign("inline-image.html")
check('src="data:image/png;base64,' in r.html and not r.remote_images, "inline image: a raster data URI is shown as it came")
check('colspan="2"' in r.html and "border-collapse" in r.html and r.html.count("<td") == 6, "inline image: the table keeps its spans and borders")

# ---- 4. timings ------------------------------------------------------------------------------------------------------------------

unit = open(os.path.join(HERE, "corpus", "benign", "newsletter.html"), encoding="utf-8").read()
unit += open(os.path.join(HERE, "corpus", "benign", "invoice.html"), encoding="utf-8").read()
for label, size, runs in (("20 kB", 20_000, 9), ("200 kB", 200_000, 5), ("2 MB", 2_000_000, 3)):
    body = (unit * (size // len(unit) + 1))[:size]
    times = []
    for _ in range(runs):
        t = time.perf_counter()
        clean(body)
        times.append(time.perf_counter() - t)
    ms = statistics.median(times) * 1000
    print(f"     {label}: median {ms:.0f} ms over {runs} runs")
    check(ms < 10_000, f"timing at {label} is under ten seconds ({ms:.0f} ms)")

with open(sys.argv[1], "w", encoding="utf-8") as f:
    json.dump({"tags": sorted(S.TAGS), "generic": sorted(S.GENERIC_ATTRIBUTES),
               "attributes": {t: sorted(a) for t, a in S.ATTRIBUTES.items()}, "cases": out_cases}, f)

print(f"\n{passed} ok, {failed} failed")
sys.exit(1 if failed else 0)
