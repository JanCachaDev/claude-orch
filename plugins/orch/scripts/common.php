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
    // PreToolUse guard against destructive shell commands
    'guard'         => ['enabled' => true, 'block' => [], 'allow' => []],
    // notifications (Discord / WhatsApp credentials live in plugin userConfig, not here)
    'notify'        => true,
    // USD limits for this project per day / ISO week; null = off (or plugin userConfig budget_*_usd)
    'budget'        => ['day' => null, 'week' => null],
    // USD per 1M tokens overrides: {"model-prefix": [input, output, cacheRead]}
    'prices'        => [],
];

/**
 * USD per 1M tokens: [input, output, cache read]. Cache writes are billed at 1.25× (5 min) / 2× (1 h) input.
 * Matched by longest model-id prefix. Source: Anthropic API price list (2026-11). Override via `prices` in orch.json.
 */
const ORCH_PRICES = [
    'claude-fable-5-2'  => [8.0, 40.0, 0.20],
    'claude-mythos-5-2' => [8.0, 40.0, 0.20],
    'claude-fable-5'    => [10.0, 50.0, 1.00],
    'claude-mythos-5'   => [10.0, 50.0, 1.00],
    'claude-opus-5-5'   => [4.0, 20.0, 0.20],
    'claude-opus-5'     => [5.0, 25.0, 0.50],
    'claude-opus-4'     => [5.0, 25.0, 0.50],
    'claude-sonnet-5-5' => [2.0, 10.0, 0.10],
    'claude-sonnet-5'   => [2.0, 10.0, 0.20],
    'claude-sonnet-4'   => [3.0, 15.0, 0.30],
    'claude-haiku-5-5'  => [0.10, 0.50, 0.01],
    'claude-haiku-4'    => [1.0, 5.0, 0.10],
];

/** @return array{0:float,1:float,2:float}|null */
function orch_price(string $model, array $overrides = []): ?array
{
    $table = array_merge(ORCH_PRICES, $overrides);
    $best = null;
    $bestLen = 0;
    foreach ($table as $prefix => $p) {
        if (str_starts_with($model, (string)$prefix) && strlen((string)$prefix) > $bestLen) {
            $best = array_map('floatval', array_values($p));
            $bestLen = strlen((string)$prefix);
        }
    }
    return $best;
}

/** Usage row (in, cw5, cw1h, cread, out) → USD, or null when the model has no price. */
function orch_cost(string $model, array $u, array $overrides = []): ?float
{
    $p = orch_price($model, $overrides);
    if ($p === null) {
        return null;
    }
    [$in, $out, $read] = $p;
    return (($u['in'] ?? 0) * $in
          + ($u['cw5'] ?? 0) * $in * 1.25
          + ($u['cw1h'] ?? 0) * $in * 2.0
          + ($u['cread'] ?? 0) * $read
          + ($u['out'] ?? 0) * $out) / 1_000_000;
}

/** Plugin userConfig value (exported to hooks as CLAUDE_PLUGIN_OPTION_<KEY>). */
function orch_option(string $key, ?string $default = null): ?string
{
    $v = getenv('CLAUDE_PLUGIN_OPTION_' . strtoupper($key));
    return ($v === false || $v === '') ? $default : $v;
}

/** Quote a value for a curl config file ("..." with \" and \\ escapes). */
function orch_curl_quote(string $v): string
{
    return '"' . strtr($v, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) . '"';
}

/**
 * Runs curl with options passed via stdin (`--config -`) so secrets never appear in the process list.
 * @param array<int, array{0:string,1:?string}> $options list of [option, value|null]
 * @return array{0:int,1:string} [exit code, output]
 */
function orch_curl(array $options, int $timeout = 8): array
{
    $cfg = "silent\nshow-error\nmax-time = $timeout\n";
    foreach ($options as [$opt, $val]) {
        $cfg .= $val === null ? "$opt\n" : "$opt = " . orch_curl_quote($val) . "\n";
    }
    $outbox = getenv('ORCH_NOTIFY_OUTBOX'); // test mode: write config instead of sending
    if ($outbox) {
        @file_put_contents($outbox, $cfg . "---\n", FILE_APPEND);
        return [0, ''];
    }
    $proc = @proc_open(['curl', '--config', '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return [127, 'curl not found'];
    }
    fwrite($pipes[0], $cfg);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), (string)$out];
}

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
            $cfg['guard'] = array_merge(ORCH_DEFAULTS['guard'], is_array($user['guard'] ?? null) ? $user['guard'] : []);
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
