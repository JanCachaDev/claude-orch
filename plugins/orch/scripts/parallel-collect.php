<?php
declare(strict_types=1);
/**
 * Collects the work of parallel workers that ran in git worktrees (/orch:parallel) into the main working tree.
 *
 *   php -n parallel-collect.php --base=<sha> --worktrees=P1=<path>,P2=<path> [--project=<dir>]
 *
 * For every worktree, in order: stage everything there, write the diff against <base> to .claude/orch/p<n>.patch,
 * try it on a temporary copy of the index (`git apply --cached --3way`), and only when that works apply it for real
 * (`git apply --3way`: working tree + index). A successfully applied worktree is removed together with its
 * worktree-* branch. A failing one leaves the main tree untouched and keeps patch and worktree for a manual look.
 * A patch touching a file that already had uncommitted changes in the main tree fails before any apply.
 * Output, one line per worktree: `P1 applied (3 files)`, `P1 applied (no changes)` or `P1 failed: <reason>; ...`.
 * Exit code 1 when any worktree failed.
 */
require __DIR__ . '/common.php';

$opts = getopt('', ['base:', 'worktrees:', 'project:']);
$project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir();
$base = (string)($opts['base'] ?? '');
$wts = [];
foreach (explode(',', (string)($opts['worktrees'] ?? '')) as $pair) {
    if (preg_match('/^([A-Za-z0-9_-]+)=(.+)$/', trim($pair), $m)) {
        $wts[$m[1]] = $m[2];
    }
}
if ($base === '' || !$wts) {
    fwrite(STDERR, "usage: parallel-collect.php --base=<sha> --worktrees=P1=<path>,P2=<path> [--project=<dir>]\n");
    exit(2);
}
if (orch_git($project, ['rev-parse', '--verify', '--quiet', $base . '^{commit}'])[0] !== 0) {
    fwrite(STDERR, "unknown base commit: $base\n");
    exit(2);
}

// registered worktrees: path => branch (or '')
$registered = [];
$cur = null;
foreach (orch_git($project, ['worktree', 'list', '--porcelain'])[1] as $line) {
    if (str_starts_with($line, 'worktree ')) {
        $cur = strtolower(orch_norm((string)(realpath(substr($line, 9)) ?: substr($line, 9))));
        $registered[$cur] = '';
    } elseif ($cur !== null && str_starts_with($line, 'branch refs/heads/')) {
        $registered[$cur] = substr($line, 18);
    }
}

$state = orch_state_dir($project);
$cfg = orch_config($project);
orch_lang_init($project, (string)getenv('CLAUDE_SESSION_ID'), $cfg);
$keepDays = max(1, (int)$cfg['trashDays']);
// files the user had already changed in the main tree before collecting (not the ones applied below)
[, $dirtyRaw] = orch_git_raw($project, ['-c', 'core.quotepath=false', 'status', '--porcelain', '-z', '-uall']);
$dirty = [];
$fields = explode("\0", rtrim($dirtyRaw, "\0"));
for ($i = 0, $n = count($fields); $i < $n; $i++) {
    if (strlen($fields[$i]) >= 4) {
        $dirty[substr($fields[$i], 3)] = true;
        strpbrk(substr($fields[$i], 0, 2), 'RC') !== false && $i++; // rename/copy: skip the original name
    }
}
$failed = 0;
foreach ($wts as $name => $path) {
    $key = strtolower(orch_norm((string)(realpath($path) ?: $path)));
    $res = orch_collect_one($project, $state, $name, $path, $key, $registered, $base, $dirty, $keepDays);
    $res[0] || $failed++;
    echo $res[1], "\n";
}
exit($failed ? 1 : 0);

/** @return array{0:bool,1:string} [ok, report line] */
function orch_collect_one(string $project, string $state, string $name, string $path, string $key, array $registered, string $base,
    array $dirty = [], int $keepDays = 7): array
{
    $patch = "$state/" . strtolower($name) . '.patch';
    $rel = '.claude/orch/' . basename($patch);
    // cleanup's state hygiene deletes p<n>.patch files older than trashDays; the worktree itself is never removed by it
    $kept = preg_match('/^p[0-9]/', basename($patch))
        ? orch_t('patch ponechán: %s (úklid ho smaže po %d dnech), worktree ponechán: %s', 'patch kept: %s (cleanup deletes it after %d days), worktree kept: %s', $rel, $keepDays, orch_norm($path))
        : orch_t('patch ponechán: %s, worktree ponechán: %s', 'patch kept: %s, worktree kept: %s', $rel, orch_norm($path));
    $fail = fn(string $why) => [false, "$name failed: $why; $kept"];
    if (!isset($registered[$key]) || $key === strtolower(orch_norm((string)(realpath($project) ?: $project)))) {
        return [false, "$name failed: $path is not a registered worktree of this repository"];
    }
    [$c, $o] = orch_git_raw($path, ['add', '-A']);
    if ($c !== 0) {
        return $fail('git add failed in the worktree (' . trim($o) . ')');
    }
    @unlink($patch);
    [$c, $o] = orch_git_raw($path, ['diff', '--cached', '--binary', '--no-color', "--output=$patch", $base]);
    if ($c !== 0) {
        return $fail('git diff failed (' . trim($o) . ')');
    }
    [, $names] = orch_git_raw($path, ['diff', '--cached', '--name-only', '-z', $base]);
    $files = array_values(array_filter(explode("\0", $names), 'strlen'));

    if ((int)@filesize($patch) === 0) {
        @unlink($patch);
        orch_collect_remove($project, $path, $registered[$key]);
        return [true, "$name applied (no changes)"];
    }

    $clash = array_values(array_filter($files, fn($f) => isset($dirty[$f])));
    if ($clash) {
        $list = implode(', ', array_slice($clash, 0, 10)) . (count($clash) > 10 ? ', …' : '');
        return $fail(orch_t('v hlavním stromu jsou necommitnuté změny v souborech, které patch mění; nejdřív je commitni nebo stashni: %s',
            'the main tree has uncommitted changes in files the patch touches; commit or stash them first: %s', $list));
    }

    // trial on a copy of the real index: nothing in the main tree changes unless the patch applies cleanly
    $idx = trim((string)(orch_git($project, ['rev-parse', '--absolute-git-dir'])[1][0] ?? '')) . '/index';
    $tmpIdx = (string)tempnam(sys_get_temp_dir(), 'orchidx');
    is_file($idx) ? @copy($idx, $tmpIdx) : null;
    [$c, $o] = orch_git_raw($project, ['apply', '--cached', '--3way', $patch], ['GIT_INDEX_FILE' => $tmpIdx]);
    @unlink($tmpIdx);
    if ($c !== 0) {
        return $fail('does not apply cleanly: ' . orch_cut(trim(str_replace("\n", ' / ', $o)), 300, '…'));
    }
    [$c, $o] = orch_git_raw($project, ['apply', '--3way', $patch]);
    if ($c !== 0) {
        return $fail('apply failed: ' . orch_cut(trim(str_replace("\n", ' / ', $o)), 300, '…'));
    }
    @unlink($patch);
    orch_collect_remove($project, $path, $registered[$key]);
    return [true, sprintf('%s applied (%d file%s)', $name, count($files), count($files) === 1 ? '' : 's')];
}

function orch_collect_remove(string $project, string $path, string $branch): void
{
    orch_git_raw($project, ['worktree', 'remove', '--force', $path]);
    if (str_starts_with($branch, 'worktree-')) { // only the branches Claude Code made for its worktrees
        orch_git_raw($project, ['branch', '-D', $branch]);
    }
    orch_git_raw($project, ['worktree', 'prune']);
}
