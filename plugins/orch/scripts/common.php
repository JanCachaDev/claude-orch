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
    // SessionStart hint pointing to the project's named sessions
    'sessionHint'   => true,
    // warn on a prompt when the main session context is at least this many tokens (false = off)
    'contextWarn'   => 150000,
    // also block the first prompt sent to a cold (cache expired) large session until /compact
    'contextBlock'  => false,
    // IANA timezone for "today" / "this week" and log timestamps (php -n runs in UTC); the TZ env var wins
    'timezone'      => null,
    // language of orch's own messages: "auto" (detected from your prompts, Czech or English) | "cs" | "en"
    'lang'          => 'auto',
    // model / effort pins per agent and team caps, read by the /orch:* skills through config.php
    'models'        => [],
    'efforts'       => [],
    'team'          => ['planners' => 2, 'workers' => 4, 'reviewers' => 3],
];

/** json_encode flags for everything orch writes: readable UTF-8, never fails on a bad byte. */
const ORCH_JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

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
        $user = json_decode(orch_strip_bom((string)@file_get_contents($file)), true);
        if (is_array($user)) {
            $cfg = array_merge($cfg, $user);
            $cfg['guard'] = array_merge(ORCH_DEFAULTS['guard'], is_array($user['guard'] ?? null) ? $user['guard'] : []);
            foreach (['models', 'efforts', 'team'] as $k) { // a wrong type must not break the skills that read these
                $cfg[$k] = is_array($user[$k] ?? null) ? array_merge(ORCH_DEFAULTS[$k], $user[$k]) : ORCH_DEFAULTS[$k];
            }
        }
    }
    orch_tz(is_string($cfg['timezone'] ?? null) ? $cfg['timezone'] : null);
    return $cfg;
}

function orch_strip_bom(string $s): string
{
    return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
}

/** Why .claude/orch.json is ignored ("Syntax error", "not a JSON object", …), or null when it is absent or fine. */
function orch_config_error(string $project): ?string
{
    $file = "$project/.claude/orch.json";
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode(orch_strip_bom((string)@file_get_contents($file)), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return json_last_error_msg();
    }
    return is_array($data) ? null : 'not a JSON object';
}

/**
 * Sets the default timezone. `php -n` has no php.ini, so PHP would use UTC and "today" / "Monday" would start
 * at 01:00 or 02:00 local time. Order: TZ env, the `timezone` config key, /etc/timezone or /etc/localtime, UTC.
 * Returns the zone in use.
 */
function orch_tz(?string $configured = null): string
{
    static $current = null;
    if ($configured === null && $current !== null) {
        return $current;
    }
    $candidates = [getenv('TZ') ?: null, $configured];
    if (DIRECTORY_SEPARATOR === '/') {
        $candidates[] = trim((string)@file_get_contents('/etc/timezone'));
        $link = @readlink('/etc/localtime');
        $candidates[] = is_string($link) && preg_match('~zoneinfo/(.+)$~', $link, $m) ? $m[1] : null;
    }
    foreach ($candidates as $tz) {
        if (is_string($tz) && $tz !== '' && @date_default_timezone_set($tz)) {
            return $current = $tz;
        }
    }
    date_default_timezone_set('UTC');
    return $current = 'UTC';
}

/** UTF-8-safe cut to at most $bytes bytes (never splits a character); $suffix is appended only when something was cut. */
function orch_cut(string $s, int $bytes, string $suffix = ''): string
{
    if (strlen($s) <= $bytes) {
        return $s;
    }
    $n = max(0, $bytes);
    while ($n > 0 && (ord($s[$n]) & 0xC0) === 0x80) { // byte $n continues a character → step back to its start
        $n--;
    }
    return substr($s, 0, $n) . $suffix;
}

/** Claude Code's project folder name for a path: every non-alphanumeric character becomes '-'. */
function orch_slug(string $path): string
{
    return preg_replace('/[^A-Za-z0-9]/u', '-', $path) ?? preg_replace('/[^A-Za-z0-9]/', '-', $path);
}

/** On Windows cmd.exe reads `/` in `vendor/bin/phpunit` as a switch: turn the first word's slashes into backslashes. */
function orch_win_command(string $cmd, ?bool $windows = null): string
{
    $windows ??= DIRECTORY_SEPARATOR === '\\';
    if ($windows && preg_match('~^(\s*)((?:\.{1,2}/)?[\w.-]+(?:/[\w.-]+)+)(?=\s|$)~', $cmd, $m)) {
        return $m[1] . str_replace('/', '\\', $m[2]) . substr($cmd, strlen($m[0]));
    }
    return $cmd;
}

/** Keeps the last ~$keep bytes (from a line start) of a log once it grows past $max. Returns true when it trimmed. */
function orch_cap_log(string $file, int $max = 1048576, int $keep = 524288): bool
{
    clearstatcache(true, $file);
    $size = (int)@filesize($file);
    if ($size <= $max) {
        return false;
    }
    $fh = @fopen($file, 'r');
    if (!$fh) {
        return false;
    }
    fseek($fh, $size - $keep);
    fgets($fh); // drop the partial first line
    $tail = (string)stream_get_contents($fh);
    fclose($fh);
    $ok = @file_put_contents($file, $tail, LOCK_EX) !== false;
    clearstatcache(true, $file);
    return $ok;
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
    @file_put_contents($file, json_encode($row, ORCH_JSON) . "\n", FILE_APPEND | LOCK_EX);
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

/**
 * Runs git in the project without a shell (argv array, so no quoting problems on Windows); stderr is merged into stdout.
 * @param list<string>|string $args argv after `git -C <project>`; a string is split on whitespace (literal words only)
 * @param array<string,string> $env extra environment variables
 * @return array{0:int,1:string} [exit code, raw output]
 */
function orch_git_raw(string $project, array|string $args, array $env = []): array
{
    $argv = is_array($args) ? array_values(array_map('strval', $args)) : preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY);
    $proc = @proc_open(['git', '-C', $project, ...$argv], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes,
        null, $env ? array_merge(getenv(), $env) : null);
    if (!is_resource($proc)) {
        return [127, ''];
    }
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($proc), $out];
}

/** Runs git in the project; returns [exitCode, outputLines]. */
function orch_git(string $project, array|string $args): array
{
    [$code, $out] = orch_git_raw($project, $args);
    return [$code, $out === '' ? [] : explode("\n", rtrim(str_replace("\r\n", "\n", $out), "\n"))];
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

// ---------------------------------------------------------------------------------------------------------------
// Language of orch's own messages (Czech / English). `lang` in .claude/orch.json: "auto" (default) | "cs" | "en".
// Auto: turn-start.php detects the language of each prompt and keeps it per session in
// .claude/orch/sessions/<session>.lang; scripts without a prompt use their session's marker, else the newest marker
// in the project, else Czech.
// ---------------------------------------------------------------------------------------------------------------

/**
 * "cs", "en" or null (too short / mixed → the caller keeps the previous language). Cheap: Czech letters and a
 * few dozen common words per language, no mbstring. Code blocks, inline code and URLs are ignored.
 */
function orch_detect_lang(string $text): ?string
{
    static $cs = null, $en = null;
    $cs ??= array_flip(explode(" ", "je jsou jsem jsi jsme jste byl byla bylo bude budou se si na že aby jak jako ale nebo tak tohle tomhle tady tam teď ted "
        . "není nejde nemám mám máš má mají můžeš muzes můžu můžeme můžete které která který když protože protoze jestli taky také ještě jeste jen pak bez "
        . "podle prosím prosim ahoj díky diky dík udělej udelej udělat udelat koukni chci chceš chtěl nějak nejak nějaký nejaky oprav přidej pridej zkus "
        . "ukaž ukaz smaž smaz spusť spust vytvoř vytvor napiš napis změň zmen uprav zkontroluj projdi soubor soubory kód proč proc kde kdy co ano ne jo "
        . "tento tato toto tuto této těch všechny vsechny mě nám vás nás už uz hlavně hlavne trochu hodně hodne pořád porad"));
    $en ??= array_flip(explode(" ", "the is are and of in it this that with for you your please can could should would what how why when where which who not be been "
        . "have has had there here just need want from if but then into also will does did was were let make look using use add show about all any some "
        . "new only more our their these those they them its than too very because after before each other same work works get give take instead "
        . "still now out over yes thanks thank hello hi"));
    $text = orch_cut($text, 4000);
    $text = preg_replace(["/```.*?```/su", "/`[^`\n]*`/u", "~\b[a-z][a-z0-9+.-]*://\S+~iu"], " ", $text);
    if (!is_string($text)) { // not valid UTF-8
        return null;
    }
    $text = strtr(strtolower($text), ["Ě" => "ě", "Š" => "š", "Č" => "č", "Ř" => "ř", "Ž" => "ž", "Ť" => "ť", "Ď" => "ď", "Ň" => "ň", "Ů" => "ů",
        "Á" => "á", "Í" => "í", "É" => "é", "Ý" => "ý", "Ú" => "ú"]);
    $score = 3 * (int)preg_match_all("/[ěščřžťďňů]/u", $text) + (int)preg_match_all("/[áíéýú]/u", $text);
    $en_score = 0;
    if (preg_match_all("/\p{L}+/u", $text, $m)) {
        foreach ($m[0] as $w) {
            $score += isset($cs[$w]) ? 2 : 0;
            $en_score += isset($en[$w]) ? 2 : 0;
        }
    }
    if ($score >= 2 && $score > $en_score) {
        return "cs";
    }
    return $en_score >= 4 && $en_score > $score ? "en" : null;
}

/** Language marker of a session ("cs" / "en"), else the newest marker in the project, else null. Never creates anything. */
function orch_lang_marker(string $project, string $session = ""): ?string
{
    $dir = "$project/.claude/orch/sessions";
    $read = function (string $f): ?string {
        $v = trim((string)@file_get_contents($f));
        return $v === "cs" || $v === "en" ? $v : null;
    };
    $session = preg_replace("/[^A-Za-z0-9_-]/", "", $session) ?? "";
    if ($session !== "" && ($v = $read("$dir/$session.lang")) !== null) {
        return $v;
    }
    $best = null;
    $bestTime = -1;
    foreach (@scandir($dir) ?: [] as $name) {
        if (str_ends_with($name, ".lang") && ($t = (int)@filemtime("$dir/$name")) > $bestTime && ($v = $read("$dir/$name")) !== null) {
            $best = $v;
            $bestTime = $t;
        }
    }
    return $best;
}

/** Language for this project and session: forced by `lang`, else the session / newest marker, else "cs". Fails soft. */
function orch_lang_resolve(string $project, string $session = "", ?array $cfg = null): string
{
    try {
        $forced = ($l = ($cfg ?? orch_config($project))["lang"] ?? null) && is_string($l) ? strtolower(trim($l)) : "auto";
        if ($forced === "cs" || $forced === "en") {
            return $forced;
        }
        return orch_lang_marker($project, $session !== "" ? $session : (string)getenv("CLAUDE_SESSION_ID")) ?? "cs";
    } catch (Throwable) {
        return "cs";
    }
}

/** Current message language; with $set it is changed first. Not set yet → resolved for the current project. */
function orch_lang(?string $set = null): string
{
    static $cur = null;
    if ($set !== null) {
        return $cur = $set === "en" ? "en" : "cs";
    }
    return $cur ??= orch_lang_resolve(orch_project_dir());
}

/** Resolves the language for this project / session and makes it the current one; call once per script. */
function orch_lang_init(string $project, string $session = "", ?array $cfg = null): string
{
    return orch_lang(orch_lang_resolve($project, $session, $cfg));
}

/** A user-visible message in the current language; with $args both texts are sprintf formats. */
function orch_t(string $cs, string $en, mixed ...$args): string
{
    $s = orch_lang() === "en" ? $en : $cs;
    return $args ? vsprintf($s, $args) : $s;
}
orch_tz(); // timezone from the environment right away; orch_config() refines it with the `timezone` key
