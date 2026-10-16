<?php
declare(strict_types=1);

/** The JSON presenters of the composer (writing.md): whitelists, never raw rows. */

function present_draft(array $d, array $attachments): array
{
    return ['draft' => (int) $d['id'], 'mailbox_id' => (int) $d['mailbox_id'], 'identity_id' => $d['identity_id'] === null ? null : (int) $d['identity_id'],
            'to' => $d['to_addresses'], 'cc' => $d['cc_addresses'], 'bcc' => $d['bcc_addresses'], 'subject' => $d['subject'], 'body_text' => $d['body_text'],
            'body_html' => $d['body_html'], 'mode' => $d['mode'], 'reply_to' => $d['reply_to_key_id'] === null ? null : (int) $d['reply_to_key_id'],
            'saved_at' => $d['saved_at'], 'version' => $d['saved_at'], 'filed' => isset($d['uid']) && $d['uid'] !== null,
            'outbound' => $d['outbound_id'] === null ? null : (int) $d['outbound_id'],
            'attachments' => array_map(static fn (array $a): array => ['id' => (int) $a['id'], 'name' => $a['filename'], 'type' => $a['mime_type'], 'size' => (int) $a['byte_size']], $attachments)];
}

function present_identity(array $i): array
{
    return ['identity_id' => $i['id'] === null ? null : (int) $i['id'], 'address' => $i['address'], 'kind' => $i['kind'], 'display_name' => $i['display_name'],
            'reply_to' => $i['reply_to'], 'is_default' => (bool) $i['is_default'], 'has_signature' => ($i['signature_html'] ?? '') !== '' || ($i['signature_text'] ?? '') !== ''];
}

function present_outbound_status(array $o, string $tz): array
{
    return ['outbound' => (int) $o['id'], 'status' => $o['status'], 'hold_reasons' => $o['hold_reasons'], 'refusal' => $o['refusal'], 'error' => $o['error'],
            'send_after' => $o['send_after'], 'send_after_local' => format_ts($o['send_after'], $tz, 'D j M, H:i'), 'sent_at' => $o['sent_at'], 'smtp_reply' => $o['smtp_reply'],
            'draft' => $o['draft_id'] === null ? null : (int) $o['draft_id'], 'thread_id' => $o['thread_id'] === null ? null : (int) $o['thread_id'],
            'seconds_left' => max(0, (int) ((new DateTimeImmutable((string) $o['send_after']))->getTimestamp() - time()))];
}
