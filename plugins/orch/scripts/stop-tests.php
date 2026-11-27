<?php
declare(strict_types=1);
/**
 * Stop hook (opt-in): when `testCommand` is set in .claude/orch.json and the working tree has changes,
 * runs the tests. On failure, sends only the last `testTailLines` lines to Claude (exit 2 = keep working).
 * Costs zero tokens when tests pass.
 */
require __DIR__ . '/common.php';

try {
    $in = orch_read_input();
    if (!empty($in['stop_hook_active'])) {
        exit(0); // already continuing because of us → don't loop
    }
    $project = orch_project_dir($in);
    $cfg = orch_config($project);
    $cmd = $cfg['testCommand'];
    if (!is_string($cmd) || trim($cmd) === '') {
        exit(0);
    }
    $state = orch_state_dir($project);
    $fpFile = "$state/tests.json";
    $fp = null;
    // NUL-separated, unquoted paths: non-ASCII names are stat()-able as they are
    [$gcode, $status] = orch_git_raw($project, ['-c', 'core.quotepath=false', 'status', '--porcelain', '-z', '-uall']); // non-zero = not a git repo → always run
    if ($gcode === 0) {
        if ($status === '') {
            exit(0); // nothing changed → skip tests
        }
        // same tree as the last green run → the result would be the same, don't burn the time again
        $fp = orch_tree_fingerprint($project, $status);
        $last = json_decode((string)@file_get_contents($fpFile), true);
        if (is_array($last) && ($last['fp'] ?? '') === $fp) {
            exit(0);
        }
    }

    chdir($project);
    $out = [];
    $code = 0;
    exec(orch_win_command($cmd) . ' 2>&1', $out, $code);
    if ($fp !== null && $code === 0) {
        @file_put_contents($fpFile, json_encode(['fp' => $fp, 'code' => $code, 'ts' => time()]));
    } else {
        @unlink($fpFile); // red (or no fingerprint): the next Stop must run the tests again, never hide a failure
    }
    if ($code !== 0) {
        $tail = array_slice($out, -max(1, (int)$cfg['testTailLines']));
        fwrite(STDERR, "Tests failed (`$cmd`, exit $code). Fix them before finishing. Last lines:\n" . implode("\n", $tail) . "\n");
        exit(2);
    }
} catch (Throwable) {
}
exit(0);


/** Hash of what the working tree looks like: status + diff stat + size and mtime of every changed path. */
function orch_tree_fingerprint(string $project, string $status): string
{
    [, $stat] = orch_git_raw($project, ['-c', 'core.quotepath=false', 'diff', '--stat', 'HEAD']);
    $parts = [$status, $stat];
    foreach (array_slice(orch_status_paths($status), 0, 500) as $path) {
        $st = @stat("$project/$path");
        $parts[] = $st ? $st['size'] . ':' . $st['mtime'] : 'gone';
    }
    return sha1(implode("\n", $parts));
}

/**
 * Paths from `git status --porcelain -z`: entries are "XY path\0"; a rename/copy entry is followed by
 * one more NUL-terminated field with the original name, which is skipped (the new name is what exists on disk).
 * @return list<string>
 */
function orch_status_paths(string $status): array
{
    $paths = [];
    $fields = explode("\0", rtrim($status, "\0"));
    for ($i = 0, $n = count($fields); $i < $n; $i++) {
        $f = $fields[$i];
        if (strlen($f) < 4) {
            continue;
        }
        $paths[] = substr($f, 3);
        if (strpbrk(substr($f, 0, 2), 'RC') !== false) {
            $i++;
        }
    }
    return $paths;
}
