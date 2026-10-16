<?php
/**
 * One held message for its reviewers (screen `review-view`). 375 first: the message, then (below; beside at 1280) the conversation it
 * answers, the purpose, the run, the reasons and the history; the five decisions at the foot. Data: q (review_get), links, notice, tz, isSuper
 */
$id = (int) $q['id'];
$back = ['/oversight/review', 'Held mail'];
if ($q['decided']):
    $last = $q['reviews'] === [] ? null : $q['reviews'][count($q['reviews']) - 1]; ?>
    <?= view('shared/header.php', ['id' => 'review-view', 'title' => 'Already decided', 'crumbs' => [[app_name(), '/'], ['Oversight', '/oversight'], ['Held mail', '/oversight/review'], ['#' . $id, null]], 'back' => $back]) ?>
    <div class="main-content" id="review-view-content"><div class="card"><div class="card-body" id="review-decided">
        This message no longer waits for review: it is <?= e((string) $q['status']) ?><?php if ($last !== null): ?> — <?= e((string) $last['decision']) ?><?= $last['reviewer_name'] !== null ? ' by ' . e((string) $last['reviewer_name']) : '' ?>, <?= e(format_ts((string) $last['created_at'], $tz, 'M j, g:i A')) ?><?php endif; ?>.
        <?= hx_link('/oversight/review', 'Back to the held mail', 'ms-1') ?>
    </div></div>
    <?php foreach ($q['reviews'] as $r): if ($r['decision'] !== 'edit' || $r['before_body_text'] === null) { continue; } ?>
        <div class="card mt-3" id="review-diff-<?= (int) $r['id'] ?>"><div class="card-header"><h5 class="card-title mb-0">Edited by <?= e((string) $r['reviewer_name']) ?></h5></div><div class="card-body fs-13">
            <?php if ((string) $r['before_subject'] !== (string) $q['subject']): ?>
                <div class="mb-2"><span class="text-muted">Subject</span> <del class="text-danger"><?= e((string) $r['before_subject']) ?></del> → <ins class="text-success"><?= e((string) $q['subject']) ?></ins></div>
            <?php endif; ?>
            <pre class="mail-diff mb-0" style="white-space:pre-wrap"><?php foreach (ov_line_diff((string) $r['before_body_text'], (string) $q['body_text']) as [$op, $line]): ?><span class="<?= $op === '-' ? 'text-danger' : ($op === '+' ? 'text-success' : 'text-muted') ?>" data-op="<?= $op === ' ' ? 'same' : ($op === '-' ? 'removed' : 'added') ?>"><?= e($op . ' ' . $line) ?></span>
<?php endforeach; ?></pre>
        </div></div>
    <?php endforeach; ?>
    </div>
<?php return; endif;
$mb = (int) $q['mailbox_id'];
$recips = ['To' => $q['to_addresses'], 'Cc' => $q['cc_addresses'], 'Bcc' => $q['bcc_addresses']];
$tag = static function (string $a) use ($q): string {
    $out = in_array($a, $q['outside_recipients'], true);
    $new = in_array($a, $q['new_recipients'], true);
    return '<span class="' . ($out ? 'text-warning fw-semibold' : '') . '">' . e($a) . '</span>' . ($out ? ' <span class="badge bg-soft-warning text-warning">outside</span>' : '') . ($new ? ' <span class="badge bg-soft-info text-info">new</span>' : '');
};
$osUrl = (string) env('OS_URL', '');
if ($osUrl === '' && ($l = (string) env('OS_LAUNCHER_URL', '')) !== '' && ($h = parse_url($l, PHP_URL_HOST))) {
    $osUrl = (parse_url($l, PHP_URL_SCHEME) ?: 'https') . '://' . preg_replace('/^app\./', 'os.', (string) $h);
}
$form = static fn (string $action, string $fid, string $inner, string $confirm = ''): string =>
    '<form method="post" action="/oversight/' . $action . '" hx-post="/oversight/' . $action . '" hx-target="#page-content" id="' . $fid . '"' . ($confirm !== '' ? ' hx-confirm="' . e($confirm) . '"' : '') . '>'
    . csrf_field() . '<input type="hidden" name="outbound" value="' . $id . '"><input type="hidden" name="mailbox" value="' . $mb . '">' . $inner . '</form>';
$a = $q['answered'];
?>
<?= view('shared/header.php', ['id' => 'review-view', 'title' => $q['subject'] !== '' ? $q['subject'] : '(no subject)', 'crumbs' => [[app_name(), '/'], ['Oversight', '/oversight'], ['Held mail', '/oversight/review'], ['#' . $id, null]], 'back' => $back]) ?>
<div class="main-content" id="review-view-content" data-outbound="<?= $id ?>">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <div class="card mb-3" id="review-message"><div class="card-body">
                <div class="fs-12 text-muted mb-2" id="review-as-sent">Exactly as it will go out <?= e(ov_expires_in($q['expires_at'])) !== '' ? '· ' . e(ov_expires_in($q['expires_at'])) : '' ?></div>
                <div class="fs-13 mb-1" id="review-from"><span class="text-muted">From</span> <?= e((string) ($q['from_name'] ?? $q['mailbox_name'])) ?> &lt;<?= e((string) $q['from_address']) ?>&gt;</div>
                <?php if (($q['sender_kind'] ?? '') === 'agent'): ?><div class="fs-13 mb-1" id="review-sender"><span class="text-muted">Sender</span> <?= e((string) $q['sender_name']) ?> (an AI agent; the mail says so in <code>X-OS-Agent</code>)</div><?php endif; ?>
                <?php foreach ($recips as $label => $list): if ($list === []) { continue; } ?>
                    <div class="fs-13 mb-1 text-break" id="review-<?= strtolower($label) ?>"><span class="text-muted"><?= $label ?></span> <?= implode(', ', array_map($tag, $list)) ?></div>
                <?php endforeach; ?>
                <div class="fs-15 fw-semibold mt-2 mb-2 text-break" id="review-subject"><?= e($q['subject'] !== '' ? $q['subject'] : '(no subject)') ?></div>
                <div class="mail-thread"><iframe class="mail-body-frame" id="review-body-frame" sandbox="allow-popups allow-popups-to-escape-sandbox allow-scripts"
                        src="/oversight/review/<?= $id ?>/body" referrerpolicy="no-referrer" title="The message"></iframe></div>
                <?php if (($q['disclosure'] ?? null) !== null && $q['disclosure'] !== ''): ?><div class="fs-12 text-muted border-top mt-2 pt-2" id="review-disclosure"><?= e((string) $q['disclosure']) ?></div><?php endif; ?>
                <?php if ($links !== []): ?>
                    <div class="mt-3" id="review-links"><div class="fs-12 fw-semibold mb-1">Links, with where they go</div>
                        <ul class="list-unstyled fs-12 mb-0">
                            <?php foreach ($links as $i => [$text, $href, $host]): ?>
                                <li class="text-break" id="review-link-<?= $i ?>"><span class="badge bg-soft-dark text-dark me-1" data-host="<?= e($host) ?>"><?= e($host) ?></span><?= $text !== '' ? e($text) . ' — ' : '' ?><span class="text-muted"><?= e(mb_substr($href, 0, 300)) ?></span></li>
                            <?php endforeach; ?>
                        </ul></div>
                <?php endif; ?>
                <?php if ($q['attachments'] !== []): ?>
                    <div class="mt-3" id="review-attachments"><div class="fs-12 fw-semibold mb-1">Attachments</div>
                        <ul class="list-unstyled fs-12 mb-0">
                            <?php foreach ($q['attachments'] as $f): ?>
                                <li class="text-break" id="review-attachment-<?= (int) $f['id'] ?>"><i class="feather-paperclip me-1"></i><?= e((string) $f['filename']) ?> · <?= e((string) $f['mime_type']) ?> · <?= e(ml_size_label((int) $f['byte_size'])) ?> · <span class="text-muted">sha256 <?= e(substr((string) $f['sha256'], 0, 16)) ?>…</span></li>
                            <?php endforeach; ?>
                        </ul></div>
                <?php endif; ?>
            </div></div>
            <?php if ($q['can_decide']): ?>
            <div class="card mb-3" id="review-decide"><div class="card-body d-grid gap-2">
                <?= $form('approve', 'review-approve-form', '<button type="submit" class="btn btn-primary btn-touch w-100" id="review-approve">Approve — send it as it is</button>') ?>
                <?= hx_link('/oversight/review/' . $id . '/edit', 'Edit, then send', 'btn btn-light btn-touch w-100', 'id="review-edit"') ?>
                <details id="review-reject-box"><summary class="btn btn-outline-danger btn-touch w-100" id="review-reject-open">Reject</summary>
                    <?= $form('reject', 'review-reject-form', '<label class="form-label fs-12 text-muted mt-2" for="review-reject-note">Why — the agent reads this in its next turn</label><textarea name="note" id="review-reject-note" rows="3" class="form-control mb-2" maxlength="2000" required></textarea><button type="submit" class="btn btn-danger btn-touch w-100" id="review-reject">Reject it</button>') ?>
                </details>
                <details id="review-reply-box"><summary class="btn btn-light btn-touch w-100" id="review-reply-open">Reply to the agent</summary>
                    <?= $form('reply', 'review-reply-form', '<label class="form-label fs-12 text-muted mt-2" for="review-reply-note">A question for the agent — the message stays held</label><textarea name="note" id="review-reply-note" rows="3" class="form-control mb-2" maxlength="2000" required></textarea><button type="submit" class="btn btn-primary btn-touch w-100" id="review-reply">Send to the agent</button>') ?>
                </details>
                <?php if ($q['reply_to_key_id'] !== null): ?>
                    <?= $form('take-over', 'review-take-over-form', '<button type="submit" class="btn btn-warning btn-touch w-100" id="review-take-over">Take over — I answer this conversation</button>',
                        'Take this conversation over? This message is not sent, and the agent is not woken by the conversation again until you give it back.') ?>
                <?php endif; ?>
            </div></div>
            <?php else: ?>
            <div class="card mb-3"><div class="card-body text-muted" id="review-not-mine">For its overseers: you see this message, but deciding it is theirs.</div></div>
            <?php endif; ?>
        </div>
        <div class="col-12 col-xl-5">
            <div class="card mb-3" id="review-why"><div class="card-header"><h5 class="card-title mb-0">Why it waits</h5></div><div class="card-body">
                <ul class="list-unstyled mb-0 fs-13">
                    <?php foreach ($q['hold_reasons'] as $why): ?>
                        <li class="mb-2" id="review-reason-<?= e($why) ?>"><span class="badge bg-soft-warning text-warning me-1"><?= e(OV_REASON_LABELS[$why] ?? $why) ?></span><span class="review-reason-sentence"><?= e(ov_reason_sentence($why, $q)) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div></div>
            <div class="card mb-3" id="review-context"><div class="card-body fs-13">
                <div class="mb-2" id="review-purpose"><span class="text-muted">The agent's purpose</span><br><?= e((string) ($q['purpose'] ?? '—')) ?></div>
                <div class="mb-2" id="review-mailbox"><span class="text-muted">Mailbox</span> <?= hx_link('/m/' . $mb . '/v/inbox', e((string) $q['mailbox_address'])) ?></div>
                <?php if ($q['agent_run_id'] !== null): ?>
                    <div class="mb-2" id="review-run"><span class="text-muted">Its run</span>
                        <?php if (!empty($isSuper) && $osUrl !== ''): ?><a href="<?= e($osUrl . '/ai/runs/' . (int) $q['agent_run_id']) ?>" target="_blank" rel="noopener">#<?= (int) $q['agent_run_id'] ?></a><?php else: ?>#<?= (int) $q['agent_run_id'] ?><?php endif; ?>
                        <div class="fs-11 text-muted">The tools a run called are listed once the kernel answers them (K56).</div></div>
                <?php endif; ?>
                <?php if ($a !== null): ?>
                    <div class="border-top pt-2" id="review-answers"><span class="text-muted">It answers</span>
                        <?= $a['thread_id'] !== null ? hx_link('/m/' . $mb . '/t/' . (int) $a['thread_id'], e((string) ($a['subject'] ?? '(no subject)')), 'd-block text-break', 'id="review-answers-link"') : '<span class="d-block">' . e((string) ($a['subject'] ?? '')) . '</span>' ?>
                        <div class="fs-12 text-muted">from <?= e((string) $a['from_address']) ?>, <?= e(format_ts((string) $a['received_at'], $tz, 'M j, g:i A')) ?></div>
                        <div class="fs-12 mt-1" id="review-answers-flags">
                            <?php $dm = $a['dmarc_pass']; ?>
                            <span class="badge <?= $dm === null ? 'bg-soft-secondary text-secondary' : (($dm === true || $dm === 't') ? 'bg-soft-success text-success' : 'bg-soft-danger text-danger') ?>" id="review-answers-dmarc">DMARC <?= $dm === null ? 'not checked' : (($dm === true || $dm === 't') ? 'pass' : 'fail') ?></span>
                            <?php if ($a['spam_score'] !== null): ?><span class="badge bg-soft-secondary text-secondary">spam score <?= e((string) $a['spam_score']) ?></span><?php endif; ?>
                            <?php if ($a['auto_submitted'] !== null || $a['is_list'] === true || $a['is_list'] === 't'): ?><span class="badge bg-soft-warning text-warning">automated</span><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div></div>
            <?php if ($q['reviews'] !== []): ?>
            <div class="card mb-3" id="review-history"><div class="card-header"><h5 class="card-title mb-0">Decisions so far</h5></div><div class="card-body fs-13">
                <?php foreach ($q['reviews'] as $r): ?>
                    <div class="mb-2" id="review-history-<?= (int) $r['id'] ?>"><span class="fw-semibold"><?= e((string) $r['decision']) ?></span> · <?= e((string) ($r['reviewer_name'] ?? 'no one')) ?> · <span class="text-muted"><?= e(format_ts((string) $r['created_at'], $tz, 'M j, g:i A')) ?></span>
                        <?php if ($r['note'] !== null): ?><div class="text-break">«<?= e((string) $r['note']) ?>»</div><?php endif; ?></div>
                <?php endforeach; ?>
            </div></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="/assets/mail/frame.js"></script>
