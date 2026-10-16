<?php
/**
 * THE COMPOSER (writing.md "compose"): full page, no modal. 375: one column — From, To (Cc/Bcc revealed), Subject, the editor, the attachment
 * strip, a sticky bottom bar (Send with Schedule…, the paper-clip, the format toggle, Discard). 1280: the same column at 760 px, the
 * conversation it answers collapsed beside it (reply and forward). Without JavaScript: the plain textarea and a normal POST — it still
 * sends. Data: box, mode, draft (row|null), sentDraft, prefill, initial, identities, format ('html'|'text'), attachments, settings, undo,
 * tz, canSend, isHuman, delegate, internalDomains, schedule (choices), conversation (thread id|null), level
 */
$mb = (int) $box['id'];
$draftId = $draft !== null ? (int) $draft['id'] : null;
$action = match ($mode) { 'reply', 'reply_all' => '/mail/reply', 'forward' => '/mail/forward', default => '/mail/send' };
$title = match ($mode) { 'reply' => 'Reply', 'reply_all' => 'Reply all', 'forward' => 'Forward', default => 'New message' };
$listUrl = '/m/' . $mb . '/v/inbox';
$rich = $format === 'html' && ($initial['body_html'] !== null || $initial['body_text'] === '' || $draft === null);
$bodyHtml = $initial['body_html'];
if ($bodyHtml === null && $initial['body_text'] !== '') {
    $bodyHtml = '<div style="white-space:pre-wrap">' . e($initial['body_text']) . '</div>';
}
$signature = $identities[0] ?? null;
$sigHtml = $draft === null && $isHuman && $signature !== null ? compose_signature_html($signature) : '';
$sigText = $draft === null && $isHuman && $signature !== null ? compose_signature_text($signature) : '';
$quoteHtml = $draft === null && $prefill !== null ? $prefill['quote_html'] : '';
$quoteText = $draft === null && $prefill !== null ? $prefill['quote_text'] : '';
$initialHtml = ($bodyHtml ?? '<div><br></div>') . $sigHtml . $quoteHtml;
$initialText = $initial['body_text'] . $sigText . $quoteText;
$suspended = $box['suspended_at'] !== null;
$chips = static function (string $name, array $list): string {
    $html = '';
    foreach ($list as $a) {
        $html .= '<input type="hidden" name="' . e($name) . '[]" value="' . e($a) . '" data-chip>';
    }
    return $html;
};
?>
<?= view('shared/header.php', ['id' => 'compose', 'title' => $title, 'crumbs' => [[$box['display_name'] !== '' ? $box['display_name'] : $box['address'], $listUrl], [$title, null]], 'back' => [$listUrl, 'Inbox']]) ?>
<div class="main-content mail-compose-page" id="mail-compose" data-mailbox="<?= $mb ?>" data-draft="<?= $draftId ?? '' ?>" data-save-seconds="<?= (int) $settings['draft_save_seconds'] ?>"
     data-undo="<?= (int) $undo ?>" data-domains="<?= e(json_encode($internalDomains)) ?>" data-format="<?= $rich ? 'html' : 'text' ?>" data-mode="<?= e($mode) ?>"
     data-version="<?= e((string) ($draft['saved_at'] ?? '')) ?>" data-max-bytes="<?= (int) $settings['max_attachments_mb'] * 1048576 ?>" data-csrf="<?= e(csrf_token()) ?>">
    <?php if ($sentDraft): ?>
        <div class="alert alert-info" id="compose-sent-draft">This draft was sent — <?= hx_link('/m/' . $mb . '/v/sent', 'see it in Sent', 'alert-link') ?>.</div>
    <?php endif; ?>
    <?php if ($suspended): ?>
        <div class="alert alert-warning" id="compose-suspended">This mailbox is suspended: nothing is sent from it until a Mail admin reactivates it.</div>
    <?php endif; ?>
    <?php if ($prefill !== null && $prefill['quote_unavailable']): ?>
        <div class="alert alert-light fs-13" id="compose-quote-unavailable">The original's body could not be read right now; its snippet is quoted instead.</div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-12 <?= $conversation !== null ? 'col-xl-8' : 'col-xl-8' ?> mail-compose-col">
            <form method="post" action="<?= e($action) ?>" id="compose-form" class="mail-compose" enctype="multipart/form-data" novalidate data-draft-url="/mail/draft-save" data-files-url="/m/<?= $mb ?>/drafts/{draft}/files">
                <?= csrf_field() ?>
                <input type="hidden" name="mailbox" value="<?= $mb ?>">
                <input type="hidden" name="draft" value="<?= $draftId ?? '' ?>" id="compose-draft-id">
                <input type="hidden" name="version" value="<?= e((string) ($draft['saved_at'] ?? '')) ?>" id="compose-version">
                <input type="hidden" name="mode" value="<?= e($mode) ?>">
                <input type="hidden" name="return_to" value="<?= e($listUrl) ?>">
                <input type="hidden" name="body_format" value="text" id="compose-body-format"><?php /* text without a script; the editor sets html when it starts */ ?>
                <input type="hidden" name="send_at" value="" id="compose-send-at">
                <input type="hidden" name="quote" value="no">
                <?php if ($initial['reply_to'] !== null): ?><input type="hidden" name="message" value="<?= (int) $initial['reply_to'] ?>"><input type="hidden" name="reply_to" value="<?= (int) $initial['reply_to'] ?>"><?php endif; ?>
                <?php if ($mode === 'reply_all'): ?><input type="hidden" name="reply_all" value="yes"><?php endif; ?>
                <?php if ($initial['forward_of'] !== null): ?><input type="hidden" name="message" value="<?= (int) $initial['forward_of'] ?>"><?php endif; ?>
                <div class="mb-2 d-flex align-items-center gap-2" id="compose-from">
                    <label class="form-label mb-0 text-muted fs-13" for="compose-identity" style="width:4rem">From</label>
                    <select class="form-select form-select-sm" name="identity" id="compose-identity">
                        <?php foreach ($identities as $i): ?>
                            <option value="<?= $i['id'] === null ? '' : (int) $i['id'] ?>"<?= (int) ($initial['identity'] ?? 0) === (int) ($i['id'] ?? 0) ? ' selected' : '' ?>
                                    data-signature-html="<?= e(compose_signature_html($i)) ?>" data-signature-text="<?= e(compose_signature_text($i)) ?>">
                                <?= e(($i['display_name'] ?? '') !== '' ? $i['display_name'] . ' <' . $i['address'] . '>' : $i['address']) ?><?= $i['kind'] === 'alias' ? ' (alias)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isHuman && (ML_LEVELS[$level] ?? 0) >= 4): ?><?= hx_link('/m/' . $mb . '/identities/new', '<i class="feather-plus"></i>', 'btn btn-sm btn-light btn-touch', 'id="compose-identity-new" title="Add an identity" aria-label="Add an identity"') ?><?php endif; ?>
                </div>
                <?php if ($delegate): ?><div class="fs-12 text-muted mb-2 ms-5 ps-3" id="compose-delegate"><?= e($box['address']) ?> (sent by <?= e((string) (current_member()['display_name'] ?? 'you')) ?>)</div><?php endif; ?>
                <?php foreach (['to' => 'To', 'cc' => 'Cc', 'bcc' => 'Bcc'] as $f => $label): $list = $initial[$f]; ?>
                    <div class="mb-2 d-flex align-items-start gap-2 mail-recipients<?= $f !== 'to' && $list === [] ? ' d-none' : '' ?>" id="compose-row-<?= $f ?>" data-field="<?= $f ?>">
                        <label class="form-label mb-0 text-muted fs-13 pt-1" for="compose-<?= $f ?>" style="width:4rem"><?= $label ?></label>
                        <div class="flex-grow-1 mail-chips" id="compose-chips-<?= $f ?>">
                            <?= $chips($f, $list) ?>
                            <input type="text" class="form-control form-control-sm mail-chip-input" id="compose-<?= $f ?>" name="<?= $f ?>_typed" value="" autocomplete="off" inputmode="email"
                                   placeholder="<?= $f === 'to' ? 'Recipients' : '' ?>" data-nojs-value="<?= e(implode(', ', $list)) ?>" aria-label="<?= $label ?>">
                        </div>
                        <?php if ($f === 'to'): ?><div class="fs-12 pt-1"><a href="#" id="compose-show-cc" class="me-2">Cc</a><a href="#" id="compose-show-bcc">Bcc</a></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <noscript><div class="fs-12 text-muted mb-2 ms-5 ps-3">Separate addresses with commas.</div></noscript>
                <div class="mb-2 d-flex align-items-center gap-2">
                    <label class="form-label mb-0 text-muted fs-13" for="compose-subject" style="width:4rem">Subject</label>
                    <input type="text" class="form-control form-control-sm" name="subject" id="compose-subject" value="<?= e($initial['subject']) ?>" maxlength="998" autocomplete="off">
                </div>
                <?php if ($mode === 'forward' && $initial['forward_attachments'] !== []): ?>
                    <div class="mb-2 ms-5 ps-3 fs-13" id="compose-forward-attachments">
                        <input type="hidden" name="attachments_included" value="yes">
                        <?php foreach ($initial['forward_attachments'] as $p): ?>
                            <label class="form-check-label me-3"><input type="checkbox" class="form-check-input me-1" name="attachment_part[]" value="<?= e((string) $p['part']) ?>" checked> <?= e((string) $p['name']) ?> <span class="text-muted">(<?= e(ml_size_label((int) $p['size'])) ?>)</span></label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="mail-editor-wrap mb-2" id="compose-editor-wrap">
                    <div class="mail-editor-toolbar btn-toolbar gap-1 mb-1 d-none" id="compose-toolbar" role="toolbar" aria-label="Formatting">
                        <?php foreach ([['bold', 'Bold', 'feather-bold'], ['italic', 'Italic', 'feather-italic'], ['underline', 'Underline', 'feather-underline'], ['link', 'Link', 'feather-link'],
                                         ['ul', 'Bulleted list', 'feather-list'], ['ol', 'Numbered list', 'feather-hash'], ['quote', 'Quote', 'feather-message-square'], ['clear', 'Remove formatting', 'feather-x-square']] as [$cmd, $label, $icon]): ?>
                            <button type="button" class="btn btn-sm btn-light" data-cmd="<?= $cmd ?>" title="<?= $label ?>" aria-label="<?= $label ?>"><i class="<?= $icon ?>"></i></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="mail-editor form-control d-none" id="compose-editor" data-initial-html="<?= e($initialHtml) ?>" role="textbox" aria-multiline="true" aria-label="Message"></div>
                    <textarea class="form-control mail-editor-plain" name="body" id="compose-body" rows="14" aria-label="Message"><?= e($initialText) ?></textarea><?php /* shown without a script; the editor hides it */ ?>
                    <input type="hidden" id="compose-initial-text" value="<?= e($initialText) ?>">
                </div>
                <div id="compose-attachments">
                    <?= view('compose/_attachments.php', ['box' => $box, 'draft' => $draftId, 'attachments' => $attachments, 'max' => (int) $settings['max_attachments_mb'] * 1048576]) ?>
                </div>
                <div class="mail-compose-bar d-flex flex-wrap align-items-center gap-2 py-2" id="compose-bar">
                    <?php if ($canSend && !$suspended): ?>
                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary btn-touch" id="compose-send"><i class="feather-send me-1"></i>Send</button>
                            <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split btn-touch" id="compose-schedule-toggle" aria-expanded="false" aria-controls="compose-schedule" title="Schedule…"><span class="visually-hidden">Schedule</span></button>
                        </div>
                    <?php elseif (!$suspended): ?>
                        <span class="text-muted fs-13" id="compose-no-send">You can write a draft here; sending needs send on this mailbox.</span>
                    <?php endif; ?>
                    <label class="btn btn-light btn-touch mb-0" id="compose-attach-label" for="compose-file" title="Attach a file"><i class="feather-paperclip"></i><span class="visually-hidden">Attach a file</span></label>
                    <input type="file" id="compose-file" class="d-none" multiple>
                    <button type="button" class="btn btn-light btn-touch" id="compose-format-toggle" title="<?= $rich ? 'Plain text' : 'Rich text' ?>"><i class="feather-type"></i><span class="visually-hidden">Toggle plain text</span></button>
                    <button type="button" class="btn btn-light btn-touch ms-auto" id="compose-discard" data-url="/mail/draft-discard"><i class="feather-trash-2 me-sm-1"></i><span class="d-none d-sm-inline">Discard</span><span class="visually-hidden d-sm-none">Discard</span></button>
                    <span class="fs-12 text-muted w-100" id="compose-status" aria-live="polite"><?= $draft !== null ? 'Saved' . ($draft['uid'] !== null ? ' to Drafts' : '') : '' ?></span>
                </div>
                <?php /* after the sticky bar, so the bar never covers it: at its natural place the bar sits above the panel */ ?>
                <?= view('compose/_schedule.php', ['choices' => $schedule, 'tz' => $tz]) ?>
                <?= view('compose/_status.php', ['mailbox' => $mb]) ?>
            </form>
        </div>
        <?php if ($conversation !== null): ?>
            <div class="col-12 col-xl-4 d-none d-xl-block" id="compose-conversation">
                <div class="card"><div class="card-body fs-13">
                    <div class="fw-semibold mb-1"><?= e((string) ($prefill['original']['subject'] ?? '')) ?></div>
                    <div class="text-muted"><?= e((string) ($prefill['original']['from_name'] ?? $prefill['original']['from_address'])) ?> · <?= e(format_ts($prefill['original']['sent_at'] ?? $prefill['original']['received_at'], $tz, 'M j, g:i A')) ?></div>
                    <?= hx_link('/m/' . $mb . '/t/' . $conversation, 'Open the conversation', 'fs-12', 'id="compose-conversation-link"') ?>
                </div></div>
            </div>
        <?php endif; ?>
    </div>
</div>
<script src="/assets/vendor/dompurify-3.4.16/purify.min.js"></script>
<script src="/assets/vendor/squire-2.4.9/squire.js"></script>
<script src="/assets/js/compose.js"></script>
