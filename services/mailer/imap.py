"""The IMAP side of the mail service, as far as Phase 0 needs it: one PROBE — connect over TLS with the certificate checked, log in
with a mailbox's opened credential, read the capabilities, the folders and INBOX's counts, log out. Slice 2's `mail-service` spec
grows this into the sync (QRESYNC, NOTIFY/IDLE, the text pass) on the same connection code.

What a login's failure MEANS decides the circuit breaker (design §6.1): `auth_failed` is the one outcome that stops all use of the
credential; a server saying it is busy (`[UNAVAILABLE]`, `[INUSE]`, `[LIMIT]`), a refused or failed connection and a certificate
that does not check out back off instead. A password never appears in an outcome, a log line or an exception's text.

Runs in a worker thread (IMAPClient is blocking); research 04 §B measured IMAPClient 3.0.1 against MaluMail's server.
"""

from __future__ import annotations

import socket
import ssl
from dataclasses import dataclass, field

import imapclient
from imapclient.exceptions import IMAPClientError, LoginError

BUSY_CODES = ("[UNAVAILABLE]", "[INUSE]", "[LIMIT]")


@dataclass
class Outcome:
    kind: str                                   # ok · auth_failed · busy · connect_failed · tls_failed · protocol
    detail: str = ""
    capabilities: list[str] = field(default_factory=list)
    folders: list[dict] = field(default_factory=list)
    inbox: dict = field(default_factory=dict)


def tls_context(ca_file: str | None) -> ssl.SSLContext:
    ctx = ssl.create_default_context(cafile=ca_file) if ca_file else ssl.create_default_context()
    ctx.check_hostname = True
    ctx.verify_mode = ssl.CERT_REQUIRED
    ctx.minimum_version = ssl.TLSVersion.TLSv1_2
    return ctx


def _text(value) -> str:
    return value.decode("utf-8", "replace") if isinstance(value, bytes) else str(value)


def probe(host: str, port: int, address: str, password: str, ca_file: str | None, timeout: float = 20.0) -> Outcome:
    try:
        client = imapclient.IMAPClient(host, port=port, ssl=True, ssl_context=tls_context(ca_file), timeout=timeout)
    except ssl.SSLCertVerificationError as e:
        return Outcome("tls_failed", f"the server's certificate did not check out ({e.verify_message})")
    except ssl.SSLError as e:
        return Outcome("tls_failed", f"TLS failed ({e.reason or type(e).__name__})")
    except (OSError, socket.timeout) as e:
        return Outcome("connect_failed", f"could not connect ({type(e).__name__})")
    try:
        try:
            client.login(address, password)
        except LoginError as e:
            said = _text(e.args[0] if e.args else "")[:200]
            if any(code in said.upper() for code in BUSY_CODES):
                return Outcome("busy", f"the server is busy: {said}")
            return Outcome("auth_failed", f"the server refused the credential: {said}")
        caps = sorted(_text(c) for c in client.capabilities())
        folders = []
        for flags, _delim, name in client.list_folders():
            folders.append({"name": _text(name), "flags": sorted(_text(f) for f in flags)})
        items = ["MESSAGES", "UIDNEXT", "UIDVALIDITY", "UNSEEN"] + (["HIGHESTMODSEQ"] if "CONDSTORE" in caps else [])
        status = client.folder_status("INBOX", items)
        inbox = {_text(k).lower(): int(v) for k, v in status.items()}
        try:
            client.logout()
        except (IMAPClientError, OSError):
            pass
        return Outcome("ok", "", caps, folders, inbox)
    except (IMAPClientError, OSError, socket.timeout) as e:
        return Outcome("protocol", f"the session failed ({type(e).__name__})")
    finally:
        try:
            client.shutdown()
        except Exception:  # noqa: BLE001 — already closed
            pass


# ================================================================================================================================
# SLICE 2 — sessions for the sync (docs/build-specs/mail-service.md §2–§3). Every login goes through open_session(): it answers a
# logged-in IMAPClient, or the Outcome that tells the caller what the refusal MEANS (auth_failed trips the breaker; the rest back off).
# IMAPClient 4.1.0 with normalise_times off (INTERNALDATE stays aware); the raw imaplib connection underneath carries what IMAPClient
# has no method for — NOTIFY, LIST … RETURN (STATUS …), UID FETCH … (CHANGEDSINCE m VANISHED) (research 04 §B).

import imaplib  # noqa: E402
import re  # noqa: E402

from imapclient.response_parser import parse_fetch_response, parse_response  # noqa: E402

imaplib.Commands.setdefault("NOTIFY", ("AUTH", "SELECTED"))          # RFC 5465 — imaplib knows no NOTIFY

ENVELOPE_HEADERS = ("Date From Sender To Cc Bcc Reply-To Subject Message-ID References In-Reply-To List-Id List-Unsubscribe "
                    "Auto-Submitted Precedence X-Spamd-Result X-Spam-Status Authentication-Results Return-Path Received")
ENVELOPE_ITEMS = ["UID", "FLAGS", "INTERNALDATE", "RFC822.SIZE", "BODYSTRUCTURE", "MODSEQ", "PREVIEW",
                  f"BODY.PEEK[HEADER.FIELDS ({ENVELOPE_HEADERS})]"]


def open_session(host: str, port: int, address: str, password: str, ca_file: str | None, timeout: float = 30.0):
    """(client, None) logged in, QRESYNC enabled when offered — or (None, Outcome) with probe()'s kinds."""
    try:
        client = imapclient.IMAPClient(host, port=port, ssl=True, ssl_context=tls_context(ca_file), timeout=timeout)
        client.normalise_times = False
    except ssl.SSLCertVerificationError as e:
        return None, Outcome("tls_failed", f"the server's certificate did not check out ({e.verify_message})")
    except ssl.SSLError as e:
        return None, Outcome("tls_failed", f"TLS failed ({e.reason or type(e).__name__})")
    except (OSError, socket.timeout) as e:
        return None, Outcome("connect_failed", f"could not connect ({type(e).__name__})")
    try:
        client.login(address, password)
    except LoginError as e:
        said = _text(e.args[0] if e.args else "")[:200]
        _close(client)
        if any(code in said.upper() for code in BUSY_CODES):
            return None, Outcome("busy", f"the server is busy: {said}")
        return None, Outcome("auth_failed", f"the server refused the credential: {said}")
    except (IMAPClientError, OSError, socket.timeout) as e:
        _close(client)
        return None, Outcome("protocol", f"the session failed ({type(e).__name__})")
    try:
        caps = {(_text(c)).upper() for c in client.capabilities()}
        if "QRESYNC" in caps:
            client.enable("QRESYNC")
        elif "CONDSTORE" in caps:
            client.enable("CONDSTORE")
    except (IMAPClientError, OSError, socket.timeout) as e:
        _close(client)
        return None, Outcome("protocol", f"the session failed ({type(e).__name__})")
    return client, None


def _close(client) -> None:
    try:
        client.logout()
    except Exception:  # noqa: BLE001
        pass
    try:
        client.shutdown()
    except Exception:  # noqa: BLE001
        pass


def close(client) -> None:
    _close(client)


def capabilities(client) -> set[str]:
    return {_text(c).upper() for c in client.capabilities()}


def raw(client, name: str, *args):
    """One raw command through imaplib: (typ, tagged data, the untagged responses it produced — popped)."""
    imap = client._imap
    imap.untagged_responses.clear()
    typ, data = imap._simple_command(name, *args)
    untagged = {k: v for k, v in imap.untagged_responses.items()}
    imap.untagged_responses.clear()
    return typ, data, untagged


def _imap_quote(name: str) -> str:
    return '"' + name.replace("\\", "\\\\").replace('"', '\\"') + '"'


def folder_name_out(name: str) -> str:
    """A folder's name as the server wants it (modified UTF-7)."""
    from imapclient.imap_utf7 import encode
    return _imap_quote(encode(name).decode("ascii"))


def folder_name_in(raw_name) -> str:
    from imapclient.imap_utf7 import decode
    b = raw_name if isinstance(raw_name, bytes) else str(raw_name).encode()
    return decode(b)


def list_status(client):
    """LIST "" "*" RETURN (SPECIAL-USE STATUS (…)) in one command → [{name, delimiter, flags, uidvalidity, uidnext, messages, unseen,
    highestmodseq}]. Falls back to LIST + STATUS per folder where LIST-STATUS is not offered."""
    caps = capabilities(client)
    items = "MESSAGES UIDNEXT UIDVALIDITY UNSEEN" + (" HIGHESTMODSEQ" if ("CONDSTORE" in caps or "QRESYNC" in caps) else "")
    folders: dict[str, dict] = {}
    order: list[str] = []
    if "LIST-STATUS" in caps:
        typ, data, untagged = raw(client, "LIST", '""', '"*"', f"RETURN (SPECIAL-USE STATUS ({items}))")
        if typ != "OK":
            raise IMAPClientError(f"LIST failed: {data!r}")
        for line in untagged.get("LIST", []):
            flags, delim, name = _parse_list_line(line)
            folders[name] = {"name": name, "delimiter": delim, "flags": flags}
            order.append(name)
        for line in untagged.get("STATUS", []):
            name, values = _parse_status_line(line)
            if name in folders:
                folders[name].update(values)
    else:
        for flags, delim, name in client.list_folders():
            n = _text(name)
            folders[n] = {"name": n, "delimiter": _text(delim) if delim else None, "flags": sorted(_text(f) for f in flags)}
            order.append(n)
        for n in order:
            st = client.folder_status(n, items.split())
            folders[n].update({_text(k).lower(): int(v) for k, v in st.items()})
    return [folders[n] for n in order]


def _flatten(line) -> bytes:
    if isinstance(line, tuple):
        out = b""
        for p in line:
            out += p if isinstance(p, bytes) else str(p).encode()
        return out
    return line if isinstance(line, bytes) else str(line).encode()


def _parse_list_line(line):
    parsed = parse_response([_flatten(line)])
    flags, delim, name = parsed[0], parsed[1], parsed[2]
    return sorted(_text(f) for f in flags), (_text(delim) if delim else None), folder_name_in(name)


def _parse_status_line(line):
    parsed = parse_response([_flatten(line)])
    name, vals = folder_name_in(parsed[0]), parsed[1]
    it = iter(vals)
    return name, {_text(k).lower(): int(v) for k, v in zip(it, it)}


def changed_since(client, modseq: int):
    """UID FETCH 1:* (UID FLAGS MODSEQ) (CHANGEDSINCE m VANISHED) on the selected folder → ({uid: {flags, modseq}}, {vanished uids})."""
    typ, data, untagged = raw(client, "UID", "FETCH", "1:*", "(UID FLAGS MODSEQ)", f"(CHANGEDSINCE {int(modseq)} VANISHED)")
    if typ != "OK":
        raise IMAPClientError(f"UID FETCH CHANGEDSINCE failed: {data!r}")
    fetches = untagged.get("FETCH", [])
    changed = {}
    if fetches:
        for uid, d in parse_fetch_response([_flatten(x) for x in fetches], False, True).items():
            changed[uid] = {"flags": [_text(f) for f in d.get(b"FLAGS", ())], "modseq": int((d.get(b"MODSEQ") or (0,))[0])}
    vanished: set[int] = set()
    for v in untagged.get("VANISHED", []):
        text = _text(_flatten(v)).replace("(EARLIER)", "").strip()
        vanished |= parse_uid_set(text)
    return changed, vanished


def parse_uid_set(text: str) -> set[int]:
    out: set[int] = set()
    for part in text.split(","):
        part = part.strip()
        if not part:
            continue
        if ":" in part:
            a, b = part.split(":", 1)
            lo, hi = sorted((int(a), int(b)))
            out.update(range(lo, hi + 1))
        else:
            out.add(int(part))
    return out


def notify_set(client) -> bool:
    """NOTIFY SET for the selected INBOX and the personal namespace; False when the server does not offer or refuses it."""
    if "NOTIFY" not in capabilities(client):
        return False
    try:
        typ, _data, _u = raw(client, "NOTIFY", "SET", "(selected (MessageNew (UID FLAGS) MessageExpunge FlagChange))",
                             "(personal (MessageNew MessageExpunge))")
    except (IMAPClientError, imaplib.IMAP4.error):
        return False
    return typ == "OK"


def quota(client):
    """GETQUOTAROOT INBOX → (used_bytes, limit_bytes) of STORAGE, or (None, None)."""
    try:
        _roots, quotas = client.get_quota_root("INBOX")
    except (IMAPClientError, imaplib.IMAP4.error):
        return None, None
    for q in quotas:
        if _text(q.resource).upper() == "STORAGE":
            return int(q.usage) * 1024, int(q.limit) * 1024
    return None, None


# ---- BODYSTRUCTURE ------------------------------------------------------------------------------------------------------------------

def walk_structure(bs):
    """The leaves of a BODYSTRUCTURE: [{part, type, subtype, params, encoding, size, disposition, filename}] — part numbers as IMAP
    names them (a single-part message's body is "1")."""
    leaves: list[dict] = []

    def parts_of(node):
        """A multipart's parts: IMAPClient's BodyData nests them in a list at [0]; a raw parse leaves them as leading tuples."""
        if isinstance(node, (list, tuple)) and node:
            if isinstance(node[0], list):
                return node[0]
            if isinstance(node[0], tuple):
                out = []
                for el in node:
                    if not isinstance(el, tuple):
                        break
                    out.append(el)
                return out
        return None

    def params_of(p) -> dict:
        out = {}
        if isinstance(p, (list, tuple)):
            it = iter(p)
            for k, v in zip(it, it):
                out[_text(k).lower()] = _text(v) if v is not None else ""
        return out

    def disposition_of(node):
        for el in node[7:]:
            if isinstance(el, (list, tuple)) and el and isinstance(el[0], (bytes, str)) and _text(el[0]).lower() in ("attachment", "inline"):
                return _text(el[0]).lower(), params_of(el[1] if len(el) > 1 else None)
        return None, {}

    def visit(node, prefix: str):
        parts = parts_of(node)
        if parts is not None:
            for i, sub in enumerate(parts, 1):
                visit(sub, f"{prefix}.{i}" if prefix else str(i))
            return
        typ, sub = _text(node[0]).lower(), _text(node[1]).lower()
        params = params_of(node[2])
        disp, dparams = disposition_of(node)
        leaves.append({"part": prefix or "1", "type": typ, "subtype": sub, "params": params,
                       "encoding": _text(node[5]).lower() if len(node) > 5 and node[5] else "7bit",
                       "size": int(node[6]) if len(node) > 6 and isinstance(node[6], int) else 0,
                       "disposition": disp, "filename": dparams.get("filename") or params.get("name"),
                       "content_id": _text(node[3]) if len(node) > 3 and node[3] else None})

    visit(bs, "")
    return leaves


def attachments_of(leaves) -> list[str]:
    names = []
    for lf in leaves:
        if lf["disposition"] == "attachment" or (lf["type"] != "text" and lf["filename"]):
            names.append(lf["filename"] or f"{lf['type']}/{lf['subtype']}")
    return names


def text_part_of(leaves):
    """The first text/plain leaf that is not an attachment, else the first text/html: (part, subtype, charset) or None."""
    for want in ("plain", "html"):
        for lf in leaves:
            if lf["type"] == "text" and lf["subtype"] == want and lf["disposition"] != "attachment":
                return lf["part"], want, lf["params"].get("charset") or "utf-8"
    return None


def fetch_body_text(client, uid_parts: dict[int, str], cap: int) -> dict[int, bytes]:
    """BINARY.PEEK[part]<0.cap> for each uid, grouped by part number (one command per part) → {uid: decoded bytes}."""
    out: dict[int, bytes] = {}
    by_part: dict[str, list[int]] = {}
    for uid, part in uid_parts.items():
        by_part.setdefault(part, []).append(uid)
    for part, uids in by_part.items():
        item = f"BINARY.PEEK[{part}]<0.{int(cap)}>"
        res = client.fetch(uids, [item])
        for uid, d in res.items():
            for k, v in d.items():
                if _text(k).upper().startswith(f"BINARY[{part}]"):
                    out[uid] = v if isinstance(v, bytes) else (b"" if v is None else str(v).encode())
    return out


_ = re


def select(client, folder: str, uidvalidity: int | None = None, modseq: int | None = None, readonly: bool = True) -> dict:
    """SELECT (EXAMINE when readonly) a folder; with what the index last knew, `(QRESYNC (uidvalidity modseq))` in the same command
    (raw imaplib: IMAPClient takes no SELECT parameter). Answers {uidvalidity, uidnext, highestmodseq, exists, changed, vanished} —
    changed {uid: {flags, modseq}} and vanished {uid} only when the server's UIDVALIDITY is the one we knew (otherwise it ignores
    QRESYNC's parameter and the caller resets the folder)."""
    imap = client._imap
    cmd = "EXAMINE" if readonly else "SELECT"
    args = [folder_name_out(folder)]
    if uidvalidity and modseq and "QRESYNC" in capabilities(client):
        args.append(f"(QRESYNC ({int(uidvalidity)} {int(modseq)}))")
    typ, data, untagged = raw(client, cmd, *args)
    if typ != "OK":
        raise IMAPClientError(f"{cmd} {folder} failed: {_text(data[0] if data else b'')}")
    imap.state = "SELECTED"
    imap.is_readonly = readonly
    first = lambda k: int(_text(untagged[k][0]).split()[0]) if untagged.get(k) else None  # noqa: E731
    out = {"uidvalidity": first("UIDVALIDITY"), "uidnext": first("UIDNEXT"), "highestmodseq": first("HIGHESTMODSEQ"),
           "exists": int(_text(untagged["EXISTS"][-1])) if untagged.get("EXISTS") else 0, "changed": {}, "vanished": set()}
    if len(args) == 2 and out["uidvalidity"] == int(uidvalidity):
        fetches = untagged.get("FETCH", [])
        if fetches:
            for uid, d in parse_fetch_response([_flatten(x) for x in fetches], False, True).items():
                out["changed"][uid] = {"flags": [_text(f) for f in d.get(b"FLAGS", ())],
                                       "modseq": int((d.get(b"MODSEQ") or (0,))[0])}
        for v in untagged.get("VANISHED", []):
            out["vanished"] |= parse_uid_set(_text(_flatten(v)).replace("(EARLIER)", "").strip())
    return out


def all_uids(client) -> list[int]:
    """Every UID of the selected folder, newest first."""
    return sorted((int(u) for u in client.search(["ALL"])), reverse=True)


def fetch_envelopes(client, uids: list[int]) -> dict:
    """§3.1's envelope items for these UIDs (a PEEK: nothing is marked read)."""
    if not uids:
        return {}
    return client.fetch(uids, ENVELOPE_ITEMS)


def create_folder(client, name: str) -> bool:
    try:
        client.create_folder(name)
        return True
    except (IMAPClientError, imaplib.IMAP4.error):
        return False


# ================================================================================================================================
# SLICE 3 — mutations on a pool connection (docs/build-specs/mail-service.md §5): one IMAP command per folder touched, UIDs grouped.
# The caller SELECTs the folder read-write first (select(…, readonly=False)) and holds the mailbox's lock.

_COPYUID = re.compile(r"COPYUID\s+(\d+)\s+([0-9:,]+)\s+([0-9:,]+)", re.I)


def uid_set(uids) -> str:
    """A UID set, ranges collapsed (1,2,3,7 → 1:3,7)."""
    out = []
    run: list[int] = []
    for u in sorted(set(int(x) for x in uids)):
        if run and u == run[-1] + 1:
            run.append(u)
            continue
        if run:
            out.append(f"{run[0]}:{run[-1]}" if len(run) > 1 else str(run[0]))
        run = [u]
    if run:
        out.append(f"{run[0]}:{run[-1]}" if len(run) > 1 else str(run[0]))
    return ",".join(out)


def store_flags(client, uids, flag: str, add: bool) -> None:
    """UID STORE set ±FLAGS.SILENT (flag) on the selected folder."""
    typ, data, _u = raw(client, "UID", "STORE", uid_set(uids), ("+" if add else "-") + "FLAGS.SILENT", f"({flag})")
    if typ != "OK":
        raise IMAPClientError(f"UID STORE failed: {_text(data[0] if data else b'')}")


def move(client, uids, folder: str) -> dict[int, int]:
    """UID MOVE set "folder" → {old uid: new uid} from the COPYUID response code (RFC 6851 + 4315)."""
    typ, data, _u = raw(client, "UID", "MOVE", uid_set(uids), folder_name_out(folder))
    if typ != "OK":
        raise IMAPClientError(f"UID MOVE failed: {_text(data[0] if data else b'')}")
    text = " ".join(_text(d) for d in (data or []))
    m = _COPYUID.search(text)
    if not m:
        # also in the untagged OK some servers send before the tagged line
        for line in _u.get("OK", []):
            m = _COPYUID.search(_text(_flatten(line)))
            if m:
                break
    if not m:
        return {}
    src = sorted(parse_uid_set(m.group(2)))
    dst = sorted(parse_uid_set(m.group(3)))
    return dict(zip(src, dst))


def expunge_uids(client, uids) -> None:
    """UID STORE +FLAGS.SILENT (\\Deleted), then UID EXPUNGE set (UIDPLUS) — only what was named goes."""
    store_flags(client, uids, "\\Deleted", True)
    typ, data, _u = raw(client, "UID", "EXPUNGE", uid_set(uids))
    if typ != "OK":
        raise IMAPClientError(f"UID EXPUNGE failed: {_text(data[0] if data else b'')}")
