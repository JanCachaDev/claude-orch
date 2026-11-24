<?php
declare(strict_types=1);
// UserPromptSubmit hook: remembers when the current turn started (notify sends "done" only for long turns).
require __DIR__ . '/common.php';

try {
    $in = orch_read_input();
    $session = orch_session_id($in);
    if ($session !== '') {
        $dir = orch_state_dir(orch_project_dir($in)) . '/sessions';
        @mkdir($dir, 0777, true);
        @file_put_contents("$dir/$session.turn", (string)time());
    }
} catch (Throwable) {
}
exit(0);
