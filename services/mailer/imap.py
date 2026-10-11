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
