<?php
declare(strict_types=1);
// SessionStart hook: remembers when the session started (scopes the untracked-file sweep in cleanup)
// and, on a fresh start, points to the project's named sessions.
require __DIR__ . '/common.php';
require_once __DIR__ . '/lib-sessions.php';

/** " (kontext 337k; po --resume nejdřív /compact)" for a session whose last context is known, else "". */
function orch_resume_context(string $home, string $slug, string $sid, array $cfg): string
{
    try {
        $c = orch_last_context("$home/projects/$slug/$sid.jsonl");
        if ($c === null) {
            return '';
        }
        $limit = $cfg['contextWarn'] ?? 150000;
        $big = is_numeric($limit) && (int)$limit > 0 && $c['ctx'] >= (int)$limit;
        return orch_t(' (kontext ', ' (context ') . orch_fmt_tokens($c['ctx'])
            . ($big ? orch_t('; po --resume nejdřív /compact, resume je vždy studený start', '; after --resume run /compact first, a resume is always a cold start') : '') . ')';
    } catch (Throwable) {
        return '';
    }
}

try {
    $in = orch_read_input();
    $session = orch_session_id($in);
    $project = orch_project_dir($in);
    $messages = [];
    orch_lang_init($project, $session); // no prompt yet: the session's marker (resume), else the project's newest one
    if (($err = orch_config_error($project)) !== null) {
        $messages[] = orch_t("orch: .claude/orch.json není platný JSON ($err) — používám výchozí hodnoty, oprav ho.",
            "orch: .claude/orch.json is not valid JSON ($err) — using the defaults, fix it.");
    }
    if ($session !== '') {
        $dir = orch_state_dir($project) . '/sessions';
        @mkdir($dir, 0777, true);
        $file = "$dir/$session.start";
        if (!is_file($file)) { // keep the first start on resume
            @file_put_contents($file, (string)time());
        }
    }
    if (($in['source'] ?? '') === 'startup' && ($cfg = orch_config($project))['sessionHint'] !== false) {
        $tp = is_string($in['transcript_path'] ?? null) ? orch_norm($in['transcript_path']) : '';
        $slug = $tp !== '' ? basename(dirname($tp)) : '';
        if ($slug === '' || $slug === '.') {
            $d = orch_transcript_dir($project);
            $slug = $d !== null ? basename($d) : '';
        }
        if ($slug !== '') {
            $mine = array_filter(
                orch_named_sessions($home = orch_claude_home(), $slug),
                fn($s, $sid) => $sid !== $session && strcasecmp($s['slug'], $slug) === 0,
                ARRAY_FILTER_USE_BOTH
            );
            uasort($mine, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
            $parts = [];
            foreach (array_slice($mine, 0, 3, true) as $sid => $s) {
                $parts[] = orch_t("„{$s['name']}“ — pokračuj v něm: claude --resume $sid", "\"{$s['name']}\" — continue it: claude --resume $sid")
                    . orch_resume_context($home, $s['slug'], $sid, $cfg);
            }
            if ($parts) {
                $messages[] = orch_t('orch: v tomhle projektu máš pojmenované sezení ', 'orch: named session in this project: ') . implode(' | ', $parts);
            }
        }
    }
    if ($messages) {
        echo json_encode(['systemMessage' => implode("\n", $messages)], ORCH_JSON | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
} catch (Throwable) {
}
exit(0);
