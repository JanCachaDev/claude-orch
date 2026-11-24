<?php
declare(strict_types=1);
// Discord / WhatsApp sender shared by notify.php and budget.php. Credentials come from plugin userConfig.
require_once __DIR__ . "/common.php";

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
