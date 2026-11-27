<?php
declare(strict_types=1);
/**
 * Sets up orch in a project:
 *   - .claude/orch.json with defaults and a detected testCommand (only if the file does not exist)
 *   - permissions.deny rules in .claude/settings.json so agents don't read vendor/, node_modules/, lock files…
 * Usage: php -n init.php [--dry-run] [--project=<dir>]
 */
require __DIR__ . '/common.php';

$opts = getopt('', ['dry-run', 'project:', 'session:']);
$dry = isset($opts['dry-run']);
$project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir();
orch_lang_init($project, (string)($opts['session'] ?? ''));
$out = [];

// --- detect test command ---
$test = null;
if (is_file("$project/vendor/bin/phpunit")) {
    $test = 'php vendor/bin/phpunit --colors=never'; // `php` prefix: works on Windows too (cmd.exe would read vendor/bin as switches)
} elseif (is_file("$project/vendor/bin/pest")) {
    $test = 'php vendor/bin/pest --colors=never';
} elseif (is_file("$project/composer.json") && isset(json_decode((string)file_get_contents("$project/composer.json"), true)['scripts']['test'])) {
    $test = 'composer test';
} elseif (is_file("$project/package.json") && isset(json_decode((string)file_get_contents("$project/package.json"), true)['scripts']['test'])) {
    $test = 'npm test --silent';
} elseif (is_file("$project/pytest.ini") || is_file("$project/pyproject.toml") && str_contains((string)file_get_contents("$project/pyproject.toml"), 'pytest')) {
    $test = 'python -m pytest -q';
} elseif (is_file("$project/go.mod")) {
    $test = 'go test ./...';
} elseif (is_file("$project/pom.xml")) {
    $test = 'mvn -q test';
} elseif (is_file("$project/Cargo.toml")) {
    $test = 'cargo test -q';
}

// --- .claude/orch.json ---
$orchFile = "$project/.claude/orch.json";
if (is_file($orchFile)) {
    $out[] = orch_t("= .claude/orch.json už existuje, nechávám beze změny", "= .claude/orch.json already exists, leaving it unchanged");
} else {
    $cfg = [
        'patterns'      => ORCH_DEFAULTS['patterns'],
        'protectedDirs' => ORCH_DEFAULTS['protectedDirs'],
        'trashDays'     => ORCH_DEFAULTS['trashDays'],
        'testCommand'   => $test,
        'timezone'      => orch_detect_timezone(),
        'notify'        => true,
        'guard'         => ['enabled' => true, 'block' => [], 'allow' => []],
    ];
    $out[] = "+ .claude/orch.json (testCommand: " . ($test ?? orch_t('nenalezen, vypnuto', 'not found, disabled')) . ")";
    if (!$dry) {
        @mkdir(dirname($orchFile), 0777, true);
        file_put_contents($orchFile, json_encode(array_filter($cfg, fn($v, $k) => $k !== 'timezone' || $v !== null, ARRAY_FILTER_USE_BOTH), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}

// --- .claude/settings.json deny rules ---
$deny = [
    'Read(./vendor/**)', 'Read(./node_modules/**)', 'Read(./.git/**)',
    'Read(./var/cache/**)', 'Read(./storage/logs/**)', 'Read(./storage/framework/**)',
    'Read(./dist/**)', 'Read(./build/**)', 'Read(./target/**)', 'Read(./coverage/**)',
    'Read(./**/*.min.js)', 'Read(./**/*.min.css)', 'Read(./**/*.map)',
    'Read(./**/composer.lock)', 'Read(./**/package-lock.json)', 'Read(./**/yarn.lock)', 'Read(./**/pnpm-lock.yaml)',
    'Read(./.claude/orch/trash/**)',
];
$settingsFile = "$project/.claude/settings.json";
$settings = [];
if (is_file($settingsFile)) {
    $settings = json_decode((string)file_get_contents($settingsFile), true);
    if (!is_array($settings)) {
        fwrite(STDERR, orch_t(".claude/settings.json není platný JSON, deny pravidla nepřidávám\n", ".claude/settings.json is not valid JSON, not adding the deny rules\n"));
        $settings = null;
    }
}
if ($settings !== null) {
    $existing = $settings['permissions']['deny'] ?? [];
    $new = array_values(array_diff($deny, $existing));
    if ($new) {
        $settings['permissions']['deny'] = array_values(array_merge($existing, $new));
        $out[] = '+ .claude/settings.json: ' . count($new) . orch_t(' deny pravidel pro čtení balastu', ' deny rules against reading bulk files');
        if (!$dry) {
            file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    } else {
        $out[] = orch_t('= .claude/settings.json: deny pravidla už jsou', '= .claude/settings.json: the deny rules are already there');
    }
}

orch_state_dir($project);

/** IANA name of the machine's timezone (Linux/macOS files, Windows tzutil for the common zones), or null. */
function orch_detect_timezone(): ?string
{
    if (DIRECTORY_SEPARATOR === '/') {
        $tz = trim((string)@file_get_contents('/etc/timezone'));
        $link = @readlink('/etc/localtime');
        $tz = $tz !== '' ? $tz : (is_string($link) && preg_match('~zoneinfo/(.+)$~', $link, $m) ? $m[1] : '');
        return $tz !== '' && @timezone_open($tz) ? $tz : null;
    }
    $win = trim((string)@shell_exec('tzutil /g 2>NUL'));
    $map = [
        'Central Europe Standard Time' => 'Europe/Prague', 'Central European Standard Time' => 'Europe/Warsaw',
        'W. Europe Standard Time' => 'Europe/Berlin', 'Romance Standard Time' => 'Europe/Paris', 'GMT Standard Time' => 'Europe/London',
        'E. Europe Standard Time' => 'Europe/Bucharest', 'FLE Standard Time' => 'Europe/Kyiv', 'Russian Standard Time' => 'Europe/Moscow',
        'Eastern Standard Time' => 'America/New_York', 'Central Standard Time' => 'America/Chicago', 'Mountain Standard Time' => 'America/Denver',
        'Pacific Standard Time' => 'America/Los_Angeles', 'UTC' => 'UTC', 'Tokyo Standard Time' => 'Asia/Tokyo',
        'China Standard Time' => 'Asia/Shanghai', 'India Standard Time' => 'Asia/Kolkata', 'AUS Eastern Standard Time' => 'Australia/Sydney',
    ];
    return $map[$win] ?? null;
}

echo ($dry ? "[dry-run]\n" : '') . implode("\n", $out) . "\n";
