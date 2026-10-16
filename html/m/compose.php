<?php
declare(strict_types=1);
/**
 * /m/{mailbox}/compose (screen `compose`; params reply, reply_all, forward — a message KEY id —, to, subject, body, template) and
 * /m/{mailbox}/drafts/{draft} (screen `draft-edit`): THE COMPOSER (docs/build-specs/writing.md; THE THIRD EXEMPLAR). Also:
 *   ?key=<message key id>   a draft written on a phone (found by the sync in Drafts): its mail_drafts row made from the index, opened here
 *   ?status=<outbound id>   JSON for the bar after Send: the row's status, the seconds left of the undo window, where it went
 * Level: triage to write (the Expert in its person's run included); Send is the handler's (send). A view holder is 403 in a sentence.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'triage');
$mb = (int) $box['id'];
$me = (int) current_member_id();
$tz = member_timezone();
$level = (string) $box['my_level'];
$status = request_integer('status');
if ($status !== null) {                                       // the bar's poll
    $o = outbound_status($pdo, $mb, $status);
    if ($o === null) {
        json_error('not_found', 'No such message.', 404);
    }
    json_response(['data' => present_outbound_status($o, $tz)]);
}
$draftId = request_integer('draft');
$draft = null;
$mode = 'new';
$prefill = null;
$key = request_integer('key');
if ($draftId !== null) {
    $draft = draft_row($pdo, $mb, $draftId);
    if ($draft === null) {
        respond_not_found('No such draft.');
    }
    $mode = (string) $draft['mode'];
} elseif ($key !== null) {
    // a draft written elsewhere: the index's message in Drafts by its key → a row of our own (its draft_key = the Message-ID)
    $st = $pdo->prepare("SELECT m.id, m.message_id_header, m.to_addresses, m.cc_addresses, m.bcc_addresses, m.subject, m.in_reply_to, m.references_ids
                           FROM mcp_messages m JOIN mcp_folders f ON f.id = m.folder_id
                          WHERE m.mailbox_id = :mb AND m.message_key_id = :k AND f.special_use = 'drafts' ORDER BY m.id DESC LIMIT 1");
    $st->execute(['mb' => $mb, 'k' => $key]);
    $pm = $st->fetch();
    if ($pm === false) {
        respond_not_found('No such draft.');
    }
    $st = $pdo->prepare('SELECT id FROM mail_drafts WHERE mailbox_id = :mb AND draft_key = :key');
    $st->execute(['mb' => $mb, 'key' => (string) $pm['message_id_header']]);
    $existing = $st->fetchColumn();
    if ($existing === false) {
        $f = ml_message_fetch($mb, (int) $pm['id'], 'html');
        $html = is_array($f) ? ($f['html'] ?? null) : null;
        $text = is_array($f) ? (string) ($f['text'] ?? '') : '';
        $st = $pdo->prepare('INSERT INTO mail_drafts (mailbox_id, member_id, draft_key, to_addresses, cc_addresses, bcc_addresses, subject, body_html, body_text, in_reply_to, references_ids, mode, filed_at)
                             VALUES (:mb, NULL, :key, :to, :cc, :bcc, :subject, :html, :text, :irt, :refs, :mode, now()) RETURNING id');
        $st->execute(['mb' => $mb, 'key' => (string) $pm['message_id_header'], 'to' => $pm['to_addresses'], 'cc' => $pm['cc_addresses'], 'bcc' => $pm['bcc_addresses'],
                      'subject' => (string) $pm['subject'], 'html' => $html, 'text' => $html === null ? $text : mime_text_of_html((string) $html), 'irt' => $pm['in_reply_to'],
                      'refs' => $pm['references_ids'], 'mode' => $pm['in_reply_to'] !== null ? 'reply' : 'new']);
        $existing = $st->fetchColumn();
    }
    redirect('/m/' . $mb . '/drafts/' . (int) $existing);
} else {
    foreach (['reply', 'reply_all', 'forward'] as $m) {
        $k = request_integer($m);
        if ($k !== null) {
            $mode = $m;
            $prefill = compose_prefill($pdo, $mb, $box, $m, $k, $me);
            if ($prefill === null) {
                respond_not_found('No such message.');
            }
            break;
        }
    }
}
$identities = compose_identities($pdo, $box);
$format = compose_format_pref($pdo, $me);
$settings = $pdo->query('SELECT draft_save_seconds, undo_send_seconds, max_attachments_mb FROM ml_settings WHERE id = 1')->fetch() ?: ['draft_save_seconds' => 10, 'undo_send_seconds' => 10, 'max_attachments_mb' => 7];
$boxSettings = is_string($box['settings'] ?? null) ? (json_decode($box['settings'], true) ?: []) : [];
$undo = (int) ($boxSettings['undo_seconds'] ?? $settings['undo_send_seconds']);
$attachments = $draft !== null ? draft_attachments($pdo, (int) $draft['id']) : [];
$canSend = (ML_LEVELS[$level] ?? 0) >= 3;
$isHuman = (current_member()['member_kind'] ?? '') === 'human';
$delegate = $box['kind'] !== 'person' || (int) ($box['owner_member_id'] ?? 0) !== $me;
$screen = $draft !== null ? 'draft-edit' : 'compose';
log_screen_view($pdo, $screen, $mb);
if (wants_json()) {
    respond_screen(['mailbox_id' => $mb, 'mode' => $mode, 'can_send' => $canSend, 'identities' => array_map('present_identity', $identities),
                    'draft' => $draft !== null ? present_draft($draft, $attachments) : null,
                    'prefill' => $prefill !== null ? ['to' => $prefill['to'], 'cc' => $prefill['cc'], 'subject' => $prefill['subject'], 'reply_to' => $prefill['reply_to_key_id'],
                                                      'attachments' => $prefill['attachments'], 'quote_unavailable' => $prefill['quote_unavailable']] : null]);
}
$ignored = [];
// a canned reply chosen on the Templates screen (slice 6): its subject and text start a new message (never over a draft or a reply)
$tplId = request_integer('template');
$tpl = $draft === null && $prefill === null && $tplId !== null ? ml_template_one($pdo, $tplId) : null;
$sentDraft = $draft !== null && $draft['outbound_id'] !== null && in_array($draft['outbound_status'], ['queued', 'sending', 'sent', 'deferred', 'held'], true);
$initial = [
    'identity' => $draft !== null ? $draft['identity_id'] : ($identities[0]['id'] ?? null),
    'to' => $draft !== null ? $draft['to_addresses'] : ($prefill['to'] ?? compose_addresses(request_string('to'), 'To', $ignored)),
    'cc' => $draft !== null ? $draft['cc_addresses'] : ($prefill['cc'] ?? []),
    'bcc' => $draft !== null ? $draft['bcc_addresses'] : [],
    'subject' => $draft !== null ? (string) $draft['subject'] : ($prefill['subject'] ?? ($tpl !== null && ($tpl['subject'] ?? '') !== '' ? (string) $tpl['subject'] : request_string('subject'))),
    'body_html' => $draft !== null ? $draft['body_html'] : null,
    'body_text' => $draft !== null ? (string) $draft['body_text'] : ($tpl !== null ? (string) $tpl['body'] : request_string('body')),
    'reply_to' => $draft !== null ? $draft['reply_to_key_id'] : ($prefill['reply_to_key_id'] ?? null),
    'in_reply_to' => $draft !== null ? $draft['in_reply_to'] : ($prefill['in_reply_to'] ?? null),
    'references' => $draft !== null ? ml_pg_text_array((string) $draft['references_ids']) : ($prefill['references_ids'] ?? []),
    'forward_of' => $mode === 'forward' && $prefill !== null ? (int) $prefill['original']['message_key_id'] : null,
    'forward_attachments' => $mode === 'forward' && $prefill !== null ? $prefill['attachments'] : [],
];
$conversation = $prefill !== null ? (int) ($prefill['original']['thread_id'] ?? 0) : null;
$title = match ($mode) { 'reply', 'reply_all' => 'Reply', 'forward' => 'Forward', default => ($draft !== null ? 'Draft' : 'New message') };
render_screen($title . ' — ' . $box['address'], view('compose/compose.php', [
    'box' => $box, 'mode' => $mode, 'draft' => $draft, 'sentDraft' => $sentDraft, 'prefill' => $prefill, 'initial' => $initial, 'identities' => $identities,
    'format' => $format, 'attachments' => $attachments, 'settings' => $settings, 'undo' => $undo, 'tz' => $tz, 'canSend' => $canSend,
    'isHuman' => $isHuman, 'delegate' => $delegate, 'internalDomains' => compose_internal_domains($pdo), 'schedule' => compose_schedule_choices($tz),
    'conversation' => $conversation, 'level' => $level, 'templateId' => $tpl !== null ? (int) $tpl['template_id'] : null,
]), ['screen' => $screen, 'entity' => $draft !== null ? 'mail_draft' : 'mailbox', 'recordId' => $draft !== null ? (int) $draft['id'] : $mb, 'mailboxId' => $mb]);
