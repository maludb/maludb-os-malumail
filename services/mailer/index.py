"""One message into the index (docs/build-specs/mail-service.md §3.2): the fetched envelope items of a message turned into the
values of a mail_messages row, the envelope digest computed exactly as db/008's ml_envelope_digest() computes it (a proof compares the
two), the message key left to ml_message_key_for() in SQL. Pure functions: no IMAP, no database.

What is decided here, from the headers alone:
- dmarc_pass  — DMARC_POLICY_ALLOW in X-Spamd-Result; NULL when the header is absent (internal mail never carries one — research 04 §B)
- spam_score  — X-Spamd-Result's first figure
- is_list     — any List-* header, or Precedence bulk/list/junk;  auto_submitted — the header's value;  return_path_null — Return-Path: <>
- local_submission — no X-Spamd-Result, no Authentication-Results, and the TOPMOST Received line added by MaluMail's own server
  `with ESMTPSA` (the domain of ML_IMAP_HOST). It proves an internal sender only once ml_settings.mm3_identity_enforced is on (db/010).
"""

from __future__ import annotations

import datetime as dt
import hashlib
import re
from email.parser import BytesHeaderParser
from email.policy import default as POLICY
from email.utils import getaddresses, parsedate_to_datetime

from services.mailer import imap

_WS = re.compile(r"\s+")
_ID = re.compile(r"<[^<>\s]+>")


def _btrim(s: str) -> str:
    return s.strip(" ")                                   # PostgreSQL's btrim(text) trims spaces only


def envelope_digest(date: str | None, frm: str | None, to: str | None, cc: str | None, subject: str | None) -> str:
    """SHA-256 over Date, From, To, Cc and Subject as db/008's ml_envelope_digest() joins them (chr(31))."""
    parts = [
        _WS.sub(" ", _btrim(date or "")),
        _btrim(frm or "").lower(),
        _WS.sub(" ", _btrim(to or "")).lower(),
        _WS.sub(" ", _btrim(cc or "")).lower(),
        _WS.sub(" ", _btrim(subject or "")),
    ]
    return hashlib.sha256("\x1f".join(parts).encode("utf-8")).hexdigest()


def _hval(msg, name: str) -> str | None:
    v = msg.get(name)
    if v is None:
        return None
    try:
        return str(v)
    except Exception:  # noqa: BLE001 — a header the policy cannot render: its raw text
        return str(msg.get_all(name, [""])[0])


def _addrs(*values: str | None) -> list[tuple[str, str]]:
    raw = [v for v in values if v]
    if not raw:
        return []
    out = []
    for name, addr in getaddresses(raw):
        addr = (addr or "").strip().lower()
        if addr and "@" in addr:
            out.append((name.strip(), addr))
    return out


def _dt(value) -> dt.datetime | None:
    if isinstance(value, dt.datetime):
        return value if value.tzinfo else value.replace(tzinfo=dt.timezone.utc)
    return None


def _flags(raw_flags) -> list[str]:
    return [imap._text(f) for f in (raw_flags or ())]


def _header_bytes(data: dict) -> bytes:
    for k, v in data.items():
        if imap._text(k).upper().startswith("BODY[HEADER"):
            return v if isinstance(v, bytes) else b""
    return b""


def parse(uid: int, data: dict, imap_host: str) -> dict:
    """The row values for one fetched message (data = IMAPClient's fetch dict for it)."""
    hdr = BytesHeaderParser(policy=POLICY).parsebytes(_header_bytes(data))
    date, frm = _hval(hdr, "Date"), _hval(hdr, "From")
    to, cc, bcc = _hval(hdr, "To"), _hval(hdr, "Cc"), _hval(hdr, "Bcc")
    subject = _hval(hdr, "Subject")
    froms = _addrs(frm)
    names: dict[str, str] = {}
    for n, a in _addrs(frm, to, cc, bcc):
        if n:
            names.setdefault(a, n)
    sent_at = None
    if date:
        try:
            sent_at = parsedate_to_datetime(date)
            if sent_at is not None and sent_at.tzinfo is None:
                sent_at = sent_at.replace(tzinfo=dt.timezone.utc)
        except (TypeError, ValueError, IndexError):
            sent_at = None
    spamd = _hval(hdr, "X-Spamd-Result")
    dmarc = None if spamd is None else ("DMARC_POLICY_ALLOW" in spamd)
    score = None
    if spamd:
        m = re.search(r"\[\s*(-?\d+(?:\.\d+)?)", spamd)
        score = float(m.group(1)) if m else None
    precedence = (_hval(hdr, "Precedence") or "").strip().lower()
    is_list = any(hdr.get(h) is not None for h in ("List-Id", "List-Unsubscribe")) or precedence in ("bulk", "list", "junk")
    auto = _hval(hdr, "Auto-Submitted")
    rp = _hval(hdr, "Return-Path")
    received = hdr.get_all("Received") or []
    host_domain = ".".join((imap_host or "").lower().split(".")[-2:]) if imap_host and "." in imap_host else (imap_host or "").lower()
    top = str(received[0]).lower() if received else ""
    local = (spamd is None and hdr.get("Authentication-Results") is None and "with esmtpsa" in top
             and bool(host_domain) and host_domain in top)
    bs = data.get(b"BODYSTRUCTURE")
    leaves = imap.walk_structure(bs) if bs is not None else []
    att = imap.attachments_of(leaves)
    preview = data.get(b"PREVIEW")
    snippet = imap._text(preview).strip()[:200] if preview else None
    flags = _flags(data.get(b"FLAGS"))
    modseq = data.get(b"MODSEQ")
    mid = (_hval(hdr, "Message-ID") or "").strip() or None
    irt = _ID.findall(_hval(hdr, "In-Reply-To") or "")
    refs = _ID.findall(_hval(hdr, "References") or "")
    sender = _addrs(_hval(hdr, "Sender"))
    return {
        "uid": int(uid),
        "modseq": int(modseq[0]) if modseq else None,
        "message_id_header": mid,
        "digest": envelope_digest(date, frm, to, cc, subject),
        "in_reply_to": irt[0] if irt else None,
        "references_ids": refs,
        "from_address": froms[0][1] if froms else None,
        "from_name": (froms[0][0] or None) if froms else None,
        "sender_address": sender[0][1] if sender else None,
        "to_addresses": [a for _n, a in _addrs(to)],
        "cc_addresses": [a for _n, a in _addrs(cc)],
        "bcc_addresses": [a for _n, a in _addrs(bcc)],
        "recipient_names": names,
        "reply_to": [a for _n, a in _addrs(_hval(hdr, "Reply-To"))],
        "subject": subject,
        "sent_at": sent_at,
        "received_at": _dt(data.get(b"INTERNALDATE")) or sent_at or dt.datetime.now(dt.timezone.utc),
        "size": int(data.get(b"RFC822.SIZE") or 0),
        "seen": "\\Seen" in flags, "flagged": "\\Flagged" in flags, "answered": "\\Answered" in flags, "draft": "\\Draft" in flags,
        "keywords": [f for f in flags if not f.startswith("\\")],
        "snippet": snippet,
        "has_attachments": bool(att),
        "attachment_names": att,
        "dmarc_pass": dmarc,
        "spam_score": score,
        "auto_submitted": auto.strip().lower() if auto else None,
        "is_list": is_list,
        "return_path_null": rp is not None and rp.strip() in ("<>", ""),
        "local_submission": local,
        "text_part": imap.text_part_of(leaves),
    }


def flags_of(raw_flags) -> dict:
    f = _flags(raw_flags)
    return {"seen": "\\Seen" in f, "flagged": "\\Flagged" in f, "answered": "\\Answered" in f, "draft": "\\Draft" in f,
            "keywords": [x for x in f if not x.startswith("\\")]}


SPECIAL = {"\\Sent": "sent", "\\Drafts": "drafts", "\\Junk": "junk", "\\Trash": "trash", "\\Archive": "archive"}


def special_use(name: str, flags: list[str]) -> str | None:
    if name.upper() == "INBOX":
        return "inbox"
    for f in flags:
        if f in SPECIAL:
            return SPECIAL[f]
    if name == "Archive":
        return "archive"
    if name == "Snoozed":
        return "snoozed"
    return None
