"""The mail service's door (design §6.1): every call but /health is signed with ML_MAILER_KEY, the key PHP and the records MCP
share with it. Inventory's bridge shape, with a nonce (Boards found that two tickets minted in one second were one string):

    X-ML-Mailer: v1.<unix seconds>.<nonce: 16–64 hex>.<hex HMAC-SHA256(key, "v1.<ts>.<nonce>.<METHOD>.<path>.<hex sha256(body)>")>

A call is accepted within 60 seconds of its timestamp, once: a nonce is remembered for twice the window. The body is JSON naming
the acting member (`actor`); the service reads that member's rights and levels from the database itself — it never takes a level
from the caller.
"""

from __future__ import annotations

import hashlib
import hmac
import re
import time

WINDOW = 60
HEADER = "x-ml-mailer"
_SHAPE = re.compile(r"v1\.(\d{1,12})\.([0-9a-f]{16,64})\.([0-9a-f]{64})")


def sign(key: bytes, method: str, path: str, body: bytes, ts: int | None = None, nonce: str | None = None) -> str:
    """The header value for one call (PHP's app/mailer.php does the same; the proofs use this)."""
    import secrets

    ts = int(time.time()) if ts is None else ts
    nonce = secrets.token_hex(16) if nonce is None else nonce
    msg = f"v1.{ts}.{nonce}.{method.upper()}.{path}.{hashlib.sha256(body).hexdigest()}".encode()
    return f"v1.{ts}.{nonce}.{hmac.new(key, msg, hashlib.sha256).hexdigest()}"


class Nonces:
    """Nonces seen within twice the window, so a captured call cannot be replayed."""

    def __init__(self):
        self._seen: dict[str, float] = {}

    def first_use(self, nonce: str, now: float) -> bool:
        if len(self._seen) > 4096:
            self._seen = {n: t for n, t in self._seen.items() if now - t < 2 * WINDOW}
        if nonce in self._seen and now - self._seen[nonce] < 2 * WINDOW:
            return False
        self._seen[nonce] = now
        return True


def verify(key: bytes, nonces: Nonces, header: str, method: str, path: str, body: bytes, now: float | None = None) -> str | None:
    """None when the call is good; else why not (for the log — the caller is told only 'not a signed call')."""
    now = time.time() if now is None else now
    m = _SHAPE.fullmatch(header.strip())
    if not m:
        return "no signature"
    ts, nonce, mac = int(m.group(1)), m.group(2), m.group(3)
    if abs(now - ts) > WINDOW:
        return "stale"
    msg = f"v1.{ts}.{nonce}.{method.upper()}.{path}.{hashlib.sha256(body).hexdigest()}".encode()
    if not hmac.compare_digest(hmac.new(key, msg, hashlib.sha256).hexdigest(), mac):
        return "bad signature"
    if not nonces.first_use(nonce, now):
        return "replayed"
    return None
