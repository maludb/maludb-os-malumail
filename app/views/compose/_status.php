<?php /** The bar after Send (writing.md "Send"): "Sending… Undo" for the window, then "Sent — View message"; a schedule's "Scheduled for … — Cancel"; held or refused in the answer's words. Filled by compose.js. Data: mailbox */ ?>
<div class="alert alert-light d-none mt-2 d-flex flex-wrap align-items-center gap-2" id="compose-sent-bar" role="status" data-status-url="/m/<?= (int) $mailbox ?>/compose?status=" data-cancel-url="/mail/send-cancel">
    <span id="compose-sent-text"></span>
    <button type="button" class="btn btn-sm btn-link p-0 d-none" id="compose-undo">Undo</button>
    <a class="d-none" id="compose-view-message" href="#">View message</a>
</div>
