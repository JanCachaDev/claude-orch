<?php
declare(strict_types=1);
/**
 * Run journal used by the /orch:* orchestration skills (.claude/orch/runs.log, JSONL).
 *
 *   start  --mode=run|bugfix|backend|parallel --task="…" --session=<id>   → prints run id
 *   end    --id=<id> --verdict=APPROVED|CHANGES_REQUESTED|ABORTED --rounds=N
 *          [--categories=bug,security,…] [--summary="…"] [--open="unresolved items | none"]
 *          [--models=agent:model,…] [--efforts=agent:level,…] [--skills=name,name,…]   (overrides; normally derived from the transcripts)
 *          → logs the run (duration, tokens, USD, models per agent, skills used by subagents) and queues a notification for the Stop hook
 *   last   [--json] [--session=<id>]   → the newest finished run: id, verdict, mode, task, open items, plan file
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
orch_lang_init($project, $session);

switch ($cmd) {
    case 'start':
        $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        // count cost from the start of the user's turn (the /orch:* prompt), not from this call
        $turnFile = "$state/sessions/$session.turn";
        $turn = ($session !== '' && is_file($turnFile)) ? (int)file_get_contents($turnFile) : 0;
        $costFrom = ($turn > 0 && time() - $turn < 600) ? $turn : time();
        // where each transcript stood at the start of the cost window: `end` then reads only the bytes after it
        $offsets = [];
        if ($tdir = orch_transcript_dir($project)) {
            foreach (orch_transcript_files($tdir, $session !== '' ? $session : null, false) as $file => $_a) {
                if ($session !== '' || (int)@filemtime($file) > time() - 2 * 86400) {
                    $off = orch_offset_before($file, $costFrom);
                    $off > 0 && $offsets[orch_transcript_rel($tdir, $file)] = $off;
                }
            }
        }
        orch_append_jsonl($log, [
            'id' => $id, 'event' => 'start', 'ts' => date('c'), 'epoch' => time(), 'cost_from' => $costFrom,
            'session' => $session ?: null, 'mode' => (string)($opts['mode'] ?? 'run'),
            'task' => orch_cut(trim((string)($opts['task'] ?? '')), 300, '…'), 'offsets' => (object)$offsets,
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
        $uopts = ['session' => $sess !== '' ? $sess : null, 'offsets' => (array)($start['offsets'] ?? []), 'skills' => true];
        $rows = orch_usage_messages($project, $from, $uopts);
        $inRun = fn($r) => ($sess === '' || $r['session'] === $sess) && $r['ts'] >= $from && $r['ts'] <= $now + 5;
        $sum = orch_usage_sum($rows, orch_config($project)['prices'], $inRun);
        $verdict = strtoupper((string)($opts['verdict'] ?? 'UNKNOWN'));
        $rounds = (int)($opts['rounds'] ?? 0);
        $cats = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower((string)($opts['categories'] ?? '')))),
            fn($c) => $c !== '' && $c !== 'none')));
        $models = orch_models_by_agent(array_filter($rows, $inRun)); // derived; --models overrides per agent
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
        $skills = array_values(array_unique(array_merge((array)$uopts['skills'], array_filter(array_map('trim', explode(',', (string)($opts['skills'] ?? ''))),
            fn($s) => $s !== '' && $s !== 'none' && preg_match('/^[\w.:@\/-]+$/', $s)))));
        $openRaw = is_string($opts['open'] ?? null) ? trim($opts['open']) : '';
        $plan = '';
        foreach ([$sess !== '' ? "plan-$sess.md" : '', 'plan.md'] as $pf) {
            if ($pf !== '' && is_file("$state/$pf")) {
                $plan = ".claude/orch/$pf";
                break;
            }
        }
        $entry = [
            'id' => $id, 'event' => 'end', 'ts' => date('c'), 'epoch' => $now,
            'session' => $sess ?: null, 'mode' => $start['mode'], 'task' => $start['task'],
            'seconds' => $now - (int)$start['epoch'], 'verdict' => $verdict, 'rounds' => $rounds,
            'categories' => $cats, 'models' => (object)$models, 'efforts' => (object)$efforts, 'skills' => $skills, 'summary' => orch_cut(trim((string)($opts['summary'] ?? '')), 300, '…'),
            'open' => in_array(strtolower($openRaw), ['none', '-'], true) ? '' : orch_cut($openRaw, 500, '…'), 'plan' => $plan,
            'tokens' => ['calls' => $sum['calls'], 'in' => $sum['in'] + $sum['cw5'] + $sum['cw1h'] + $sum['cread'], 'out' => $sum['out']],
            'usd' => round($sum['usd'], 4),
        ];
        orch_append_jsonl($log, $entry);

        $icon = $verdict === 'APPROVED' ? '✅' : ($verdict === 'ABORTED' ? '⛔' : '⚠️');
        $text = orch_t("%s [%s] orch:%s hotovo – %s\n%s, %d kol review, %s, ~$%.2f", "%s [%s] orch:%s done – %s\n%s, %d review rounds, %s, ~$%.2f",
            $icon, basename($project), $start['mode'], $start['task'], $verdict, $rounds,
            orch_fmt_duration($entry['seconds']), $entry['usd']);
        if ($entry['summary'] !== '') {
            $text .= "\n" . $entry['summary'];
        }
        queue_notification($state, $sess, $text);
        printf("run %s: %s, %d rounds, %s, %s tokens in / %s out, ~$%.2f\n", $id, $verdict, $rounds,
            orch_fmt_duration($entry['seconds']), orch_fmt_tokens($entry['tokens']['in']), orch_fmt_tokens($entry['tokens']['out']), $entry['usd']);
        break;

    case 'last':
        $last = null;
        foreach (orch_read_jsonl($log) as $r) {
            if (($r['event'] ?? '') === 'end' && ($session === '' || ($r['session'] ?? '') === $session)) {
                $last = $r;
            }
        }
        if ($last === null) {
            fwrite(STDERR, "no finished run\n");
            exit(1);
        }
        $out = ['id' => (string)$last['id'], 'verdict' => (string)($last['verdict'] ?? ''), 'mode' => (string)($last['mode'] ?? ''),
            'task' => (string)($last['task'] ?? ''), 'open' => (string)($last['open'] ?? ''), 'plan' => (string)($last['plan'] ?? ''),
            'ts' => (string)($last['ts'] ?? '')];
        if (isset($opts['json'])) {
            echo json_encode($out, ORCH_JSON | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT), "\n";
        } else {
            echo "run {$out['id']} {$out['verdict']} mode={$out['mode']}\ntask: {$out['task']}\nopen: " . ($out['open'] ?: 'none')
                . "\nplan: " . ($out['plan'] ?: 'none') . "\n";
        }
        break;

    case 'notify':
        queue_notification($state, $session, (string)($opts['text'] ?? 'orch: test'));
        echo orch_t("Notifikace připravena, odešle se po dokončení odpovědi (Stop hook).\n", "Notification queued, it is sent when the reply finishes (Stop hook).\n");
        break;

    default:
        fwrite(STDERR, "usage: run-log.php start|end|last|notify [options]\n");
        exit(1);
}

function queue_notification(string $state, string $session, string $text): void
{
    @file_put_contents("$state/notify-pending.json", json_encode(['session' => $session, 'text' => $text], ORCH_JSON | JSON_PARTIAL_OUTPUT_ON_ERROR));
}

/**
 * Model family per agent type (without the orch: prefix) from the calls of one run, e.g. ['worker' => 'sonnet', 'reviewer' => 'opus'].
 * Several families are joined by '+' in order of calls. The main thread is skipped (it is the user's session model).
 * @return array<string,string>
 */
function orch_models_by_agent(array $rows): array
{
    $count = [];
    foreach ($rows as $r) {
        if ($r['agent'] === 'main' || $r['agent'] === 'subagent') {
            continue;
        }
        $fam = preg_match('/^claude-([a-z]+)/', $r['model'], $m) ? $m[1] : $r['model'];
        $a = preg_replace('/^orch:/', '', $r['agent']);
        $count[$a][$fam] = ($count[$a][$fam] ?? 0) + 1;
    }
    $res = [];
    foreach ($count as $a => $fams) {
        arsort($fams);
        $res[$a] = implode('+', array_keys($fams));
    }
    return $res;
}
