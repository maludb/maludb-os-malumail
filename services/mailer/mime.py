"""The MIME a message becomes (docs/build-specs/sending-and-oversight.md §4): built from the outbound row (or a draft row) and its
attachments with the standard library's EmailMessage under the SMTP policy — never from anything a caller sends at submission time.
multipart/alternative (text, and html when there is one), multipart/mixed around it for attachments (always `attachment`, no inline
images in v1); the disclosure footer appended to both parts for an agent; an agent's identity signature appended by the service (a
person's composer shows theirs in the body); the headers of §4.2. The Sent copy carries Bcc; the submitted message never does.
"""

from __future__ import annotations

import copy
import email.utils
import mimetypes
import re
from email.message import EmailMessage
from email.policy import SMTP

from services.mailer import sanitise

ENCODED_MAX = int(9.8 * 1024 * 1024)
REFERENCES_KEEP = 19


def text_from_html(html: str) -> str:
    """The text derived from cleaned HTML: block elements to lines, links as `text <url>`, quotes as `> `, the rest stripped."""
    from html.parser import HTMLParser

    class T(HTMLParser):
        def __init__(self):
            super().__init__(convert_charrefs=True)
            self.out: list[str] = []
            self.quote = 0
            self.link: str | None = None
            self.link_text: list[str] = []
            self.skip = 0

        def _nl(self):
            self.out.append("\n")

        def handle_starttag(self, tag, attrs):
            if self.skip:
                self.skip += 1
                return
            if tag in ("script", "style", "head", "title"):
                self.skip = 1
                return
            a = dict(attrs)
            if tag == "blockquote":
                self._nl()
                self.quote += 1
            elif tag in ("p", "div", "br", "li", "tr", "h1", "h2", "h3", "h4", "h5", "h6", "table", "ul", "ol", "pre", "hr"):
                self._nl()
                if tag == "li":
                    self.out.append("- ")
                if tag == "hr":
                    self.out.append("----")
            elif tag == "a":
                self.link, self.link_text = a.get("href") or "", []

        def handle_endtag(self, tag):
            if self.skip:
                self.skip -= 1
                return
            if tag == "blockquote":
                self.quote = max(0, self.quote - 1)
                self._nl()
            elif tag in ("p", "div", "li", "tr", "h1", "h2", "h3", "h4", "h5", "h6", "table", "pre"):
                self._nl()
            elif tag == "a" and self.link is not None:
                t = "".join(self.link_text).strip()
                href = self.link
                if href and not href.startswith("mailto:") and t != href:
                    self.out.append(f"{t} <{href}>" if t else f"<{href}>")
                else:
                    self.out.append(t or href)
                self.link = None

        def handle_data(self, data):
            if self.skip:
                return
            if self.link is not None:
                self.link_text.append(data)
                return
            self.out.append(data)

    p = T()
    p.feed(html)
    p.close()
    raw = "".join(p.out)
    lines = []
    quote_depth = 0
    # quotes: rebuild with "> " per depth by re-walking (simple: lines after a blockquote start are marked by the parser's depth — kept simple:
    # the parser emitted blank lines around quotes; depth is applied by a second pass on the html's blockquote structure)
    for ln in raw.replace("\r\n", "\n").split("\n"):
        lines.append(re.sub(r"[ \t]+", " ", ln).strip())
    text = "\n".join(lines)
    text = re.sub(r"\n{3,}", "\n\n", text).strip()
    return _quote_lines(html, text)


def _quote_lines(html: str, text: str) -> str:
    """Prefix the text of each top-level <blockquote> with '> ' (one level; nested quotes gain another)."""
    # find quoted segments by converting each blockquote's content separately
    def inner_text(fragment: str) -> str:
        return text_from_html_plain(fragment)
    out = text
    for m in re.finditer(r"<blockquote[^>]*>(.*?)</blockquote>", html, re.S | re.I):
        q = inner_text(m.group(1))
        if not q.strip():
            continue
        quoted = "\n".join("> " + ln if ln else ">" for ln in q.split("\n"))
        if q in out:
            out = out.replace(q, quoted, 1)
    return out


def text_from_html_plain(html: str) -> str:
    """HTML to text with no quote marks (used for the inside of a quote)."""
    cleaned = re.sub(r"<\s*(br|/p|/div|/li|/tr|/h[1-6])\s*/?>", "\n", html, flags=re.I)
    cleaned = re.sub(r"<li[^>]*>", "- ", cleaned, flags=re.I)
    cleaned = re.sub(r"<a\s[^>]*href=\"([^\"]*)\"[^>]*>(.*?)</a>", lambda m: (m.group(2).strip() + " <" + m.group(1) + ">") if m.group(2).strip() and m.group(2).strip() != m.group(1) else m.group(1), cleaned, flags=re.I | re.S)
    cleaned = re.sub(r"<[^>]+>", "", cleaned)
    import html as _h
    lines = [re.sub(r"[ \t]+", " ", ln).strip() for ln in _h.unescape(cleaned).replace("\r\n", "\n").split("\n")]
    return re.sub(r"\n{3,}", "\n\n", "\n".join(lines)).strip()


def _addr(display: str | None, address: str) -> str:
    return email.utils.formataddr((display or "", address))


def _refs(refs: list[str] | None) -> str | None:
    if not refs:
        return None
    if len(refs) > REFERENCES_KEEP + 1:
        refs = [refs[0]] + refs[-REFERENCES_KEEP:]
    return " ".join(refs)


def build(row: dict, attachments: list[dict], identity: dict | None, mailbox_address: str, mailbox_display: str | None,
          sender_address: str | None, agent_name: str | None, business: str | None, attachment_bytes, for_draft: bool = False,
          date=None) -> EmailMessage:
    """The message. `row`: an outbound_email or mail_drafts row (dict). `attachment_bytes(att) -> bytes` opens one attachment.
    `sender_address`: the Sender header's address for a delegate (None otherwise). An agent's signature and disclosure are appended here."""
    msg = EmailMessage(policy=SMTP)
    from_name = (identity or {}).get("display_name") or mailbox_display
    from_addr = (identity or {}).get("address") or mailbox_address
    msg["From"] = _addr(from_name, from_addr)
    if sender_address and sender_address.lower() != from_addr.lower():
        msg["Sender"] = sender_address
    if (identity or {}).get("reply_to"):
        msg["Reply-To"] = identity["reply_to"]
    if row.get("to_addresses"):
        msg["To"] = ", ".join(row["to_addresses"])
    if row.get("cc_addresses"):
        msg["Cc"] = ", ".join(row["cc_addresses"])
    msg["Subject"] = row.get("subject") or ""
    msg["Date"] = email.utils.format_datetime(date) if date is not None else email.utils.formatdate(usegmt=True)
    msg["Message-ID"] = row["message_id"] if not for_draft else row["draft_key"]
    if row.get("in_reply_to"):
        msg["In-Reply-To"] = row["in_reply_to"]
    r = _refs(list(row.get("references_ids") or []))
    if r:
        msg["References"] = r
    if row.get("sender_is_agent") and agent_name:
        msg["X-OS-Agent"] = f"{agent_name}; business={business or 'the business'}"
    if row.get("auto_submitted"):
        msg["Auto-Submitted"] = row["auto_submitted"]
    text = row.get("body_text") or ""
    html = row.get("body_html")
    if html:
        html = sanitise.sanitise(html, cid_url=lambda c: None, remote_image_url=lambda u: None).html   # the compose profile: no images
        if not text.strip():
            text = text_from_html(html)
    tail_text, tail_html = "", ""
    if row.get("sender_is_agent") and identity and (identity.get("signature_text") or identity.get("signature_html")):
        tail_text += "\n-- \n" + (identity.get("signature_text") or text_from_html(identity.get("signature_html") or ""))
        tail_html += '<div class="sig">-- <br>' + (identity.get("signature_html") or "<p>" + (identity.get("signature_text") or "") + "</p>") + "</div>"
    if row.get("disclosure"):
        tail_text += "\n-- \n" + row["disclosure"]
        tail_html += "<p>-- <br>" + _esc(row["disclosure"]) + "</p>"
    text = text.rstrip("\n") + ("\n" + tail_text if tail_text else "") + "\n"
    msg.set_content(text, subtype="plain", charset="utf-8", cte="quoted-printable")
    if html:
        msg.add_alternative("<html><body>" + html + tail_html + "</body></html>", subtype="html", charset="utf-8", cte="quoted-printable")
    for att in attachments:
        data = attachment_bytes(att)
        ctype = att.get("mime_type") or mimetypes.guess_type(att.get("filename") or "")[0] or "application/octet-stream"
        if "/" not in ctype:
            ctype = "application/octet-stream"
        maintype, subtype = ctype.split("/", 1)
        if maintype in ("message", "multipart"):
            maintype, subtype = "application", "octet-stream"
        msg.add_attachment(data, maintype=maintype, subtype=subtype, filename=att.get("filename") or "attachment", disposition="attachment")
    for part in msg.walk():                                        # MIME-Version belongs to the message, not to a part
        if part is not msg:
            del part["MIME-Version"]
    return msg


def _esc(s: str) -> str:
    return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def submitted_bytes(msg: EmailMessage) -> bytes:
    """What goes to the SMTP server: never a Bcc header."""
    m = copy.deepcopy(msg)
    del m["Bcc"]
    return m.as_bytes()


def sent_copy_bytes(msg: EmailMessage, bcc: list[str]) -> bytes:
    """The copy APPENDed to Sent: with the Bcc recipients (the sender sees whom they blind-copied)."""
    m = copy.deepcopy(msg)
    del m["Bcc"]
    if bcc:
        m["Bcc"] = ", ".join(bcc)
    return m.as_bytes()


def too_large(msg: EmailMessage) -> bool:
    return len(submitted_bytes(msg)) > ENCODED_MAX
