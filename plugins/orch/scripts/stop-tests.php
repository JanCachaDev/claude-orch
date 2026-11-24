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
    if (orch_is_git($project)) {
        [$code, $status] = orch_git($project, 'status --porcelain');
        if ($code === 0 && !$status) {
            exit(0); // nothing changed → skip tests
        }
    }

    chdir($project);
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $tail = array_slice($out, -max(1, (int)$cfg['testTailLines']));
        fwrite(STDERR, "Tests failed (`$cmd`, exit $code). Fix them before finishing. Last lines:\n" . implode("\n", $tail) . "\n");
        exit(2);
    }
} catch (Throwable) {
}
exit(0);
