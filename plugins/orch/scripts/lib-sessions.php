<?php
declare(strict_types=1);
/**
 * Session discovery under ~/.claude: named sessions, background-job sessions, prompt counts.
 * Formats are internal to Claude Code and may change between versions; every reader tolerates garbage.
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/lib-usage.php';

const ORCH_TITLE_TAIL = 262144;

/** Last custom-title in the final 256 KB of a transcript (Claude Code re-appends it), or null. */
function orch_last_title(string $file): ?string
{
    $fh = @fopen($file, 'r');
    if (!$fh) {
        return null;
    }
    $size = (int)@filesize($file);
    if ($size > ORCH_TITLE_TAIL) {
        fseek($fh, $size - ORCH_TITLE_TAIL);
    }
    $buf = (string)stream_get_contents($fh);
    fclose($fh);
    $title = null;
    foreach (explode("\n", $buf) as $line) {
        if (!str_contains($line, '"custom-title"')) {
            continue;
        }
        $j = json_decode($line, true);
        if (is_array($j) && ($j['type'] ?? '') === 'custom-title' && is_string($j['customTitle'] ?? null) && $j['customTitle'] !== '') {
            $title = $j['customTitle'];
        }
    }
    return $title;
}

/** @return list<array<string,mixed>> decoded jobs/<x>/state.json files, each with '_mtime' */
function orch_job_states(string $home): array
{
    $out = [];
    foreach (glob("$home/jobs/*/state.json") ?: [] as $f) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j)) {
            $j['_mtime'] = (int)@filemtime($f);
            $out[] = $j;
        }
    }
    return $out;
}

/** @return array<string, array{name:string, slug:string, mtime:int}> */
function orch_named_sessions(string $home, ?string $slug = null): array
{
    $res = [];
    foreach (glob("$home/projects/" . ($slug ?? '*') . '/*.jsonl') ?: [] as $f) {
        $title = orch_last_title($f);
        if ($title !== null) {
            $res[basename($f, '.jsonl')] = ['name' => $title, 'slug' => basename(dirname($f)), 'mtime' => (int)@filemtime($f)];
        }
    }
    foreach (orch_job_states($home) as $j) {
        $sid = $j['sessionId'] ?? null;
        if (($j['nameSource'] ?? '') !== 'user' || !is_string($sid) || $sid === '' || isset($res[$sid])) {
            continue;
        }
        $name = (string)($j['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $link = is_string($j['linkScanPath'] ?? null) ? orch_norm($j['linkScanPath']) : '';
        $jslug = $link !== '' ? basename(dirname($link))
            : preg_replace('/[^A-Za-z0-9]/', '-', (string)($j['cwd'] ?? ''));
        if ($slug !== null && strcasecmp((string)$jslug, $slug) !== 0) {
            continue;
        }
        $mt = $link !== '' && is_file($link) ? (int)@filemtime($link) : $j['_mtime'];
        $res[$sid] = ['name' => $name, 'slug' => (string)$jslug, 'mtime' => $mt];
    }
    return $res;
}

/** @return array<string,true> */
function orch_job_sessions(string $home): array
{
    $res = [];
    foreach (orch_job_states($home) as $j) {
        foreach (['sessionId', 'resumeSessionId'] as $k) {
            if (is_string($j[$k] ?? null) && $j[$k] !== '') {
                $res[$j[$k]] = true;
            }
        }
    }
    return $res;
}

/** @return array<string,int> prompts per session id */
function orch_prompt_counts(string $home): array
{
    $res = [];
    $fh = @fopen("$home/history.jsonl", 'r');
    if (!$fh) {
        return $res;
    }
    while (($line = fgets($fh)) !== false) {
        $j = json_decode($line, true);
        if (is_array($j) && is_string($j['sessionId'] ?? null) && $j['sessionId'] !== '') {
            $res[$j['sessionId']] = ($res[$j['sessionId']] ?? 0) + 1;
        }
    }
    fclose($fh);
    return $res;
}

/** @return array<string,true> sessions of running Claude Code processes (sessions/*.json), kept regardless of pid liveness */
function orch_live_sessions(string $home): array
{
    $res = [];
    foreach (glob("$home/sessions/*.json") ?: [] as $f) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && is_string($j['sessionId'] ?? null) && $j['sessionId'] !== '') {
            $res[$j['sessionId']] = true;
        }
    }
    return $res;
}

/** Reason pruning must not run (a job state file exists but cannot be read), or null. */
function orch_prune_blocker(string $home): ?string
{
    foreach (glob("$home/jobs/*/state.json") ?: [] as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false || !is_array(json_decode($raw, true))) {
            return orch_t("nelze přečíst $f.", "cannot read $f.");
        }
    }
    foreach (glob("$home/sessions/*.json") ?: [] as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false || !is_array(json_decode($raw, true))) {
            return orch_t("nelze přečíst $f.", "cannot read $f.");
        }
    }
    return null;
}

/** @return array<string,true> lowercase slugs of projects with a running session or a job (cwd of running sessions and jobs) */
function orch_protected_slugs(string $home): array
{
    $res = [];
    $cwds = array_column(orch_job_states($home), 'cwd');
    foreach (glob("$home/sessions/*.json") ?: [] as $f) {
        $j = json_decode((string)@file_get_contents($f), true);
        $cwds[] = is_array($j) ? ($j['cwd'] ?? null) : null;
    }
    foreach ($cwds as $c) {
        if (is_string($c) && $c !== '') {
            $res[strtolower(orch_slug($c))] = true;
        }
    }
    return $res;
}
