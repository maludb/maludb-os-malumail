#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Phase 1 — the claim check (MaluMail, 2026-10-11; ported from Developer's, which ported Boards' and the Data Lake's): every screen and action of mcp/action_registry.json is claimed by exactly ONE slice spec,
 * and every name a spec claims exists in the registry. Run by tests/phase1/check_specs.sh (which also runs the registry and
 * approvals checks); exit non-zero on any defect.
 *
 * A spec's claim section is headed exactly `## Manifest rows claimed` and holds two lines, each on ONE line:
 *     Screens (N): `screen-id`, `screen-id`, …
 *     Actions (N): `action_name`, `action_name`, …
 * N must equal the number of names on the line. Anything else in the section (tables, "Left to …" notes) is for people and is not read.
 * Then the tools: every `| `name`` row of docs/malumail-mcp-tool-surface.md is named by at least one slice (its "MCP tools" section, or
 * the shell's "MCP tools this slice names"), and every tool a slice names is a row of the surface; and every approval of maludb-os.json is a
 * manifest action with the same category.
 */
$root = dirname(__DIR__, 2);
$registryPath = $root . '/mcp/action_registry.json';
$specDir = $root . '/docs/build-specs';
$specs = ['sso-shell', 'domains-mailboxes', 'mail-list', 'reading', 'writing', 'oversight', 'search-labels-snooze', 'delegation-access', 'correspondents', 'notifications', 'home-admin'];
$buildSpecs = ['mail-service', 'reading-and-rendering', 'sending-and-oversight'];

$registry = json_decode((string) file_get_contents($registryPath), true);
if (!is_array($registry) || !isset($registry['screens'], $registry['actions'])) {
    fwrite(STDERR, "FAIL cannot read {$registryPath}\n");
    exit(2);
}
$screens = array_keys($registry['screens']);
$actions = array_keys($registry['actions']);

$ok = 0;
$fail = 0;
$say = static function (bool $pass, string $what) use (&$ok, &$fail): void {
    echo ($pass ? 'ok   ' : 'FAIL ') . $what . "\n";
    $pass ? $ok++ : $fail++;
};

/** The claim section of one spec → ['screens' => [...], 'actions' => [...]] plus the counts it states. */
function read_claims(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $in = false;
    $out = ['screens' => null, 'actions' => null, 'screens_n' => null, 'actions_n' => null, 'heading' => false];
    foreach ($lines as $line) {
        if (str_starts_with($line, '## ')) {
            if ($in) {
                break;
            }
            $in = trim($line) === '## Manifest rows claimed';
            $out['heading'] = $out['heading'] || $in;
            continue;
        }
        if (!$in) {
            continue;
        }
        if (preg_match('/^(Screens|Actions)\s*\((\d+)\):\s*(.*)$/', $line, $m)) {
            $kind = strtolower($m[1]);
            preg_match_all('/`([a-z0-9_-]+)`/', $m[3], $names);
            $out[$kind] = $names[1];
            $out[$kind . '_n'] = (int) $m[2];
        }
    }
    return $out;
}

$claimedBy = ['screens' => [], 'actions' => []];      // name → [spec, …]
$perSpec = [];
foreach ($specs as $spec) {
    $path = "{$specDir}/{$spec}.md";
    if (!is_file($path)) {
        $say(false, "{$spec}.md: missing");
        continue;
    }
    $c = read_claims($path);
    $say($c['heading'], "{$spec}.md: has a `## Manifest rows claimed` section");
    foreach (['screens', 'actions'] as $kind) {
        $list = $c[$kind];
        if ($list === null) {
            $say(false, "{$spec}.md: no `" . ucfirst($kind) . " (N):` line in the claim section");
            $list = [];
        } else {
            $say(count($list) === $c[$kind . '_n'], "{$spec}.md: " . ucfirst($kind) . " ({$c[$kind . '_n']}) names " . count($list));
            $dupes = array_keys(array_filter(array_count_values($list), static fn (int $n): bool => $n > 1));
            $say($dupes === [], "{$spec}.md: no name twice in its own {$kind} line" . ($dupes ? ' — ' . implode(', ', $dupes) : ''));
        }
        $perSpec[$spec][$kind] = count(array_unique($list));
        foreach (array_unique($list) as $name) {
            $claimedBy[$kind][$name][] = $spec;
        }
    }
}

// Every claimed name exists in the registry.
foreach (['screens' => $screens, 'actions' => $actions] as $kind => $known) {
    foreach ($claimedBy[$kind] as $name => $by) {
        if (!in_array($name, $known, true)) {
            $say(false, "{$kind}: `{$name}` claimed by " . implode(', ', $by) . " is not in the registry");
        }
    }
}
// Every registry row is claimed by exactly one spec.
$unclaimed = ['screens' => [], 'actions' => []];
$twice = ['screens' => [], 'actions' => []];
foreach (['screens' => $screens, 'actions' => $actions] as $kind => $known) {
    foreach ($known as $name) {
        $by = $claimedBy[$kind][$name] ?? [];
        if ($by === []) {
            $unclaimed[$kind][] = $name;
            $say(false, "{$kind}: `{$name}` (section \"" . $registry[$kind][$name]['section'] . "\") is claimed by no spec");
        } elseif (count($by) > 1) {
            $twice[$kind][] = $name;
            $say(false, "{$kind}: `{$name}` is claimed by " . count($by) . ' specs — ' . implode(', ', $by));
        }
    }
    $say($unclaimed[$kind] === [] && $twice[$kind] === [], "{$kind}: every one of " . count($known) . ' claimed by exactly one spec');
}

// ---- the tools: the surface's rows vs what the slices name
$surface = (string) file_get_contents($root . '/docs/malumail-mcp-tool-surface.md');
$from = strpos($surface, '## Records server'); $to = strpos($surface, '## The resolvers');     // the tool tables only: not the status, gate, inventory or resolver tables
$surface = substr($surface, $from, $to - $from);
preg_match_all('/^\| `([a-z_]+)` \|/m', $surface, $sm);
$surfaceTools = array_values(array_unique($sm[1]));
$namedBy = [];
foreach ($specs as $spec) {
    $text = (string) file_get_contents("{$specDir}/{$spec}.md");
    if (preg_match('/^## MCP tools this slice (?:makes real|names).*?(?=^## )/ms', $text, $tm)) {
        preg_match_all('/`([a-z_]+)`/', $tm[0], $nm);
        foreach (array_unique($nm[1]) as $n) { $namedBy[$n][] = $spec; }
    } else {
        $say(false, "{$spec}.md: no `## MCP tools this slice …` section");
    }
}
$notInSurface = [];
foreach ($namedBy as $n => $by) {
    if (!in_array($n, $surfaceTools, true)) {
        $notInSurface[] = $n;
        $say(false, "tools: `{$n}` named by " . implode(', ', $by) . ' is not a row of the surface');
    }
}
$unnamed = [];
foreach ($surfaceTools as $n) {
    if (!isset($namedBy[$n])) { $unnamed[] = $n; }
}
$say($unnamed === [], 'tools: every one of ' . count($surfaceTools) . ' surface tools is named by a slice' . ($unnamed ? ' — unnamed: ' . implode(', ', $unnamed) : ''));
$say($notInSurface === [], 'tools: every tool a slice names is in the surface');
// ---- the approvals: maludb-os.json vs the registry
$manifestJson = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$bad = [];
foreach ($manifestJson['approvals'] ?? [] as $a) {
    $reg = $registry['actions'][$a['action']] ?? null;
    if ($reg === null || ($reg['approval'] ?? null) !== $a['category']) { $bad[] = $a['action']; }
}
// ---- the agents: every tool and action granted in maludb-os.json exists (a grant to a missing tool is a hire that fails at install)
$missingGrants = [];
foreach ($manifestJson['agents'] ?? [] as $ag) {
    foreach (['Records MCP', 'Activity MCP'] as $ep) {
        foreach ($ag['tool_grants'][$ep] ?? [] as $t) { if (!in_array($t, $surfaceTools, true)) { $missingGrants[] = "{$ag['key']}:{$t}"; } }
    }
    foreach ($ag['tool_grants']['Actions MCP'] ?? [] as $t) { if (!isset($registry['actions'][$t])) { $missingGrants[] = "{$ag['key']}:{$t}"; } }
}
$say($missingGrants === [], 'agents: every granted tool and action exists' . ($missingGrants ? ' — missing: ' . implode(', ', $missingGrants) : ''));
foreach ($manifestJson['agents'] ?? [] as $ag) {
    $granted = [];
    foreach ($ag['tool_grants'] ?? [] as $list) { foreach ($list as $t) { $granted[] = $t; } }
    $md = (string) @file_get_contents($root . '/' . $ag['job_description']);
    $named = [];
    if (preg_match('/## Tools \(your grants\)(.*?)(?=Skills you carry|^## )/ms', $md, $sec)) {
        preg_match_all('/`([a-z_]+)`/', $sec[1], $tm);
        $named = $tm[1];
    }
    $extra = array_diff(array_unique($named), $granted);
    $lacking = array_diff(array_unique($granted), $named);
    $say($named !== [] && $extra === [] && $lacking === [], "agents: {$ag['key']}'s job description names exactly its grants"
        . ($extra ? ' — named, not granted: ' . implode(', ', $extra) : '') . ($lacking ? ' — granted, not named: ' . implode(', ', $lacking) : ''));
}
foreach ($buildSpecs as $b) {
    $say(is_file("{$specDir}/{$b}.md"), "build spec {$b}.md exists");
}
$say($bad === [], 'approvals: every one of ' . count($manifestJson['approvals'] ?? []) . ' is a registry action with the same category' . ($bad ? ' — ' . implode(', ', $bad) : ''));

// ---- the requirements: every MUST of research 03 (R1–R63) is claimed by a spec, or held elsewhere by name in docs/phase1-consistency.md
$res = $root . '/docs/research/';
$must = [];
$grab = static function (string $file, string $re) use (&$must, $res): void {
    preg_match_all($re, (string) file_get_contents($res . $file), $m);
    foreach ($m[1] as $id) { $must[$id] = true; }
};
$grab('03-webmail-and-agent-mail.md', '/^\| (R\d+) \| MUST \|/m');
$say(count($must) === 41, 'requirements: ' . count($must) . ' MUSTs read from docs/research/03-webmail-and-agent-mail.md');
$readReq = static function (string $path, string $heading): array {
    $out = []; $in = false; $stated = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_starts_with($line, '## ')) { if ($in) { break; } $in = trim($line) === $heading; continue; }
        if (!$in) { continue; }
        if (preg_match('/^([A-Za-z ]+)\s*\((\d+)\):\s*(.*)$/', $line, $m)) {
            preg_match_all('/\b((?:MR|R|T|E|S)\d+)\b/', $m[3], $ids);
            $stated[] = [trim($m[1]), (int) $m[2], count(array_unique($ids[1]))];
            foreach ($ids[1] as $id) { $out[$id] = true; }
        }
    }
    return [$out, $stated];
};
$claimedReq = [];
foreach (array_merge($specs, $buildSpecs) as $spec) {
    $path = "{$specDir}/{$spec}.md";
    if (!is_file($path)) { continue; }
    [$ids, $stated] = $readReq($path, '## Requirements claimed');
    if (in_array($spec, $buildSpecs, true) && $stated === []) { continue; }          // a build spec claims only when it says so
    $say($stated !== [], "{$spec}.md: has a `## Requirements claimed` section with a `MUSTs (N):` line");
    foreach ($stated as [$label, $n, $count]) { $say($n === $count, "{$spec}.md: {$label} ({$n}) names {$count}"); }
    foreach (array_keys($ids) as $id) { $claimedReq[$id][] = $spec; }
}
$consistency = $root . '/docs/phase1-consistency.md';
$held = [];
if (is_file($consistency)) {
    [$held, $stated] = $readReq($consistency, '## Requirements not claimed by a slice');
    foreach ($stated as [$label, $n, $count]) { $say($n === $count, "phase1-consistency.md: {$label} ({$n}) names {$count}"); }
} else {
    $say(false, 'docs/phase1-consistency.md exists');
}
$unclaimedReq = array_values(array_filter(array_keys($must), static fn (string $id): bool => !isset($claimedReq[$id]) && !isset($held[$id])));
$say($unclaimedReq === [], 'requirements: every MUST is claimed by a spec or held elsewhere by name' . ($unclaimedReq ? ' — unclaimed: ' . implode(', ', $unclaimedReq) : ''));
$unknown = array_values(array_filter(array_keys($claimedReq + $held), static fn (string $id): bool => !isset($must[$id]) && !preg_match('/^(R|T|MR|E|S)\d+$/', $id)));
$say($unknown === [], 'requirements: no claim names an id the research lacks');

echo "\nClaimed per spec (screens / actions):\n";
$ts = 0; $ta = 0;
foreach ($perSpec as $spec => $n) {
    printf("  %-16s %3d / %3d\n", $spec, $n['screens'], $n['actions']);
    $ts += $n['screens']; $ta += $n['actions'];
}
printf("  %-16s %3d / %3d   (registry: %d screens, %d actions)\n", 'total', $ts, $ta, count($screens), count($actions));
printf("\n%d ok, %d FAIL\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
