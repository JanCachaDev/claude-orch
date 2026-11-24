<?php
declare(strict_types=1);
// PostToolUse(Write) hook: logs every file an agent writes into .claude/orch/files.log (JSONL).
require __DIR__ . '/common.php';

try {
    $in = orch_read_input();
    $path = $in['tool_input']['file_path'] ?? null;
    if (is_string($path) && $path !== '') {
        $project = orch_project_dir($in);
        $rel = orch_relative($path, $project);
        if ($rel !== null && stripos($rel, '.claude/orch/') !== 0) {
            orch_append_jsonl(orch_state_dir($project) . '/files.log', [
                'ts'      => date('c'),
                'session' => $in['session_id'] ?? null,
                'agent'   => $in['agent_type'] ?? 'main',
                'file'    => $rel,
            ]);
        }
    }
} catch (Throwable) {
}
exit(0);
