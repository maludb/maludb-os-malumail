<?php /** The rail's mailbox section (mail-list.md "The rail"): the caption and the counts block the poll swaps. Data: railData, here */ ?>
<li class="nxl-item nxl-caption" id="nav-group-mailboxes"><label>Mailboxes</label></li>
<?= view('mail/partials/rail-counts.php', ['railData' => $railData, 'here' => $here, 'oob' => false]) ?>
