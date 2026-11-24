<?php
declare(strict_types=1);
/**
 * Compact report for one project:
 *   tokens + estimated USD per agent and model, orchestration runs, review stats,
 *   files written/cleaned by agents, guard blocks, notifications.
 * Usage: php -n stats.php [--days=7] [--project=<dir>]
 *
 * USD is an API-price estimate (see ORCH_PRICES in common.php). On a Pro/Max subscription
 * you don't pay per token; treat it as a relative cost measure.
 */
require __DIR__ . '/lib-usage.php';

$opts = getopt('', ['days:', 'project:']);
$days = max(1, (int)($opts['days'] ?? 7));
$project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir();
$since = time() - $days * 86400;
$cfg = orch_config($project);
$state = "$project/.claude/orch";

$rows = orch_usage_messages($project, $since);

echo "orch stats — posledních $days dní — " . basename($project) . "\n";

// --- tokens ---
if (!$rows) {
    echo "\nŽádné přepisy sezení" . (orch_transcript_dir($project) ? '' : ' (adresář nenalezen)') . ".\n";
} else {
    $sessions = count(array_unique(array_column($rows, 'session')));
    echo "Sezení: $sessions\n";
    foreach (['agent' => 'Podle agenta', 'model' => 'Podle modelu'] as $key => $title) {
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r[$key]][] = $r;
        }
        $sums = array_map(fn($g) => orch_usage_sum($g, $cfg['prices']), $groups);
        uasort($sums, fn($a, $b) => $b['usd'] <=> $a['usd']);
        printf("\n%-26s %6s %8s %9s %9s %8s %8s\n", $title, 'calls', 'input', 'cache_wr', 'cache_rd', 'output', 'USD');
        foreach ($sums as $name => $s) {
            print_row((string)$name, $s);
        }
        print_row('CELKEM', orch_usage_sum($rows, $cfg['prices']));
    }
}

// --- runs ---
$runs = array_values(array_filter(orch_read_jsonl("$state/runs.log"), fn($r) => ($r['event'] ?? '') === 'end' && ($r['epoch'] ?? 0) >= $since));
if ($runs) {
    echo "\nBěhy orchestrace (" . count($runs) . "):\n";
    printf("  %-8s %-34s %-17s %6s %8s %7s\n", 'mód', 'úkol', 'verdikt', 'kola', 'čas', 'USD');
    foreach (array_slice(array_reverse($runs), 0, 10) as $r) {
        printf("  %-8s %-34s %-17s %6d %8s %7.2f\n", $r['mode'], cut((string)$r['task'], 34), $r['verdict'], $r['rounds'],
            orch_fmt_duration((int)$r['seconds']), (float)$r['usd']);
    }
    $approvedFirst = count(array_filter($runs, fn($r) => $r['verdict'] === 'APPROVED' && $r['rounds'] <= 1));
    $avgRounds = array_sum(array_column($runs, 'rounds')) / count($runs);
    $avgUsd = array_sum(array_column($runs, 'usd')) / count($runs);
    $cats = [];
    foreach ($runs as $r) {
        foreach ($r['categories'] ?? [] as $c) {
            $cats[$c] = ($cats[$c] ?? 0) + 1;
        }
    }
    arsort($cats);
    printf("Review: schváleno na 1. pokus %d %%, průměrně %.1f kol, ~$%.2f za běh\n",
        round(100 * $approvedFirst / count($runs)), $avgRounds, $avgUsd);
    if ($cats) {
        echo 'Nejčastější nálezy: ' . implode(', ', array_map(fn($k, $v) => "$k ($v)", array_keys($cats), $cats)) . "\n";
    }
}

// --- files ---
$written = [];
$tmpWritten = 0;
foreach (orch_read_jsonl("$state/files.log") as $r) {
    if (strtotime((string)($r['ts'] ?? '')) >= $since) {
        $a = (string)($r['agent'] ?? 'main');
        $written[$a] = ($written[$a] ?? 0) + 1;
        $tmpWritten += orch_matches((string)$r['file'], $cfg['patterns']) ? 1 : 0;
    }
}
$moved = 0;
$movedBytes = 0;
$restored = 0;
foreach (orch_read_jsonl("$state/cleanup.log") as $r) {
    if (strtotime((string)($r['ts'] ?? '')) < $since) {
        continue;
    }
    $restored += isset($r['restored']) ? 1 : 0;
    foreach ($r['moved'] ?? [] as $m) {
        $moved++;
        $movedBytes += (int)($m['bytes'] ?? 0);
    }
}
arsort($written);
echo "\nSoubory (Write): " . array_sum($written) . " zápisů, dočasných $tmpWritten"
    . ($written ? ' — ' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($written), $written)) : '') . "\n";
printf("Úklid: %d souborů do koše (%s B), obnoveno %d, košů na disku %d\n",
    $moved, orch_fmt_tokens($movedBytes), $restored, count(glob("$state/trash/*", GLOB_ONLYDIR) ?: []));

// --- guard / notify ---
$blocks = array_filter(orch_read_jsonl("$state/guard.log"), fn($r) => strtotime((string)$r['ts']) >= $since);
if ($blocks) {
    $why = array_count_values(array_column($blocks, 'reason'));
    arsort($why);
    echo 'Pojistka zablokovala: ' . count($blocks) . ' — ' . implode(', ', array_map(fn($k, $v) => "$k ($v)", array_keys($why), $why)) . "\n";
}
$notes = array_filter(orch_read_jsonl("$state/notify.log"), fn($r) => strtotime((string)$r['ts']) >= $since);
if ($notes) {
    $fail = count(array_filter($notes, fn($r) => in_array(true, array_map(fn($v) => $v !== 'ok', $r['results'] ?? []), true)));
    echo 'Notifikace: ' . count($notes) . " odesláno" . ($fail ? ", $fail s chybou (viz .claude/orch/notify.log)" : '') . "\n";
}

function print_row(string $name, array $s): void
{
    printf("%-26s %6d %8s %9s %9s %8s %8s\n", cut($name, 26), $s['calls'], orch_fmt_tokens($s['in']),
        orch_fmt_tokens($s['cw5'] + $s['cw1h']), orch_fmt_tokens($s['cread']), orch_fmt_tokens($s['out']),
        $s['unpriced'] && $s['usd'] == 0 ? '?' : sprintf('%.2f', $s['usd']));
}

function cut(string $s, int $n): string
{
    return strlen($s) <= $n ? $s : substr($s, 0, $n - 1) . '…';
}
