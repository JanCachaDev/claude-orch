<?php
declare(strict_types=1);
/**
 * Frees disk space in ~/.claude: moves session transcripts not touched for N days
 * (projects/<slug>/<session>.jsonl + projects/<slug>/<session>/ with subagents + file-history/<session>/)
 * to ~/.claude/orch-trash/<time>/. Nothing is deleted outright; trash older than 7 days is purged on the next real run.
 *
 *   php -n prune.php [--days=30] [--dry-run] [--keep=<session id>]
 *   php -n prune.php --restore-last        moves the newest trash batch back
 *   php -n prune.php --auto                SessionStart hook: silent, once a day, days from userConfig prune_days
 *
 * Min --days is 7. Pruned sessions disappear from /resume and from stats.
 * Never moved: named sessions (custom-title / named jobs), job sessions, running sessions (sessions/*.json), the --keep session.
 * Project memory (projects/<slug>/memory) goes to the trash only when the slug has no session transcript left.
 * Short unnamed sessions (1-2 prompts, or 0 prompts and < 100 KB) go after 1 day regardless of --days.
 */
require __DIR__ . '/lib-usage.php';
require __DIR__ . '/lib-sessions.php';

$opts = getopt('', ['days:', 'dry-run', 'keep:', 'restore-last', 'trash-days:', 'auto']);
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
    $conflicts = [];
    foreach (batch_items($last) as $rel) {
        $n += restore_item($last, $home, $rel, $conflicts);
    }
    remove_empty_dirs($last);
    echo "Obnoveno $n položek z " . basename($last) . ".\n";
    if (is_dir($last)) {
        echo "Ponecháno v koši (cíl už existuje, nic nebylo přepsáno nebo smazáno): " . basename($last) . ":\n";
        foreach ($conflicts as $c) {
            echo "  $c\n";
        }
    }
    exit(0);
}

$auto = isset($opts['auto']);
$keep = (string)($opts['keep'] ?? (getenv('CLAUDE_SESSION_ID') ?: ''));
$liveSlugs = [];
if (($pd = (string)getenv('CLAUDE_PROJECT_DIR')) !== '') {
    $liveSlugs[] = orch_slug($pd);
}
if ($auto) {
    try {
        if (!stream_isatty(STDIN)) { // stdin is read only in --auto and never from a terminal
            $in = json_decode((string)@stream_get_contents(STDIN), true);
            if (is_array($in) && is_string($in['session_id'] ?? null) && $in['session_id'] !== '') {
                $keep = $in['session_id'];
            }
            if (is_array($in) && is_string($in['transcript_path'] ?? null) && $in['transcript_path'] !== '') {
                $liveSlugs[] = basename(dirname(orch_norm($in['transcript_path'])));
            }
            if (is_array($in) && is_string($in['cwd'] ?? null) && $in['cwd'] !== '') {
                $liveSlugs[] = orch_slug($in['cwd']);
            }
        }
        $cfg = orch_option('prune_days');
        $n = is_numeric($cfg) ? (int)$cfg : 30; // unset/empty = default 30
        $stamp = "$trashRoot/.last-auto";
        if ($n <= 0 || (is_file($stamp) && $now - (int)@filemtime($stamp) < 86400) || orch_prune_blocker($home) !== null) {
            exit(0);
        }
        $days = max(7, $n);
        @mkdir($trashRoot, 0777, true);
        @file_put_contents($stamp, (string)$now);
        ob_start();
        run_prune($home, $trashRoot, $now, $days, false, $keep, 7, $liveSlugs);
        $out = (string)ob_get_clean();
        if (preg_match('/Přesunuto (\d+)\/(\d+)/', $out, $m) && (int)$m[1] > 0) {
            @file_put_contents("$trashRoot/prune.log", date('Y-m-d H:i:s', $now) . " auto-prune: $m[1] položek (nepoužito $days+ dní, krátká sezení 1+ den)\n", FILE_APPEND);
        }
    } catch (Throwable) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
    exit(0);
}

if (($why = orch_prune_blocker($home)) !== null) {
    echo "Úklid přerušen, nic nepřesunuto: $why" . "\n";
    exit(0);
}
run_prune($home, $trashRoot, $now, max(7, (int)($opts['days'] ?? 30)), isset($opts['dry-run']), $keep, (int)($opts['trash-days'] ?? 7), $liveSlugs);

function run_prune(string $home, string $trashRoot, int $now, int $days, bool $dry, string $keep, int $trashDays = 7, array $liveSlugs = []): void
{
    $cutoff = $now - $days * 86400;
    $shortCutoff = $now - 86400;
    $protected = $keep !== '' ? [$keep => true] : [];
    foreach (array_keys(orch_named_sessions($home)) as $sid) {
        $protected[$sid] = true;
    }
    $protected += orch_job_sessions($home) + orch_live_sessions($home);
    $counts = orch_prompt_counts($home);
    $nProtected = 0;
    $protSlugs = orch_protected_slugs($home) + array_fill_keys(array_map('strtolower', $liveSlugs), true);

    $plan = []; // project slug => [items, bytes]
    $moves = [];
    $orphans = [];
    foreach (glob("$home/projects/*", GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        $left = 0;
        foreach (glob("$dir/*.jsonl") ?: [] as $f) {
            $left++;
            $sid = basename($f, '.jsonl');
            $mt = (int)@filemtime($f);
            $c = $counts[$sid] ?? 0;
            $short = ($c >= 1 && $c <= 2) || ($c === 0 && (int)@filesize($f) < 102400);
            $limit = $mt < $cutoff ? $cutoff : ($short && $mt < $shortCutoff ? $shortCutoff : null);
            if ($limit === null) {
                continue;
            }
            if (isset($protected[$sid]) || !is_readable($f)) { // unreadable: cannot check its title, so keep it
                $nProtected++;
                continue;
            }
            $items = ["projects/$slug/$sid.jsonl"];
            if (is_dir("$dir/$sid")) {
                if (newest("$dir/$sid") >= $limit) {
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
            $left--;
        }
        if ($left === 0 && is_dir("$dir/memory") && !isset($protSlugs[strtolower($slug)])) {
            $orphans[] = $slug;
        }
    }

    $label = "chráněno: $nProtected (pojmenovaná, úlohy, běžící)\n";
    if (!$moves && !$orphans) {
        echo "Nic k úklidu: žádné sezení starší než $days dní.\n" . $label;
    } else {
        uasort($plan, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
        $total = array_sum(array_column($plan, 'bytes'));
        echo ($dry ? 'Náhled — ' : '') . "sezení nepoužitá $days+ dní (krátká nepojmenovaná 1+ den):\n";
        foreach ($plan as $slug => $p) {
            printf("  %-50s %3d sezení %10s\n", $slug, $p['sessions'], orch_fmt_bytes($p['bytes']));
        }
        printf("Celkem %d sezení, %s.\n", array_sum(array_column($plan, 'sessions')), orch_fmt_bytes($total));
        foreach ($orphans as $o) {
            echo "osiřelá paměť: $o\n";
        }
        echo $label;
        if (!$dry) {
            $batch = "$trashRoot/" . date('Ymd-His', $now);
            $ok = 0;
            foreach ($moves as $rel) {
                @mkdir(dirname("$batch/$rel"), 0777, true);
                $ok += @rename("$home/$rel", "$batch/$rel") ? 1 : 0;
            }
            $total = count($moves);
            foreach ($orphans as $o) { // decided after the renames: only when the last session really left
                if (!glob("$home/projects/$o/*.jsonl") && is_dir("$home/projects/$o/memory")) {
                    @mkdir("$batch/projects/$o", 0777, true);
                    $total++;
                    $ok += @rename("$home/projects/$o/memory", "$batch/projects/$o/memory") ? 1 : 0;
                    @rmdir("$home/projects/$o"); // only succeeds when empty
                }
            }
            echo "Přesunuto $ok/" . $total . " položek do $batch (vrátíš: prune.php --restore-last).\n";
        }
    }

    if (!$dry) {
        $keepDays = max(1, $trashDays);
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

/** Moves one batch item back; existing targets are merged file by file without overwriting. */
function restore_item(string $batch, string $home, string $rel, array &$conflicts): int
{
    $from = "$batch/$rel";
    $to = "$home/$rel";
    if (!file_exists($to)) {
        @mkdir(dirname($to), 0777, true);
        return @rename($from, $to) ? 1 : 0;
    }
    if (!is_dir($from) || !is_dir($to)) {
        $conflicts[] = $rel;
        return 0;
    }
    $n = 0;
    foreach (scandir($from) ?: [] as $e) {
        if ($e !== '.' && $e !== '..') {
            $n += restore_item($batch, $home, "$rel/$e", $conflicts);
        }
    }
    return $n;
}

function remove_empty_dirs(string $dir): void
{
    foreach (scandir($dir) ?: [] as $e) {
        if ($e !== '.' && $e !== '..' && is_dir("$dir/$e") && !is_link("$dir/$e")) {
            remove_empty_dirs("$dir/$e");
        }
    }
    @rmdir($dir);
}
