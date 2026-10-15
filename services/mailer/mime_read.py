"""Reading one message on a pool connection (docs/build-specs/mail-service.md §4; reading-and-rendering.md): the header summary, the part
list with decoded sizes, the HTML part through the sanitiser (with the part and image links minted here), the text part for an agent
(through extract.py), one part or the whole message streamed in chunks. Every fetch is a PEEK. Nothing here writes; nothing here
decides inline or attachment (PHP's door does, from the bytes).

The links (mail-service.md §4, decision 3): base64url(JSON) "." hex HMAC-SHA256(ML_PART_KEY, the base64url text), the JSON
{"k": "part" | "img", "m": mailbox, "r": "folder.uidvalidity.uid", "p": part or the image's index, "a": member, "e": expiry unix}
(+ "u": the image's URL for an img link), valid one hour, verified by PHP's /p/ and /img/ doors alone.
"""

from __future__ import annotations

import base64
import collections
import hashlib
import hmac
import json
import re
import threading
import time
from email.header import decode_header, make_header
from email.parser import BytesHeaderParser
from email.policy import default as POLICY
from email.utils import getaddresses, parsedate_to_datetime

from services.mailer import extract, imap, sanitise

LINK_TTL = 3600
HTML_MAX = 2 * 1024 * 1024
TEXT_MAX = 100_000
CHUNK = 1024 * 1024
PLACEHOLDER = "/assets/mail/remote-image.svg"


# ---- links ------------------------------------------------------------------------------------------------------------------------

def _b64(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode().rstrip("=")


def sign_link(key: bytes, kind: str, mailbox: int, ref: str, part: str, member: int, url: str | None = None, ttl: int = LINK_TTL) -> str:
    body = {"k": kind, "m": int(mailbox), "r": ref, "p": str(part), "a": int(member), "e": int(time.time()) + ttl}
    if url is not None:
        body["u"] = url
    text = _b64(json.dumps(body, separators=(",", ":"), sort_keys=True).encode())
    return text + "." + hmac.new(key, text.encode(), hashlib.sha256).hexdigest()


def verify_link(key: bytes, token: str, now: float | None = None) -> dict | None:
    """The link's body when its MAC and expiry hold (the proofs use it; PHP's doors are the verifiers in production)."""
    if "." not in token:
        return None
    text, mac = token.rsplit(".", 1)
    if not hmac.compare_digest(hmac.new(key, text.encode(), hashlib.sha256).hexdigest(), mac):
        return None
    try:
        body = json.loads(base64.urlsafe_b64decode(text + "=" * (-len(text) % 4)))
    except (ValueError, TypeError):
        return None
    if not isinstance(body, dict) or int(body.get("e", 0)) < (time.time() if now is None else now):
        return None
    return body


# ---- headers ----------------------------------------------------------------------------------------------------------------------

_SPAMD_SCORE = re.compile(r"\[\s*(-?\d+(?:\.\d+)?)")


def _hval(msg, name: str) -> str | None:
    v = msg.get(name)
    if v is None:
        return None
    try:
        return str(v)
    except Exception:  # noqa: BLE001
        return str(msg.get_all(name, [""])[0])


def _addr_list(value: str | None) -> list[dict]:
    if not value:
        return []
    out = []
    for name, addr in getaddresses([value]):
        addr = (addr or "").strip().lower()
        if addr:
            out.append({"name": name.strip() or None, "address": addr})
    return out


def _decoded(value: str | None) -> str | None:
    if value is None:
        return None
    try:
        return str(make_header(decode_header(value)))
    except Exception:  # noqa: BLE001
        return value


def header_summary(raw_header: bytes) -> dict:
    """The headers that matter, as parsed values (mail-service.md §4 `headers`)."""
    hdr = BytesHeaderParser(policy=POLICY).parsebytes(raw_header)
    spamd = _hval(hdr, "X-Spamd-Result")
    score = None
    if spamd:
        m = _SPAMD_SCORE.search(spamd)
        score = float(m.group(1)) if m else None
    date = _hval(hdr, "Date")
    when = None
    if date:
        try:
            when = parsedate_to_datetime(date).isoformat()
        except (TypeError, ValueError, IndexError):
            when = None
    ids = re.compile(r"<[^<>\s]+>")
    return {
        "from": _addr_list(_hval(hdr, "From")),
        "sender": _addr_list(_hval(hdr, "Sender")),
        "reply_to": _addr_list(_hval(hdr, "Reply-To")),
        "to": _addr_list(_hval(hdr, "To")),
        "cc": _addr_list(_hval(hdr, "Cc")),
        "date": date, "sent_at": when,
        "subject": _hval(hdr, "Subject"),
        "message_id": (_hval(hdr, "Message-ID") or "").strip() or None,
        "in_reply_to": ids.findall(_hval(hdr, "In-Reply-To") or ""),
        "references": ids.findall(_hval(hdr, "References") or ""),
        "dmarc": None if spamd is None else ("pass" if "DMARC_POLICY_ALLOW" in spamd else "fail"),
        "spam_score": score,
        "spamd_result": (spamd or "")[:500] or None,
        "list_id": _hval(hdr, "List-Id"), "list_unsubscribe": _hval(hdr, "List-Unsubscribe"),
        "auto_submitted": _hval(hdr, "Auto-Submitted"), "precedence": _hval(hdr, "Precedence"),
        "x_os_agent": _hval(hdr, "X-OS-Agent"),
        "authentication_results": (_hval(hdr, "Authentication-Results") or "")[:500] or None,
    }


def raw_headers_list(raw_header: bytes) -> list[list[str]]:
    """Every header as [name, value] for the Original page (folded lines joined), decoded."""
    out = []
    head = raw_header.split(b"\r\n\r\n", 1)[0].replace(b"\r\n", b"\n")
    cur = None
    for line in head.split(b"\n"):
        if line[:1] in (b" ", b"\t") and cur is not None:
            cur[1] += " " + line.strip().decode("utf-8", "replace")
            continue
        if cur is not None:
            out.append(cur)
        name, _, value = line.partition(b":")
        cur = [name.decode("utf-8", "replace"), value.strip().decode("utf-8", "replace")]
    if cur is not None and cur[0]:
        out.append(cur)
    return [[n, _decoded(v) or v] for n, v in out]


# ---- parts --------------------------------------------------------------------------------------------------------------------

def _leaf_info(lf: dict) -> dict:
    return {"part": lf["part"], "type": f"{lf['type']}/{lf['subtype']}", "name": _decoded(lf.get("filename")) if lf.get("filename") else None,
            "disposition": lf.get("disposition"), "size": lf.get("size", 0), "encoded_size": lf.get("size", 0),
            "content_id": lf.get("content_id"), "charset": (lf.get("params") or {}).get("charset")}


def structure_and_header(c, uid: int) -> tuple[dict, list[dict], bytes] | None:
    """BODYSTRUCTURE and BODY.PEEK[HEADER] of one message on the selected folder → (summary, leaves, raw header), or None when gone."""
    res = c.fetch([uid], ["BODYSTRUCTURE", "BODY.PEEK[HEADER]"])
    d = res.get(uid)
    if not d:
        return None
    raw_header = b""
    for k, v in d.items():
        if imap._text(k).upper().startswith("BODY[HEADER"):
            raw_header = v if isinstance(v, bytes) else b""
    bs = d.get(b"BODYSTRUCTURE")
    leaves = imap.walk_structure(bs) if bs is not None else []
    return header_summary(raw_header), [_leaf_info(lf) for lf in leaves], raw_header


def decoded_sizes(c, uid: int, parts: list[dict]) -> None:
    """BINARY.SIZE for every leaf, in one command (research 04 §B: decoded sizes)."""
    if not parts:
        return
    items = [f"BINARY.SIZE[{p['part']}]" for p in parts]
    try:
        res = c.fetch([uid], items)
    except Exception:  # noqa: BLE001 — a server without BINARY: the encoded size stands
        return
    d = res.get(uid) or {}
    sizes = {}
    for k, v in d.items():
        ks = imap._text(k).upper()
        if ks.startswith("BINARY.SIZE["):
            sizes[ks[len("BINARY.SIZE["):-1]] = int(v)
    for p in parts:
        if p["part"] in sizes:
            p["size"] = sizes[p["part"]]


def fetch_part_bytes(c, uid: int, part: str, start: int = 0, length: int | None = None, binary: bool = True) -> bytes:
    """BINARY.PEEK[part]<start.length> (decoded) — or BODY.PEEK[part]<…> when binary is False — of one message."""
    item = ("BINARY.PEEK" if binary else "BODY.PEEK") + f"[{part}]" + (f"<{start}.{length}>" if length is not None else "")
    res = c.fetch([uid], [item])
    d = res.get(uid) or {}
    want = ("BINARY" if binary else "BODY") + f"[{part}]"
    for k, v in d.items():
        ks = imap._text(k).upper()
        if ks.startswith(want.upper()):
            return v if isinstance(v, bytes) else (b"" if v is None else str(v).encode())
    return b""


def stream_part(c, uid: int, part: str, size: int | None, binary: bool = True):
    """The part's bytes in CHUNK-sized partials — never held whole (reading-and-rendering.md decision 7)."""
    offset = 0
    while True:
        chunk = fetch_part_bytes(c, uid, part, offset, CHUNK, binary)
        if not chunk:
            return
        yield chunk
        offset += len(chunk)
        if len(chunk) < CHUNK or (size is not None and offset >= size):
            return


def decode_text(data: bytes, charset: str | None) -> str:
    try:
        return data.decode(charset or "utf-8", errors="replace")
    except LookupError:
        return data.decode("utf-8", errors="replace")


def text_leaf(parts: list[dict], want: str) -> dict | None:
    for p in parts:
        if p["type"] == f"text/{want}" and p.get("disposition") != "attachment":
            return p
    return None


# ---- the forms -----------------------------------------------------------------------------------------------------------------

class BodyCache:
    """Sanitised bodies, in memory, at most 64 MB, keyed by (mailbox, ref, images, profile, member) — a link is bound to its member."""

    def __init__(self, limit: int = 64 * 1024 * 1024):
        self.limit, self.size = limit, 0
        self.items: collections.OrderedDict = collections.OrderedDict()
        self.lock = threading.Lock()

    def get(self, key):
        with self.lock:
            v = self.items.get(key)
            if v is not None:
                self.items.move_to_end(key)
            return v

    def put(self, key, value: dict) -> None:
        n = len(value.get("html") or "")
        with self.lock:
            if key in self.items:
                self.size -= len(self.items.pop(key).get("html") or "")
            self.items[key] = value
            self.size += n
            while self.size > self.limit and self.items:
                _k, old = self.items.popitem(last=False)
                self.size -= len(old.get("html") or "")

    def invalidate(self, mailbox: int) -> None:
        with self.lock:
            for k in [k for k in self.items if k[0] == mailbox]:
                self.size -= len(self.items.pop(k).get("html") or "")


def html_form(c, uid: int, parts: list[dict], key: bytes, mailbox: int, ref: str, member: int, images: str, profile: str) -> dict:
    """The HTML part through the sanitiser (reading-and-rendering.md §1 with slice 3's link-host addition)."""
    leaf = text_leaf(parts, "html")
    if leaf is None:
        return {"html": None}
    if int(leaf.get("size") or 0) > HTML_MAX:
        return {"html": None, "html_too_large": True}
    raw = fetch_part_bytes(c, uid, leaf["part"])
    if len(raw) > HTML_MAX:
        return {"html": None, "html_too_large": True}
    html = decode_text(raw, leaf.get("charset"))
    by_cid = {}
    for p in parts:
        cid = p.get("content_id")
        if cid:
            by_cid[cid.strip("<>")] = p["part"]
    counter = {"n": 0}

    def cid_url(content_id: str):
        if profile != "reading":
            return None
        part = by_cid.get(content_id.strip("<>"))
        return None if part is None else "/p/" + sign_link(key, "part", mailbox, ref, part, member)

    def remote_image_url(url: str):
        if profile != "reading":
            return None
        counter["n"] += 1
        if images != "show":
            return PLACEHOLDER
        return "/img/" + sign_link(key, "img", mailbox, ref, str(counter["n"]), member, url=url)

    s = sanitise.sanitise(html, cid_url=cid_url, remote_image_url=remote_image_url)
    return {"html": s.html, "hidden_removed": s.hidden_removed, "remote_images": len(s.remote_images), "pixels_dropped": s.pixels_dropped,
            "deceptive_links": s.deceptive_links, "cid_refs": s.cid_refs, "unstable": s.unstable, "images_shown": images == "show"}


def text_form(c, uid: int, parts: list[dict], include_quoted: bool, limit: int = TEXT_MAX) -> dict:
    """The agent's text (reading-and-rendering.md §6, extract.py): the plain part when it is there and not empty, else the HTML parsed
    for text only. Never rendered."""
    plain = text_leaf(parts, "plain")
    html = text_leaf(parts, "html")
    chosen, is_html = None, False
    if plain is not None:
        raw = fetch_part_bytes(c, uid, plain["part"])
        text = decode_text(raw, plain.get("charset"))
        if text.strip():
            chosen = text
    if chosen is None and html is not None:
        raw = fetch_part_bytes(c, uid, html["part"])
        chosen, is_html = decode_text(raw, html.get("charset")), True
    if chosen is None:
        return {"text": "", "links": [], "hidden_removed": 0, "quoted_removed": False, "signature_removed": False, "truncated": False}
    return extract.extract(chosen, is_html, include_quoted=include_quoted, limit=limit)


def plain_form(c, uid: int, parts: list[dict]) -> dict:
    """A message with no HTML part: its plain text, escaped by PHP in the page (decision 4). Capped at HTML_MAX characters."""
    plain = text_leaf(parts, "plain")
    if plain is None:
        return {"text": None}
    raw = fetch_part_bytes(c, uid, plain["part"])
    text = decode_text(raw, plain.get("charset"))
    return {"text": text[:HTML_MAX], "truncated": len(text) > HTML_MAX}
