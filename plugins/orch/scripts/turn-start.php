<?php
declare(strict_types=1);
// UserPromptSubmit hook: remembers when the current turn started (notify sends "done" only for long turns)
// and shows a pending budget alert (written by budget.php) to the user once.
require __DIR__ . '/common.php';

try {
    $in = orch_read_input();
    $session = orch_session_id($in);
    $alert = orch_state_dir(orch_project_dir($in)) . '/budget-alert.txt';
    if (is_file($alert)) {
        $msg = (string)file_get_contents($alert);
        @unlink($alert);
        echo json_encode(['systemMessage' => $msg], JSON_UNESCAPED_UNICODE) . "
";
    }
    if ($session !== '') {
        $dir = orch_state_dir(orch_project_dir($in)) . '/sessions';
        @mkdir($dir, 0777, true);
        @file_put_contents("$dir/$session.turn", (string)time());
    }
} catch (Throwable) {
}
exit(0);
