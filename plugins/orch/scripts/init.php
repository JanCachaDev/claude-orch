<?php
declare(strict_types=1);
/**
 * Sets up orch in a project:
 *   - .claude/orch.json with defaults and a detected testCommand (only if the file does not exist)
 *   - permissions.deny rules in .claude/settings.json so agents don't read vendor/, node_modules/, lock files…
 * Usage: php -n init.php [--dry-run] [--project=<dir>]
 */
require __DIR__ . '/common.php';

$opts = getopt('', ['dry-run', 'project:']);
$dry = isset($opts['dry-run']);
$project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir();
$out = [];

// --- detect test command ---
$test = null;
if (is_file("$project/vendor/bin/phpunit")) {
    $test = 'vendor/bin/phpunit --colors=never';
} elseif (is_file("$project/vendor/bin/pest")) {
    $test = 'vendor/bin/pest --colors=never';
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
    $out[] = "= .claude/orch.json už existuje, nechávám beze změny";
} else {
    $cfg = [
        'patterns'      => ORCH_DEFAULTS['patterns'],
        'protectedDirs' => ORCH_DEFAULTS['protectedDirs'],
        'trashDays'     => ORCH_DEFAULTS['trashDays'],
        'testCommand'   => $test,
        'notify'        => true,
        'guard'         => ['enabled' => true, 'block' => [], 'allow' => []],
    ];
    $out[] = "+ .claude/orch.json (testCommand: " . ($test ?? 'nenalezen, vypnuto') . ")";
    if (!$dry) {
        @mkdir(dirname($orchFile), 0777, true);
        file_put_contents($orchFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
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
        fwrite(STDERR, ".claude/settings.json není platný JSON, deny pravidla nepřidávám\n");
        $settings = null;
    }
}
if ($settings !== null) {
    $existing = $settings['permissions']['deny'] ?? [];
    $new = array_values(array_diff($deny, $existing));
    if ($new) {
        $settings['permissions']['deny'] = array_values(array_merge($existing, $new));
        $out[] = '+ .claude/settings.json: ' . count($new) . ' deny pravidel pro čtení balastu';
        if (!$dry) {
            file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    } else {
        $out[] = '= .claude/settings.json: deny pravidla už jsou';
    }
}

orch_state_dir($project);
echo ($dry ? "[dry-run]\n" : '') . implode("\n", $out) . "\n";
