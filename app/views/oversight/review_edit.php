<?php /** Edit a held message (screen `review-edit`): subject and body in the composer's editor; the recipients shown, not edited. Data: q (review_get) */
$id = (int) $q['id'];
$rich = ($q['body_html'] ?? null) !== null && trim((string) $q['body_html']) !== '';
?>
<?= view('shared/header.php', ['id' => 'review-edit', 'title' => 'Edit before sending', 'crumbs' => [[app_name(), '/'], ['Oversight', '/oversight'], ['Held mail', '/oversight/review'], ['#' . $id, '/oversight/review/' . $id], ['Edit', null]],
    'back' => ['/oversight/review/' . $id, 'the message']]) ?>
<div class="main-content" id="review-edit-page" data-format="<?= $rich ? 'html' : 'text' ?>">
    <form method="post" action="/oversight/edit" hx-post="/oversight/edit" hx-target="#page-content" id="review-edit-form"><?= csrf_field() ?>
        <input type="hidden" name="outbound" value="<?= $id ?>">
        <input type="hidden" name="body_format" id="review-edit-format" value="text">
        <div class="fs-13 mb-2 text-break" id="review-edit-recipients"><span class="text-muted">To</span> <?= e(implode(', ', array_merge($q['to_addresses'], $q['cc_addresses'], $q['bcc_addresses']))) ?>
            <div class="fs-11 text-muted">Recipients are not edited here: to write to someone else, reject it with a note or take the conversation over.</div></div>
        <label class="form-label fs-12 text-muted" for="review-edit-subject">Subject</label>
        <input type="text" name="subject" id="review-edit-subject" class="form-control btn-touch mb-2" maxlength="998" value="<?= e((string) $q['subject']) ?>">
        <div class="mail-editor-wrap mb-2">
            <div class="mail-editor-toolbar btn-toolbar gap-1 mb-1 d-none" id="compose-toolbar" role="toolbar" aria-label="Formatting">
                <?php foreach ([['bold', 'Bold', 'feather-bold'], ['italic', 'Italic', 'feather-italic'], ['underline', 'Underline', 'feather-underline'], ['link', 'Link', 'feather-link'],
                                 ['ul', 'Bulleted list', 'feather-list'], ['ol', 'Numbered list', 'feather-hash'], ['quote', 'Quote', 'feather-message-square'], ['clear', 'Remove formatting', 'feather-x-square']] as [$cmd, $label, $icon]): ?>
                    <button type="button" class="btn btn-sm btn-light" data-cmd="<?= $cmd ?>" title="<?= $label ?>" aria-label="<?= $label ?>"><i class="<?= $icon ?>"></i></button>
                <?php endforeach; ?>
            </div>
            <div class="mail-editor form-control d-none" id="review-edit-editor" data-initial-html="<?= e($rich ? (string) $q['body_html'] : '') ?>" role="textbox" aria-multiline="true" aria-label="Message"></div>
            <textarea class="form-control mail-editor-plain" name="body" id="review-edit-body" rows="14" aria-label="Message"><?= e((string) $q['body_text']) ?></textarea>
        </div>
        <div class="d-flex flex-wrap gap-2 py-2">
            <button type="submit" class="btn btn-primary btn-touch" id="review-edit-save">Save and send</button>
            <?= hx_link('/oversight/review/' . $id, 'Cancel', 'btn btn-light btn-touch', 'id="review-edit-cancel"') ?>
        </div>
    </form>
</div>
<script src="/assets/vendor/dompurify-3.4.16/purify.min.js"></script>
<script src="/assets/vendor/squire-2.4.9/squire.js"></script>
<script src="/assets/js/compose.js"></script>
