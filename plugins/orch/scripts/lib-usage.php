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
        while (($line = fgets($fh)) !== false) {
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
