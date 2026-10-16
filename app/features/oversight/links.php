<?php
declare(strict_types=1);

/**
 * A held message's links with their REAL hosts (oversight.md decision 3): parsed server-side from the HTML the mail service sanitised at
 * Send (and any bare URL in the text), so a reviewer sees where a link goes without hovering — the anchor's words are never trusted.
 */

/** The links of a held message's HTML with their real hosts (decision 3): [[text, href, host]] — parsed server-side, never trusted from the agent. */
function ov_links_with_hosts(?string $html, string $text = ''): array
{
    $out = [];
    if ($html !== null && trim($html) !== '') {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $out[] = [trim(preg_replace('/\s+/u', ' ', (string) $a->textContent) ?? ''), $href, ov_link_host($href)];
        }
    }
    if (preg_match_all('#\bhttps?://[^\s<>"\']+#i', $text, $m)) {
        $seen = array_column($out, 1);
        foreach ($m[0] as $u) {
            $u = rtrim($u, '.,);:!?');
            if (!in_array($u, $seen, true)) {
                $out[] = ['', $u, ov_link_host($u)];
                $seen[] = $u;
            }
        }
    }
    return array_slice($out, 0, 200);
}

function ov_link_host(string $href): string
{
    if (preg_match('/^mailto:/i', $href)) {
        $addr = substr($href, 7);
        $addr = explode('?', $addr, 2)[0];
        return 'mail to ' . strtolower(rawurldecode($addr));
    }
    $host = parse_url($href, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? strtolower($host) : '(no host)';       // an international name stays in its xn-- form: a look-alike shows as one
}
