<?php
declare(strict_types=1);
/**
 * One-line view of the project config for the /orch:* skills (they read it instead of parsing orch.json themselves):
 *
 *   php -n config.php [--project=<dir>] [--git]
 *   → models={} efforts={} team={"planners":2,"workers":4,"reviewers":3} testCommand="vendor/bin/phpunit" lang="cs" [invalid-json:<err>] [git=clean|dirty|none head=<sha>]
 *
 * Space-separated key=<compact JSON> tokens. `lang` is the language orch's messages use for this project/session ("cs" or "en":
 * the `lang` key of orch.json, or in "auto" mode the language detected from the prompts; --session=<id> picks that session's). `invalid-json:<err>` appears when .claude/orch.json does not parse
 * (defaults are then in effect). With --git the working tree state follows: clean, dirty or none (not a repo).
 * Never fails: exit code 0.
 */
require __DIR__ . '/common.php';

$opts = getopt('', ['project:', 'git', 'session:']);
$project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir();
$cfg = orch_config($project);
$flags = ORCH_JSON | JSON_PARTIAL_OUTPUT_ON_ERROR;

$tokens = [
    'models=' . json_encode((object)$cfg['models'], $flags),
    'efforts=' . json_encode((object)$cfg['efforts'], $flags),
    'team=' . json_encode((object)$cfg['team'], $flags),
    'testCommand=' . json_encode(is_string($cfg['testCommand'] ?? null) && trim($cfg['testCommand']) !== '' ? $cfg['testCommand'] : null, $flags),
    'lang=' . json_encode(orch_lang_init($project, (string)($opts['session'] ?? ''), $cfg), $flags),
];
if (($err = orch_config_error($project)) !== null) {
    $tokens[] = 'invalid-json:' . str_replace(' ', '_', $err);
}
if (isset($opts['git'])) {
    [$code, $status] = orch_git($project, ['status', '--porcelain']);
    if ($code !== 0) {
        $tokens[] = 'git=none';
    } else {
        $tokens[] = 'git=' . ($status ? 'dirty' : 'clean');
        [$hc, $head] = orch_git($project, ['rev-parse', 'HEAD']);
        if ($hc === 0 && isset($head[0]) && preg_match('/^[0-9a-f]{7,64}$/', $head[0])) {
            $tokens[] = 'head=' . $head[0];
        }
    }
}
echo implode(' ', $tokens), "\n";
exit(0);
