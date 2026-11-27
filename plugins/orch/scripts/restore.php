<?php
declare(strict_types=1);
/**
 * Restores files moved to .claude/orch/trash by cleanup.
 *   --list [--limit=20]       recent files still in trash (newest first)
 *   --file=<relative path>    restore the newest trashed copy of that file
 *   --batch=<trash dir name>  restore a whole cleanup batch
 *   --force                   overwrite an existing file
 *   --project=<dir>
 */
require __DIR__ . '/common.php';

$opts = getopt('', ['list', 'limit:', 'file:', 'batch:', 'force', 'project:', 'session:']);
$project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir();
orch_lang_init($project, (string)($opts['session'] ?? ''));
$state = orch_state_dir($project);
$force = isset($opts['force']);

// newest first: [trash dir, rel path]
$entries = [];
foreach (array_reverse(orch_read_jsonl("$state/cleanup.log")) as $row) {
    foreach ($row['moved'] ?? [] as $m) {
        $src = "$state/trash/{$row['trash']}/{$m['file']}";
        if (is_file($src)) {
            $entries[] = ['trash' => (string)$row['trash'], 'file' => (string)$m['file'], 'agent' => (string)($m['agent'] ?? '?'), 'src' => $src];
        }
    }
}

if (isset($opts['list']) || (!isset($opts['file']) && !isset($opts['batch']))) {
    if (!$entries) {
        echo orch_t("Koš je prázdný.\n", "The trash is empty.\n");
        exit(0);
    }
    foreach (array_slice($entries, 0, max(1, (int)($opts['limit'] ?? 20))) as $e) {
        printf("%-24s %-40s [%s]\n", $e['trash'], $e['file'], $e['agent']);
    }
    exit(0);
}

$todo = [];
if (isset($opts['file'])) {
    $want = trim(orch_norm((string)$opts['file']), '/');
    foreach ($entries as $e) {
        if (strcasecmp($e['file'], $want) === 0) {
            $todo[] = $e;
            break;
        }
    }
} else {
    $todo = array_values(array_filter($entries, fn($e) => $e['trash'] === (string)$opts['batch']));
}
if (!$todo) {
    fwrite(STDERR, orch_t("V koši nic takového není. Seznam: --list\n", "There is nothing like that in the trash. List: --list\n"));
    exit(1);
}

$code = 0;
foreach ($todo as $e) {
    $dest = "$project/{$e['file']}";
    if (is_file($dest) && !$force) {
        echo orch_t("! {$e['file']} už existuje (použij --force)\n", "! {$e['file']} already exists (use --force)\n");
        $code = 1;
        continue;
    }
    @mkdir(dirname($dest), 0777, true);
    if (@rename($e['src'], $dest)) {
        echo orch_t("↩ {$e['file']}  (z {$e['trash']})\n", "↩ {$e['file']}  (from {$e['trash']})\n");
        orch_append_jsonl("$state/cleanup.log", ['ts' => date('c'), 'restored' => $e['file'], 'trash' => $e['trash']]);
    } else {
        echo orch_t("! {$e['file']}: nepodařilo se přesunout\n", "! {$e['file']}: could not be moved\n");
        $code = 1;
    }
}
exit($code);
