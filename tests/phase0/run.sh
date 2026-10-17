#!/usr/bin/env bash
# Phase 0 proof, repeatable: the schema (db/proof/phase0_proof.sql, the schema's checks — skipped with a line when the file is
# not there yet) and the kit without a kernel (testing-without-a-kernel.md §4) — the directory fixture applied, the roles from
# access[], MaluMail's two roles and the walls on who may hold a right (a person's decisions never an agent's; the admin rights a
# super-admin's alone, D2), the settings as §6.5 and D4–D10 seed them, a hand-off minted the kernel's way and presented, its replay /
# expiry / another audience / an unknown member / a tampered signature / a member with no grant refused, every refusal logged, a
# member admitted with no MaluMail role told why, health answering, CSRF, the activity funnel with the mailbox's id, a person's key
# and the rate rule, the sign-out notice ending sessions, a grant the kernel revoked shutting the door, the worker's empty pass, the
# ingest bridge against a fake MaluDB, and PHP holding no MaluMail secret that opens a mailbox. Scratch database only (ml_dev0); the
# installed application (if any) is never touched, and nothing here reaches MaluMail. Ports 9201 (the app), 9202 (the fake kernel),
# 9203 (the fake MaluDB + MaluMail REST); 9204–9205 are the MCP servers' (Phase 4), 9206 the mail service's, 9207 the image door's.
# Prints ok/FAIL per check and a count; exits non-zero on any failure. Needs `sudo -n -u postgres` (scratch ml_* databases and the
# three malumail_* roles only), php 8.3 with sodium, curl, openssl, and for the ingest check a python with asyncpg + httpx
# (/var/www/mcp/venv/bin/python, else $ML_PY).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export ML_DEV_DB=${ML_DEV_DB:-ml_dev0}
export ML_DEV_ENV=${ML_DEV_ENV:-/tmp/ml-dev0.env}
PY=${ML_PY:-/var/www/mcp/venv/bin/python}
PORT=9201; P_KERNEL=9202; P_FAKE=9203
TMP=$(mktemp -d /tmp/ml-kit.XXXXXX)
pass=0; fail=0; PIDS=()
ok()   { echo "ok   $1"; pass=$((pass+1)); }
bad()  { echo "FAIL $1"; fail=$((fail+1)); }
check(){ if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got: $1, wanted: $2)"; fi; }
has()  { if grep -q -- "$2" <<<"$1"; then ok "$3"; else bad "$3 (missing: $2)"; fi; }
hasnt(){ if grep -q -- "$2" <<<"$1"; then bad "$3 (found: $2)"; else ok "$3"; fi; }
sql()  { sudo -n -u postgres psql -d "$ML_DEV_DB" -Atc "$1"; }
as()   { sql "select set_config('app.member_id', '$1', false); select $2" | tail -1; }
cleanup() { local rc=$?; for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done; rm -rf "$TMP"; exit $rc; }
trap cleanup EXIT INT TERM
for port in $PORT $P_KERNEL $P_FAKE; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done

bash "$ROOT/tests/setup_dev.sh" >/dev/null || { echo "scratch database failed"; exit 1; }
set -a; . "$ML_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$PORT" OS_INTERNAL_URL="http://127.0.0.1:$P_KERNEL" MALUDB_API_URL="http://127.0.0.1:$P_FAKE" MALUMAIL_API_URL="http://127.0.0.1:$P_FAKE"
export FAKE_KERNEL_STATE="$TMP/kernel.json" FAKE_MALUDB_LOG="$TMP/maludb.log" FAKE_MALUMAIL_LOG="$TMP/malumail.log"
cd "$ROOT"
APP="http://127.0.0.1:$PORT"

echo "== the schema (db/proof/phase0_proof.sql)"
if [ -n "${ML_MIGRATIONS_UPTO:-}${ML_DEV_UPTO:-}" ]; then
  echo "skip db/proof/phase0_proof.sql — the migrations stop at ${ML_MIGRATIONS_UPTO:-$ML_DEV_UPTO} (the kit alone)"
elif [ -f "$ROOT/db/proof/phase0_proof.sql" ]; then
  out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$ML_DEV_DB" -f "$ROOT/db/proof/phase0_proof.sql" 2>&1)
  echo "$out" | grep -E '^ ?FAIL' || true
  summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
  failed=$(echo "$summary" | sed -E 's/.* ([0-9]+) failed.*/\1/')
  check "$failed" "0" "schema proof: $summary"
else
  echo "skip db/proof/phase0_proof.sql is not written yet (the schema builder's) — the kit's checks below run on whatever db/*.sql applies"
fi

echo "== the contract tables (db/001–005)"
check "$(sql "select string_agg(role_key, ',' order by sort_order) from mcp_app_roles")" "user,admin" "the two roles, in order (os.app-roles/1; design §2, D2)"
check "$(sql "select string_agg(role_key, ',') from ml_roles where is_admin")" "admin" "exactly one admin role"
check "$(sql "select string_agg(role_key || '=' || capability, ',' order by sort_order) from ml_roles")" "user=write,admin=admin" "each role's capability (the base role keyed user, so the installer's standing grant makes a Mail user); no read-only role"
check "$(sql "select string_agg(role_key || ':' || cardinality(rights), ',' order by sort_order) from mcp_app_roles")" "user:3,admin:9" "Mail user 3 rights, Mail admin all 9"
check "$(sql "select string_agg(right_key, ',' order by right_key) from ml_role_rights where role_key = 'user'")" "mail.oversee,mail.use,tokens.own" "a Mail user reads and writes, oversees, keeps tokens"
check "$(sql "select string_agg(held_by || ':' || right_key, ',' order by held_by, right_key) from ml_rights where held_by <> 'anyone'")" "people:mail.oversee,super_admins:access.audit,super_admins:agents.manage,super_admins:aliases.manage,super_admins:domains.manage,super_admins:mailboxes.manage,super_admins:settings.manage" "who may hold a right: oversight is a person's, every admin right a super-admin's (design §3, D2)"
check "$(sudo -n -u postgres psql -d "$ML_DEV_DB" -qAt -c "update ml_rights set held_by = 'agents' where right_key = 'mail.use'" 2>&1 | grep -c 'violates check constraint')" "1" "…and held_by admits nothing else"
check "$(sudo -n -u postgres psql -d "$ML_DEV_DB" -v ON_ERROR_STOP=1 -qAt -c "begin; insert into activity_log (source, action, after) values ('system', 'review.expire', '{\"count\":3}'); select source from activity_log; rollback;" 2>&1 | head -1)" "system" "activity_log admits source system (the worker's own passes)"
check "$(sudo -n -u postgres psql -d "$ML_DEV_DB" -v ON_ERROR_STOP=1 -qAt -c "insert into activity_log (source, action) values ('portal2', 'x.y')" 2>&1 | grep -c 'violates check constraint')" "1" "…and refuses a source outside the list"
check "$(sql "select string_agg(column_name || ':' || data_type, ',' order by ordinal_position) from information_schema.columns where table_name = 'activity_log' and column_name in ('token_id','mailbox_id')")" "token_id:bigint,mailbox_id:bigint" "the audit keys appended to activity_log (token_id; mailbox_id, design §6.6 — a mailbox's trail and access log one index)"
check "$(sql "select undo_send_seconds || ':' || images_default || ':' || index_text_max_kb || ':' || max_attachments_mb || ':' || draft_save_seconds || ':' || poll_visible_seconds || '/' || poll_hidden_seconds from ml_settings")" "10:ask:100:7:10:15/60" "reading and writing seeded as D4/D6/D7/D8 say (undo 10 s, images on request, 100 kB of text, 7 MB, drafts every 10 s, poll 15/60 s)"
check "$(sql "select held_expiry_hours || ':' || default_send_mode || ':' || default_first_contact_review || ':' || default_attachments_review || ':' || default_max_external_per_day || '/' || default_max_per_hour || '/' || default_max_per_thread_per_day || ':' || default_wake_from || ':' || default_disclosure from ml_settings")" "72:hold_all:true:true:20/50/5:internal_and_known:true" "a new agent mailbox's policy seeded as D10/D11 say (hold_all, first contact and attachments held, 20/50/5, known senders wake, disclosure on; held mail expires at 72 h)"
check "$(sql "select coalesce(agent_external_budget::text, 'warm-up') || ':' || coalesce(agent_budget_started_at::text, 'not-started') || ':' || hold_all_agents || ':' || hold_all_external || ':' || notice_version || ':' || clerk_note_at || ':' || key_rate_per_minute || '/' || key_rate_per_day from ml_settings")" "warm-up:not-started:false:false:1:07:10:00:60/5000" "the business budget follows the warm-up until the first agent send; both switches off; notice version 1; the Clerk's hour; the key rates"
check "$(sudo -n -u postgres psql -d "$ML_DEV_DB" -v ON_ERROR_STOP=1 -qAt -c "begin; update ml_settings set notice_text = notice_text || ' Changed.'; select notice_version from ml_settings; rollback;" 2>&1 | head -1)" "2" "a new notice text is a new version (everyone acknowledges it again)"
check "$(sudo -n -u postgres psql -d "$ML_DEV_DB" -qAt -c "update ml_settings set notice_version = 9" 2>&1 | grep -c 'follows its text')" "1" "…and the version never moves without the text"
check "$(sudo -n -u postgres psql -d "$ML_DEV_DB" -qAt -c "update ml_settings set disclosure_text = 'Sent by software.'" 2>&1 | grep -c 'violates check constraint')" "1" "the disclosure must name the agent ({agent})"
check "$(sudo -n -u postgres psql -d "$ML_DEV_DB" -qAt -c "update ml_settings set poll_visible_seconds = 120" 2>&1 | grep -c 'violates check constraint')" "1" "a visible poll slower than the hidden one is refused"
check "$(sudo -n -u postgres psql -d "$ML_DEV_DB" -qAt -c "update ml_settings set undo_send_seconds = 31" 2>&1 | grep -c 'violates check constraint')" "1" "undo send is 0–30 seconds (D8)"
check "$(sql "select count(*) from information_schema.role_table_grants where grantee in ('malumail_records_ro','malumail_activity_ro') and table_name in ('activity_log','mcp_access_tokens','members','key_usage','ml_settings')")" "0" "the read roles hold no grant on the contract tables (the mcp_* views are their window)"

echo "== the mirror (the fixture through directory_sync --from-file)"
php bin/directory_sync.php --from-file bin/dev_directory.json >/dev/null 2>&1; check "$?" "0" "the directory fixture applies"
check "$(sql "select count(*) || ' of ' || count(*) filter (where capability is not null) from members")" "11 of 10" "eleven members mirrored, ten admitted (the Watcher holds no grant)"
check "$(sql "select roles::text from members where id = 27")" "{admin,user}" "roles map from the feed's access[]: Marco holds Mail admin and Mail user"
check "$(sql "select is_agent::int || ',' || roles::text || ',' || display_name from members where id = 42")" "1,{user},SMOKE Clerk" "the Mail Clerk is an agent (is_agent generated) holding Mail user"
check "$(sql "select is_external::int || ',' || capability || ',' || roles::text from members where id = 29")" "1,read,{}" "Ann is external and holds read; the role the kernel named (viewer) is not MaluMail's and is dropped"
check "$(sql "select ml_member_roles(28)::text || ' ' || ml_member_roles(31)::text || ' ' || ml_member_roles(1)::text || ' ' || ml_member_roles(41)::text || ' ' || ml_member_roles(43)::text")" "{user} {} {admin} {} {admin}" "ml_member_roles: write with no roles yet is Mail user, read is NO role (a read grant never sends mail); the super-admin admin; an unadmitted agent nothing; Ada holds what she was given"

echo "== roles, rights and who may hold them (ml_has_right, ml_member_has_right, ml_is_mail_admin, ml_right_refusal)"
check "$(as 1 "ml_is_mail_admin() and ml_has_right('mailboxes.manage') and ml_has_right('aliases.manage') and ml_has_right('domains.manage') and ml_has_right('access.audit') and ml_has_right('settings.manage') and ml_has_right('mail.use') and ml_has_right('mail.oversee') and ml_right_refusal('mailboxes.manage') is null")" "t" "the super-admin runs MaluMail: every right (D2)"
check "$(as 27 "not ml_is_mail_admin() and not ml_has_right('mailboxes.manage') and not ml_has_right('aliases.manage') and not ml_has_right('access.audit') and not ml_has_right('settings.manage') and ml_has_right('mail.use') and ml_has_right('mail.oversee') and not app_is_super_admin()")" "t" "Marco holds the Mail admin role but is no super-admin: every admin right refused, a Mail user's kept (D2)"
check "$(as 27 "ml_right_refusal('mailboxes.manage')")" "MaluMail's administration is for the OS's super-admins. You hold the Mail admin role, but you are not a super-admin." "…and is told why, never a bare 403"
check "$(as 26 "ml_has_right('mail.use') and ml_has_right('mail.oversee') and ml_has_right('tokens.own') and not ml_has_right('mailboxes.manage') and not ml_is_mail_admin() and ml_is_member_here()")" "t" "a Mail user reads, writes and oversees; administers nothing"
check "$(as 26 "ml_right_refusal('aliases.manage')")" "Only a Mail admin — a super-admin of the OS — can do that." "…told so in a sentence"
check "$(as 28 "ml_has_right('mail.use') and ml_is_member_here()")" "t" "a member whose roles have not arrived is read from the capability (write = Mail user)"
check "$(as 29 "app_is_active_member() and not ml_is_member_here() and not ml_has_right('mail.use') and not ml_has_right('tokens.own')")" "t" "Ann, admitted with read and no MaluMail role, holds nothing here (fail closed)"
check "$(as 31 "ml_right_refusal('mail.use')")" "Your access to MaluMail gives no role yet. A super-admin gives the Mail user role." "Lee (read, no roles) is told why"
check "$(as 40 "ml_has_right('mail.use') and app_member_kind() = 'agent' and ml_is_member_here() and not ml_has_right('mail.oversee') and not ml_is_mail_admin()")" "t" "the Mail Expert (an agent granted Mail user) uses mail but never holds a person's decision — oversight is refused to every agent (design §3)"
check "$(as 40 "ml_right_refusal('mail.oversee')")" "That is a person's decision: an agent never decides it." "…told so"
check "$(as 43 "not ml_has_right('mailboxes.manage') and not ml_has_right('settings.manage') and not ml_is_mail_admin() and ml_has_right('mail.use')")" "t" "Ada, an agent GIVEN the Mail admin role, administers nothing"
check "$(as 43 "ml_right_refusal('mailboxes.manage')")" "MaluMail's administration is for people: an agent never administers mail." "…told so"
check "$(as 41 "not app_is_active_member() and not ml_has_right('mail.use') and not ml_is_member_here()")" "t" "an agent not yet admitted holds nothing (fail closed)"
check "$(as '' "ml_has_right('mail.use') or ml_is_member_here() or ml_is_mail_admin()")" "f" "nobody (no app.member_id) holds nothing"
check "$(sql "select ml_member_has_right(26, 'mail.use')::text || ',' || ml_member_has_right(27, 'mailboxes.manage')::text || ',' || ml_member_has_right(1, 'mailboxes.manage')::text || ',' || ml_member_has_right(42, 'mail.oversee')::text || ',' || ml_member_has_right(30, 'mail.oversee')::text")" "true,false,true,false,true" "ml_member_has_right answers for a given member (Dana, a person, may oversee; the Clerk, an agent, may not)"

echo "== sign-on"
php -r '$f = json_decode(file_get_contents("bin/dev_directory.json"), true); file_put_contents(getenv("FAKE_KERNEL_STATE"), json_encode(["feed" => $f["feed"]]));'
php -S 127.0.0.1:$P_KERNEL tests/fake_kernel.php >"$TMP/kernel.log" 2>&1 & PIDS+=($!)
php -S 127.0.0.1:$P_FAKE tests/fake_maludb.php >"$TMP/fake.log" 2>&1 & PIDS+=($!)
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$PORT -t html tests/dev_router.php >"$TMP/app.log" 2>&1 & PIDS+=($!)
sleep 1.2
check "$(curl -s -o /dev/null -w '%{http_code}' $APP/api/v1/health)" "200" "health answers 200"
check "$(curl -s $APP/api/v1/health | php -r '$h = json_decode(stream_get_contents(STDIN), true); echo ($h["application"] ?? "?") . ":" . ($h["storage"] ?? "?") . ":" . ($h["mailer"] ?? "?") . ":" . ($h["images"] ?? "?");')" "malumail:ok:down:down" "…naming the application malumail, its attachment store writable, the mail service and the image door down — reported, never a failure of this health"
url27=$(php bin/dev_handoff.php 27)
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$TMP/jar27" "$url27")" "302 $APP/" "a signed hand-off token opens a session (302 to /)"
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$TMP/jar27" "$APP/")" "302 $APP/notice?next=%2F" "the session is Marco's: home sends him to the notice he has not read (Phase 2's gate)"
has "$(curl -s -b "$TMP/jar27" "$APP/notice")" 'id="header-user-name">SMOKE Marco<' "…whose page names the signed-in member"
check "$(curl -s -o /dev/null -w '%{http_code}' "$url27")" "403" "the same token a second time is refused (replay)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(HANDOFF_TTL=-120 php bin/dev_handoff.php 26)")" "403" "an expired token is refused"
bad_url=$(APP_KEY=other php bin/dev_handoff.php 26 | sed "s#$APP##")
check "$(curl -s -o /dev/null -w '%{http_code}' "$APP$bad_url")" "403" "a token minted for another application is refused (audience)"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(php bin/dev_handoff.php 999)")" "403" "an unknown member is refused"
check "$(sql "select count(*) from members where id = 999")" "0" "…and is never created in the mirror"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(HANDOFF_NO_GRANT=1 php bin/dev_handoff.php 30)")" "403" "a hand-off whose claims carry no grant is refused (capability)"
pyld=$(echo "$url27" | sed -E 's/.*token=([^.]+\.[^.]+\.[^.]+\.[^.]+)\.[a-f0-9]+.*/\1/')
check "$(curl -s -o /dev/null -w '%{http_code}' "$APP/sso?token=$pyld.$(printf '0%.0s' $(seq 1 64))&claims=x.y")" "403" "a tampered signature is refused"
check "$(sql "select string_agg(after->>'reason', ',' order by id) from activity_log where action = 'member.sign_on.refused'")" "replay,token,token,capability,capability,token" "every refusal is logged with its reason (an unknown member's claims carry no grant: capability); none is told to the visitor"
check "$(curl -s -o /dev/null -w '%{http_code}' "$APP/")" "302" "an anonymous visit to / is sent to the launcher (never a login form)"
check "$(sql "select count(*) from member_sessions where member_id = 27 and ended_at is null")" "1" "the session is listed for the kernel's sign-out notice"
check "$(sql "select (after->'roles')::text from activity_log where action = 'member.sign_on' and entity_id = 27")" '["admin", "user"]' "member.sign_on logged with the roles"
code29=$(curl -s -o /dev/null -w '%{http_code}' -c "$TMP/jar29" "$(php bin/dev_handoff.php 29)"); check "$code29" "302" "Ann (external, read) signs on — the kernel's grant stands"
home29=$(curl -s -w '\n%{http_code}' -b "$TMP/jar29" "$APP/")
check "$(echo "$home29" | tail -1)" "403" "…but with no MaluMail role the home refuses her"
has "$home29" 'Your access to MaluMail gives no role yet' "…saying why (ml_right_refusal), never a bare 403"
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$TMP/jarn" "$(php bin/dev_handoff.php 26)&next=/mail/1/inbox")" "302 $APP/mail/1/inbox" "the hand-off lands on the launcher's next path when it is local"
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$TMP/jarx" "$(php bin/dev_handoff.php 26)&next=https://evil.example/")" "302 $APP/" "…and on / when it is not"

echo "== CSRF"
tok=$(curl -s -b "$TMP/jar27" "$APP/my/trail" | sed -n 's/.*name="csrf_token" value="\([a-f0-9]*\)".*/\1/p' | head -1)
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" -X POST "$APP/logout.php")" "403" "a POST without a CSRF token is refused"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" -X POST -d 'csrf_token=deadbeef' "$APP/logout.php")" "403" "a POST with a bad CSRF token is refused"
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST -d 'csrf_token=x' "$APP/")" "302 https://app.example.invalid/launcher?app=malumail" "an anonymous POST to a screen goes to the launcher (there is no login form here)"
check "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -X POST "$APP/")" "401" "…and answers a JSON caller 401, never a bare 403"

echo "== the activity funnel"
out=$(php tests/phase0/probe.php log 2>/dev/null)
check "$(sql "select mailbox_id || ':' || entity_id || ':' || entity_type || ':' || source from activity_log where action = 'probe.event'")" "1:77:message:cron" "log_activity writes the mailbox's id in mailbox_id beside the entity (source cron under the CLI)"
check "$(sql "select mailbox_id from activity_log where action = 'probe.mailbox'")" "2" "…and takes the mailbox from the entity when the entity IS a mailbox"
check "$(sql "select count(*) || ':' || count(mailbox_id) || ':' || count(entity_id) from activity_log where action = 'probe.bad_mailbox'")" "1:0:0" "…and drops a mailbox id or an entity id that is not a positive number without losing the row"
check "$(echo "$out" | tr '\n' ' ')" "logged survived " "log_activity never throws on a bad actor, an unencodable payload or a bad key"

echo "== keys: a person's own, rate-limited"
RAW="mcp_$(openssl rand -hex 24)"; HASH=$(printf '%s' "$RAW" | openssl dgst -sha256 | sed 's/.* //')
sql "insert into mcp_access_tokens (member_id, label, token_hash, scope) values (26, 'SMOKE key', '$HASH', 'api')" >/dev/null
TOK=$(sql "select id from mcp_access_tokens where token_hash = '$HASH'")
check "$(sql "select rate_per_minute || '/' || rate_per_day from mcp_access_tokens where id = $TOK")" "60/5000" "a key's limits default from the settings (the trigger: 60/min, 5,000/day)"
check "$(sql "select member_id || ':' || label || ':' || rate_per_minute from ml_resolve_key('$HASH')")" "26:SMOKE key:60" "a key resolves to its person, label and limits (ml_resolve_key)"
check "$(sudo -n -u postgres psql -d "$ML_DEV_DB" -qAt -c "insert into mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute) values (26, 'SMOKE bad', 'x$(openssl rand -hex 8)', 'mcp', 5)" 2>&1 | grep -c 'violates check constraint')" "1" "a person's mcp token can never carry a key's limits"
r1=$(php tests/phase0/probe.php apikey "$RAW" 2>&1 | tr '\n' ' ')
has "$r1" "authenticated:26:key=yes:label=SMOKE key" "the token API authenticates a key as its person"
check "$(sql "select token_id || ':' || source || ':' || (after->>'label') from activity_log where action = 'probe.api'")" "$TOK:api:SMOKE key" "…every row under a key carries token_id, source api and the key's label, never the key"
MCP="mcp_$(openssl rand -hex 24)"; MH=$(printf '%s' "$MCP" | openssl dgst -sha256 | sed 's/.* //')
sql "insert into mcp_access_tokens (member_id, label, token_hash, scope) values (26, 'SMOKE mcp', '$MH', 'mcp')" >/dev/null
has "$(php tests/phase0/probe.php apikey "$MCP" 2>&1 | tr '\n' ' ')" '"code":"unauthorized"' "a person's mcp token is not a door to the token API"
# 61 calls in one statement: the key's API call above counts when it fell in this same minute (then 59 more pass and 2 are refused), not when the
# minute turned since (then 60 pass and 1 is refused) — either way the 61st call of a minute is refused. (A run crossing the minute failed the old form.)
rl=$(sql "select string_agg(ok::text, ',' order by n) from (select n, (ml_rate_ok($TOK)).ok from generate_series(1, 61) n) s" | tr ',' '\n' | sort | uniq -c | tr -s ' ' | tr '\n' ';')
case "$rl" in " 2 false; 59 true;"|" 1 false; 60 true;") ok "the rate rule counts 60 calls a minute and refuses the 61st (ml_rate_ok) — $rl" ;; *) bad "the rate rule (got: $rl, wanted 60 a minute with the earlier call counted or not)" ;; esac
NEW=$(sql "select ml_key_rotate($TOK, '$(openssl rand -hex 32)')"); check "$(sql "select rotated_from = $TOK and rate_per_minute = 60 from mcp_access_tokens where id = $NEW") $(sql "select expires_at <= now() + interval '25 hours' from mcp_access_tokens where id = $TOK")" "t t" "rotation mints a key of the same person and limits and gives the old one 24 hours"

echo "== the kernel's word shuts the doors"
notice="27.$(date +%s).malumail"; sig=$(printf 'sso-logout:%s' "$notice" | openssl dgst -sha256 -hmac "$ACTION_TOKEN_KEY" | sed 's/.* //')
check "$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=$notice.$sig" "$APP/sso/logout")" "204" "the kernel's sign-out notice answers 204"
check "$(sql "select ended_by from member_sessions where member_id = 27 order by created_at desc limit 1")" "kernel" "…and ends the member's session"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar27" "$APP/")" "302" "…so the next request goes to the launcher"
check "$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=27.1.malumail.deadbeef" "$APP/sso/logout")" "204" "a bad notice also answers 204 and changes nothing"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar29" "$APP/")" "403" "Ann's session stands while her grant does (refused the home for want of a role, not sent away)"
php -r '$s = json_decode(file_get_contents(getenv("FAKE_KERNEL_STATE")), true); $s["incremental"] = ["schema" => "os.directory-changes/1", "since" => "2026-01-01T00:00:00.000000Z", "next" => "2026-01-02T00:00:00.000000Z", "full" => false, "members" => [], "departments" => [], "memberships" => [], "deleted_departments" => [], "scopes" => [], "access" => [["member_id" => 29, "capability" => null, "roles" => [], "scopes" => []]]]; file_put_contents(getenv("FAKE_KERNEL_STATE"), json_encode($s));'
php bin/directory_sync.php >/dev/null 2>&1; check "$?" "0" "the change feed applies through the kernel's internal API (the fake kernel)"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar29" "$APP/")" "302" "a grant the kernel revoked shuts the door within one sync"
check "$(sql "select ended_by from member_sessions where member_id = 29 order by created_at desc limit 1") $(sql "select capability is null from members where id = 29")" "directory t" "…the session ended by the directory and the mirror holds no grant"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(HANDOFF_NO_GRANT=1 php bin/dev_handoff.php 29)")" "403" "…and a hand-off whose claims now carry no grant is refused (the kernel's claims are the authority)"
tok26=$(curl -s -c "$TMP/jar26" -b "$TMP/jar26" -o /dev/null "$(php bin/dev_handoff.php 26)"; curl -s -b "$TMP/jar26" "$APP/my/trail" | sed -n 's/.*name="csrf_token" value="\([a-f0-9]*\)".*/\1/p' | head -1)
check "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$TMP/jar26" -X POST -d "csrf_token=$tok26" "$APP/logout.php")" "302 https://app.example.invalid/launcher" "MaluMail's own sign-out (POST + CSRF) goes back to the launcher"
check "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/jar26" "$APP/")" "302" "…and the session is over"

echo "== the worker, the ingest bridge, mail"
php bin/worker.php >"$TMP/worker.out" 2>&1; check "$?:$(sql "select count(*) from activity_log where action = 'worker.pass' and source = 'cron'")" "0:1" "the worker runs a pass of stubs, logs worker.pass (source cron) and exits 0"
check "$(php -r '$r = json_decode(file_get_contents($argv[1]), true); echo count($r["ran"] ?? []) . ":" . (isset($r["ran"]["key_usage_prune"]["pruned"]) ? "pruned" : "?") . ":" . (isset($r["ran"]["dispatches"]["answered"]) && !isset($r["ran"]["dispatches"]["stub"]) ? "real" : "?") . ":" . (isset($r["ran"]["break_glass_expiry"]["revoked"]) && !isset($r["ran"]["break_glass_expiry"]["stub"]) ? "real" : "?");' "$TMP/worker.out")" "10:pruned:real:real" "…ten passes: the key-usage prune, slice 5's dispatches and slice 7's permission expiry real, the rest stubs naming their slice"
if [ -x "$PY" ] && "$PY" -c 'import asyncpg, httpx' 2>/dev/null; then
  "$PY" mcp/activity_ingest.py >"$TMP/ingest.out" 2>&1; check "$?" "0" "the ingest bridge ships the log to MaluDB (fake) and advances its checkpoint"
  check "$(php -r '$first = json_decode(file(getenv("FAKE_MALUDB_LOG"))[0], true); echo array_key_first($first["payload"]) . "=" . $first["payload"]["application"] . ":" . $first["kind"];')" "application=malumail:activity" "the payload names the application first (memory.md)"
  check "$(php -r 'foreach (file(getenv("FAKE_MALUDB_LOG")) as $l) { $b = json_decode($l, true); if (($b["payload"]["action"] ?? "") === "probe.event") echo $b["payload"]["mailbox_id"] ?? "-"; }')" "1" "…and carries the mailbox's id"
  check "$(sql "select last_id = (select max(id) from activity_log) from activity_ingest_state")" "t" "…the checkpoint stands at the last row"
else
  bad "the ingest bridge needs a python with asyncpg and httpx (ML_PY)"; bad "the payload names the application first"; bad "payload carries the audit keys"; bad "the checkpoint"
fi
check "$(php tests/phase0/probe.php mail 2>&1)" "sent:200" "MaluMail sends through the (fake) API when a key is set"
check "$(MALUMAIL_API_KEY= php tests/phase0/probe.php mail 2>&1 | cut -d: -f1)" "mail-threw" "with no key the send raises a catchable error (mail is optional)"

echo "== PHP holds no MaluMail secret that opens a mailbox (design §6.1, §6.2)"
check "$(grep -rlE 'MALUMAIL_MAILBOX_API_KEY|ML_SEAL_SECRET_KEY|sodium_crypto_box_seal_open|tools/call' app html bin | wc -l)" "0" "no PHP file names the customer key or the sealing secret, opens a sealed box, or calls the Mailbox MCP"
check "$(grep -cE '^(MALUMAIL_MAILBOX_API_KEY|ML_SEAL_SECRET_KEY)=' "$ML_DEV_ENV")" "0" "the proofs' PHP environment holds neither (they are in the mail service's stand-in file only)"
check "$(grep -cE '^(MALUMAIL_MAILBOX_API_KEY|ML_SEAL_SECRET_KEY)=' "${ML_DEV_ENV%.env}-mailer.env")" "2" "…which holds both"
check "$(php -r '$pk = base64_decode(getenv("ML_SEAL_PUBLIC_KEY")); $box = sodium_crypto_box_seal("secret-password", $pk); $sk = base64_decode(trim(explode("=", file($argv[1])[0], 2)[1])); echo sodium_crypto_box_seal_open($box, sodium_crypto_box_keypair_from_secretkey_and_publickey($sk, $pk)) === "secret-password" ? "opens" : "no";' "${ML_DEV_ENV%.env}-mailer.env")" "opens" "a password sealed with the public key opens only with the mail service's secret half (the scratch pair is a pair)"

echo "== $pass ok, $fail failed"
[ "$fail" -eq 0 ]
