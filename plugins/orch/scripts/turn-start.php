<?php
declare(strict_types=1);
// UserPromptSubmit hook: remembers when the current turn started (notify sends "done" only for long turns),
// shows a pending budget alert (written by budget.php) once, and tells the user to run /reload-plugins
// (once per installed version) when a newer orch is installed than the one this session loaded.
require __DIR__ . '/lib-version.php';

try {
    $in = orch_read_input();
    $session = orch_session_id($in);
    $state = orch_state_dir(orch_project_dir($in));
    $messages = [];
    $alert = "$state/budget-alert.txt";
    if (is_file($alert)) {
        $messages[] = (string)file_get_contents($alert);
        @unlink($alert);
    }
    if ($session !== '') {
        $dir = "$state/sessions";
        @mkdir($dir, 0777, true);
        @file_put_contents("$dir/$session.turn", (string)time());
        // checked on every prompt (an update can happen while the session is open), announced once per installed version
        $loaded = orch_loaded_version();
        $installed = orch_installed_version(orch_claude_home());
        $announced = "$dir/$session.vercheck";
        if (orch_reload_needed($loaded, $installed) && @file_get_contents($announced) !== $installed) {
            @file_put_contents($announced, (string)$installed);
            $messages[] = "orch: toto sezení běží na $loaded, nainstalovaná je $installed — spusť /reload-plugins.";
        }
    }
    if ($messages) {
        echo json_encode(['systemMessage' => implode("\n", $messages)], JSON_UNESCAPED_UNICODE), "\n";
    }
} catch (Throwable) {
}
exit(0);
