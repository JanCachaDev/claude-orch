<?php
declare(strict_types=1);
// UserPromptSubmit hook: remembers when the current turn started (notify sends "done" only for long turns),
// shows a pending budget alert (written by budget.php) once, tells the user to run /reload-plugins
// (once per installed version) when a newer orch is installed than the one this session loaded, and warns about
// a large main-session context (cold cache = whole context rewritten; warm = every call re-reads it).
// The language of each prompt is detected here and kept per session (see orch_detect_lang in common.php); every
// other script reads it. "contextBlock": true in .claude/orch.json blocks the first prompt sent to a cold, large session.
require __DIR__ . '/lib-version.php';

/**
 * Context warning for this prompt, or null. Reads only the tail of the transcript.
 * @return array{text:string, base?:string, cold:bool, ts?:int}|null
 */
function orch_context_warning(array $in, array $cfg, string $dir, string $session): ?array
{
    $why = null;
    $limit = $cfg['contextWarn'] ?? 150000;
    $tp = is_string($in['transcript_path'] ?? null) ? $in['transcript_path'] : '';
    if ($session === '' || $tp === '' || !is_numeric($limit) || (int)$limit <= 0) {
        return null;
    }
    $c = orch_last_context($tp, $why);
    if ($why === 'compact') { // the old context is gone: the next growth must be announced again
        @unlink("$dir/$session.ctxband");
        @unlink("$dir/$session.ctxcold");
    }
    if ($c === null || $c['ctx'] < (int)$limit) {
        return null;
    }
    $prices = is_array($cfg['prices'] ?? null) ? $cfg['prices'] : [];
    $k = orch_fmt_tokens($c['ctx']);
    $idle = time() - $c['ts'];
    if ($idle > $c['ttl']) {
        $usd = orch_cost($c['model'], [$c['ttl'] >= 3600 ? 'cw1h' : 'cw5' => $c['ctx']], $prices);
        $base = orch_t('orch: cache vypršela (%d min), kontext %s — tento tah ho přepíše%s.',
            'orch: cache expired (%d min), context %s — this turn rewrites it%s.',
            intdiv($idle, 60), $k, $usd === null ? '' : sprintf(' (~$%.2f)', $usd));
        return ['text' => $base . orch_t(' Příště nejdřív /compact.', ' Next time /compact first.'), 'base' => $base, 'cold' => true, 'ts' => $c['ts']];
    }
    $band = intdiv($c['ctx'] - (int)$limit, 100000);
    $file = "$dir/$session.ctxband";
    $seen = is_file($file) ? (int)file_get_contents($file) : -1;
    if ($band <= $seen) {
        return null;
    }
    @file_put_contents($file, (string)$band);
    $usd = orch_cost($c['model'], ['cread' => $c['ctx']], $prices);
    return ['text' => orch_t('orch: kontext %s: každé volání čte %s%s; zvaž /compact', 'orch: context %s: every call reads %s%s; consider /compact', $k, $k,
        $usd === null ? '' : sprintf(' (~$%.2f)', $usd)), 'cold' => false];
}

try {
    $in = orch_read_input();
    $session = orch_session_id($in);
    $project = orch_project_dir($in);
    $state = orch_state_dir($project);
    $cfg = orch_config($project);
    $lang = is_string($cfg['lang'] ?? null) ? strtolower(trim($cfg['lang'])) : 'auto';
    if ($session !== '' && $lang !== 'cs' && $lang !== 'en') { // auto: detect from this prompt, keep the last one when unsure
        $mark = "$state/sessions/$session.lang";
        $seen = orch_detect_lang((string)($in['prompt'] ?? ''));
        if ($seen !== null) {
            @mkdir(dirname($mark), 0777, true);
            @file_put_contents($mark, $seen);
        } elseif (is_file($mark)) {
            @touch($mark); // keeps it the newest marker of the project
        }
    }
    orch_lang_init($project, $session, $cfg);
    $messages = [];
    $block = false;
    $alert = "$state/budget-alert.txt";
    if (is_file($alert)) {
        $messages[] = (string)file_get_contents($alert);
        @unlink($alert);
    }
    if ($session !== '') {
        $dir = "$state/sessions";
        @mkdir($dir, 0777, true);
        @file_put_contents("$dir/$session.turn", (string)time());
        // checked on every prompt (an update can happen while the session is open), announced once per installed version
        $loaded = orch_loaded_version();
        $installed = orch_installed_version(orch_claude_home());
        $announced = "$dir/$session.vercheck";
        if (orch_reload_needed($loaded, $installed) && @file_get_contents($announced) !== $installed) {
            @file_put_contents($announced, (string)$installed);
            $messages[] = orch_t("orch: toto sezení běží na $loaded, nainstalovaná je $installed — spusť /reload-plugins.",
                "orch: this session is running $loaded, $installed is installed — run /reload-plugins.");
        }
        try {
            $w = orch_context_warning($in, $cfg, $dir, $session);
            if ($w !== null) {
                $text = $w['text'];
                if ($w['cold']) {
                    // the same cold state is announced / blocked only once: sending the prompt again goes through
                    $mark = "$dir/$session.ctxcold";
                    $stamp = (string)($w['ts'] ?? '');
                    $already = $stamp !== '' && @file_get_contents($mark) === $stamp;
                    $exempt = (bool)preg_match('~^\s*/(compact|clear)\b~i', (string)($in['prompt'] ?? ''));
                    if ($stamp !== '') {
                        @file_put_contents($mark, $stamp);
                    }
                    if (!$already && !$exempt && ($cfg['contextBlock'] ?? false) === true) {
                        $block = true;
                        $text = ($w['base'] ?? $text) . orch_t(' Spusť nejdřív /compact (prompt nebyl odeslán); chceš-li pokračovat bez něj, pošli ho znovu.',
                            ' Run /compact first (the prompt was not sent); to continue without it, send it again.');
                    } elseif ($already) {
                        $text = '';
                    }
                }
                if ($text !== '') {
                    $messages[] = $text;
                }
            }
        } catch (Throwable) {
        }
    }
    if ($messages) {
        $msg = implode("\n", $messages);
        echo json_encode($block ? ['decision' => 'block', 'reason' => $msg] : ['systemMessage' => $msg], ORCH_JSON | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
    }
} catch (Throwable) {
}
exit(0);
