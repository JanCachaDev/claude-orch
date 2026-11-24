<?php
declare(strict_types=1);
/**
 * Compact report: tokens per agent / model from Claude Code transcripts + files written & cleaned by agents.
 * Usage: php -n stats.php [--days=7] [--project=<dir>]
 *
 * Transcripts: ~/.claude/projects/<slug>/<session>.jsonl (main thread)
 *              ~/.claude/projects/<slug>/<session>/subagents/agent-<id>.jsonl (+ .meta.json with agentType)
 * One API message can span several JSONL lines → deduplicated by message.id.
 * The transcript format is internal to Claude Code and may change between versions.
 */
require __DIR__ . '/common.php';

$opts = getopt('', ['days:', 'project:']);
$days = max(1, (int)($opts['days'] ?? 7));
$project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir();
$since = time() - $days * 86400;

// --- locate transcript dir ---
$home = getenv('CLAUDE_CONFIG_DIR') ?: ((getenv('USERPROFILE') ?: getenv('HOME')) . '/.claude');
$root = orch_norm($home) . '/projects';
$slug = preg_replace('/[^A-Za-z0-9]/u', '-', str_replace('/', '\\', $project))
    ?? preg_replace('/[^A-Za-z0-9]/', '-', $project);
$dir = "$root/$slug";
if (!is_dir($dir)) {
    $dir = null;
    foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $d) {
        if (strcasecmp(basename($d), (string)$slug) === 0) {
            $dir = $d;
            break;
        }
    }
}

// --- tokens ---
$byAgent = [];
$byModel = [];
$sessions = [];
$seen = [];

$add = function (string $file, string $agent) use (&$byAgent, &$byModel, &$sessions, &$seen, $since): void {
    $fh = @fopen($file, 'r');
    if (!$fh) {
        return;
    }
    while (($line = fgets($fh)) !== false) {
        if (!str_contains($line, '"usage"')) {
            continue;
        }
        $j = json_decode($line, true);
        $m = $j['message'] ?? null;
        $u = $m['usage'] ?? null;
        if (!is_array($u) || !isset($m['id']) || isset($seen[$m['id']])) {
            continue;
        }
        if (isset($j['timestamp']) && strtotime((string)$j['timestamp']) < $since) {
            continue;
        }
        $seen[$m['id']] = true;
        $row = [
            'in'     => (int)($u['input_tokens'] ?? 0),
            'cwrite' => (int)($u['cache_creation_input_tokens'] ?? 0),
            'cread'  => (int)($u['cache_read_input_tokens'] ?? 0),
            'out'    => (int)($u['output_tokens'] ?? 0),
            'calls'  => 1,
        ];
        $model = (string)($m['model'] ?? '?');
        foreach ($row as $k => $v) {
            $byAgent[$agent][$k] = ($byAgent[$agent][$k] ?? 0) + $v;
            $byModel[$model][$k] = ($byModel[$model][$k] ?? 0) + $v;
        }
        $sessions[(string)($j['sessionId'] ?? '?')] = true;
    }
    fclose($fh);
};

if ($dir) {
    foreach (glob("$dir/*.jsonl") ?: [] as $f) {
        if (filemtime($f) >= $since) {
            $add($f, 'main');
        }
    }
    foreach (glob("$dir/*/subagents/agent-*.jsonl") ?: [] as $f) {
        if (filemtime($f) < $since) {
            continue;
        }
        $meta = json_decode((string)@file_get_contents(substr($f, 0, -6) . '.meta.json'), true);
        $add($f, (string)($meta['agentType'] ?? 'subagent'));
    }
}

$fmt = fn(int $n): string => $n >= 1_000_000 ? sprintf('%.1fM', $n / 1e6) : ($n >= 1000 ? sprintf('%.1fk', $n / 1e3) : (string)$n);

$table = function (string $title, array $rows) use ($fmt): void {
    if (!$rows) {
        return;
    }
    uasort($rows, fn($a, $b) => ($b['out'] + $b['cwrite'] + $b['in']) <=> ($a['out'] + $a['cwrite'] + $a['in']));
    printf("\n%-28s %6s %8s %9s %9s %8s\n", $title, 'calls', 'input', 'cache_wr', 'cache_rd', 'output');
    $tot = [];
    foreach ($rows as $name => $r) {
        printf("%-28s %6d %8s %9s %9s %8s\n", substr((string)$name, 0, 28), $r['calls'], $fmt($r['in']), $fmt($r['cwrite']), $fmt($r['cread']), $fmt($r['out']));
        foreach ($r as $k => $v) {
            $tot[$k] = ($tot[$k] ?? 0) + $v;
        }
    }
    printf("%-28s %6d %8s %9s %9s %8s\n", 'TOTAL', $tot['calls'], $fmt($tot['in']), $fmt($tot['cwrite']), $fmt($tot['cread']), $fmt($tot['out']));
};

echo "orch stats — posledních $days dní — $project\n";
if (!$dir) {
    echo "\nPřepisy sezení nenalezeny v $root\n";
} else {
    echo 'Sezení: ' . count($sessions) . "\n";
    $table('Tokeny podle agenta', $byAgent);
    $table('Tokeny podle modelu', $byModel);
}

// --- files ---
$state = "$project/.claude/orch";
$written = [];
$tmpWritten = 0;
$cfg = orch_config($project);
foreach (orch_read_jsonl("$state/files.log") as $r) {
    if (strtotime((string)($r['ts'] ?? '')) >= $since) {
        $a = (string)($r['agent'] ?? 'main');
        $written[$a] = ($written[$a] ?? 0) + 1;
        $tmpWritten += orch_matches((string)$r['file'], $cfg['patterns']) ? 1 : 0;
    }
}
$movedCount = 0;
$movedBytes = 0;
foreach (orch_read_jsonl("$state/cleanup.log") as $r) {
    if (strtotime((string)($r['ts'] ?? '')) >= $since) {
        foreach ($r['moved'] ?? [] as $m) {
            $movedCount++;
            $movedBytes += (int)($m['bytes'] ?? 0);
        }
    }
}
$trashDirs = count(glob("$state/trash/*", GLOB_ONLYDIR) ?: []);

echo "\nSoubory (Write): " . array_sum($written) . " zápisů, z toho dočasných: $tmpWritten\n";
arsort($written);
foreach ($written as $a => $n) {
    echo "  $a: $n\n";
}
printf("Uklizeno do koše: %d souborů (%s B), košů na disku: %d\n", $movedCount, $fmt($movedBytes), $trashDirs);
