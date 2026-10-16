"""The mail service's settings (design §6.1). In production systemd hands them over as the environment, from the application's
config/.env and /etc/malumail/mailer.env (the second wins; deploy/malumail-mailer.service). For the proofs, ML_DEV_ENV names
tests/setup_dev.sh's scratch file and its `-mailer.env` stand-in, read the way app/bootstrap.php reads ML_DEV_ENV — never set in
production. Nothing here is ever logged."""

from __future__ import annotations

import base64
import os
from dataclasses import dataclass
from pathlib import Path


def _read_env_file(path: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        v = v.strip()
        if len(v) >= 2 and v[0] == v[-1] and v[0] in "\"'":
            v = v[1:-1]
        out[k.strip()] = v
    return out


def environment() -> dict[str, str]:
    """The process's environment; for the proofs, the scratch files first and the environment over them (as systemd's would be)."""
    env: dict[str, str] = {}
    dev = os.environ.get("ML_DEV_ENV")
    if dev:
        p = Path(dev)
        env.update(_read_env_file(p))
        secret = p.with_name(p.name[: -len(".env")] + "-mailer.env") if p.name.endswith(".env") else None
        if secret and secret.exists():
            env.update(_read_env_file(secret))
    env.update(os.environ)
    return env


class ConfigError(Exception):
    pass


@dataclass(frozen=True)
class Config:
    app_env: str
    db_dsn: dict
    port: int
    mailer_key: bytes
    seal_secret: bytes | None          # None: no credential can be opened (the door still answers health and administration)
    customer_key: str | None           # MaluMail's customer key for the Mailbox MCP; None: administration answers 503
    mcp_url: str
    imap_host: str
    imap_port: int
    tls_ca_file: str | None            # proofs only: the scratch CA the fixture's certificate is signed by; refused in production
    pass_seconds: int
    max_connections: int = 400         # ML_MAILER_MAX_CONNECTIONS: every IMAP connection of the service, watchers and pools (§2)
    part_key: bytes = b""              # ML_PART_KEY: signs the one-hour part and image links the service mints as it sanitises (slice 3)
    smtp_host: str = "mbox01.malumail.com"   # ML_SMTP_HOST / ML_SMTP_PORT: submission (587, STARTTLS) — slice 4
    smtp_port: int = 587
    storage_dir: str = "storage"       # ML_STORAGE_DIR: the attachment store PHP writes (files/), read here to build the MIME
    text_per_minute: int = 4           # ML_MAILER_TEXT_PER_MINUTE: the text pass's commands a minute per mailbox (§3.4); the proofs raise it

    @staticmethod
    def load(env: dict[str, str] | None = None) -> "Config":
        e = environment() if env is None else env
        app_env = e.get("APP_ENV", "prod")
        key = e.get("ML_MAILER_KEY", "")
        if len(key) < 32:
            raise ConfigError("ML_MAILER_KEY is missing or shorter than 32 characters: the door would accept anything")
        seal = e.get("ML_SEAL_SECRET_KEY") or None
        seal_bytes = None
        if seal:
            seal_bytes = base64.b64decode(seal, validate=True)
            if len(seal_bytes) != 32:
                raise ConfigError("ML_SEAL_SECRET_KEY is not a libsodium box secret key (32 bytes, base64)")
        ca = e.get("ML_TLS_CA_FILE") or None
        if ca and app_env == "prod":
            raise ConfigError("ML_TLS_CA_FILE is for the proofs' fixture only and is refused in production: MaluMail's server is checked "
                              "against the system's certificate authorities")
        port = int(e.get("ML_MAILER_PORT") or 0)
        if not 1 <= port <= 65535:
            raise ConfigError("ML_MAILER_PORT is not set")
        return Config(
            app_env=app_env,
            db_dsn={"host": e.get("DB_HOST", "127.0.0.1"), "port": int(e.get("DB_PORT") or 5432), "database": e.get("DB_NAME", ""),
                    "user": e.get("DB_USER", ""), "password": e.get("DB_PASSWORD", "")},
            port=port,
            mailer_key=key.encode(),
            seal_secret=seal_bytes,
            customer_key=e.get("MALUMAIL_MAILBOX_API_KEY") or None,
            mcp_url=e.get("MALUMAIL_MCP_URL", "https://api.malumail.com/mcp"),
            imap_host=e.get("ML_IMAP_HOST", "mbox01.malumail.com"),
            imap_port=int(e.get("ML_IMAP_PORT") or 993),
            tls_ca_file=ca,
            pass_seconds=int(e.get("ML_MAILER_PASS_SECONDS") or 60),
            max_connections=max(4, int(e.get("ML_MAILER_MAX_CONNECTIONS") or 400)),
            text_per_minute=max(1, int(e.get("ML_MAILER_TEXT_PER_MINUTE") or 4)),
            part_key=(e.get("ML_PART_KEY") or "").encode(),
            smtp_host=e.get("ML_SMTP_HOST", "mbox01.malumail.com"),
            smtp_port=int(e.get("ML_SMTP_PORT") or 587),
            storage_dir=e.get("ML_STORAGE_DIR", "storage"),
        )
