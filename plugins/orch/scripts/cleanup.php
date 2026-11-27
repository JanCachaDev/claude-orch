<?php
declare(strict_types=1);
/**
 * Moves temporary files left by agents into .claude/orch/trash/<timestamp>/ (never deletes directly)
 * and purges trash older than `trashDays`.
 *
 * A file is moved only when ALL hold:
 *   - its basename matches a temp pattern from config,
 *   - it is inside the project and not in a protected dir,
 *   - it is not tracked by git.
 * Candidates: files written via Write in this session (files.log) + untracked git files matching
 * patterns modified since the session started (catches files created via Bash).
 *
 * Hook mode (SessionEnd): reads hook JSON from stdin.
 * Manual mode: php -n cleanup.php --manual [--dry-run] [--project=<dir>]
 *   considers every logged session and all untracked matching files regardless of age
 */
require __DIR__ . '/common.php';

$opts = getopt('', ['manual', 'dry-run', 'all', 'project:', 'session:']);
$manual = isset($opts['manual']);
$dryRun = isset($opts['dry-run']);
$all = isset($opts['all']) || $manual; // manual run has no session → consider everything

try {
    $in = $manual ? [] : orch_read_input();
    $project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir($in);
    $cfg = orch_config($project);
    $state = orch_state_dir($project);
    $session = orch_session_id($in);
    orch_lang_init($project, $session !== '' ? $session : (string)($opts['session'] ?? ''), $cfg);
    $isGit = orch_is_git($project);

    // --- collect candidates ---
    $candidates = [];
    foreach (orch_read_jsonl("$state/files.log") as $row) {
        if ($all || ($session !== '' && ($row['session'] ?? '') === $session)) {
            $candidates[(string)$row['file']] = (string)($row['agent'] ?? 'main');
        }
    }

    if ($isGit && $cfg['scanUntracked']) {
        $since = 0;
        if (!$all) {
            $startFile = "$state/sessions/$session.start";
            // without a known session start, do not sweep untracked files at all
            $since = ($session !== '' && is_file($startFile)) ? (int)file_get_contents($startFile) : PHP_INT_MAX;
        }
        [$code, $raw] = orch_git_raw($project, ['--literal-pathspecs', 'ls-files', '--others', '--exclude-standard', '-z']);
        if ($code === 0) {
            foreach (explode("\0", $raw) as $rel) {
                $rel = orch_norm($rel);
                if ($rel === '') {
                    continue;
                }
                if (orch_matches($rel, $cfg['patterns']) && @filemtime("$project/$rel") >= $since) {
                    $candidates[$rel] ??= 'untracked';
                }
            }
        }
    }

    // --- filter ---
    $toMove = [];
    foreach ($candidates as $rel => $agent) {
        if (is_file("$project/$rel") && orch_matches($rel, $cfg['patterns']) && !orch_is_protected($rel, $cfg['protectedDirs'])) {
            $toMove[$rel] = $agent;
        }
    }
    if ($isGit && $toMove) {
        // one git call per chunk instead of one per file; a failed call keeps everything in place
        $tracked = [];
        foreach (array_chunk(array_keys($toMove), 100) as $chunk) {
            [$code, $raw] = orch_git_raw($project, array_merge(['--literal-pathspecs', 'ls-files', '-z', '--'], $chunk));
            if ($code !== 0) {
                $tracked = array_fill_keys(array_map('strtolower', array_keys($toMove)), true);
                break;
            }
            foreach (explode("\0", $raw) as $t) {
                $t !== '' && $tracked[strtolower(orch_norm($t))] = true;
            }
        }
        foreach (array_keys($toMove) as $rel) {
            if (isset($tracked[strtolower((string)$rel)])) {
                unset($toMove[$rel]); // tracked by git → never touch
            }
        }
    }

    // --- move to trash ---
    $moved = [];
    if ($toMove && !$dryRun) {
        $trash = "$state/trash/" . date('Y-m-d_His') . ($session !== '' ? '_' . substr($session, 0, 8) : '');
        foreach ($toMove as $rel => $agent) {
            $dest = "$trash/$rel";
            @mkdir(dirname($dest), 0777, true);
            if (@rename("$project/$rel", $dest)) {
                $moved[] = ['file' => $rel, 'agent' => $agent, 'bytes' => (int)@filesize($dest)];
            }
        }
        if ($moved) {
            orch_append_jsonl("$state/cleanup.log", [
                'ts' => date('c'), 'session' => $session ?: null, 'trash' => basename($trash), 'moved' => $moved,
            ]);
        }
    }

    // --- purge old trash ---
    $purged = 0;
    $hygiene = [];
    if (!$dryRun) {
        $limit = time() - (int)$cfg['trashDays'] * 86400;
        foreach (glob("$state/trash/*", GLOB_ONLYDIR) ?: [] as $dir) {
            if (@filemtime($dir) < $limit) {
                orch_rmdir($dir);
                $purged++;
            }
        }
        $hygiene = orch_state_hygiene($project, $state, $limit, $manual);
    }

    if ($manual) {
        if (!$toMove) {
            echo orch_t("Nic k úklidu.
", "Nothing to clean up.
");
        } else {
            echo ($dryRun ? orch_t('Přesunul bych do koše', 'Would move to trash') : orch_t('Přesunuto do koše', 'Moved to trash')) . " (" . count($toMove) . "):\n";
            foreach ($toMove as $rel => $agent) {
                echo "  $rel  [$agent]\n";
            }
        }
        if ($purged) {
            echo orch_t("Vysypáno starých košů: $purged
", "Old trash batches purged: $purged
");
        }
        foreach ($hygiene as $line) {
            echo $line, "\n";
        }
    }
} catch (Throwable $e) {
    if ($manual) {
        fwrite(STDERR, 'orch cleanup error: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
exit(0);

function orch_rmdir(string $dir): void
{
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $p = "$dir/$item";
        is_dir($p) && !is_link($p) ? orch_rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}


/**
 * Keeps .claude/orch small: drops stale session markers, plan and patch files, old files.log rows, caps the other
 * logs, removes an oversized usage cache and prunes dead git worktree records. Never touches transcripts or worktrees
 * (leftover .claude/worktrees/* older than a day are only listed, in manual mode).
 * @return list<string> notes for the manual report
 */
function orch_state_hygiene(string $project, string $state, int $olderThan, bool $manual): array
{
    $notes = [];
    foreach (['start', 'turn', 'vercheck', 'ctxband', 'ctxcold', 'lang'] as $ext) {
        foreach (glob("$state/sessions/*.$ext") ?: [] as $f) {
            @filemtime($f) < $olderThan && @unlink($f);
        }
    }
    foreach (array_merge(glob("$state/plan-*.md") ?: [], glob("$state/p[0-9]*.patch") ?: []) as $f) {
        @filemtime($f) < $olderThan && @unlink($f);
    }
    // files.log: rows older than the retention are no longer cleanup candidates
    $log = "$state/files.log";
    if (is_file($log)) {
        $rows = orch_read_jsonl($log);
        $keep = array_values(array_filter($rows, fn($r) => (int)strtotime((string)($r['ts'] ?? '')) >= $olderThan));
        if (count($keep) < count($rows)) {
            @file_put_contents($log, $keep ? implode("\n", array_map(fn($r) => json_encode($r, ORCH_JSON), $keep)) . "\n" : '', LOCK_EX);
        }
    }
    foreach (['guard', 'notify', 'cleanup', 'runs'] as $name) {
        orch_cap_log("$state/$name.log");
    }
    $cache = "$state/usage-cache.json";
    if ((int)@filesize($cache) > 1048576) {
        @unlink($cache);
    }
    if (is_dir("$project/.git") || is_file("$project/.git")) {
        orch_git($project, ['worktree', 'prune']);
    }
    if ($manual) {
        foreach (glob("$project/.claude/worktrees/*", GLOB_ONLYDIR) ?: [] as $wt) {
            if (@filemtime($wt) < time() - 86400) {
                $notes[] = orch_t('Starý worktree (smaž ručně: git worktree remove --force): ', 'Old worktree (remove it manually: git worktree remove --force): ') . orch_norm($wt);
            }
        }
    }
    return $notes;
}