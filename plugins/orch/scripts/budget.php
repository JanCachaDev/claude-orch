<?php
declare(strict_types=1);
/**
 * Budget alert. Async Stop hook: when this project's estimated spend today / this week
 * (Monday 00:00 local time) crosses a limit, sends one Discord/WhatsApp message per period and
 * leaves .claude/orch/budget-alert.txt, which turn-start.php shows to the user on the next prompt.
 *
 * Limits in USD: "budget": {"day": 10, "week": 50} in .claude/orch.json,
 * or plugin userConfig budget_day_usd / budget_week_usd (orch.json wins). Unset = off.
 * Throttled: transcripts are re-read at most every `budget.checkSeconds` (default 300) seconds.
 *
 *   php -n budget.php --project=<dir> --status   prints spend vs. limits, no alerts
 */
require __DIR__ . '/lib-usage.php';
require __DIR__ . '/lib-notify.php';

$opts = getopt('', ['project:', 'status', 'now:']);
try {
    $in = isset($opts['project']) ? [] : orch_read_input();
    $project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir($in);
    $cfg = orch_config($project);
    $b = is_array($cfg['budget'] ?? null) ? $cfg['budget'] : [];
    $limits = array_filter([
        'day'  => limit($b['day'] ?? null, 'budget_day_usd'),
        'week' => limit($b['week'] ?? null, 'budget_week_usd'),
    ], fn($v) => $v !== null);
    $status = isset($opts['status']);
    if (!$limits && !$status) {
        exit(0);
    }

    $state = orch_state_dir($project);
    $file = "$state/budget.json";
    $mem = json_decode((string)@file_get_contents($file), true) ?: [];
    $now = isset($opts['now']) ? (int)$opts['now'] : time();
    if (!$status && $now - (int)($mem['checked'] ?? 0) < (int)($b['checkSeconds'] ?? 300)) {
        exit(0);
    }

    $dayStart = strtotime('today', $now);
    $weekStart = strtotime('monday this week', $now);
    $rows = orch_usage_messages($project, min($dayStart, $weekStart));
    $spend = [
        'day'  => orch_usage_sum($rows, $cfg['prices'], fn($r) => $r['ts'] >= $dayStart)['usd'],
        'week' => orch_usage_sum($rows, $cfg['prices'], fn($r) => $r['ts'] >= $weekStart)['usd'],
    ];
    $period = ['day' => date('Y-m-d', $now), 'week' => date('o-\WW', $now)];
    $label = ['day' => 'dnes', 'week' => 'tento týden'];

    if ($status) {
        foreach (['day', 'week'] as $k) {
            printf("%-11s %8.2f USD%s\n", $label[$k], $spend[$k], isset($limits[$k]) ? sprintf(' / limit %.2f', $limits[$k]) : ' (bez limitu)');
        }
        exit(0);
    }

    $mem['checked'] = $now;
    $alerts = [];
    foreach ($limits as $k => $limit) {
        if ($spend[$k] >= $limit && ($mem['alerted'][$k] ?? '') !== $period[$k]) {
            $mem['alerted'][$k] = $period[$k];
            $alerts[] = sprintf('%s %.2f USD z limitu %.2f USD', $label[$k], $spend[$k], $limit);
        }
    }
    @file_put_contents($file, json_encode($mem));
    if ($alerts) {
        $text = '💸 [' . basename($project) . '] orch: rozpočet překročen — ' . implode('; ', $alerts);
        @file_put_contents("$state/budget-alert.txt", $text);
        if ($cfg['notify']) {
            $results = orch_notify_send($text);
            if ($results) {
                orch_append_jsonl("$state/notify.log", ['ts' => date('c'), 'event' => 'budget', 'results' => $results]);
            }
        }
    }
} catch (Throwable) {
}
exit(0);

function limit($v, string $option): ?float
{
    $v ??= orch_option($option);
    return is_numeric($v) && (float)$v > 0 ? (float)$v : null;
}
