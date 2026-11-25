<?php
declare(strict_types=1);
// SessionStart hook: remembers when the session started (scopes the untracked-file sweep in cleanup)
// and, on a fresh start, points to the project's named sessions.
require __DIR__ . '/common.php';
require_once __DIR__ . '/lib-sessions.php';

try {
    $in = orch_read_input();
    $session = orch_session_id($in);
    $project = orch_project_dir($in);
    if ($session !== '') {
        $dir = orch_state_dir($project) . '/sessions';
        @mkdir($dir, 0777, true);
        $file = "$dir/$session.start";
        if (!is_file($file)) { // keep the first start on resume
            @file_put_contents($file, (string)time());
        }
    }
    if (($in['source'] ?? '') === 'startup' && (orch_config($project)['sessionHint'] ?? true) !== false) {
        $tp = is_string($in['transcript_path'] ?? null) ? orch_norm($in['transcript_path']) : '';
        $slug = $tp !== '' ? basename(dirname($tp)) : '';
        if ($slug === '' || $slug === '.') {
            $d = orch_transcript_dir($project);
            $slug = $d !== null ? basename($d) : '';
        }
        if ($slug !== '') {
            $mine = array_filter(
                orch_named_sessions(orch_claude_home(), $slug),
                fn($s, $sid) => $sid !== $session && strcasecmp($s['slug'], $slug) === 0,
                ARRAY_FILTER_USE_BOTH
            );
            uasort($mine, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
            $parts = [];
            foreach (array_slice($mine, 0, 3, true) as $sid => $s) {
                $parts[] = "„{$s['name']}“ — pokračuj v něm: claude --resume $sid";
            }
            if ($parts) {
                $msg = 'orch: v tomhle projektu máš pojmenované sezení ' . implode(' | ', $parts);
                echo json_encode(['systemMessage' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
    }
} catch (Throwable) {
}
exit(0);
