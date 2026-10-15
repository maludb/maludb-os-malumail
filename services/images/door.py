"""THE IMAGE DOOR (design §6.4 layer three; docs/build-specs/reading-and-rendering.md §4): fetches one remote image of a message for a
person who asked "Show images", and nothing else. Its own user, no credentials, no database, no environment file. The standard
library's HTTP server on the socket systemd hands it (fd 3, LISTEN_FDS) or on --port for the proofs; one thread per request, at most
16 at once.

  GET /fetch?u=<url>   → 200 with the bytes and the sniffed Content-Type (PNG, JPEG, GIF or WebP), or 4xx/502 with a one-word reason:
                          refused_scheme · refused_address · too_many_redirects · too_large · not_an_image · timeout · failed
  GET /health          → ok

The rules: http or https on 80 or 443 only; a name resolved ONCE, every address it answers global (ipaddress.is_global, not multicast)
or none is used, the connection made to that address with Host and SNI set by hand; at most three redirects, each re-checked from the
first rule; no proxy, no cookies, no referrer, an honest User-Agent; 5 MB counted while reading; 5 s to connect, 15 s in all. It logs
the host and the reason, never the whole URL. For the proofs only, ML_IMAGES_TEST_ALLOW=host:port admits one loopback fixture address;
refused when APP_ENV is prod (the mail service's ML_TLS_CA_FILE rule).
"""

from __future__ import annotations

import argparse
import http.client
import ipaddress
import logging
import os
import socket
import ssl
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

MAX_BYTES = 5 * 1024 * 1024
MAX_REDIRECTS = 3
CONNECT_TIMEOUT = 5.0
TOTAL_TIMEOUT = 15.0
MAX_THREADS = 16
UA = "MaluMail-image-door/1"
log = logging.getLogger("malumail.images")


class Refused(Exception):
    def __init__(self, reason: str, status: int = 403):
        super().__init__(reason)
        self.reason, self.status = reason, status


def sniff(head: bytes) -> str | None:
    if head.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image/png"
    if head.startswith(b"\xff\xd8\xff"):
        return "image/jpeg"
    if head.startswith((b"GIF87a", b"GIF89a")):
        return "image/gif"
    if head[:4] == b"RIFF" and head[8:12] == b"WEBP":
        return "image/webp"
    return None


def test_allowed() -> tuple[str, int] | None:
    v = os.environ.get("ML_IMAGES_TEST_ALLOW", "")
    if not v or os.environ.get("APP_ENV", "prod") == "prod":
        return None
    host, _, port = v.rpartition(":")
    return (host, int(port)) if host and port.isdigit() else None


def resolve_pinned(host: str, port: int) -> str:
    """The one address a name is dialled at: every address it answers must be global, or none is used."""
    allow = test_allowed()
    if allow is not None and (host, port) == allow:
        return host
    try:
        infos = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
    except socket.gaierror:
        raise Refused("failed", 502)
    addrs = []
    for fam, _t, _p, _c, sa in infos:
        a = ipaddress.ip_address(sa[0])
        if not a.is_global or a.is_multicast:
            raise Refused("refused_address")
        addrs.append(sa[0])
    if not addrs:
        raise Refused("failed", 502)
    return addrs[0]


def check_url(url: str) -> tuple[str, str, int, str]:
    u = urlsplit(url)
    scheme = (u.scheme or "").lower()
    if scheme not in ("http", "https") or not u.hostname:
        raise Refused("refused_scheme")
    port = u.port or (443 if scheme == "https" else 80)
    allow = test_allowed()
    if port not in (80, 443) and not (allow is not None and (u.hostname, port) == allow):
        raise Refused("refused_scheme")
    try:
        ipaddress.ip_address(u.hostname)
        is_ip = True
    except ValueError:
        is_ip = False
    if is_ip:
        a = ipaddress.ip_address(u.hostname)
        if (not a.is_global or a.is_multicast) and not (allow is not None and (u.hostname, port) == allow):
            raise Refused("refused_address")
    path = u.path or "/"
    if u.query:
        path += "?" + u.query
    return scheme, u.hostname, port, path


def fetch(url: str, deadline: float) -> tuple[bytes, str]:
    for _hop in range(MAX_REDIRECTS + 1):
        scheme, host, port, path = check_url(url)
        addr = resolve_pinned(host, port)
        left = max(0.5, min(CONNECT_TIMEOUT, deadline - _now()))
        if scheme == "https":
            ctx = ssl.create_default_context()
            conn = http.client.HTTPSConnection(addr, port, timeout=left, context=ctx)
            conn._http_vsn = 11
            # SNI and the certificate's name are the NAME's, the socket the pinned address's
            conn.connect = _https_connect(conn, host, ctx, left)
        else:
            conn = http.client.HTTPConnection(addr, port, timeout=left)
        try:
            try:
                conn.request("GET", path, headers={"Host": host, "User-Agent": UA, "Accept": "image/*"})
                conn.sock.settimeout(max(0.5, deadline - _now()))
                resp = conn.getresponse()
            except (socket.timeout, TimeoutError):
                raise Refused("timeout", 504)
            except (OSError, http.client.HTTPException, ssl.SSLError):
                raise Refused("failed", 502)
            if resp.status in (301, 302, 303, 307, 308):
                loc = resp.getheader("Location")
                if not loc:
                    raise Refused("failed", 502)
                from urllib.parse import urljoin
                url = urljoin(url, loc)
                if _hop == MAX_REDIRECTS:
                    raise Refused("too_many_redirects")
                continue
            if resp.status != 200:
                raise Refused("failed", 502)
            data = b""
            while True:
                if _now() > deadline:
                    raise Refused("timeout", 504)
                try:
                    chunk = resp.read(65536)
                except (socket.timeout, TimeoutError):
                    raise Refused("timeout", 504)
                except (OSError, http.client.HTTPException):
                    raise Refused("failed", 502)
                if not chunk:
                    break
                data += chunk
                if len(data) > MAX_BYTES:
                    raise Refused("too_large", 413)
            kind = sniff(data[:16])
            if kind is None:
                raise Refused("not_an_image", 415)
            return data, kind
        finally:
            try:
                conn.close()
            except Exception:  # noqa: BLE001
                pass
    raise Refused("too_many_redirects")


def _https_connect(conn, server_name: str, ctx: ssl.SSLContext, timeout: float):
    def connect():
        sock = socket.create_connection((conn.host, conn.port), timeout)
        conn.sock = ctx.wrap_socket(sock, server_hostname=server_name)
    return connect


def _now() -> float:
    import time
    return time.monotonic()


class Handler(BaseHTTPRequestHandler):
    server_version = "MaluMail-image-door/1"
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):  # noqa: D102 — the door logs what it decides, not every line
        pass

    def _answer(self, status: int, body: bytes, ctype: str) -> None:
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Cache-Control", "private, max-age=3600")
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):  # noqa: N802
        u = urlsplit(self.path)
        if u.path == "/health":
            return self._answer(200, b"ok\n", "text/plain")
        if u.path != "/fetch":
            return self._answer(404, b"not_found\n", "text/plain")
        url = (parse_qs(u.query).get("u") or [""])[0]
        host = (urlsplit(url).hostname or "?") if url else "?"
        try:
            data, kind = fetch(url, _now() + TOTAL_TIMEOUT)
        except Refused as r:
            log.info("refused %s for %s", r.reason, host)
            return self._answer(r.status, (r.reason + "\n").encode(), "text/plain")
        except Exception as e:  # noqa: BLE001
            log.info("failed (%s) for %s", type(e).__name__, host)
            return self._answer(502, b"failed\n", "text/plain")
        log.info("served %s %d bytes", host, len(data))
        self._answer(200, data, kind)


class Door(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, address, handler, fd: int | None = None):
        if fd is not None:
            super().__init__(address, handler, bind_and_activate=False)
            self.socket = socket.socket(fileno=fd)
            self.server_address = self.socket.getsockname()
        else:
            super().__init__(address, handler)
        self._sem = threading.BoundedSemaphore(MAX_THREADS)

    def process_request(self, request, client_address):
        if not self._sem.acquire(timeout=5):
            try:
                request.sendall(b"HTTP/1.1 503 Service Unavailable\r\nContent-Length: 5\r\nConnection: close\r\n\r\nbusy\n")
            finally:
                self.shutdown_request(request)
            return
        super().process_request(request, client_address)

    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self._sem.release()


def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s: %(message)s")
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=0, help="listen here (the proofs); without it, the socket systemd passes as fd 3")
    ap.add_argument("--port-file", default=None)
    a = ap.parse_args()
    if a.port:
        srv = Door(("127.0.0.1", a.port), Handler)
    elif os.environ.get("LISTEN_FDS") == "1":
        srv = Door(("127.0.0.1", 0), Handler, fd=3)
    else:
        log.error("not started: no --port and no socket from systemd (LISTEN_FDS)")
        return 2
    if a.port_file:
        with open(a.port_file, "w") as f:
            f.write(str(srv.server_address[1]))
    log.info("listening on %s:%s", *srv.server_address[:2])
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
