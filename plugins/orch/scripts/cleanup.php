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

$opts = getopt('', ['manual', 'dry-run', 'all', 'project:']);
$manual = isset($opts['manual']);
$dryRun = isset($opts['dry-run']);
$all = isset($opts['all']) || $manual; // manual run has no session → consider everything

try {
    $in = $manual ? [] : orch_read_input();
    $project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir($in);
    $cfg = orch_config($project);
    $state = orch_state_dir($project);
    $session = orch_session_id($in);
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
        [$code, $lines] = orch_git($project, 'ls-files --others --exclude-standard');
        if ($code === 0) {
            foreach ($lines as $rel) {
                $rel = orch_norm($rel);
                if (orch_matches($rel, $cfg['patterns']) && @filemtime("$project/$rel") >= $since) {
                    $candidates[$rel] ??= 'untracked';
                }
            }
        }
    }

    // --- filter ---
    $toMove = [];
    foreach ($candidates as $rel => $agent) {
        if (!is_file("$project/$rel")
            || !orch_matches($rel, $cfg['patterns'])
            || orch_is_protected($rel, $cfg['protectedDirs'])) {
            continue;
        }
        if ($isGit && orch_git($project, 'ls-files --error-unmatch -- ' . escapeshellarg($rel))[0] === 0) {
            continue; // tracked by git → never touch
        }
        $toMove[$rel] = $agent;
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
    if (!$dryRun) {
        $limit = time() - (int)$cfg['trashDays'] * 86400;
        foreach (glob("$state/trash/*", GLOB_ONLYDIR) ?: [] as $dir) {
            if (@filemtime($dir) < $limit) {
                orch_rmdir($dir);
                $purged++;
            }
        }
        // session start markers older than trash retention are useless
        foreach (glob("$state/sessions/*.start") ?: [] as $f) {
            if (@filemtime($f) < $limit) {
                @unlink($f);
            }
        }
    }

    if ($manual) {
        if (!$toMove) {
            echo "Nic k úklidu.\n";
        } else {
            echo ($dryRun ? "Přesunul bych do koše" : "Přesunuto do koše") . " (" . count($toMove) . "):\n";
            foreach ($toMove as $rel => $agent) {
                echo "  $rel  [$agent]\n";
            }
        }
        if ($purged) {
            echo "Vysypáno starých košů: $purged\n";
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
