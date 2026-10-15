"""A loopback image server for the image door's proof (reading-and-rendering.md §7): a PNG, a redirect chain (two hops to the PNG; four
hops that never end), a redirect into a private address, an SVG, an HTML page that calls itself image/png, a 6 MB "image", and a slow
answer. Admitted by the door only through ML_IMAGES_TEST_ALLOW=127.0.0.1:<port> (proofs only). Never on the network."""

import struct
import sys
import time
import zlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


def png(w=2, h=2):
    raw = b"".join(b"\x00" + b"\x10\x80\x40" * w for _ in range(h))
    def chunk(t, d):
        return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b"")


PNG = png()
PORT = int(sys.argv[1])


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _send(self, status, body=b"", ctype="application/octet-stream", extra=()):
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        for k, v in extra:
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        p = self.path.split("?")[0]
        seen = [h for h in ("Cookie", "Referer", "Authorization") if self.headers.get(h)]
        if seen:
            return self._send(400, b"leaked " + ",".join(seen).encode(), "text/plain")
        if p == "/logo.png":
            return self._send(200, PNG, "image/png")
        if p == "/redir1":
            return self._send(302, b"", extra=[("Location", f"http://127.0.0.1:{PORT}/redir2")])
        if p == "/redir2":
            return self._send(302, b"", extra=[("Location", f"http://127.0.0.1:{PORT}/logo.png")])
        if p == "/private":
            return self._send(302, b"", extra=[("Location", "http://10.0.0.1/x.png")])
        if p == "/loopback":
            return self._send(302, b"", extra=[("Location", "http://127.0.0.1:5432/x.png")])
        if p.startswith("/loop"):                               # /loop0 → /loop1 → … never ends (after /loopback, which it would otherwise catch)
            n = int(p[5:] or 0)
            return self._send(302, b"", extra=[("Location", f"http://127.0.0.1:{PORT}/loop{n + 1}")])
        if p == "/svg":
            return self._send(200, b'<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>', "image/svg+xml")
        if p == "/page.png":
            return self._send(200, b"<html><body>not an image</body></html>", "image/png")
        if p == "/big.png":
            return self._send(200, PNG + b"\x00" * (6 * 1024 * 1024), "image/png")
        if p == "/slow.png":
            time.sleep(20)
            return self._send(200, PNG, "image/png")
        if p == "/ua":
            return self._send(200, (self.headers.get("User-Agent") or "").encode(), "text/plain")
        return self._send(404, b"no", "text/plain")


ThreadingHTTPServer(("127.0.0.1", PORT), H).serve_forever()
