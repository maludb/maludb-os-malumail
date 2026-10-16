# Worker brief — MaluMail slices 6–10 and Phase 4 (Sonnet 5.5)

You build ONE slice of MaluMail from its build spec. The planning model built Phase 2 and slices 1–5 (slice 1 the CRUD exemplar, slices 2–5
the list, reading, writing and oversight exemplars) and verifies and commits your work. Follow this brief exactly; the spec is the contract.

## Where and what you may touch
- The repository is `/srv/apps/malumail` (main branch, NOT installed — a scratch clone). Work in it directly.
- **Never commit, never push, never `git stash`/`reset`/`checkout`/`clean` away anyone's work.** Leave every change in the working tree; the
  planning model reviews the diff and commits.
- Never touch `/var/www` (the kernel) or another application under `/srv/apps/*` — read them for reference only. Never run `app_install.php`.
- Databases: only the scratch `ml_dev<N>` your proof makes. `sudo -n -u postgres` is allowed for scratch `ml_*` databases and the three
  `malumail_*` roles only — nothing else under sudo (no Apache, systemd, `/etc`).
- **Never reach MaluMail.** Every proof runs against the fixtures (`tests/fixtures/`). Never set `ML_LIVE_CREDENTIALS`, never open, read, list or
  print any credential file outside this repository, never log in to a real mail server — a wrong password there bans this whole machine. A
  slice's `live.py` step stays skipped in your runs.
- **This repository is public.** Never write a secret, a token, a password, a credential's location, an internal address, a privileged account
  name or a transcript into a file.

## Read before writing any code
1. `CLAUDE.md` — the rules and every slice's section (the lessons of slices 1–5 are there, not repeated here).
2. Your slice's spec in `docs/build-specs/` — every section: divergences, who may do what, screens, files ("exactly these"), query functions,
   mail-service calls, tools, manifest rows, log events, out of scope, proof, decisions, open questions.
3. The manifest rows it claims in `docs/malumail-action-manifest.md`, the tools in `docs/malumail-mcp-tool-surface.md`, the build specs it
   names (`mail-service.md`, `reading-and-rendering.md`, `sending-and-oversight.md`) and the design sections it names in
   `docs/malumail-design.md`; `docs/phase1-consistency.md` for decisions already taken.
4. The schema it names (`db/*.sql`) — read the functions and triggers you call; the database is the referee (the wall, the levels, refusals).
   **The wall has two implementations** (`ml_mailbox_level()` and db/017's set-based one, proven equal over 576 cases): never change one alone.
5. The exemplars, binding on shape and idiom:
   - slice 1 (CRUD screens): `app/features/admin/`, `html/admin/`, `app/views/admin/`, `tests/phase3/slice1/`;
   - slice 2 (lists, the poll, the mail service's sync): `app/features/mail/list.php`, `html/m/`, `services/mailer/{watch,index,store}.py`;
   - slice 3 (actions on mail through ONE runner, the mail service's `mutate`): `app/features/mail/reading.php` `mail_action_run()`,
     `html/mail/*.php`, `services/mailer/server.py`, `tests/phase3/slice3/`;
   - slice 4 (forms that write, the composer): `app/features/compose/`, `html/m/compose.php`;
   - slice 5 (people-only decisions, the worker's turns, the latest proof idiom): `app/features/oversight/`, `bin/worker.php`,
     `tests/phase3/slice5/` (`run.sh`, `checks.php`, `tools.py`, `browser.mjs`);
   - the kit: `app/handler.php`, `app/http.php`, `app/auth.php`, `app/bootstrap.php`, `app/db.php`, `app/activity.php`, `app/picker.php`,
     `app/mailer.php`, `app/views/layout.php`, `app/features/shell/nav.php`.
6. The builder plugin from its clone (the installed copy is stale): `~/htmx-php-builder/skills/php-patterns/`, `design-system/` (incl.
   `references/record-picker.md`), `mcp-servers/` for Phase 4, `chat-actions/` for the manifest; the contract from `~/maludb-os-integration`.

## The kit's idiom (must match)
- A write handler: `ml_handler_begin()` (POST + CSRF) → the mailbox and its level through the wall (a hidden mailbox is the same 404 as a
  missing one) → `require_right()` / `require_human()` where the spec says → field readers (`ml_text`, `ml_int`, `ml_choice`, `ml_ref`,
  `request_list`) collecting `$errors` → `ml_refuse_fields()` → the database's function in `ml_guard($pdo, fn)` (a transaction +
  `log_activity()`; the schema's refusals become 422/404/403 with the schema's sentence) or the mail service through `app/mailer.php` →
  `ml_done(message, id, land, 'hxEvent', extra)`. Every state-changing handler is POST + CSRF + authorization + `log_activity()`; every log row
  about a mailbox carries `mailbox_id`.
- **Mail is changed only by the mail service** (`mutate` and its siblings), never by PHP speaking IMAP; every fetch is a PEEK; an intake
  mailbox is never marked `\Seen` and its INBOX never moved (`ml_mutation_refusal()` says so — ask it first so the page says why).
- A screen: `require_login()`, `log_screen_view()`, `wants_json()` → `respond_screen()` from a whitelist presenter (never raw rows), else the
  page through `view()` and the layout. A screen you build replaces its placeholder: add its file; keep its gate the same as `screen_gate()`'s.
- `view()` uses `extract(EXTR_SKIP)`: never pass data keys named `template`, `data`, `path` or `viewsRoot`.
- Full pages, no modals (the record picker and `hx-confirm` are the only exemptions); works at 375 px with no sideways scroll; a form chooses a
  record through the record picker, never a `<select>` of rows; lists of named things are cards.
- Words, not codes, on screen; the spec's wording and element ids exactly.
- **The activity log records what people and agents do, never what mail says**: no body, subject, sender's name, folder name of a person's
  mailbox, template text, password or token in a log payload — ids, counts, digests.
- A global helper is prefixed `ml_` (one function namespace across features — a duplicate name is a fatal on some pages only).
- pdo_pgsql refuses a named placeholder used twice — name each use. PHP here has no `intl`.
- Match the surrounding code's comment density and naming.

## Migrations
Committed migrations are never modified. A schema defect you find gets a NEW numbered migration (the next free number — `ls db/`; a spec's
number may be stale —, additive, views keep `security_barrier`, grants checked afterwards), applied by `tests/setup_dev.sh` with the rest, and a
proof of its own added to `tests/phase0/schema.sh`'s list; recorded under "Found and fixed". Re-run `tests/phase0/schema.sh` after it.

## Ambiguity
If the spec is silent on a detail that changes nothing outside the slice (wording, layout, an order), take the reading closest to the exemplars and
the design and record it under "Decisions taken here". If an answer would change the schema's meaning, the wall, a right, a level, what an agent
may do, the manifest, the tool surface, or contradicts another spec — STOP: end your turn with a report beginning `STOPPED:` and the exact
question; you will be resumed with the answer. Never improvise around a gap, and never loosen a default or an allow-list to make a test pass.

## Proof (the slice is not done until all of this is green)
- `tests/phase3/slice<N>/run.sh` modelled on slice 5's: a fresh scratch `ml_dev<N>` (env `/tmp/ml-dev<N>.env`, state `/tmp/ml-dev<N>-state`,
  shots `/tmp/ml-shots-s<N>`), `tests/setup_dev.sh`, `tests/phase2/servers.sh` (app :9201, fake kernel :9202, fake MaluDB/MaluMail :9203,
  the mail service :9206, the image door :9207, Apache's internal vhost :9205; the IMAP/SMTP fixtures on ports of their own), the directory
  sync, a seed, one proof per area (PHP for pages and handlers incl. JSON mode and the log vocabulary; Python for the mail service and the
  records/activity queries), `browser.mjs` (Playwright from `/var/www/web/node_modules`: the slice's screens at 375×740 and 1280×800, no
  sideways scroll, no console error), the registry `--check`. Everything the spec's Proof section lists is a check. `ML_S<N>_HOLD=1` leaves
  the servers up for development.
- Run it under php -S AND `ML_APP=apache`; both must pass.
- Look at the screenshots (the Read tool shows images) — viewport shots — and fix what looks wrong (overflow, truncation, overlap).
- `php bin/build_action_registry.php` after the manifest rows are built; `--check` green.
- An earlier proof that asserted this slice's screens UNBUILT (a placeholder, a worker stub) is changed to follow whether they are built,
  never deleted.
- Every earlier suite stays green under both servers: `tests/phase2/run.sh`, `tests/phase3/slice{1..N-1}/run.sh` (each with and without
  `ML_APP=apache`), plus `tests/mailer/run.sh`, `tests/phase0/run.sh`, `tests/phase0/schema.sh`, `tests/phase1/check_specs.sh`; and
  `tests/sanitiser/run.sh` if anything under `services/mailer/sanitise.py` changed. Run them one after another in ONE shell command with their
  tails, not one tool call each — your turns are limited.

## Lessons (they cost hours)
- Never run two suites at once (they share ports 9201–9207). Do not pipe `servers.sh start` into another command.
- Do not add or rename files under `html/` while a suite is running — the registry check reads the tree.
- The `malumail_*` roles are cluster-wide: each `setup_dev.sh` resets their passwords, so only the newest scratch env works.
- Kill a stuck proof's processes by exact pid (`ps -eo pid,cmd`), never `pkill -f`/`pgrep -f` with a pattern that matches your own shell.
- `php -l` does not catch a missing `require_once`; a 500 in the server log usually is one. Read the server log.
- A psql command without the scratch env's password prompts and hangs: always source the env first (or use `sudo -n -u postgres`).
- Fixtures started after `servers.sh start` (the start kills every pid in the state directory).
- Other sessions run proofs on this host on other ports — not yours; leave them alone.
- Work in few, large steps: write whole files, then run; your turns are limited (an earlier builder hit the limit twice).

## Docs (in the same working tree)
- The spec gains `## Built and proven (<date>)`: what was built, **Proven** with each proof's count under both servers, **Found and fixed**,
  **Decisions taken here (within the spec)**.
- `CLAUDE.md` a `## Slice <N>` section in the form of slice 5's (the run command, the counts, the binding idiom, lasting rules);
  `README.md`'s state paragraph; `docs/malumail-design.md` §16 an entry in the form of slice 5's.

## Your final report (your last message)
1. `DONE` or `STOPPED: <question>`.
2. Files added and changed (a list), migrations added and why.
3. Each proof's count under php -S and under Apache; the registry's screens/actions built.
4. Every earlier suite re-run and its result (both servers).
5. Decisions taken; defects found and fixed; anything in the spec NOT done, and why.
6. A draft commit message in the repository's style (`Slice <N> — …: …`, like slice 5's `git log -1 fcfb20e`).
