<?php
declare(strict_types=1);
/**
 * Frees disk space in ~/.claude: moves session transcripts not touched for N days
 * (projects/<slug>/<session>.jsonl + projects/<slug>/<session>/ with subagents + file-history/<session>/)
 * to ~/.claude/orch-trash/<time>/. Nothing is deleted outright; trash older than 7 days is purged on the next real run.
 *
 *   php -n prune.php [--days=30] [--dry-run] [--keep=<session id>]
 *   php -n prune.php --restore-last        moves the newest trash batch back
 *
 * Min --days is 7. Pruned sessions disappear from /resume and from stats.
 */
require __DIR__ . '/lib-usage.php';

$opts = getopt('', ['days:', 'dry-run', 'keep:', 'restore-last', 'trash-days:']);
$home = orch_claude_home();
$trashRoot = "$home/orch-trash";
$now = time();

if (isset($opts['restore-last'])) {
    $batches = glob("$trashRoot/*", GLOB_ONLYDIR) ?: [];
    sort($batches);
    $last = end($batches);
    if (!$last) {
        echo "Koš je prázdný.\n";
        exit(0);
    }
    $n = 0;
    foreach (batch_items($last) as $rel) {
        $to = "$home/$rel";
        if (!file_exists($to)) {
            @mkdir(dirname($to), 0777, true);
            $n += @rename("$last/$rel", $to) ? 1 : 0;
        }
    }
    @remove_tree($last);
    echo "Obnoveno $n položek z " . basename($last) . ".\n";
    exit(0);
}

$days = max(7, (int)($opts['days'] ?? 30));
$dry = isset($opts['dry-run']);
$keep = (string)($opts['keep'] ?? (getenv('CLAUDE_SESSION_ID') ?: ''));
$cutoff = $now - $days * 86400;

$plan = []; // project slug => [items, bytes]
$moves = [];
foreach (glob("$home/projects/*", GLOB_ONLYDIR) ?: [] as $dir) {
    $slug = basename($dir);
    foreach (glob("$dir/*.jsonl") ?: [] as $f) {
        $sid = basename($f, '.jsonl');
        if ($sid === $keep || (int)@filemtime($f) >= $cutoff) {
            continue;
        }
        $items = ["projects/$slug/$sid.jsonl"];
        if (is_dir("$dir/$sid")) {
            if (newest("$dir/$sid") >= $cutoff) {
                continue; // a subagent of this session was active recently
            }
            $items[] = "projects/$slug/$sid";
        }
        if (is_dir("$home/file-history/$sid")) {
            $items[] = "file-history/$sid";
        }
        $bytes = 0;
        foreach ($items as $rel) {
            $p = "$home/$rel";
            $bytes += is_dir($p) ? orch_dir_size($p)['bytes'] : (int)@filesize($p);
        }
        $plan[$slug] ??= ['sessions' => 0, 'bytes' => 0];
        $plan[$slug]['sessions']++;
        $plan[$slug]['bytes'] += $bytes;
        array_push($moves, ...$items);
    }
}

if (!$moves) {
    echo "Nic k úklidu: žádné sezení starší než $days dní.\n";
} else {
    uasort($plan, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    $total = array_sum(array_column($plan, 'bytes'));
    echo ($dry ? 'Náhled — ' : '') . "sezení nepoužitá $days+ dní:\n";
    foreach ($plan as $slug => $p) {
        printf("  %-50s %3d sezení %10s\n", $slug, $p['sessions'], orch_fmt_bytes($p['bytes']));
    }
    printf("Celkem %d sezení, %s.\n", array_sum(array_column($plan, 'sessions')), orch_fmt_bytes($total));
    if (!$dry) {
        $batch = "$trashRoot/" . date('Ymd-His', $now);
        $ok = 0;
        foreach ($moves as $rel) {
            @mkdir(dirname("$batch/$rel"), 0777, true);
            $ok += @rename("$home/$rel", "$batch/$rel") ? 1 : 0;
        }
        echo "Přesunuto $ok/" . count($moves) . " položek do $batch (vrátíš: prune.php --restore-last).\n";
    }
}

if (!$dry) {
    $keepDays = max(1, (int)($opts['trash-days'] ?? 7));
    $purged = 0;
    foreach (glob("$trashRoot/*", GLOB_ONLYDIR) ?: [] as $b) {
        if ((int)@filemtime($b) < $now - $keepDays * 86400) {
            remove_tree($b);
            $purged++;
        }
    }
    if ($purged) {
        echo "Smazáno $purged starých košů (> $keepDays dní).\n";
    }
}

function newest(string $dir): int
{
    $t = (int)@filemtime($dir);
    try {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            $t = max($t, (int)$f->getMTime());
        }
    } catch (Throwable) {
    }
    return $t;
}

/** Top-level moved items of a batch, relative to ~/.claude (projects/<slug>/<x>, file-history/<sid>). */
function batch_items(string $batch): array
{
    $items = [];
    foreach (glob("$batch/projects/*/*") ?: [] as $p) {
        $items[] = substr($p, strlen($batch) + 1);
    }
    foreach (glob("$batch/file-history/*") ?: [] as $p) {
        $items[] = substr($p, strlen($batch) + 1);
    }
    return $items;
}

function remove_tree(string $dir): void
{
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
    } catch (Throwable) {
    }
    @rmdir($dir);
}
