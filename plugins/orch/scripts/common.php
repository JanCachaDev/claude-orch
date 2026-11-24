<?php
declare(strict_types=1);

/**
 * Shared helpers for orch hook scripts.
 * Run with `php -n` (no php.ini → no Xdebug), so only core PHP is available.
 * Every function fails soft: a hook must never break a session.
 */

const ORCH_DEFAULTS = [
    // basename patterns (fnmatch, case-insensitive) that count as temporary
    'patterns'      => ['tmp_*', 'temp_*', '*.tmp', 'scratch*', 'debug_*', '*.bak', '*.orig', 'test_output*'],
    // directories never touched by cleanup (relative to project root)
    'protectedDirs' => ['.git', '.claude', '.idea', 'vendor', 'node_modules'],
    // also sweep untracked git files matching patterns (catches files created via Bash, not Write)
    'scanUntracked' => true,
    // days to keep trash before it is purged
    'trashDays'     => 7,
    // shell command run on Stop when the working tree changed; null = disabled
    'testCommand'   => null,
    'testTailLines' => 30,
];

function orch_read_input(): array
{
    $raw = stream_get_contents(STDIN);
    $data = json_decode($raw === false ? '' : $raw, true);
    return is_array($data) ? $data : [];
}

function orch_norm(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function orch_project_dir(array $input = []): string
{
    $dir = getenv('CLAUDE_PROJECT_DIR') ?: ($input['cwd'] ?? getcwd());
    return orch_norm((string)$dir);
}

function orch_state_dir(string $project): string
{
    $dir = "$project/.claude/orch";
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_file("$dir/.gitignore")) {
        @file_put_contents("$dir/.gitignore", "*\n");
    }
    return $dir;
}

function orch_config(string $project): array
{
    $cfg = ORCH_DEFAULTS;
    $file = "$project/.claude/orch.json";
    if (is_file($file)) {
        $user = json_decode((string)file_get_contents($file), true);
        if (is_array($user)) {
            $cfg = array_merge($cfg, $user);
        }
    }
    return $cfg;
}

/** Absolute or relative path -> path relative to project, or null when outside the project. */
function orch_relative(string $path, string $project): ?string
{
    $path = orch_norm($path);
    if (!preg_match('~^([a-zA-Z]:)?/~', $path)) {
        $path = "$project/$path";
    }
    $prefix = $project . '/';
    if (stripos($path, $prefix) !== 0) {
        return null;
    }
    $rel = substr($path, strlen($prefix));
    return ($rel === '' || str_contains("/$rel/", '/../')) ? null : $rel;
}

function orch_append_jsonl(string $file, array $row): void
{
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

function orch_read_jsonl(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/** Runs git in the project; returns [exitCode, outputLines]. */
function orch_git(string $project, string $args): array
{
    $out = [];
    $code = 1;
    @exec('git -C ' . escapeshellarg($project) . ' ' . $args . ' 2>&1', $out, $code);
    return [$code, $out];
}

function orch_is_git(string $project): bool
{
    return orch_git($project, 'rev-parse --is-inside-work-tree')[0] === 0;
}

function orch_matches(string $rel, array $patterns): bool
{
    $base = basename($rel);
    foreach ($patterns as $p) {
        if (fnmatch((string)$p, $base, FNM_CASEFOLD)) {
            return true;
        }
    }
    return false;
}

function orch_is_protected(string $rel, array $dirs): bool
{
    foreach ($dirs as $d) {
        $d = trim(orch_norm((string)$d), '/');
        if ($d !== '' && (strcasecmp($rel, $d) === 0 || stripos($rel, "$d/") === 0)) {
            return true;
        }
    }
    return false;
}

function orch_session_id(array $input): string
{
    return preg_replace('/[^A-Za-z0-9_-]/', '', (string)($input['session_id'] ?? '')) ?? '';
}
