<?php
declare(strict_types=1);

/**
 * The wall as the pages meet it (design §3; the rules themselves are SQL — ml_mailbox_level(), ml_level(), ml_my_mailbox_ids()):
 *   ml_require_mailbox($id, $level)   the mailbox row and the caller's level; 404 below view (no row, no mailbox — an address never
 *                                      leaks), 403 in a sentence below $level
 *   ml_rail()                          the mailboxes the caller opens, for the rail (mcp_mailboxes, the wall in the view)
 *   ml_default_mailbox()              the home's mailbox: the person's own, else the first they open
 *   ml_oversees_any()                  does the caller oversee (decide for) at least one agent mailbox
 */

const ML_LEVELS = ['none' => 0, 'view' => 1, 'triage' => 2, 'send' => 3, 'full' => 4];
const ML_LEVEL_WORDS = ['view' => 'read this mailbox', 'triage' => 'file and organise mail here', 'send' => 'send from this mailbox',
                        'full' => 'change this mailbox\'s settings and who may open it'];

/** The mailbox (mcp_mailboxes' row) and the caller's level on it, or the refusal: 404 below view, 403 below $level. */
function ml_require_mailbox(int|string|null $id, string $level = 'view'): array
{
    $id = is_string($id) && ctype_digit($id) ? (int) $id : $id;
    if (!is_int($id) || $id <= 0) {
        respond_not_found('No such mailbox.');
    }
    $st = db()->prepare('SELECT * FROM mcp_mailboxes WHERE id = :id');
    $st->execute(['id' => $id]);
    $box = $st->fetch();
    $have = $box === false ? 'none' : (string) $box['my_level'];
    if ($box === false || (ML_LEVELS[$have] ?? 0) < 1) {
        respond_not_found('No such mailbox.');
    }
    if ((ML_LEVELS[$have] ?? 0) < (ML_LEVELS[$level] ?? 1)) {
        refuse(403, 'You can ' . ML_LEVEL_WORDS[$have] . ' (' . $have . '), but not ' . ML_LEVEL_WORDS[$level] . '.');
    }
    return $box;
}

/** The rail: the mailboxes the caller opens at view or above — a person's own first, then shared, then agents', by address. Cached per request. */
function ml_rail(?PDO $pdo = null): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $pdo ??= db();
    $me = (int) (current_member_id() ?? 0);
    $st = $pdo->prepare("SELECT id, address, display_name, kind, owner_member_id, my_level, inbox_unseen, held_count, suspended_at, sync_state
                           FROM mcp_mailboxes
                          WHERE my_level <> 'none'
                          ORDER BY (kind = 'person' AND owner_member_id = :me) DESC, CASE kind WHEN 'person' THEN 1 WHEN 'shared' THEN 2 ELSE 3 END, address");
    $st->execute(['me' => $me]);
    return $cache = $st->fetchAll();
}

/** The home's mailbox: the person's own, else the first they open; null when none. */
function ml_default_mailbox(): ?array
{
    $rail = ml_rail();
    return $rail[0] ?? null;
}

/** Does the caller decide for at least one agent mailbox (a person holding full on it) — the rail's Oversight item. */
function ml_oversees_any(): bool
{
    static $v = null;
    return $v ??= db_bool(db(), "SELECT EXISTS (SELECT 1 FROM mailboxes b WHERE b.kind = 'agent' AND ml_is_decider(b.id, app_current_member_id()))");
}

/** The Mail admins' names, for the no-mailbox card. */
function ml_mail_admin_names(): array
{
    return array_column(db()->query('SELECT m.display_name FROM members m WHERE m.id IN (SELECT ml_mail_admin_ids()) ORDER BY m.display_name')->fetchAll(), 'display_name');
}
