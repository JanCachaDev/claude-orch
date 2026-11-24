---
name: notify-test
description: Send a test notification to the configured Discord / WhatsApp channels.
disable-model-invocation: true
---
1. Run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" notify --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --text="🔔 orch: testovací notifikace z projektu funguje"`.
2. Reply in max 3 lines (user's language): the message is sent by the Stop hook right after this reply; delivery results go to `.claude/orch/notify.log`; channels are configured with `/plugin` → orch → Configure (Discord webhook URL, WhatsApp phone + apikey).
