<?php
declare(strict_types=1);

/** My own trail (sso-shell.md "Query functions"): what I did here, newest first, 50 a page, with the mailbox it concerned. */

const TRAIL_PAGE = 50;

function trail_for(PDO $pdo, int $member, ?string $since, ?int $mailbox, int $page): array
{
    $where = ['a.actor_member_id = :m', "a.action <> 'screen.view'"];
    $args = ['m' => $member];
    if ($since !== null) { $where[] = 'a.occurred_at >= CAST(:since AS date)'; $args['since'] = $since; }
    if ($mailbox !== null) { $where[] = 'a.mailbox_id = :mb'; $args['mb'] = $mailbox; }
    $st = $pdo->prepare('SELECT a.id, a.occurred_at, a.action, a.source, a.entity_type, a.entity_id, a.mailbox_id, b.address AS mailbox
                           FROM activity_log a LEFT JOIN mailboxes b ON b.id = a.mailbox_id
                          WHERE ' . implode(' AND ', $where) . '
                          ORDER BY a.occurred_at DESC, a.id DESC LIMIT ' . (TRAIL_PAGE + 1) . ' OFFSET ' . ((max(1, $page) - 1) * TRAIL_PAGE));
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, TRAIL_PAGE), 'more' => count($rows) > TRAIL_PAGE];
}

/** An event in words — the action's entity and verb as a sentence, never what any mail said. */
function trail_sentence(array $r): string
{
    [$entity, $verb] = array_pad(explode('.', (string) $r['action'], 2), 2, '');
    $words = ['notice.acknowledge' => 'Acknowledged the monitoring notice', 'token.mint' => 'Minted a token', 'token.revoke' => 'Revoked a token',
              'member.sign_on' => 'Signed on', 'member.sign_out' => 'Signed out', 'assistant.ask' => 'Asked the command bar'];
    return $words[$r['action']] ?? ucfirst(str_replace('_', ' ', $verb)) . ' — ' . str_replace('_', ' ', $entity);
}
