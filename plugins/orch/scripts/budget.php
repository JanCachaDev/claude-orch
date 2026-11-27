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

$opts = getopt('', ['project:', 'status', 'now:', 'session:']);
try {
    $in = isset($opts['project']) ? [] : orch_read_input();
    $project = isset($opts['project']) ? orch_norm((string)$opts['project']) : orch_project_dir($in);
    $cfg = orch_config($project);
    orch_lang_init($project, orch_session_id($in) ?: (string)($opts['session'] ?? ''), $cfg);
    $b =is_array($cfg['budget'] ?? null) ? $cfg['budget'] : [];
    $limits = array_filter([
        'day'  => orch_budget_limit($b['day'] ?? null, 'budget_day_usd'),
        'week' => orch_budget_limit($b['week'] ?? null, 'budget_week_usd'),
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
    // incremental: usage-cache.json keeps per-transcript 15-minute buckets, only newly appended bytes are parsed
    $spend = ['day' => 0.0, 'week' => 0.0];
    foreach (orch_usage_buckets($project, "$state/usage-cache.json", min($dayStart, $weekStart)) as $b) {
        $usd = orch_cost($b['model'], $b['u'], $cfg['prices']) ?? 0.0;
        $b['ts'] >= $dayStart && $spend['day'] += $usd;
        $b['ts'] >= $weekStart && $spend['week'] += $usd;
    }
    $period = ['day' => date('Y-m-d', $now), 'week' => date('o-\WW', $now)];
    $label = ['day' => orch_t('dnes', 'today'), 'week' => orch_t('tento týden', 'this week')];

    if ($status) {
        foreach (['day', 'week'] as $k) {
            printf("%-11s %8.2f USD%s\n", $label[$k], $spend[$k], isset($limits[$k]) ? sprintf(' / limit %.2f', $limits[$k]) : orch_t(' (bez limitu)', ' (no limit)'));
        }
        exit(0);
    }

    $mem['checked'] = $now;
    $alerts = [];
    foreach ($limits as $k => $limit) {
        if ($spend[$k] >= $limit && ($mem['alerted'][$k] ?? '') !== $period[$k]) {
            $mem['alerted'][$k] = $period[$k];
            $alerts[] = orch_t('%s %.2f USD z limitu %.2f USD', '%s %.2f USD of the %.2f USD limit', $label[$k], $spend[$k], $limit);
        }
    }
    @file_put_contents($file, json_encode($mem));
    if ($alerts) {
        $text = '💸 [' . basename($project) . '] ' . orch_t('orch: rozpočet překročen — ', 'orch: budget exceeded — ') . implode('; ', $alerts);
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

function orch_budget_limit($v, string $option): ?float
{
    $v ??= orch_option($option);
    return is_numeric($v) && (float)$v > 0 ? (float)$v : null;
}
