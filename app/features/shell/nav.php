<?php
declare(strict_types=1);

/**
 * The shell (docs/build-specs/sso-shell.md): the rail's views and top items, the phone's tab bar, links, the bell, the notice gate and the
 * placeholders. The rail itself is ml_rail() (app/features/mail/wall.php) — the wall decides it, never this file.
 */

/** A mailbox's views, in the rail's order (design §9). */
const ML_VIEWS = ['inbox' => ['Inbox', 'feather-inbox'], 'starred' => ['Starred', 'feather-star'], 'snoozed' => ['Snoozed', 'feather-clock'],
                  'sent' => ['Sent', 'feather-send'], 'drafts' => ['Drafts', 'feather-file'], 'scheduled' => ['Scheduled', 'feather-calendar'],
                  'outbox' => ['Outbox', 'feather-upload'], 'all' => ['All mail', 'feather-archive'], 'spam' => ['Spam', 'feather-alert-octagon'],
                  'trash' => ['Trash', 'feather-trash-2']];

/** The rail's items below the mailboxes: [id, url, icon, label, shown?]. */
function nav_top_items(): array
{
    return [
        ['oversight', '/oversight', 'feather-eye', 'Oversight', has_right('mail.oversee') && (ml_oversees_any() || ml_reviews_waiting())],
        ['templates', '/templates', 'feather-copy', 'Templates', has_right('mail.use')],
        ['admin', '/admin', 'feather-settings', 'Admin', has_right('mailboxes.manage')],
        ['notifications', '/notifications', 'feather-bell', 'Notifications', true],
    ];
}

/** The phone's tabs: Inbox · Search · Compose · Me (sso-shell.md Divergence 1). [id, url, icon, label] */
function nav_tabs(): array
{
    $box = ml_default_mailbox();
    $tabs = [['tab-inbox', '/', 'feather-inbox', 'Inbox'], ['tab-search', '/search', 'feather-search', 'Search']];
    if ($box !== null && (ML_LEVELS[$box['my_level']] ?? 0) >= ML_LEVELS['triage']) {
        $tabs[] = ['tab-compose', '/m/' . (int) $box['id'] . '/compose', 'feather-edit', 'Compose'];
    }
    $tabs[] = ['tab-me', '/my/trail', 'feather-user', 'Me'];
    return $tabs;
}

/** A link that navigates by HTMX into #page-content and still works as a plain link. $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** The path of the current request. */
function current_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
}

/** The mailbox the current page is in (its {mailbox}), for the rail's highlight and its views; else the default. */
function current_mailbox_id(): ?int
{
    if (preg_match('#^/m/([0-9]+)(/|$)#', current_path(), $m)) {
        return (int) $m[1];
    }
    $id = request_integer('mailbox');
    return $id !== null && $id > 0 ? $id : null;
}

/** The bell: my unread notifications. */
function bell_count(PDO $pdo, int $memberId): int
{
    static $cache = null;
    if ($cache === null) {
        $cache = (int) $pdo->query('SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL')->fetchColumn();   // the view is the caller's own
    }
    return $cache;
}

// ------------------------------------------------------------------------------------------------- the notice gate (Divergence 2)

/** Paths a person reaches before acknowledging the notice: the notice itself, their tokens, trail and notifications, signing out. */
function notice_exempt(string $path): bool
{
    return (bool) preg_match('#^/(notice(/.*)?|notice\.php|tokens(/.*)?|my/trail(\.php)?|notifications(/.*)?|settings/notifications(/.*)?|logout(\.php)?|sso(\.php|/.*)?|api/.*|assets/.*|manifest\.webmanifest|pick(/.*)?)$#', $path);
}

/**
 * A PERSON who has not acknowledged the current notice and reaches a mail screen lands on /notice (design §3; sso-shell.md
 * Divergence 2). An agent's action token is never gated (an agent reads no notice); JSON callers are told so.
 */
function notice_gate(): void
{
    // one MaluMail does not admit (no role) is refused by the screen with the reason, never asked to read a notice for mail they cannot use
    if (PHP_SAPI === 'cli' || !is_logged_in() || is_action_authed() || (current_member()['member_kind'] ?? '') !== 'human' || !is_member_here()) {
        return;
    }
    if (notice_exempt(current_path()) || db_bool(db(), 'SELECT ml_notice_acknowledged(:m)', ['m' => (int) current_member_id()])) {
        return;
    }
    if (wants_json()) {
        json_error('notice_unacknowledged', 'Read and acknowledge the business\'s mail-monitoring notice first (/notice).', 403);
    }
    redirect('/notice?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/')));
}

// ------------------------------------------------------------------------------------------------- placeholders

/** The registry's screens (mcp/action_registry.json), cached per request. */
function registry_screens(): array
{
    static $s = null;
    return $s ??= (json_decode((string) @file_get_contents(APP_ROOT . '/mcp/action_registry.json'), true)['screens'] ?? []);
}

/** The screen a path is, by the registry's URL templates — the most literal template wins: [screen id, the template's params] or null. */
function screen_for_path(string $path): ?array
{
    $best = null;
    $bestScore = -1;
    foreach (registry_screens() as $id => $s) {
        $tpl = (string) $s['url'];
        $names = [];
        $rx = preg_replace_callback('/\\\\\{([a-z_]+)\\\\\}/', static function (array $m) use (&$names): string { $names[] = $m[1]; return '([^/]+)'; }, preg_quote($tpl, '#'));
        if (!preg_match('#^' . $rx . '/?$#', $path, $mm)) {
            continue;
        }
        $score = strlen(preg_replace('/\{[a-z_]+\}/', '', $tpl));
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = [$id, array_combine($names, array_slice($mm, 1)) ?: []];
        }
    }
    return $best;
}

/** The slice that builds a screen, from its manifest section ("… (slice 3 — …)", "(Phase 2 — …)"). */
function screen_slice(array $s): string
{
    $section = (string) ($s['section'] ?? '');
    if (preg_match('/\((slice [0-9]+)/', $section, $m)) {
        return $m[1];
    }
    return preg_match('/\((Phase [0-9]+)/', $section, $m) ? $m[1] : 'a later slice';
}

/** What a screen needs before its placeholder answers: [right, mailbox level or null]. The built screen applies its own. */
function screen_gate(string $id): array
{
    $byId = [
        'admin' => ['settings.manage', null], 'admin-sync' => ['settings.manage', null], 'admin-settings' => ['settings.manage', null],
        'admin-agents' => ['agents.manage', null], 'admin-domains' => ['domains.manage', null],
        'admin-mailboxes' => ['mailboxes.manage', null], 'admin-mailbox-new' => ['mailboxes.manage', null], 'admin-mailbox-view' => ['mailboxes.manage', null],
        'admin-aliases' => ['aliases.manage', null], 'admin-alias-new' => ['aliases.manage', null], 'admin-alias-view' => ['aliases.manage', null],
        'oversight' => ['mail.oversee', null], 'review-queue' => ['mail.oversee', null], 'review-view' => ['mail.oversee', null],
        'review-edit' => ['mail.oversee', null], 'unscreened' => ['mail.oversee', null],
        'agent-policy' => ['mail.oversee', 'full'], 'mailbox-settings' => ['mail.use', 'full'], 'access-grant-new' => ['mail.use', 'full'],
        'identity-new' => ['mail.use', 'full'], 'identity-edit' => ['mail.use', 'full'], 'folders' => ['mail.use', 'triage'],
        'labels' => ['mail.use', 'triage'], 'compose' => ['mail.use', 'triage'], 'draft-edit' => ['mail.use', 'triage'],
    ];
    if (isset($byId[$id])) {
        return $byId[$id];
    }
    $url = (string) (registry_screens()[$id]['url'] ?? '');
    return ['mail.use', str_starts_with($url, '/m/{mailbox}') ? 'view' : null];
}
