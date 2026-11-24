<?php
declare(strict_types=1);
/**
 * Machine-wide report across ALL projects and sessions:
 *   disk usage of ~/.claude (per top-level dir and per project transcripts, plus each project's .claude/orch),
 *   tokens + estimated USD per project, agent and model for the last N days.
 * Usage: php -n global.php [--days=7] [--top=12] [--json]
 * Reads only; changes nothing. USD uses built-in prices (per-project overrides are ignored).
 */
require __DIR__ . '/lib-usage.php';

$opts = getopt('', ['days:', 'top:', 'json']);
$days = max(1, (int)($opts['days'] ?? 7));
$top = max(1, (int)($opts['top'] ?? 12));
$since = time() - $days * 86400;
$home = orch_claude_home();

// --- disk: top-level dirs of ~/.claude ---
$disk = [];
$total = 0;
foreach (scandir($home) ?: [] as $name) {
    if ($name === '.' || $name === '..') {
        continue;
    }
    $p = "$home/$name";
    $s = is_dir($p) ? orch_dir_size($p) : ['bytes' => (int)@filesize($p), 'files' => 1];
    $total += $s['bytes'];
    if (is_dir($p)) {
        $disk[$name] = $s;
    }
}
uasort($disk, fn($a, $b) => $b['bytes'] <=> $a['bytes']);

// --- per project ---
$projects = [];
$allRows = [];
foreach (glob("$home/projects/*", GLOB_ONLYDIR) ?: [] as $dir) {
    $sessions = glob("$dir/*.jsonl") ?: [];
    $last = 0;
    foreach ($sessions as $f) {
        $last = max($last, (int)@filemtime($f));
    }
    $path = project_path($sessions) ?? basename($dir);
    $rows = orch_usage_messages_dir($dir, $since);
    foreach ($rows as &$r) {
        $r['project'] = $path;
    }
    unset($r);
    array_push($allRows, ...$rows);
    $orch = is_dir("$path/.claude/orch") ? orch_dir_size("$path/.claude/orch")['bytes'] : 0;
    $projects[$path] = [
        'slug' => basename($dir), 'bytes' => orch_dir_size($dir)['bytes'], 'sessions' => count($sessions),
        'last' => $last, 'orch_bytes' => $orch, 'usage' => orch_usage_sum($rows),
    ];
}
uasort($projects, fn($a, $b) => $b['bytes'] <=> $a['bytes']);

$byAgent = group_sum($allRows, 'agent');
$byModel = group_sum($allRows, 'model');
$totals = orch_usage_sum($allRows);

if (isset($opts['json'])) {
    echo json_encode([
        'days' => $days, 'home' => $home, 'disk_total' => $total, 'disk' => (object)$disk,
        'projects' => (object)$projects, 'agents' => (object)$byAgent, 'models' => (object)$byModel, 'totals' => $totals,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
    exit(0);
}

echo "orch global — $home — celkem " . orch_fmt_bytes($total) . "\n";

echo "\nDisk (~/.claude)\n";
foreach (array_slice($disk, 0, 8, true) as $name => $s) {
    printf("  %-22s %10s %8d souborů\n", "$name/", orch_fmt_bytes($s['bytes']), $s['files']);
}

printf("\nProjekty (%d) — přepisy na disku, tokeny za %d dní\n", count($projects), $days);
printf("  %-34s %10s %7s %11s %9s %8s %9s\n", 'projekt', 'přepisy', 'sezení', 'naposledy', 'tokeny', 'USD', 'orch/');
foreach (array_slice($projects, 0, $top, true) as $path => $p) {
    $u = $p['usage'];
    printf("  %s %9s %6d %11s %9s %8.2f %9s\n", pad(cut(short_path($path), 34), 34), orch_fmt_bytes($p['bytes']), $p['sessions'],
        $p['last'] ? date('Y-m-d', $p['last']) : '-', orch_fmt_tokens(tok($u)), $u['usd'],
        $p['orch_bytes'] ? orch_fmt_bytes($p['orch_bytes']) : '-');
}
if (count($projects) > $top) {
    echo '  … a ' . (count($projects) - $top) . " dalších (--top=N)\n";
}

if ($allRows) {
    foreach (['Podle agenta' => $byAgent, 'Podle modelu' => $byModel] as $title => $sums) {
        printf("\n%-26s %6s %9s %8s %8s\n", "$title ({$days} dní)", 'calls', 'tokeny', 'output', 'USD');
        foreach (array_slice($sums, 0, 10, true) as $name => $s) {
            printf("%-26s %6d %9s %8s %8.2f\n", cut((string)$name, 26), $s['calls'], orch_fmt_tokens(tok($s)), orch_fmt_tokens($s['out']), $s['usd']);
        }
        printf("%-26s %6d %9s %8s %8.2f\n", 'CELKEM', $totals['calls'], orch_fmt_tokens(tok($totals)), orch_fmt_tokens($totals['out']), $totals['usd']);
    }
} else {
    echo "\nZa posledních $days dní žádné tokeny.\n";
}
echo "\nPřepisy starší než cleanupPeriodDays (settings.json, výchozí 30) maže Claude Code sám.\n";

/** Real project path from the "cwd" field of the newest transcript. */
function project_path(array $sessions): ?string
{
    usort($sessions, fn($a, $b) => (int)@filemtime($b) <=> (int)@filemtime($a));
    foreach (array_slice($sessions, 0, 3) as $f) {
        $fh = @fopen($f, 'r');
        for ($i = 0; $fh && $i < 50 && ($line = fgets($fh)) !== false; $i++) {
            if (str_contains($line, '"cwd"') && preg_match('/"cwd":"((?:[^"\\\\]|\\\\.)*)"/', $line, $m)) {
                fclose($fh);
                return orch_norm((string)json_decode('"' . $m[1] . '"'));
            }
        }
        $fh && fclose($fh);
    }
    return null;
}

function group_sum(array $rows, string $key): array
{
    $g = [];
    foreach ($rows as $r) {
        $g[$r[$key]][] = $r;
    }
    $sums = array_map(fn($x) => orch_usage_sum($x), $g);
    uasort($sums, fn($a, $b) => [$b['usd'], tok($b)] <=> [$a['usd'], tok($a)]);
    return $sums;
}

function tok(array $s): int
{
    return $s['in'] + $s['cw5'] + $s['cw1h'] + $s['cread'] + $s['out'];
}

function short_path(string $p): string
{
    $h = orch_norm((string)(getenv('USERPROFILE') ?: getenv('HOME')));
    return $h !== '' && stripos($p, $h) === 0 ? '~' . substr($p, strlen($h)) : $p;
}

function cut(string $s, int $n): string
{
    // php -n has no mbstring; keep the tail (the project name) and cut on UTF-8 boundaries
    return strlen($s) <= $n ? $s : '…' . (preg_match('/.{0,' . ($n - 1) . '}$/us', $s, $m) ? $m[0] : substr($s, -($n - 1)));
}

function pad(string $s, int $n): string
{
    return $s . str_repeat(' ', max(0, $n - preg_match_all('/./us', $s)));
}
