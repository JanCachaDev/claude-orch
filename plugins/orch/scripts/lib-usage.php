<?php
declare(strict_types=1);
/**
 * Reads token usage from Claude Code transcripts of one project.
 *   ~/.claude/projects/<slug>/<session>.jsonl                      main thread
 *   ~/.claude/projects/<slug>/<session>/subagents/agent-<id>.jsonl subagents (+ .meta.json with agentType)
 * One API message can span several JSONL lines → deduplicated by message.id.
 * The transcript format is internal to Claude Code and may change between versions.
 */
require_once __DIR__ . '/common.php';

function orch_claude_home(): string
{
    return orch_norm(getenv('CLAUDE_CONFIG_DIR') ?: ((getenv('USERPROFILE') ?: getenv('HOME')) . '/.claude'));
}

function orch_transcript_dir(string $project): ?string
{
    $root = orch_claude_home() . '/projects';
    $native = str_replace('/', DIRECTORY_SEPARATOR === '\\' || preg_match('~^[a-z]:~i', $project) ? '\\' : '/', $project);
    $slug = preg_replace('/[^A-Za-z0-9]/u', '-', $native) ?? preg_replace('/[^A-Za-z0-9]/', '-', $project);
    if (is_dir("$root/$slug")) {
        return "$root/$slug";
    }
    foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $d) {
        if (strcasecmp(basename($d), (string)$slug) === 0) {
            return $d;
        }
    }
    return null;
}

/**
 * @return list<array{ts:int, session:string, agent:string, model:string, u:array<string,int>}>
 */
function orch_usage_messages(string $project, int $since = 0): array
{
    $dir = orch_transcript_dir($project);
    return $dir === null ? [] : orch_usage_messages_dir($dir, $since);
}

/** Same as orch_usage_messages(), for one ~/.claude/projects/<slug> directory. */
function orch_usage_messages_dir(string $dir, int $since = 0): array
{
    $files = [];
    foreach (glob("$dir/*.jsonl") ?: [] as $f) {
        $files[$f] = 'main';
    }
    foreach (glob("$dir/*/subagents/agent-*.jsonl") ?: [] as $f) {
        $meta = json_decode((string)@file_get_contents(substr($f, 0, -6) . '.meta.json'), true);
        $files[$f] = (string)($meta['agentType'] ?? 'subagent');
    }

    $seen = [];
    $rows = [];
    foreach ($files as $file => $agent) {
        if (@filemtime($file) < $since) {
            continue;
        }
        $fh = @fopen($file, 'r');
        if (!$fh) {
            continue;
        }
        $turn = '';
        while (($line = fgets($fh)) !== false) {
            // user lines carry the promptId of the turn they belong to (subagents: the parent turn)
            if (str_contains($line, '"type":"user"') && preg_match('/"promptId":"([^"]+)"/', $line, $pm)) {
                $turn = $pm[1];
                if ($agent === 'main' && !isset($GLOBALS['orch_prompts'][$turn]) && !str_contains($line, '"isMeta":true')
                    && !str_contains($line, '"tool_result"')) {
                    $text = orch_prompt_text(json_decode($line, true)['message']['content'] ?? '');
                    if ($text !== '') {
                        $GLOBALS['orch_prompts'][$turn] = $text;
                    }
                }
                continue;
            }
            if (!str_contains($line, '"usage"')) {
                continue;
            }
            $j = json_decode($line, true);
            $m = $j['message'] ?? null;
            $u = $m['usage'] ?? null;
            if (!is_array($u) || !isset($m['id']) || isset($seen[$m['id']])) {
                continue;
            }
            $ts = isset($j['timestamp']) ? (int)strtotime((string)$j['timestamp']) : 0;
            if ($ts < $since) {
                continue;
            }
            $seen[$m['id']] = true;
            $cw = (int)($u['cache_creation_input_tokens'] ?? 0);
            $cw1h = (int)($u['cache_creation']['ephemeral_1h_input_tokens'] ?? 0);
            $rows[] = [
                'ts'      => $ts,
                'session' => (string)($j['sessionId'] ?? '?'),
                'agent'   => $agent,
                'turn'    => $turn,
                'model'   => (string)($m['model'] ?? '?'),
                'u'       => [
                    'in'    => (int)($u['input_tokens'] ?? 0),
                    'cw5'   => max(0, $cw - $cw1h),
                    'cw1h'  => $cw1h,
                    'cread' => (int)($u['cache_read_input_tokens'] ?? 0),
                    'out'   => (int)($u['output_tokens'] ?? 0),
                ],
            ];
        }
        fclose($fh);
    }
    return $rows;
}

/** Sum usage + cost of messages; optional filter callback. */
function orch_usage_sum(array $rows, array $prices = [], ?callable $filter = null): array
{
    $sum = ['calls' => 0, 'in' => 0, 'cw5' => 0, 'cw1h' => 0, 'cread' => 0, 'out' => 0, 'usd' => 0.0, 'unpriced' => 0];
    foreach ($rows as $r) {
        if ($filter && !$filter($r)) {
            continue;
        }
        $sum['calls']++;
        foreach ($r['u'] as $k => $v) {
            $sum[$k] += $v;
        }
        $c = orch_cost($r['model'], $r['u'], $prices);
        $c === null ? $sum['unpriced']++ : $sum['usd'] += $c;
    }
    return $sum;
}

function orch_fmt_tokens(int $n): string
{
    return $n >= 1_000_000 ? sprintf('%.1fM', $n / 1e6) : ($n >= 1000 ? sprintf('%.1fk', $n / 1e3) : (string)$n);
}

/** Short one-line version of a user prompt ("/orch:run …" for slash commands). */
function orch_prompt_text($content): string
{
    if (is_array($content)) {
        $content = implode(' ', array_map(fn($c) => is_array($c) && ($c['type'] ?? '') === 'text' ? (string)$c['text'] : '', $content));
    }
    $s = (string)$content;
    if (preg_match('~<command-name>([^<]*)</command-name>~', $s, $m)) {
        $args = preg_match('~<command-args>([^<]*)</command-args>~', $s, $a) ? ' ' . $a[1] : '';
        $s = $m[1] . $args;
    }
    $s = preg_replace('~<(system-reminder|local-command-[a-z]+)>.*?</\1>~s', '', $s) ?? $s;
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? '');
    return strlen($s) > 200 ? substr($s, 0, 200) : $s;
}

/**
 * Most expensive turns (one user prompt and everything it triggered, subagents included).
 * @return list<array{session:string, turn:string, prompt:string, from:int, to:int, project?:string}&array>
 */
function orch_top_turns(array $rows, array $prices = [], int $n = 5): array
{
    $g = [];
    foreach ($rows as $r) {
        if ($r['turn'] === '') {
            continue;
        }
        $g[$r['session'] . '|' . $r['turn']][] = $r;
    }
    $turns = [];
    foreach ($g as $key => $list) {
        [$session, $turn] = explode('|', $key, 2);
        $ts = array_column($list, 'ts');
        $turns[] = orch_usage_sum($list, $prices) + [
            'session' => $session, 'turn' => $turn, 'prompt' => (string)($GLOBALS['orch_prompts'][$turn] ?? '?'),
            'from' => min($ts), 'to' => max($ts), 'project' => (string)($list[0]['project'] ?? ''),
            'agents' => count(array_unique(array_column($list, 'agent'))),
        ];
    }
    usort($turns, fn($a, $b) => $b['usd'] <=> $a['usd']);
    return array_slice($turns, 0, $n);
}

/** Recursive size in bytes and file count; never throws. */
function orch_dir_size(string $dir): array
{
    $bytes = 0;
    $files = 0;
    if (!is_dir($dir)) {
        return ['bytes' => 0, 'files' => 0];
    }
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $bytes += (int)$f->getSize();
                $files++;
            }
        }
    } catch (Throwable) {
    }
    return ['bytes' => $bytes, 'files' => $files];
}

function orch_fmt_bytes(int $b): string
{
    foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'kB' => 1 << 10] as $u => $n) {
        if ($b >= $n) {
            return sprintf('%.1f %s', $b / $n, $u);
        }
    }
    return "$b B";
}

function orch_fmt_duration(int $s): string
{
    return $s >= 3600 ? sprintf('%dh %02dm', intdiv($s, 3600), intdiv($s % 3600, 60))
        : ($s >= 60 ? sprintf('%dm %02ds', intdiv($s, 60), $s % 60) : "{$s}s");
}

const ORCH_CONTEXT_TAIL = 524288;

/**
 * Context size of the main session from the LAST assistant call, read from the final 512 KB of its transcript only
 * (never the whole file). ctx = input + cache write + cache read; ttl = 3600 when the newest cache write used the
 * 1 h cache, else 300. Null when nothing usable is found or when the context was just compacted (the old
 * size no longer applies). Never throws.
 * @return array{ts:int, ctx:int, ttl:int, model:string}|null
 */
function orch_last_context(string $transcript): ?array
{
    try {
        $fh = @fopen($transcript, 'r');
        if (!$fh) {
            return null;
        }
        $size = (int)@filesize($transcript);
        $partial = $size > ORCH_CONTEXT_TAIL;
        if ($partial) {
            fseek($fh, $size - ORCH_CONTEXT_TAIL);
        }
        $buf = (string)stream_get_contents($fh);
        fclose($fh);
        $lines = explode("\n", $buf);
        if ($partial) {
            array_shift($lines); // starts mid-line
        }
        $last = null;
        $ttl = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            if ($last === null && (str_contains($line, '"isCompactSummary":true') || str_contains($line, '"subtype":"compact_boundary"'))) {
                return null;
            }
            if (!str_contains($line, '"usage"') || !str_contains($line, '"type":"assistant"') || str_contains($line, '"isSidechain":true')) {
                continue;
            }
            $j = json_decode($line, true);
            $u = is_array($j) ? ($j['message']['usage'] ?? null) : null;
            if (!is_array($u)) {
                continue;
            }
            $cw = (int)($u['cache_creation_input_tokens'] ?? 0);
            if ($last === null) {
                $ctx = (int)($u['input_tokens'] ?? 0) + $cw + (int)($u['cache_read_input_tokens'] ?? 0);
                $ts = isset($j['timestamp']) ? (int)strtotime((string)$j['timestamp']) : 0;
                if ($ctx <= 0 || $ts <= 0) {
                    continue;
                }
                $last = ['ts' => $ts, 'ctx' => $ctx, 'model' => (string)($j['message']['model'] ?? '')];
            }
            if ($cw > 0) { // the newest call that wrote cache tells which TTL is in use
                $ttl = (int)($u['cache_creation']['ephemeral_1h_input_tokens'] ?? 0) > 0 ? 3600 : 300;
                break;
            }
        }
        return $last === null ? null : $last + ['ttl' => $ttl ?? 300];
    } catch (Throwable) {
        return null;
    }
}

/**
 * Main-agent calls that wrote >= $min tokens to the cache at once (a cold cache rewritten after an idle gap).
 * usd = cost of those cache writes only.
 * @return array{count:int, tokens:int, usd:float}
 */
function orch_cold_rewrites(array $rows, array $prices = [], int $min = 50000): array
{
    $res = ['count' => 0, 'tokens' => 0, 'usd' => 0.0];
    foreach ($rows as $r) {
        if (($r['agent'] ?? '') !== 'main' || $r['u']['cw5'] + $r['u']['cw1h'] < $min) {
            continue;
        }
        $res['count']++;
        $res['tokens'] += $r['u']['cw5'] + $r['u']['cw1h'];
        $res['usd'] += orch_cost($r['model'], ['cw5' => $r['u']['cw5'], 'cw1h' => $r['u']['cw1h']], $prices) ?? 0.0;
    }
    return $res;
}

/** Average context (in + cache write + cache read) of main-agent calls; 0 when there are none. */
function orch_avg_context(array $rows): int
{
    $sum = 0;
    $n = 0;
    foreach ($rows as $r) {
        if (($r['agent'] ?? '') === 'main') {
            $sum += $r['u']['in'] + $r['u']['cw5'] + $r['u']['cw1h'] + $r['u']['cread'];
            $n++;
        }
    }
    return $n ? intdiv($sum, $n) : 0;
}

/** "Studená cache" report line shared by stats.php and global.php, plus the numbers for --json. */
function orch_cold_summary(array $rows, array $prices, float $totalUsd): array
{
    $c = orch_cold_rewrites($rows, $prices);
    return $c + ['share' => $totalUsd > 0 ? round(100 * $c['usd'] / $totalUsd, 1) : 0.0, 'avg_ctx' => orch_avg_context($rows)];
}

function orch_cold_line(array $c): string
{
    return sprintf('Studená cache: %d tahů přepsalo >=50k, $%.2f (%d %% útraty); průměrný kontext main %s',
        $c['count'], $c['usd'], round($c['share']), orch_fmt_tokens($c['avg_ctx']));
}
