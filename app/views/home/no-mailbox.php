<?php /** Home with no mailbox open to the person (sso-shell.md "Screens"). Data: admins (names) */ ?>
<?= view('shared/header.php', ['id' => 'home', 'title' => app_name(), 'crumbs' => [[app_name(), null]]]) ?>
<div class="main-content">
    <div class="card" id="no-mailbox-card">
        <div class="card-body">
            <h6 class="fw-bold mb-2">No mailbox is open to you yet.</h6>
            <p class="mb-2">A Mail admin connects one. Until MaluMail issues application passwords (MM1), a person's own mailbox waits.</p>
            <p class="text-muted mb-0" id="no-mailbox-admins"><?= $admins === [] ? 'No Mail admin is named yet.' : 'Mail admins: ' . e(implode(', ', $admins)) . '.' ?></p>
        </div>
    </div>
</div>
