<?php
declare(strict_types=1);
// UserPromptSubmit hook: remembers when the current turn started (notify sends "done" only for long turns),
// shows a pending budget alert (written by budget.php) once, tells the user to run /reload-plugins
// (once per installed version) when a newer orch is installed than the one this session loaded, and warns about
// a large main-session context (cold cache = whole context rewritten; warm = every call re-reads it).
// "contextBlock": true in .claude/orch.json blocks the first prompt sent to a cold, large session.
require __DIR__ . '/lib-version.php';

/**
 * Context warning for this prompt, or null. Reads only the tail of the transcript.
 * @return array{text:string, cold:bool, ts?:int}|null
 */
function orch_context_warning(array $in, array $cfg, string $dir, string $session): ?array
{
    $limit = $cfg['contextWarn'] ?? 150000;
    $tp = is_string($in['transcript_path'] ?? null) ? $in['transcript_path'] : '';
    if ($session === '' || $tp === '' || !is_numeric($limit) || (int)$limit <= 0) {
        return null;
    }
    $c = orch_last_context($tp);
    if ($c === null || $c['ctx'] < (int)$limit) {
        return null;
    }
    $prices = is_array($cfg['prices'] ?? null) ? $cfg['prices'] : [];
    $k = orch_fmt_tokens($c['ctx']);
    $idle = time() - $c['ts'];
    if ($idle > $c['ttl']) {
        $usd = orch_cost($c['model'], [$c['ttl'] >= 3600 ? 'cw1h' : 'cw5' => $c['ctx']], $prices);
        $text = sprintf('orch: cache vypršela (%d min), kontext %s — tento tah ho přepíše%s. Příště nejdřív /compact.',
            intdiv($idle, 60), $k, $usd === null ? '' : sprintf(' (~$%.2f)', $usd));
        return ['text' => $text, 'cold' => true, 'ts' => $c['ts']];
    }
    $band = intdiv($c['ctx'] - (int)$limit, 100000);
    $file = "$dir/$session.ctxband";
    $seen = is_file($file) ? (int)file_get_contents($file) : -1;
    if ($band <= $seen) {
        return null;
    }
    @file_put_contents($file, (string)$band);
    $usd = orch_cost($c['model'], ['cread' => $c['ctx']], $prices);
    return ['text' => sprintf('orch: kontext %s: každé volání čte %s%s; zvaž /compact', $k, $k,
        $usd === null ? '' : sprintf(' (~$%.2f)', $usd)), 'cold' => false];
}

try {
    $in = orch_read_input();
    $session = orch_session_id($in);
    $project = orch_project_dir($in);
    $state = orch_state_dir($project);
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
            $messages[] = "orch: toto sezení běží na $loaded, nainstalovaná je $installed — spusť /reload-plugins.";
        }
        try {
            $cfg = orch_config($project);
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
                        $text = preg_replace('/ Příště nejdřív \/compact\.$/u', '', $text) . ' Spusť nejdřív /compact (prompt nebyl odeslán); chceš-li pokračovat bez něj, pošli ho znovu.';
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
        echo json_encode($block ? ['decision' => 'block', 'reason' => $msg] : ['systemMessage' => $msg], JSON_UNESCAPED_UNICODE), "\n";
    }
} catch (Throwable) {
}
exit(0);
