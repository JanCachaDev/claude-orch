<?php
declare(strict_types=1);
/**
 * Sends notifications to Discord and/or WhatsApp. Runs as an async hook, never blocks Claude.
 *
 *   --event=stop          Stop hook: sends a finished /orch:* run (notify-pending.json),
 *                         otherwise "done" when the turn took >= notify_min_seconds
 *   --event=notification  Notification hook: Claude waits for you (permission, question)
 *   --event=test          sends a test message right away (used by /orch:notify-test via Stop)
 *
 * Credentials come from plugin userConfig (env CLAUDE_PLUGIN_OPTION_*), stored by Claude Code
 * in the OS credential store, never in the project:
 *   discord_webhook_url
 *   whatsapp_provider  callmebot (default) | twilio
 *   whatsapp_phone     +420…  (recipient)
 *   whatsapp_apikey    CallMeBot apikey, or Twilio "ACCOUNT_SID:AUTH_TOKEN"
 *   whatsapp_from      Twilio sender, e.g. +14155238886
 *   notify_min_seconds default 60
 * Disable per project with {"notify": false} in .claude/orch.json.
 */
require __DIR__ . '/lib-usage.php';

$opts = getopt('', ['event:', 'project:']);
$event = (string)($opts['event'] ?? 'stop');

try {
    $in = $event === 'test' && isset($opts['project']) ? [] : orch_read_input();
    $project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir($in);
    $cfg = orch_config($project);
    if (!$cfg['notify']) {
        exit(0);
    }
    $state = orch_state_dir($project);
    $session = orch_session_id($in);
    $name = basename($project);
    $text = null;

    if ($event === 'notification') {
        $msg = trim((string)($in['message'] ?? 'Claude čeká na tebe'));
        $text = "⏸ [$name] $msg";
    } elseif ($event === 'test') {
        $text = "🔔 [$name] orch: testovací notifikace funguje.";
    } else { // stop
        $pendingFile = "$state/notify-pending.json";
        $pending = is_file($pendingFile) ? json_decode((string)file_get_contents($pendingFile), true) : null;
        if (is_array($pending) && (($pending['session'] ?? '') === '' || $pending['session'] === $session)) {
            @unlink($pendingFile);
            $text = (string)$pending['text'];
        } else {
            $turnFile = "$state/sessions/$session.turn";
            $min = (int)orch_option('notify_min_seconds', '60');
            if ($session !== '' && is_file($turnFile)) {
                $elapsed = time() - (int)file_get_contents($turnFile);
                if ($elapsed >= $min) {
                    @unlink($turnFile); // one notification per turn
                    $last = trim(preg_replace('/\s+/', ' ', (string)($in['last_assistant_message'] ?? '')) ?? '');
                    if (strlen($last) > 300) {
                        $last = substr($last, 0, 300) . '…';
                    }
                    $text = "✅ [$name] Claude dokončil (" . orch_fmt_duration($elapsed) . ')' . ($last !== '' ? "\n$last" : '');
                }
            }
        }
    }

    if ($text === null) {
        exit(0);
    }
    $results = orch_notify_send($text);
    if ($results) {
        orch_append_jsonl("$state/notify.log", ['ts' => date('c'), 'event' => $event, 'results' => $results]);
    }
    if ($event === 'test' && isset($opts['project'])) {
        echo $results ? json_encode($results, JSON_UNESCAPED_UNICODE) . "\n" : "Žádný kanál není nastavený (discord_webhook_url / whatsapp_*).\n";
    }
} catch (Throwable) {
}
exit(0);


/** @return array<string,string> channel => "ok" | error */
function orch_notify_send(string $text): array
{
    $results = [];

    $discord = orch_option('discord_webhook_url');
    if ($discord !== null) {
        $body = json_encode(['username' => 'orch', 'content' => substr($text, 0, 1900)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$code, $out] = orch_curl([
            ['fail', null],
            ['url', $discord],
            ['header', 'Content-Type: application/json'],
            ['data-binary', (string)$body],
        ]);
        $results['discord'] = $code === 0 ? 'ok' : "error $code: " . trim(substr($out, 0, 200));
    }

    $phone = orch_option('whatsapp_phone');
    $key = orch_option('whatsapp_apikey');
    if ($phone !== null && $key !== null) {
        $provider = strtolower((string)orch_option('whatsapp_provider', 'callmebot'));
        if ($provider === 'twilio') {
            $sid = explode(':', $key, 2)[0];
            $from = (string)orch_option('whatsapp_from', '');
            [$code, $out] = orch_curl([
                ['fail', null],
                ['url', "https://api.twilio.com/2010-04-01/Accounts/" . rawurlencode($sid) . "/Messages.json"],
                ['user', $key],
                ['data-urlencode', 'From=whatsapp:' . $from],
                ['data-urlencode', 'To=whatsapp:' . $phone],
                ['data-urlencode', 'Body=' . substr($text, 0, 1500)],
            ]);
        } else {
            $url = 'https://api.callmebot.com/whatsapp.php?phone=' . rawurlencode($phone)
                 . '&text=' . rawurlencode(substr($text, 0, 1000)) . '&apikey=' . rawurlencode($key);
            [$code, $out] = orch_curl([['fail', null], ['url', $url]]);
        }
        $results['whatsapp'] = $code === 0 ? 'ok' : "error $code: " . trim(substr($out, 0, 200));
    }

    return $results;
}
