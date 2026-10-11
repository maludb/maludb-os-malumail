"""Activity-memory ingestion bridge: MaluMail's activity_log -> the tenant's MaluDB episodes.

Copied from the kernel (memory.md §2); the one difference is the payload key
"application": "malumail", first, so recall knows which application an episode came from.
The rows carry ids, counts and domains, never mail text (D14): what reaches memory is what people
and agents did, never what mail says.
Runs on a systemd timer (malumail-activity-ingest.timer). Each run:
  1. reads the checkpoint from activity_ingest_state,
  2. fetches activity_log rows past it (oldest first, bounded batch),
  3. POSTs each row to the MaluDB API as an 'activity' episode
     (title = action, payload = the full log row),
  4. advances the checkpoint only past rows the API accepted.

Idempotence: the checkpoint only moves forward on success, so a failed run
re-sends from the same point. A duplicate episode is preferable to a lost one —
activity memory cannot be backfilled once the app moves on.
"""
from __future__ import annotations

import asyncio
import json
import sys

import asyncpg
import httpx

from db import ENV

BATCH_LIMIT = 500


def _episode_body(row: dict) -> dict:
    action = row["action"] or "unknown"
    actor = f"member #{row['actor_member_id']}" if row["actor_member_id"] else "system"
    title = f"{action} by {actor}"
    payload = {
        "application": ENV.get("APP_KEY", "malumail"),
        "activity_log_id": row["id"],
        "actor_member_id": row["actor_member_id"],
        "source": row["source"],
        "action": action,
        "screen": row["screen"],
        "route": row["route"],
        "entity_type": row["entity_type"],
        "entity_id": row["entity_id"],
        "before": row["before"],
        "after": row["after"],
        "request_id": row["request_id"],
        "session_id": row["session_id"],
        "agent_run_id": row["agent_run_id"],
        "department_id": row["department_id"],
        "kernel_request_id": row["kernel_request_id"],
        "location_id": row["location_id"],
        "token_id": row["token_id"],                      # the key an event ran under (its label is in `after`, never the key)
        "mailbox_id": row["mailbox_id"],                  # a mailbox's trail: memory can answer "who released the held mail on sales@ yesterday"
    }
    return {
        "kind": "activity",
        "title": title,
        "summary": None,
        "occurred_at": row["occurred_at"].isoformat(),
        "sensitivity": "internal",
        "provenance": "provided",
        "payload": {k: v for k, v in payload.items() if v is not None},
    }


async def main() -> int:
    api_url = ENV.get("MALUDB_API_URL", "").rstrip("/")
    token = ENV.get("MALUDB_API_TOKEN", "")
    if not api_url or not token:
        print("MALUDB_API_URL / MALUDB_API_TOKEN not configured — nothing to do.")
        return 0

    con = await asyncpg.connect(
        host=ENV.get("DB_HOST", "127.0.0.1"),
        port=int(ENV.get("DB_PORT", "5432")),
        database=ENV.get("DB_NAME", "malumail"),
        user=ENV.get("DB_USER", "malumail_rw"),
        password=ENV.get("DB_PASSWORD", ""),
    )
    try:
        # Single-flight: the systemd timer and any manual run must never overlap,
        # or the checkpoint can regress and re-ship rows.
        locked = await con.fetchval("SELECT pg_try_advisory_lock(hashtext('activity_ingest'))")
        if not locked:
            print("another ingest run holds the lock — skipping.")
            return 0
        last_id = await con.fetchval("SELECT last_id FROM activity_ingest_state WHERE id = 1")
        rows = await con.fetch(
            """SELECT id, occurred_at, actor_member_id, source, action, screen, route,
                      entity_type, entity_id, before, after, request_id, session_id,
                      agent_run_id, department_id, location_id, kernel_request_id, token_id, mailbox_id
                 FROM activity_log WHERE id > $1 ORDER BY id LIMIT $2""",
            last_id, BATCH_LIMIT,
        )
        if not rows:
            return 0

        shipped = 0
        async with httpx.AsyncClient(timeout=30) as client:
            for r in rows:
                row = dict(r)
                # jsonb columns arrive as strings from asyncpg by default
                for k in ("before", "after"):
                    if isinstance(row[k], str):
                        try:
                            row[k] = json.loads(row[k])
                        except ValueError:
                            pass
                resp = await client.post(
                    f"{api_url}/v1/episodes",
                    headers={"Authorization": f"Bearer {token}"},
                    json=_episode_body(row),
                )
                if resp.status_code not in (200, 201):
                    print(f"episode POST failed at activity_log id {row['id']}: "
                          f"{resp.status_code} {resp.text[:200]}", file=sys.stderr)
                    break
                last_id = row["id"]
                shipped += 1

        if shipped:
            await con.execute(
                "UPDATE activity_ingest_state SET last_id = GREATEST(last_id, $1), updated_at = now() WHERE id = 1",
                last_id,
            )
        print(f"shipped {shipped}/{len(rows)} activity rows; checkpoint now {last_id}")
        return 0 if shipped == len(rows) else 1
    finally:
        await con.close()


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
