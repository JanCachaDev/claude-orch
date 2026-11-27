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
    $slug = orch_slug($project);
    if (is_dir("$root/$slug")) {
        return "$root/$slug";
    }
    foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $d) {
        if (strcasecmp(basename($d), $slug) === 0) {
            return $d;
        }
    }
    return null;
}

/**
 * Transcript files of one ~/.claude/projects/<slug> directory.
 * @param string|null $session only this session's main transcript and its subagents
 * @param bool        $meta    read each subagent's .meta.json for its agentType (false = 'subagent', saves one read per file)
 * @return array<string,string> file => 'main' | agentType
 */
function orch_transcript_files(string $dir, ?string $session = null, bool $meta = true): array
{
    $files = [];
    $main = $session === null ? (glob("$dir/*.jsonl") ?: []) : (is_file("$dir/$session.jsonl") ? ["$dir/$session.jsonl"] : []);
    foreach ($main as $f) {
        $files[$f] = 'main';
    }
    foreach (glob($session === null ? "$dir/*/subagents/agent-*.jsonl" : "$dir/$session/subagents/agent-*.jsonl") ?: [] as $f) {
        $m = $meta ? json_decode((string)@file_get_contents(substr($f, 0, -6) . '.meta.json'), true) : null;
        $files[$f] = (string)($m['agentType'] ?? 'subagent');
    }
    return $files;
}

/** Transcript path relative to its project directory (the key used for offsets and the usage cache). */
function orch_transcript_rel(string $dir, string $file): string
{
    return substr($file, strlen($dir) + 1);
}

/**
 * Byte offset in a transcript from which everything is at or after $ts: lets `run-log end` skip the (possibly
 * hundreds of MB) history before a run. A file untouched since $ts counts as fully "before" (its size). The offset is
 * conservative (never later than the first newer line); a timestamp filter on top does the exact cut.
 */
function orch_offset_before(string $file, int $ts): int
{
    $size = (int)@filesize($file);
    if ($size === 0 || (int)@filemtime($file) < $ts) {
        return $size;
    }
    $fh = @fopen($file, 'r');
    if (!$fh) {
        return $size;
    }
    $start = max(0, $size - 8 * 1048576);
    fseek($fh, $start);
    $buf = (string)stream_get_contents($fh);
    fclose($fh);
    $lines = explode("\n", $buf);
    $end = strlen($buf); // position right after the content of line $i
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $len = strlen($lines[$i]);
        if ($i > 0 || $start === 0) { // the first line of a mid-file window may be partial: ignore it
            if ($len > 0 && preg_match('/"timestamp":"([^"]+)"/', $lines[$i], $m) && (int)strtotime($m[1]) < $ts - 5) {
                return min($size, $start + $end + 1);
            }
        }
        $end -= $len + 1;
    }
    return $start;
}

/** Positions $fh at the first complete line at or after $offset (the offset may point into a line). */
function orch_seek_line($fh, int $offset): void
{
    if ($offset <= 0) {
        fseek($fh, 0);
        return;
    }
    fseek($fh, $offset - 1);
    if (fgetc($fh) !== "\n") {
        fgets($fh); // inside a line: skip its rest
    }
}

/** Skill names called through the Skill tool on one transcript line. @return list<string> */
function orch_line_skills(string $line): array
{
    $j = json_decode($line, true);
    $out = [];
    foreach ((array)($j['message']['content'] ?? []) as $c) {
        if (is_array($c) && ($c['type'] ?? '') === 'tool_use' && ($c['name'] ?? '') === 'Skill' && is_string($c['input']['skill'] ?? null)) {
            $out[] = $c['input']['skill'];
        }
    }
    return $out;
}

/**
 * @param array $opts in: 'prompts' (bool, collect prompt texts for orch_top_turns), 'session' (only that session's
 *                    transcripts), 'offsets' (transcript path relative to the project dir => byte offset to start from),
 *                    'skills' (true = collect subagent Skill calls). out: 'skills' => list of skill names.
 * @return list<array{ts:int, session:string, agent:string, turn:string, model:string, u:array<string,int>}>
 */
function orch_usage_messages(string $project, int $since = 0, array &$opts = []): array
{
    $dir = orch_transcript_dir($project);
    if ($dir === null) {
        $opts['skills'] = [];
        return [];
    }
    return orch_usage_messages_dir($dir, $since, $opts);
}

/** Same as orch_usage_messages(), for one ~/.claude/projects/<slug> directory. */
function orch_usage_messages_dir(string $dir, int $since = 0, array &$opts = []): array
{
    $files = orch_transcript_files($dir, $opts['session'] ?? null);
    $wantPrompts = !empty($opts['prompts']);
    $wantSkills = !empty($opts['skills']);
    $offsets = (array)($opts['offsets'] ?? []);
    $skills = [];

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
        $offset = (int)($offsets[orch_transcript_rel($dir, $file)] ?? 0);
        // a file that is now smaller than the saved offset was rewritten → read it from the start again
        $offset > 0 && $offset <= (int)@filesize($file) ? orch_seek_line($fh, $offset) : fseek($fh, 0);
        $turn = '';
        while (($line = fgets($fh)) !== false) {
            // user lines carry the promptId of the turn they belong to (subagents: the parent turn)
            if (str_contains($line, '"type":"user"') && preg_match('/"promptId":"([^"]+)"/', $line, $pm)) {
                $turn = $pm[1];
                if ($wantPrompts && $agent === 'main' && !isset($GLOBALS['orch_prompts'][$turn]) && !str_contains($line, '"isMeta":true')
                    && !str_contains($line, '"tool_result"')) {
                    $text = orch_prompt_text(json_decode($line, true)['message']['content'] ?? '');
                    if ($text !== '') {
                        $GLOBALS['orch_prompts'][$turn] = $text;
                    }
                }
                continue;
            }
            if ($wantSkills && $agent !== 'main' && str_contains($line, '"name":"Skill"')) {
                foreach (orch_line_skills($line) as $s) {
                    $skills[$s] = true;
                }
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
    $opts['skills'] = array_keys($skills);
    return $rows;
}

const ORCH_BUCKET = 900;

/**
 * Usage per 15-minute bucket and model, kept incrementally in $cacheFile (state/usage-cache.json): per transcript the
 * parsed byte offset, size and mtime, the last few message ids (a message can span lines) and the bucket sums. Only
 * new bytes are parsed on later calls; a file that shrank or whose offset no longer fits is parsed from 0 again.
 * @return list<array{ts:int, model:string, calls:int, u:array<string,int>}> buckets starting at or after $since
 */
function orch_usage_buckets(string $project, string $cacheFile, int $since): array
{
    $dir = orch_transcript_dir($project);
    if ($dir === null) {
        return [];
    }
    $cache = json_decode((string)@file_get_contents($cacheFile), true);
    $cache = is_array($cache) && ($cache['v'] ?? 0) === 1 && is_array($cache['files'] ?? null) ? $cache : ['v' => 1, 'files' => []];
    $keepFrom = time() - 15 * 86400;
    $live = [];
    $dirty = false;
    foreach (orch_transcript_files($dir, null, false) as $file => $_agent) {
        $rel = orch_transcript_rel($dir, $file);
        $size = (int)@filesize($file);
        $mtime = (int)@filemtime($file);
        $c = $cache['files'][$rel] ?? null;
        if ($c === null && $mtime < $since) {
            continue; // never parsed and too old to matter
        }
        $live[$rel] = true;
        if ($c !== null && ($c['size'] ?? -1) === $size && ($c['mtime'] ?? -1) === $mtime) {
            continue;
        }
        $off = $c !== null ? (int)($c['off'] ?? 0) : 0;
        if ($c === null || $off > $size) {
            $c = ['off' => 0, 'ids' => [], 'b' => []]; // new or shrunk → start over
            $off = 0;
        }
        $fh = @fopen($file, 'r');
        if (!$fh) {
            continue;
        }
        fseek($fh, $off);
        $seen = array_fill_keys((array)($c['ids'] ?? []), true);
        $lastIds = (array)($c['ids'] ?? []);
        $pos = $off;
        while (($line = fgets($fh)) !== false) {
            if (!str_ends_with($line, "\n")) {
                break; // incomplete last line: parse it next time
            }
            $pos += strlen($line);
            if (!str_contains($line, '"usage"')) {
                continue;
            }
            $j = json_decode($line, true);
            $m = $j['message'] ?? null;
            $u = $m['usage'] ?? null;
            if (!is_array($u) || !isset($m['id']) || isset($seen[$m['id']])) {
                continue;
            }
            $seen[$m['id']] = true;
            $lastIds[] = (string)$m['id'];
            $ts = isset($j['timestamp']) ? (int)strtotime((string)$j['timestamp']) : 0;
            if ($ts < $keepFrom) {
                continue;
            }
            $cw = (int)($u['cache_creation_input_tokens'] ?? 0);
            $cw1h = (int)($u['cache_creation']['ephemeral_1h_input_tokens'] ?? 0);
            $b = &$c['b'][intdiv($ts, ORCH_BUCKET) * ORCH_BUCKET][(string)($m['model'] ?? '?')];
            $b ??= [0, 0, 0, 0, 0, 0]; // calls, in, cw5, cw1h, cread, out
            $b[0]++;
            $b[1] += (int)($u['input_tokens'] ?? 0);
            $b[2] += max(0, $cw - $cw1h);
            $b[3] += $cw1h;
            $b[4] += (int)($u['cache_read_input_tokens'] ?? 0);
            $b[5] += (int)($u['output_tokens'] ?? 0);
            unset($b);
        }
        fclose($fh);
        $c['off'] = $pos;
        $c['size'] = $size;
        $c['mtime'] = $mtime;
        $c['ids'] = array_slice(array_values(array_unique($lastIds)), -20);
        $cache['files'][$rel] = $c;
        $dirty = true;
    }
    foreach ($cache['files'] as $rel => &$c) {
        if (!isset($live[$rel])) {
            unset($cache['files'][$rel]); // deleted, pruned or too old
            $dirty = true;
            continue;
        }
        foreach (array_keys($c['b'] ?? []) as $bucket) {
            if ($bucket < $keepFrom) {
                unset($c['b'][$bucket]);
            }
        }
    }
    unset($c);
    if ($dirty) {
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($cache)) !== false && !@rename($tmp, $cacheFile)) {
            @unlink($tmp);
        }
    }
    $sum = [];
    foreach ($cache['files'] as $c) {
        foreach ($c['b'] ?? [] as $bucket => $models) {
            if ($bucket + ORCH_BUCKET <= $since) {
                continue;
            }
            foreach ($models as $model => $v) {
                $r = &$sum[$bucket . '|' . $model];
                $r ??= ['ts' => (int)$bucket, 'model' => (string)$model, 'calls' => 0, 'u' => ['in' => 0, 'cw5' => 0, 'cw1h' => 0, 'cread' => 0, 'out' => 0]];
                $r['calls'] += $v[0];
                foreach (['in', 'cw5', 'cw1h', 'cread', 'out'] as $i => $k) {
                    $r['u'][$k] += $v[$i + 1];
                }
                unset($r);
            }
        }
    }
    return array_values($sum);
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
    return orch_cut($s, 200);
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
 * size no longer applies; $why is then set to 'compact'). Never throws.
 * @return array{ts:int, ctx:int, ttl:int, model:string}|null
 */
function orch_last_context(string $transcript, ?string &$why = null): ?array
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
                $why = 'compact';
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
    return orch_t('Studená cache: %d tahů přepsalo >=50k, $%.2f (%d %% útraty); průměrný kontext main %s',
        'Cold cache: %d turns rewrote >=50k, $%.2f (%d %% of spend); average main context %s',
        $c['count'], $c['usd'], round($c['share']), orch_fmt_tokens($c['avg_ctx']));
}
