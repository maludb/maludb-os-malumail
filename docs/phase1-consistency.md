# MaluMail — Phase 1 consistency (2026-10-11)

What Phase 1 decided beyond the approved design, what it changed in the shared documents, and what it leaves to the owner.
`tests/phase1/check_specs.sh` proves the documents agree with each other: every screen and action of the registry claimed by exactly
one slice spec, every tool of the surface named by a slice, every agent's grant real and named in its job description, every MUST of
research 03 claimed or held here by name.

## The documents

| Document | What it holds |
|---|---|
| `docs/malumail-mcp-tool-surface.md` | 36 records tools and 7 activity tools, the resolvers, the agents' grants |
| `docs/malumail-action-manifest.md` → `mcp/action_registry.json` | 45 screens, 67 actions (41 a person's alone, 3 paused for agents) |
| `docs/build-specs/mail-service.md`, `reading-and-rendering.md`, `sending-and-oversight.md` | the three build specs |
| `docs/build-specs/sso-shell.md` + ten slice specs | Phase 2 and Phase 3's slices 1–10 |
| `db/012_phase1_fixes.sql` | the schema fixes the specs found |

## Requirements not claimed by a slice
MUSTs (2): `R56`, `R63`

- **R56** — retention and legal hold: v1 deletes nothing on its own (design §11); retention, the 30-day purge of Trash and Spam and
  a legal hold are Extended, each its own spec.
- **R63** — MaluMail's own items on mbox01 (a master user, push notification, a Sieve path, SPECIAL-USE folders): the infrastructure
  side's, tracked as MaluMail items MM1–MM10 in design §12.

## Decisions taken in Phase 1

(Filled from the specs' "Decisions taken here" at reconciliation.)

## Changes to the shared documents

(Filled at reconciliation.)

## For the owner

(Filled at reconciliation.)
