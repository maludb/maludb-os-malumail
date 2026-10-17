"""The outbox loop (docs/build-specs/sending-and-oversight.md §5): claim due rows (ml_outbound_claim — the kill switch first), build
the MIME, measure it, open the mailbox's credential, submit over SMTP (587, STARTTLS, the certificate checked, one connection per
mailbox per pass), ml_outbound_sent() the moment the server says 250, then file the Sent copy (with Bcc, \\Seen, the submission
time), flag the answered message \\Answered (never \\Seen), expunge the draft's Drafts copy. What a reply means (§5.3): 4xx → deferred;
5xx → failed; some recipients refused → sent with the refusal named and a send_failed notice; 535 at login → the circuit breaker; a
connection or TLS failure → deferred. At start, a row found `sending` for ten minutes is searched for in Sent by its Message-ID and
becomes sent or lost — never submitted again by itself (decision 1)."""

from __future__ import annotations

import asyncio
import datetime as dt
import logging
import smtplib
import socket
import ssl
import time
from pathlib import Path

from services.mailer import imap, mime, seal
from services.mailer.watch import Busy, Stopped

log = logging.getLogger("malumail.mailer.outbox")
PER_MAILBOX = 2
AT_ONCE = 20
FILE_TRIES = 5


class Outbox:
    def __init__(self, cfg, store, sync, backoff, drafts):
        self.cfg, self.store, self.sync, self.backoff, self.drafts = cfg, store, sync, backoff, drafts
        self.wake = asyncio.Event()
        self.per_mailbox: dict[int, asyncio.Semaphore] = {}
        self.all = asyncio.Semaphore(AT_ONCE)
        self.sent = 0
        self.file_tries: dict[int, int] = {}

    def kick(self) -> None:
        self.wake.set()

    async def loop(self) -> None:
        await self.crash_search()
        while True:
            try:
                due = await self.store.due_count()
                wait = 2.0 if due else float(max(2, self.cfg.pass_seconds))
                nxt = await self.store.next_due()
                if nxt is not None:
                    secs = (nxt - dt.datetime.now(dt.timezone.utc)).total_seconds()
                    wait = min(wait, max(0.5, secs + 0.2))
            except Exception as e:  # noqa: BLE001
                log.warning("outbox: %s", type(e).__name__)
                wait = 5.0
            try:
                await asyncio.wait_for(self.wake.wait(), timeout=wait)
            except asyncio.TimeoutError:
                pass
            self.wake.clear()
            try:
                await self.tick()
            except Exception as e:  # noqa: BLE001
                log.warning("outbox tick failed: %s", type(e).__name__)

    async def tick(self) -> None:
        ids = await self.store.claim(10)
        if ids:
            await asyncio.gather(*(self.send_one(i) for i in ids))
        for i in await self.store.unfiled():
            await self.file(i)

    # ---- the crash rule --------------------------------------------------------------------------------------------------------------
    async def crash_search(self) -> None:
        for oid in await self.store.stalled(10):
            o = await self.store.outbound(oid)
            if o is None:
                continue
            found = False
            w = self.sync.watchers.get(int(o["mailbox_id"]))
            sent_folder = await self.store.folder_named(int(o["mailbox_id"]), "sent")
            if w is not None and sent_folder is not None and o["message_id"]:
                try:
                    def look(c):
                        imap.select(c, sent_folder["name"])
                        return bool(imap.search_header(c, "Message-ID", o["message_id"]))
                    found = await self._on_pool(w, look)
                except Exception:  # noqa: BLE001
                    found = False
            if found:
                await self.store.outbound_sent(oid, None, "found in Sent after a restart")
                log.info("outbound %s: found in Sent after a restart — sent", oid)
            else:
                await self.store.outbound_lost(oid)
                log.warning("outbound %s: lost while sending — failed, never resent by itself", oid)

    # ---- one row -------------------------------------------------------------------------------------------------------------------
    def _sem(self, mailbox_id: int) -> asyncio.Semaphore:
        return self.per_mailbox.setdefault(mailbox_id, asyncio.Semaphore(PER_MAILBOX))

    async def send_one(self, oid: int) -> None:
        o = await self.store.outbound(oid)
        if o is None or o["status"] != "sending":
            return
        mb = int(o["mailbox_id"])
        async with self.all, self._sem(mb):
            try:
                await self._send(o)
            except Exception as e:  # noqa: BLE001
                log.warning("outbound %s: %s", oid, type(e).__name__)
                await self.store.outbound_deferred(oid, f"{type(e).__name__}")

    def _read_att(self, a: dict) -> bytes:
        p = Path(a["storage_path"])
        if not p.is_absolute():
            p = Path(self.cfg.storage_dir) / p
        return p.read_bytes()

    def _message(self, o, atts) -> tuple:
        identity = None
        if o["identity_address"]:
            identity = {"address": o["identity_address"], "display_name": o["identity_display"], "reply_to": o["identity_reply_to"],
                        "signature_html": o["signature_html"], "signature_text": o["signature_text"]}
        sender = None
        if o["on_behalf_member_id"] is not None:
            sender = o["delegate_mailbox"] or o["sender_email"] or (o["mailbox_address"] if o["sender_kind"] == "agent" else None)
        msg = mime.build(dict(o), atts, identity, o["mailbox_address"], o["mailbox_display"], sender,
                         o["sender_name"] if o["sender_is_agent"] else None, o["business_name"], self._read_att,
                         date=dt.datetime.now(dt.timezone.utc))
        return msg

    async def _send(self, o) -> None:
        oid, mb = int(o["id"]), int(o["mailbox_id"])
        atts = await self.store.attachments_of("outbound_email", oid)
        try:
            msg = self._message(o, atts)
        except OSError as e:
            await self.store.outbound_failed(oid, f"An attachment could not be read ({type(e).__name__})")
            return
        if mime.too_large(msg):
            await self.store.outbound_failed(oid, "The message is larger than MaluMail accepts (about 9.8 MB encoded)")
            return
        cred = await self.store.credential_row(mb)
        if cred is None or cred["credential_id"] is None or cred["status"] != "ok" or self.cfg.seal_secret is None:
            await self.store.outbound_failed(oid, "The mailbox's credential was refused; a Mail admin stores a new one")
            return
        now = time.time()
        if self.backoff.wait(mb, now):
            await self.store.outbound_deferred(oid, "the mail server could not be reached a moment ago")
            return
        password = seal.open_sealed(self.cfg.seal_secret, bytes(cred["ciphertext"]))
        recipients = list(o["to_addresses"] or []) + list(o["cc_addresses"] or []) + list(o["bcc_addresses"] or [])
        raw = mime.submitted_bytes(msg)
        outcome = await asyncio.to_thread(self._submit, cred["address"], password, raw, recipients)
        del password
        kind, detail, refused, queue_id = outcome
        if kind == "ok":
            self.backoff.clear(mb)
            self.sent += 1
            reply = detail
            if refused:
                reply = detail + " — refused: " + ", ".join(f"{a} ({c} {m})" for a, (c, m) in refused.items())
            await self.store.outbound_sent(oid, queue_id, reply[:500])
            if refused:
                who = int(o["sender_member_id"]) if o["sender_kind"] == "human" else await self.store.primary_overseer(mb)
                # the domain, never a correspondent's address (notifications.md: a notice carries no mail content)
                domains = sorted({str(a).rsplit("@", 1)[-1].lower() for a in refused})
                await self.store.notify_send_failed(oid, who, "Some recipients were refused",
                                                    f"The server refused {len(refused)} recipient{'' if len(refused) == 1 else 's'} at " + ", ".join(domains) + ".", mb)
            await self.file(oid, msg=msg, o=o)
            return
        if kind == "auth_failed":
            told = await self.store.trip_breaker(mb, cred["credential_id"], cred["address"], detail, None)
            log.warning("circuit breaker (SMTP): mailbox %s's credential failed; %d Mail admin(s) told", mb, told)
            await self.store.outbound_failed(oid, "The mailbox's credential was refused; a Mail admin stores a new one")
            return
        if kind == "deferred":
            await self.store.outbound_deferred(oid, detail[:500])
            return
        if kind == "failed":
            await self.store.outbound_failed(oid, detail[:1000])
            return
        if kind == "lost":
            await self.store.outbound_lost(oid)
            log.warning("outbound %s: the connection closed during DATA — failed, never resent by itself", oid)
            return
        # a connection or TLS failure: the mailbox's backoff, the row deferred
        self.backoff.fail(mb, now)
        await self.store.outbound_deferred(oid, detail[:500])

    def _submit(self, address: str, password: str, raw: bytes, recipients: list[str]):
        """('ok', reply, refused, queue_id) | ('auth_failed' | 'deferred' | 'failed' | 'lost' | 'connect_failed', detail, {}, None)."""
        try:
            s = smtplib.SMTP(self.cfg.smtp_host, self.cfg.smtp_port, timeout=30)
        except (OSError, socket.timeout) as e:
            return "connect_failed", f"could not connect ({type(e).__name__})", {}, None
        try:
            s.ehlo()
            try:
                s.starttls(context=imap.tls_context(self.cfg.tls_ca_file))
            except ssl.SSLError as e:
                return "connect_failed", f"TLS failed ({e.reason or type(e).__name__})", {}, None
            s.ehlo()
            # ONE authentication attempt, never more: smtplib's login() tries every mechanism the server offers in turn, so a refused
            # password became several failed logins — and MaluMail's abuse protection counts them against this whole machine (the
            # breaker's promise is one attempt; found by slice 4's proof). PLAIN, else LOGIN; nothing else is tried.
            offered = (s.esmtp_features.get("auth") or "").upper().split()
            mechanism = "PLAIN" if "PLAIN" in offered else ("LOGIN" if "LOGIN" in offered else None)
            if mechanism is None:
                return "failed", "the server offers neither PLAIN nor LOGIN authentication", {}, None
            s.user, s.password = address, password
            try:
                s.auth(mechanism, getattr(s, "auth_" + mechanism.lower()), initial_response_ok=True)
            except smtplib.SMTPAuthenticationError as e:
                return "auth_failed", _text(e.smtp_error), {}, None
            # the transaction by hand (smtplib's sendmail drops the 250 reply, which carries the queue id)
            try:
                code, resp = s.mail(address, ["SIZE=%d" % len(raw)] if s.has_extn("size") else [])
                if code != 250:
                    s.rset()
                    if 400 <= code < 500:
                        return "deferred", f"{code} {_text(resp)}", {}, None
                    return "failed", f"{code} {_text(resp)}", {}, None
                refused = {}
                for r in recipients:
                    code, resp = s.rcpt(r)
                    if code not in (250, 251):
                        refused[r] = (code, _text(resp))
                if len(refused) == len(recipients):
                    s.rset()
                    worst = max(c for c, _m in refused.values())
                    if 400 <= worst < 500 and all(400 <= c < 500 for c, _m in refused.values()):
                        return "deferred", "every recipient deferred: " + "; ".join(f"{a}: {c} {m}" for a, (c, m) in refused.items()), {}, None
                    return "failed", "every recipient refused: " + "; ".join(f"{a}: {c} {m}" for a, (c, m) in refused.items()), {}, None
                try:
                    code, resp = s.data(raw)
                except (smtplib.SMTPServerDisconnected, OSError, socket.timeout) as e:
                    # the connection died AFTER the message was handed over: it may have gone (decision 1 — never resent by itself)
                    return "lost", f"the server closed the connection during DATA ({type(e).__name__})", {}, None
                if code != 250:
                    if 400 <= code < 500:
                        return "deferred", f"{code} {_text(resp)}", {}, None
                    return "failed", f"{code} {_text(resp)}", {}, None
            except smtplib.SMTPServerDisconnected as e:
                return "connect_failed", f"the server closed the connection ({e})", {}, None
            except smtplib.SMTPResponseException as e:
                if 400 <= e.smtp_code < 500:
                    return "deferred", f"{e.smtp_code} {_text(e.smtp_error)}", {}, None
                return "failed", f"{e.smtp_code} {_text(e.smtp_error)}", {}, None
            except (OSError, socket.timeout) as e:
                return "connect_failed", f"the connection failed ({type(e).__name__})", {}, None
            reply = f"{code} {_text(resp)}"
            import re
            m = re.search(r"queued as (\S+)", reply)
            return "ok", reply, refused, (m.group(1) if m else None)
        finally:
            try:
                s.quit()
            except Exception:  # noqa: BLE001
                pass

    # ---- filing ------------------------------------------------------------------------------------------------------------------------
    async def file(self, oid: int, msg=None, o=None) -> None:
        o = o or await self.store.outbound(oid)
        if o is None or o["status"] != "sent" or o["filed_at"] is not None:
            return
        mb = int(o["mailbox_id"])
        tries = self.file_tries.get(oid, 0)
        if tries >= FILE_TRIES:
            await self.store.outbound_note(oid, "not filed in Sent")
            return
        self.file_tries[oid] = tries + 1
        w = self.sync.watchers.get(mb)
        sent_folder = await self.store.folder_named(mb, "sent")
        if w is None or not w.is_alive() or sent_folder is None:
            return
        if msg is None:
            atts = await self.store.attachments_of("outbound_email", oid)
            try:
                msg = self._message(o, atts)
            except OSError:
                await self.store.outbound_note(oid, "not filed in Sent")
                return
        raw = mime.sent_copy_bytes(msg, list(o["bcc_addresses"] or []))
        answered = await self.store.message_row_for_key(int(o["reply_to_key_id"])) if o["reply_to_key_id"] else None
        sent_at = o["sent_at"] or dt.datetime.now(dt.timezone.utc)

        def act(c):
            with w.lock:
                imap.select(c, sent_folder["name"], readonly=False)
                if not imap.search_header(c, "Message-ID", o["message_id"]):          # never a second copy
                    imap.append(c, sent_folder["name"], raw, ["\\Seen"], sent_at)
                if answered is not None:
                    try:
                        info = imap.select(c, answered["folder"], readonly=False)
                        if info["uidvalidity"] == int(answered["uidvalidity"]):
                            imap.answered(c, [int(answered["uid"])])
                    except Exception:  # noqa: BLE001 — the answered message may have moved; the flag is a courtesy
                        pass
                return True
        try:
            await self._on_pool(w, act)
        except (Busy, Stopped):
            return
        except Exception as e:  # noqa: BLE001
            log.info("outbound %s not filed yet: %s", oid, type(e).__name__)
            return
        await self.store.outbound_filed(oid)
        self.file_tries.pop(oid, None)
        if o["draft_id"] is not None:
            await self.store.pool.execute("UPDATE mail_drafts SET discarded_at = COALESCE(discarded_at, now()) WHERE id = $1", int(o["draft_id"]))
            self.drafts.kick()

    async def _on_pool(self, w, fn):
        def run():
            c = w.pool.acquire(10)
            broken = True
            try:
                out = fn(c)
                broken = False
                return out
            finally:
                w.pool.give_back(c, broken=broken)
        return await asyncio.to_thread(run)


def _text(v) -> str:
    return v.decode("utf-8", "replace") if isinstance(v, bytes) else str(v)
