---
name: global
description: Machine-wide report across all projects and sessions — disk used by ~/.claude (transcripts, plugins, file history) and tokens/USD per project, agent and model.
argument-hint: "[days, default 7]"
disable-model-invocation: true
---
Run (N = "$ARGUMENTS" if it is a number, otherwise 7): `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/global.php" --days=<N>`

Show the output to the user as-is (inside a code block). Add at most 3 lines of observation in the user's language (e.g. the biggest project on disk, the most expensive project, that old transcripts are removed by Claude Code after `cleanupPeriodDays`, which can be lowered in `~/.claude/settings.json`). Do not delete anything.
