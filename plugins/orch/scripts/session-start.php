<?php
declare(strict_types=1);
// SessionStart hook: remembers when the session started (scopes the untracked-file sweep in cleanup).
require __DIR__ . '/common.php';

try {
    $in = orch_read_input();
    $session = orch_session_id($in);
    if ($session !== '') {
        $dir = orch_state_dir(orch_project_dir($in)) . '/sessions';
        @mkdir($dir, 0777, true);
        $file = "$dir/$session.start";
        if (!is_file($file)) { // keep the first start on resume
            @file_put_contents($file, (string)time());
        }
    }
} catch (Throwable) {
}
exit(0);
