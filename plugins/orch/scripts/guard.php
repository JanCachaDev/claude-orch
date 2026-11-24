<?php
declare(strict_types=1);
/**
 * PreToolUse(Bash|PowerShell) hook: denies destructive shell commands before they run.
 * Config (.claude/orch.json): {"guard": {"enabled": true, "block": ["regex"], "allow": ["regex"]}}
 *   allow wins over block. Regexes are PCRE without delimiters, case-insensitive.
 * Costs zero tokens unless it blocks (then Claude gets a one-line reason).
 */
require __DIR__ . '/common.php';

const ORCH_GUARD_RULES = [
    // rm -r on root, home, cwd, parent or wildcard-everything
    '(?:^|[\s;&|(])rm\s+(?:-\S+\s+)*-[a-z]*r[a-z]*\s+(?:-\S+\s+)*(?:\S+\s+)*?(?:/|/\*|~|~/|~/\*|\*|\.|\./|\./\*|\.\.|\.\./?|\$HOME/?|"\$HOME"/?)(?=$|[\s;&|)])'
        => 'rm -r on /, ~, ., .. or *',
    '(?:^|[\s;&|])git\s+push\b(?=.*\s(?:--force(?!-with-lease)\b|-f\b|\+\S+))' => 'git force push (use --force-with-lease if really needed)',
    '(?:^|[\s;&|])git\s+reset\s+(?:\S+\s+)*--hard\b'          => 'git reset --hard discards uncommitted work',
    '(?:^|[\s;&|])git\s+clean\s+(?:\S+\s+)*-[a-z]*f'          => 'git clean -f deletes untracked files',
    '(?:^|[\s;&|])git\s+(?:checkout|restore)\s+(?:--\s+)?\.(?=$|[\s;&|])' => 'discards all uncommitted changes',
    '(?:^|[\s;&|])git\s+stash\s+(?:drop|clear)\b'             => 'drops stashed work',
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
        if (@preg_match('~' . str_replace('~', '\~', (string)$re) . '~i', $cmd) === 1) {
            return null;
        }
    }
    $rules = ORCH_GUARD_RULES;
    foreach ((array)($g['block'] ?? []) as $re) {
        $rules[(string)$re] = 'blocked by project guard rule';
    }
    foreach ($rules as $re => $why) {
        if (@preg_match('~' . str_replace('~', '\~', (string)$re) . '~i', $cmd) === 1) {
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
    if ($why !== null) {
        orch_append_jsonl(orch_state_dir($project) . '/guard.log', [
            'ts' => date('c'), 'session' => $in['session_id'] ?? null, 'agent' => $in['agent_type'] ?? 'main',
            'reason' => $why, 'command' => mb_substr_safe($cmd, 300),
        ]);
        echo json_encode(['hookSpecificOutput' => [
            'hookEventName'            => 'PreToolUse',
            'permissionDecision'       => 'deny',
            'permissionDecisionReason' => "orch guard: $why. Use a safer alternative or ask the user to run it.",
        ]], JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable) {
}
exit(0);

function mb_substr_safe(string $s, int $n): string
{
    return strlen($s) <= $n ? $s : substr($s, 0, $n) . '…';
}
