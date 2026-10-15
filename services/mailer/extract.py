"""The agent's side of a message (reading-and-rendering.md §6): text only, the quoted history and the signature cut, hidden content
removed and counted, every link as `text [→ host]`. Reads the raw text or HTML part, never the sanitised HTML (the sanitiser drops
`class`, which is how Gmail and Yahoo mark quotes — decision 5). Nothing it produces is ever rendered; the records server fences it.
"""

from __future__ import annotations

import html as _html
import re
from html.parser import HTMLParser
from urllib.parse import urlsplit

from services.mailer import sanitise

_ON_WROTE = re.compile(r"^\s*(On\s.+\swrote:|Le\s.+a\s+écrit\s*:|Am\s.+schrieb\s.*:|El\s.+escribió\s*:)\s*$", re.I)
_ORIGINAL = re.compile(r"^\s*-{2,}\s*(Original Message|Ursprüngliche Nachricht|Message d'origine|Mensaje original)\s*-{2,}\s*$", re.I)
_HEADER_BLOCK = re.compile(r"^\s*\**(From|Sent|To|Subject|Date|Cc)\**\s*:", re.I)
_SIG_LINE = re.compile(r"^-- ?$")
_URLISH = re.compile(r"^\S+\.[a-z]{2,}\b", re.I)
_WS = re.compile(r"[ \t\r\f\v]+")
_NL = re.compile(r"\n{3,}")
_HTML_QUOTE_CLASSES = ("gmail_quote", "yahoo_quoted", "moz-cite-prefix", "protonmail_quote")
_HTML_QUOTE_IDS = ("divrplyfwdmsg", "appendonsend", "ms-outlook-mobile-signature")
_OUTLOOK_SEP = re.compile(r"^\s*border\s*:\s*none\s*;\s*border-top\s*:\s*solid", re.I)
_VOID = sanitise.VOID | {"meta", "link", "base", "input", "area", "source", "track", "param", "embed", "keygen", "o:p"}


def _host(url: str) -> str:
    try:
        return (urlsplit(url).hostname or "").lower()
    except ValueError:
        return ""


class _Text(HTMLParser):
    """HTML to text, quotes and hidden elements cut on the way, links collected."""

    def __init__(self, include_quoted: bool):
        super().__init__(convert_charrefs=True)
        self.include_quoted = include_quoted
        self.out: list[str] = []
        self.links: list[dict] = []
        self.skip = 0                    # depth inside a cut element (quote, hidden, script…)
        self.hidden_removed = 0
        self.quoted = False
        self.signature = False
        self.stack: list[str] = []
        self._link: dict | None = None
        self._link_text: list[str] = []
        self._pending_outlook = False

    def _cut(self, why: str) -> None:
        self.skip = 1
        if why == "hidden":
            self.hidden_removed += 1
        elif why == "quote":
            self.quoted = True
        elif why == "signature":
            self.signature = True

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if self.skip:
            if tag not in _VOID:
                self.skip += 1
            return
        if tag in _VOID and tag != "br" and tag != "o:p":
            return
        if tag in ("script", "style", "head", "title", "template", "noscript"):
            self.skip = 1
            return
        cls = (a.get("class") or "").lower().split()
        ident = (a.get("id") or "").lower()
        style = a.get("style") or ""
        decls = sanitise._declarations(style) or []
        if "hidden" in a or sanitise._hides(decls):
            self._cut("hidden")
            return
        if not self.include_quoted:
            if any(c in _HTML_QUOTE_CLASSES for c in cls) or ident in _HTML_QUOTE_IDS or (tag == "blockquote" and (a.get("type") or "").lower() == "cite"):
                self._cut("quote")
                return
            if tag == "div" and _OUTLOOK_SEP.match(style):
                self._pending_outlook = True
        if any(c in ("gmail_signature",) for c in cls):
            self._cut("signature")
            return
        if tag == "a":
            self._link = {"href": a.get("href") or ""}
            self._link_text = []
        if tag in ("p", "div", "br", "li", "tr", "h1", "h2", "h3", "h4", "h5", "h6", "blockquote", "table", "ul", "ol", "pre"):
            self.out.append("\n")
        if tag == "o:p":
            return
        if tag == "li":
            self.out.append("- ")
        self.stack.append(tag)

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        if tag in _VOID and self.stack and self.stack[-1] == tag:
            self.stack.pop()

    def handle_endtag(self, tag):
        if tag in _VOID:
            return
        if self.skip:
            self.skip -= 1
            return
        if tag == "a" and self._link is not None:
            text = "".join(self._link_text).strip()
            href = self._link["href"]
            host = _host(href)
            if href and host:
                self.links.append({"text": text[:200], "host": host, "path": (urlsplit(href).path or "/")[:200]})
                self.out.append(f"{text} [→ {host}]" if text else f"[→ {host}]")
            else:
                self.out.append(text)
            self._link = None
        if tag in ("p", "div", "li", "tr", "h1", "h2", "h3", "h4", "h5", "h6", "blockquote", "table", "pre"):
            self.out.append("\n")
        if self.stack and self.stack[-1] == tag:
            self.stack.pop()

    def handle_data(self, data):
        if self.skip:
            return
        if self._link is not None:
            self._link_text.append(data)
            return
        self.out.append(data)


def _cut_text_quotes(lines: list[str]) -> tuple[list[str], bool]:
    """A line `On … wrote:`, `-----Original Message-----`, a From:/Sent:/To:/Subject: block, or a trailing run of `>` lines — first match wins."""
    for i, line in enumerate(lines):
        if _ON_WROTE.match(line) or _ORIGINAL.match(line):
            return lines[:i], True
        if _HEADER_BLOCK.match(line) and i + 1 < len(lines) and _HEADER_BLOCK.match(lines[i + 1]):
            return lines[:i], True
    j = len(lines)
    while j > 0 and (lines[j - 1].strip() == "" or lines[j - 1].lstrip().startswith(">")):
        j -= 1
    if j < len(lines) and any(ln.lstrip().startswith(">") for ln in lines[j:]):
        return lines[:j], True
    return lines, False


def _cut_signature(lines: list[str]) -> tuple[list[str], bool]:
    """The RFC 3676 line `-- ` and what follows — never more than the last 15 lines."""
    start = max(0, len(lines) - 15)
    for i in range(len(lines) - 1, start - 1, -1):
        if _SIG_LINE.match(lines[i]):
            return lines[:i], True
    return lines, False


def _tidy(text: str) -> str:
    text = "\n".join(_WS.sub(" ", ln).rstrip() for ln in text.replace("\r\n", "\n").split("\n"))
    return _NL.sub("\n\n", text).strip()


def extract(content: str, is_html: bool, include_quoted: bool = False, limit: int = 20_000) -> dict:
    links: list[dict] = []
    hidden = 0
    quoted = signature = False
    if is_html:
        p = _Text(include_quoted)
        p.feed(content)
        p.close()
        text = "".join(p.out)
        links, hidden, quoted, signature = p.links, p.hidden_removed, p.quoted, p.signature
        if p._pending_outlook and not include_quoted:
            lines = text.split("\n")
            cut, q = _cut_text_quotes(lines)
            if q:
                text, quoted = "\n".join(cut), True
    else:
        text = content
        for m in re.finditer(r"https?://[^\s<>\"')\]]+", content):
            links.append({"text": m.group(0)[:200], "host": _host(m.group(0)), "path": (urlsplit(m.group(0)).path or "/")[:200]})
    lines = text.replace("\r\n", "\n").split("\n")
    if not include_quoted:
        lines, q = _cut_text_quotes(lines)
        quoted = quoted or q
        # the RFC 3676 `-- ` rule only once the history is gone: Gmail writes the signature BEFORE the quote, and with the quote kept
        # the line would cut it too (the element-level cut above still takes a gmail_signature)
        lines, s = _cut_signature(lines)
        signature = signature or s
    out = _tidy("\n".join(lines))
    truncated = len(out) > limit
    return {"text": out[:limit], "links": links[:200], "hidden_removed": hidden, "quoted_removed": quoted,
            "signature_removed": signature, "truncated": truncated}
