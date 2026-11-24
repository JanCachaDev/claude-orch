---
name: stats
description: Show token usage per agent/model and files written/cleaned by agents in this project.
argument-hint: "[days, default 7]"
disable-model-invocation: true
---
Run (N = "$ARGUMENTS" if it is a number, otherwise 7): `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/stats.php" --days=<N> --project="${CLAUDE_PROJECT_DIR}"`

Show the output to the user as-is (inside a code block). Add at most 2 lines of observation (e.g. which agent costs most). Nothing else.
