<?php
declare(strict_types=1);
/**
 * PreToolUse(Bash|PowerShell) hook: denies destructive shell commands before they run.
 * Config (.claude/orch.json): {"guard": {"enabled": true, "block": ["regex"], "allow": ["regex"]}}
 *   allow wins over block. Regexes are PCRE without delimiters, case-insensitive.
 * Costs zero tokens unless it blocks (then Claude gets a one-line reason).
 */
require __DIR__ . '/common.php';

/** `git` plus optional global options (`-C <dir>`, `-c k=v`, quoted or not) before the subcommand. */
const ORCH_GIT = '(?:^|[\s;&|])git(?:\s+-[Cc]\s+(?:"[^"]*"|\'[^\']*\'|[^\s;&|]+))*\s+';
/** A pathspec meaning the whole tree: `.`, `./`, `:/`, `:/.`, `:(top)`, optionally quoted. */
const ORCH_GIT_ALL = '["\']?(?:\.|\./|:/|:/\.|:\(top\)\.?)["\']?(?=$|[\s;&|])';

const ORCH_GUARD_RULES = [
    // rm -r on root, home, cwd, parent or wildcard-everything
    '(?:^|[\s;&|(])rm\s+(?:-\S+\s+)*-[a-z]*r[a-z]*\s+(?:-\S+\s+)*(?:\S+\s+)*?(?:/|/\*|~|~/|~/\*|\*|\.|\./|\./\*|\.\.|\.\./?|\$HOME/?|"\$HOME"/?)(?=$|[\s;&|)])'
        => 'rm -r on /, ~, ., .. or *',
    ORCH_GIT . 'push\b(?=.*\s(?:--force(?!-with-lease)\b|-f\b|\+\S+))' => 'git force push (use --force-with-lease if really needed)',
    ORCH_GIT . 'reset\s+(?:\S+\s+)*--hard\b'          => 'git reset --hard discards uncommitted work',
    ORCH_GIT . 'clean\s+(?:\S+\s+)*-[a-z]*f'          => 'git clean -f deletes untracked files',
    ORCH_GIT . '(?:checkout|restore)\s+(?:--\s+)?' . ORCH_GIT_ALL => 'discards all uncommitted changes',
    ORCH_GIT . 'checkout\s+(?:-\S+\s+)*(?:HEAD|@)\s+(?:--\s+)?' . ORCH_GIT_ALL => 'discards all uncommitted changes',
    ORCH_GIT . 'restore\s+(?:[^\s;&|]+\s+)*?(?:--worktree|-[a-z]*W[a-z]*|--source(?:=|\s+)[^\s;&|]+)\s+(?:[^\s;&|]+\s+)*?' . ORCH_GIT_ALL => 'discards all uncommitted changes',
    ORCH_GIT . '(?:checkout|switch)\s+(?:[^\s;&|]+\s+)*?(?:-f|--force|--discard-changes)(?=$|[\s;&|])' => 'git checkout/switch --force discards uncommitted work',
    'Remove-Item\b(?=[^;&|]*-Recurse)(?=[^;&|]*\s(?:\*|\.[\\/]?|\.[\\/]\*)(?=$|[\s;"\']))' => 'Remove-Item -Recurse on the whole project',
    ORCH_GIT . 'stash\s+(?:drop|clear)\b'             => 'drops stashed work',
    '\b(?:drop\s+(?:table|database|schema)|truncate\s+(?:table\s+)?[`"\w.]+\s*(?:;|$|["\']))' => 'DROP/TRUNCATE SQL',
    '\bdelete\s+from\s+[`"\w.]+\s*(?:;|$|["\'])'              => 'DELETE without WHERE',
    '\bmkfs(?:\.\w+)?\b|\bdd\s+if=|\bformat\s+[a-z]:'         => 'disk formatting / raw disk write',
    'chmod\s+-R\s+0?777\b'                                    => 'chmod -R 777',
    ':\(\)\s*\{\s*:\s*\|\s*:\s*&\s*\}\s*;\s*:'                => 'fork bomb',
    '\b(?:curl|wget|iwr|Invoke-WebRequest)\b[^|]*\|\s*(?:sudo\s+)?(?:ba|z)?sh\b|\|\s*iex\b' => 'piping a download into a shell',
    'Remove-Item\b(?=.*-Recurse)(?=.*\s(?:[a-z]:\\\\?|~|\\\\|\$HOME|\$env:USERPROFILE)(?:$|[\s;"\']))' => 'Remove-Item -Recurse on a drive/home',
    '\b(?:rd|rmdir)\s+/s\b(?=.*\s(?:[a-z]:\\\\?|\\\\)(?:$|\s))|\bdel\s+(?:/\w\s+)*/s\b(?=.*\s[a-z]:\\\\?(?:$|\s))' => 'recursive delete of a drive',
    '(?:^\s*|[;&|(]\s*|\bsudo\s+)(?:shutdown|reboot|halt|poweroff)\b' => 'shutdown / reboot',
];

function orch_guard_check(string $cmd, array $cfg): ?string
{
    $g = $cfg['guard'] ?? [];
    if (empty($g['enabled'])) {
        return null;
    }
    foreach ((array)($g['allow'] ?? []) as $re) {
        $r = @preg_match('~' . str_replace('~', '\~', (string)$re) . '~i', $cmd);
        $r === false && $GLOBALS['orch_guard_bad'][(string)$re] = true;
        if ($r === 1) {
            return null;
        }
    }
    $rules = ORCH_GUARD_RULES;
    foreach ((array)($g['block'] ?? []) as $re) {
        $rules[(string)$re] = 'blocked by project guard rule';
    }
    foreach ($rules as $re => $why) {
        $r = @preg_match('~' . str_replace('~', '\~', (string)$re) . '~i', $cmd);
        $r === false && $GLOBALS['orch_guard_bad'][(string)$re] = true; // invalid user regex: reported, never fatal
        if ($r === 1) {
            return $why;
        }
    }
    return null;
}

// CLI test mode: php -n guard.php --check="<command>" → prints reason or "ok"
$opts = getopt('', ['check:', 'project:']);
if (isset($opts['check'])) {
    $project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir();
    $why = orch_guard_check((string)$opts['check'], orch_config($project));
    foreach (array_keys($GLOBALS['orch_guard_bad'] ?? []) as $bad) {
        fwrite(STDERR, "orch guard: invalid regex in .claude/orch.json, ignored: $bad\n");
    }
    echo $why === null ? "ok\n" : "BLOCK: $why\n";
    exit($why === null ? 0 : 1);
}

try {
    $in = orch_read_input();
    $cmd = $in['tool_input']['command'] ?? null;
    if (!is_string($cmd) || $cmd === '') {
        exit(0);
    }
    $project = orch_project_dir($in);
    $why = orch_guard_check($cmd, orch_config($project));
    orch_guard_report_bad(orch_state_dir($project));
    if ($why !== null) {
        orch_append_jsonl(orch_state_dir($project) . '/guard.log', [
            'ts' => date('c'), 'session' => $in['session_id'] ?? null, 'agent' => $in['agent_type'] ?? 'main',
            'reason' => $why, 'command' => orch_cut($cmd, 300, '…'),
        ]);
        echo json_encode(['hookSpecificOutput' => [
            'hookEventName'            => 'PreToolUse',
            'permissionDecision'       => 'deny',
            'permissionDecisionReason' => "orch guard: $why. Use a safer alternative or ask the user to run it.",
        ]], ORCH_JSON | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
} catch (Throwable) {
}
exit(0);

/** Logs each invalid user regex once (state/guard-bad.json remembers which) so a typo does not silently disable a rule. */
function orch_guard_report_bad(string $state): void
{
    $bad = array_keys($GLOBALS['orch_guard_bad'] ?? []);
    if (!$bad) {
        return;
    }
    $file = "$state/guard-bad.json";
    $known = json_decode((string)@file_get_contents($file), true);
    $known = is_array($known) ? $known : [];
    $new = array_diff($bad, $known);
    foreach ($new as $re) {
        orch_append_jsonl("$state/guard.log", ['ts' => date('c'), 'reason' => 'invalid guard regex in orch.json (ignored)', 'regex' => orch_cut($re, 200, '…')]);
    }
    if ($new) {
        @file_put_contents($file, json_encode(array_values(array_unique(array_merge($known, $bad))), ORCH_JSON));
    }
}
