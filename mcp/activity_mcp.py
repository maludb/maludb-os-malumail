"""malumail_activity_mcp — MaluMail's activity-memory server (docs/malumail-mcp-tool-surface.md "Activity server"). Every query runs as the asking
member (and, in an agent's run for a person, that person) behind the same wall as the mailboxes: a mailbox's trail for whoever may open it, its
administration for the Mail admins, a row about no mailbox for its actor (mcp_activity_log, db/023). The log is the same rows activity_ingest.py ships
to the tenant's MaluDB as `activity` episodes; the tools read the local log, which is the wall's, rather than the memory, which has none (Boards,
Spaces and Shareholder Intelligence made the same decision). A payload carries ids, counts, levels and domains — never mail (db/002) — and a subject
excerpt of an agent's or shared mailbox is fenced like every string that came in a message (ml_tools). Read-only; the one write is the access-log row
of a read of a mailbox (ml_log_mcp_read)."""
from mcp.server.fastmcp import FastMCP
from pydantic import Field

import activity_queries as aq
import db
from ml_tools import RO, Base, Ref, caller, finish
from records_server import Caller

mcp = FastMCP("malumail_activity_mcp", stateless_http=True)
F = Field
SINCE = F(None, description="Only events on or after this ISO 8601 date or time")


async def _pool():
    return await db.get_pool(db.ENV["MCP_ACTIVITY_DB_USER"], db.ENV["MCP_ACTIVITY_DB_PASSWORD"])


class MailboxTrailIn(Base):
    mailbox: Ref = F(..., description="The mailbox: its id or its address")
    since: str | None = SINCE
    limit: int = F(50, ge=1, le=100, description="How many rows at most (1–100, default 50)")


class ReviewTrailIn(Base):
    mailbox: Ref | None = F(None, description="Only this mailbox (id or address)")
    since: str | None = SINCE
    limit: int = F(50, ge=1, le=100, description="How many rows at most (1–100, default 50)")


class MemberActivityIn(Base):
    member: Ref = F(..., description="A person or an agent: id, name, or me")
    since: str | None = SINCE
    limit: int = F(50, ge=1, le=100, description="How many rows at most (1–100, default 50)")


class RecordHistoryIn(Base):
    record_type: str = F(..., pattern="^(mailbox|alias|policy|permission|domain|outbound)$", description="mailbox, alias, policy (by the agent mailbox's id), permission, domain or outbound")
    record_id: int = F(..., description="The record's number")
    limit: int = F(50, ge=1, le=100, description="How many rows at most (1–100, default 50)")


class AgentActivityIn(Base):
    agent: Ref | None = F(None, description="Only this agent (id or name)")
    since: str | None = SINCE
    limit: int = F(25, ge=1, le=100, description="How many agents at most (1–100, default 25)")


class ActivitySearchIn(Base):
    q: str = F(..., min_length=2, max_length=200, description="Words — an action (permission), a person's name, a domain; each word must match")
    since: str | None = SINCE
    limit: int = F(25, ge=1, le=100, description="How many rows at most (1–100, default 25)")


class RecentIn(Base):
    limit: int = F(50, ge=1, le=100, description="How many rows at most (1–100, default 50)")


def reg(name: str, model, fn, title: str, description: str) -> None:
    async def run(params) -> str:
        return finish(name, await fn(await _pool(), caller(), **params.model_dump(exclude_none=True)))
    run.__name__, run.__annotations__ = name, {"params": model, "return": str}
    mcp.tool(name=name, description=description, annotations={"title": title, **RO})(run)


reg("mailbox_trail", MailboxTrailIn, aq.mailbox_trail, "Who opened a mailbox", "Who opened the mailbox (not its owner), when, through what door — its owner's and the Mail admins'; below_level for anyone else.")
reg("review_trail", ReviewTrailIn, aq.review_trail, "Decisions on held mail", "The decisions on held mail: who, what, how long after the message was held — in the mailboxes you triage.")
reg("member_activity", MemberActivityIn, aq.member_activity, "What someone did in mail", "What a person or an agent did in mail — sends, files, decisions — in the mailboxes you may open.")
reg("record_history", RecordHistoryIn, aq.record_history, "A record's changes", "One record's changes, by whom and when: a mailbox, a forwarding address, an agent mailbox's policy, a permission, a domain, a message in the outbox.")
reg("agent_activity", AgentActivityIn, aq.agent_activity, "Agents' mail activity", "Per agent: sent, held, rejected, edited, refused, dispatches — for the agent mailboxes you oversee.")
reg("activity_search", ActivitySearchIn, aq.activity_search, "Search the trail", "The guarded search over the trail: words, each of which must be in the action, the actor's name or the event's details; never SQL.")
reg("recent_activity", RecentIn, aq.recent_activity, "Latest events", "The latest events in the mailboxes you may open.")


def main() -> None:
    import server_common
    server_common.run(mcp, "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", port=int(db.ENV["MCP_ACTIVITY_PORT"]), endpoint_name="Activity MCP")


if __name__ == "__main__":
    main()
