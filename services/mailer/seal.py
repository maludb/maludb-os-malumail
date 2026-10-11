"""Credentials sealed with libsodium's sealed box (design §6.1–6.2): anyone holding the PUBLIC key seals (PHP, ML_SEAL_PUBLIC_KEY);
only the mail service, holding the SECRET key (ML_SEAL_SECRET_KEY in /etc/malumail/mailer.env), opens. PHP's
sodium_crypto_box_seal and PyNaCl's SealedBox are the same construction (libsodium crypto_box_seal)."""

from __future__ import annotations

import hashlib

from nacl.public import PrivateKey, PublicKey, SealedBox


def seal(public_key: bytes, secret: str) -> bytes:
    return SealedBox(PublicKey(public_key)).encrypt(secret.encode())


def open_sealed(secret_key: bytes, ciphertext: bytes) -> str:
    return SealedBox(PrivateKey(secret_key)).decrypt(ciphertext).decode()


def public_of(secret_key: bytes) -> bytes:
    return bytes(PrivateKey(secret_key).public_key)


def last4(ciphertext: bytes) -> str:
    """mailbox_credentials.last4: of the sealed box's SHA-256, never of the secret."""
    return hashlib.sha256(ciphertext).hexdigest()[-4:]
