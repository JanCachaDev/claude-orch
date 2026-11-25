<?php
declare(strict_types=1);
/**
 * Run journal used by the /orch:* orchestration skills (.claude/orch/runs.log, JSONL).
 *
 *   start  --mode=run|bugfix|backend|parallel --task="…" --session=<id>   → prints run id
 *   end    --id=<id> --verdict=APPROVED|CHANGES_REQUESTED|ABORTED --rounds=N
 *          [--categories=bug,security,…] [--skills=name,name,…] [--summary="…"]
 *          → logs the run (duration, tokens, USD) and queues a notification for the Stop hook
 *   notify --text="…" --session=<id>   → queues a custom notification (sent by the Stop hook)
 *
 * Common: --project=<dir> (default CLAUDE_PROJECT_DIR / cwd)
 */
require __DIR__ . '/lib-usage.php';

$cmd = $argv[1] ?? '';
$opts = []; // getopt() stops at the subcommand, so parse --key=value ourselves
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir();
$state = orch_state_dir($project);
$log = "$state/runs.log";
$session = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($opts['session'] ?? '')) ?? '';

switch ($cmd) {
    case 'start':
        $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        // count cost from the start of the user's turn (the /orch:* prompt), not from this call
        $turnFile = "$state/sessions/$session.turn";
        $turn = ($session !== '' && is_file($turnFile)) ? (int)file_get_contents($turnFile) : 0;
        $costFrom = ($turn > 0 && time() - $turn < 600) ? $turn : time();
        orch_append_jsonl($log, [
            'id' => $id, 'event' => 'start', 'ts' => date('c'), 'epoch' => time(), 'cost_from' => $costFrom,
            'session' => $session ?: null, 'mode' => (string)($opts['mode'] ?? 'run'),
            'task' => substr(trim((string)($opts['task'] ?? '')), 0, 300),
        ]);
        echo $id, "\n";
        break;

    case 'end':
        $id = (string)($opts['id'] ?? '');
        $start = null;
        foreach (orch_read_jsonl($log) as $r) {
            if (($r['id'] ?? '') === $id && ($r['event'] ?? '') === 'start') {
                $start = $r;
            }
        }
        if ($start === null) {
            fwrite(STDERR, "unknown run id: $id\n");
            exit(1);
        }
        $now = time();
        $sess = (string)($start['session'] ?? $session);
        $from = (int)($start['cost_from'] ?? $start['epoch']) - 2;
        $rows = orch_usage_messages($project, $from);
        $sum = orch_usage_sum($rows, orch_config($project)['prices'],
            fn($r) => ($sess === '' || $r['session'] === $sess) && $r['ts'] >= $from && $r['ts'] <= $now + 5);
        $verdict = strtoupper((string)($opts['verdict'] ?? 'UNKNOWN'));
        $rounds = (int)($opts['rounds'] ?? 0);
        $cats = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower((string)($opts['categories'] ?? '')))),
            fn($c) => $c !== '' && $c !== 'none')));
        $models = [];
        foreach (explode(',', (string)($opts['models'] ?? '')) as $pair) {
            $pos = strrpos($pair, ':'); // agent names may carry the orch: prefix, so split on the last colon
            $a = $pos === false ? '' : trim(substr($pair, 0, $pos));
            $m = $pos === false ? '' : trim(substr($pair, $pos + 1));
            if ($a !== '' && $m !== '' && preg_match('/^[\w.:-]+$/', "$a$m")) {
                $models[preg_replace('/^orch:/', '', $a)] = strtolower($m);
            }
        }
        $efforts = [];
        foreach (explode(',', (string)($opts['efforts'] ?? '')) as $pair) {
            $pos = strrpos($pair, ':');
            $a = $pos === false ? '' : trim(substr($pair, 0, $pos));
            $e = $pos === false ? '' : strtolower(trim(substr($pair, $pos + 1)));
            if ($a !== '' && preg_match('/^[\w.:-]+$/', $a) && in_array($e, ['low', 'medium', 'high', 'xhigh', 'max'], true)) {
                $efforts[preg_replace('/^orch:/', '', $a)] = $e;
            }
        }
        $skills = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($opts['skills'] ?? ''))),
            fn($s) => $s !== '' && $s !== 'none' && preg_match('/^[\w.:@\/-]+$/', $s))));
        $entry = [
            'id' => $id, 'event' => 'end', 'ts' => date('c'), 'epoch' => $now,
            'session' => $sess ?: null, 'mode' => $start['mode'], 'task' => $start['task'],
            'seconds' => $now - (int)$start['epoch'], 'verdict' => $verdict, 'rounds' => $rounds,
            'categories' => $cats, 'models' => (object)$models, 'efforts' => (object)$efforts, 'skills' => $skills, 'summary' => substr(trim((string)($opts['summary'] ?? '')), 0, 300),
            'tokens' => ['calls' => $sum['calls'], 'in' => $sum['in'] + $sum['cw5'] + $sum['cw1h'] + $sum['cread'], 'out' => $sum['out']],
            'usd' => round($sum['usd'], 4),
        ];
        orch_append_jsonl($log, $entry);

        $icon = $verdict === 'APPROVED' ? '✅' : ($verdict === 'ABORTED' ? '⛔' : '⚠️');
        $text = sprintf("%s [%s] orch:%s hotovo – %s\n%s, %d kol review, %s, ~$%.2f",
            $icon, basename($project), $start['mode'], $start['task'], $verdict, $rounds,
            orch_fmt_duration($entry['seconds']), $entry['usd']);
        if ($entry['summary'] !== '') {
            $text .= "\n" . $entry['summary'];
        }
        queue_notification($state, $sess, $text);
        printf("run %s: %s, %d rounds, %s, %s tokens in / %s out, ~$%.2f\n", $id, $verdict, $rounds,
            orch_fmt_duration($entry['seconds']), orch_fmt_tokens($entry['tokens']['in']), orch_fmt_tokens($entry['tokens']['out']), $entry['usd']);
        break;

    case 'notify':
        queue_notification($state, $session, (string)($opts['text'] ?? 'orch: test'));
        echo "Notifikace připravena, odešle se po dokončení odpovědi (Stop hook).\n";
        break;

    default:
        fwrite(STDERR, "usage: run-log.php start|end|notify [options]\n");
        exit(1);
}

function queue_notification(string $state, string $session, string $text): void
{
    @file_put_contents("$state/notify-pending.json", json_encode(['session' => $session, 'text' => $text], JSON_UNESCAPED_UNICODE));
}
