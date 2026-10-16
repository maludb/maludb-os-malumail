<?php
declare(strict_types=1);

/**
 * CANNED REPLIES (docs/build-specs/search-labels-snooze.md — slice 6). A person's own, and the ones shared with everyone using MaluMail
 * (decision 7: canned text carries no mail, so a shared reply is everyone's to read and its owner's — or a Mail admin's — to change). The
 * rows are email_templates behind db/012's mcp_templates (the caller's own and the shared ones); the writes are ml_template_save() and
 * ml_template_delete() (db/014), which ask who may. An agent keeps none.
 */

/** The canned replies the caller sees: [{template_id, name, subject, body, shared, owner_member_id, owner_name, usage_count, updated_at, mine}], mine first. */
function ml_templates_list(PDO $pdo, string $q = ''): array
{
    $me = (int) (current_member_id() ?? 0);
    $st = $pdo->prepare("SELECT t.template_id, t.name, t.subject, t.body, t.shared, t.owner_member_id, COALESCE(o.display_name, '') AS owner_name, t.usage_count, t.updated_at
                           FROM mcp_templates t LEFT JOIN members o ON o.id = t.owner_member_id
                          WHERE (:q = '' OR t.name ILIKE '%' || :q2 || '%' OR t.subject ILIKE '%' || :q3 || '%' OR t.body ILIKE '%' || :q4 || '%')
                          ORDER BY (t.owner_member_id = :me) DESC, lower(t.name), t.template_id");
    $st->execute(['q' => $q, 'q2' => $q, 'q3' => $q, 'q4' => $q, 'me' => $me]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['shared'] = $r['shared'] === true || $r['shared'] === 't';
        $r['mine'] = (int) $r['owner_member_id'] === $me;
        $rows[] = $r;
    }
    return $rows;
}

/** One canned reply the caller sees, or null (404). */
function ml_template_one(PDO $pdo, int $id): ?array
{
    foreach (ml_templates_list($pdo) as $t) {
        if ((int) $t['template_id'] === $id) {
            return $t;
        }
    }
    return null;
}

/** A message that used a canned reply was sent: its count goes up once (only a reply the sender sees — their own or a shared one). */
function ml_template_used(PDO $pdo, ?int $id): void
{
    if ($id === null || ml_template_one($pdo, $id) === null) {
        return;
    }
    $pdo->prepare('UPDATE email_templates SET usage_count = usage_count + 1 WHERE id = :i')->execute(['i' => $id]);
}

/** The first line of a body, for the card. */
function ml_template_first_line(string $body): string
{
    $line = trim(strtok(str_replace("\r", '', $body), "\n") ?: '');
    return mb_strlen($line) > 120 ? mb_substr($line, 0, 117) . '…' : $line;
}
