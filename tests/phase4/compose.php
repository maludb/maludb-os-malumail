<?php
/**
 * Composes the registry the way the installer writes mcp/registries/malumail.json — deploy/kernel-registry-malumail.json's resolve block + mcp/action_registry.json,
 * each built action carrying the tool_name K55 gives it (the KERNEL's own assign_registry_tool_names(), read only, against the registries installed beside it) —
 * and writes it to the path given. Prints the actions that take the prefix here. Nothing of the kernel is written.
 *   php tests/phase4/compose.php <out.json> <records mcp url> <app internal url>
 */
$root = dirname(__DIR__, 2);
[, $out, $records, $base] = $argv;
require_once '/var/www/app/features/applications/action_tool_names.php';
$wrapper = json_decode((string) file_get_contents($root . '/deploy/kernel-registry-malumail.json'), true);
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$others = load_action_registries('/var/www/mcp/registries');
unset($others['malumail']);
$composed = ['schema' => 'maludb-os.registry/1', 'app_key' => 'malumail', 'name' => 'MaluMail', 'base_url' => $base, 'records_url' => $records, 'resolve' => $wrapper['resolve'], 'registry' => $reg];
[$named, $clash] = assign_registry_tool_names('malumail', $composed, null, $others, kernel_action_names('/var/www'));
file_put_contents($out, json_encode($named));
$prefixed = [];
foreach ($named['registry']['actions'] as $a) { if (($a['tool_name'] ?? $a['action']) !== $a['action']) { $prefixed[] = $a['action']; } }
echo json_encode(['clash' => $clash, 'others' => count($others), 'prefixed' => $prefixed]), "\n";
