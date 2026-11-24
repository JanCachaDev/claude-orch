---
name: cleanup
description: Move temporary files left by agents (tmp_*, debug_*, *.bak …, never git-tracked) to .claude/orch/trash.
disable-model-invocation: true
---
1. Run: `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/cleanup.php" --manual --dry-run --project="${CLAUDE_PROJECT_DIR}"`
2. Show the list to the user. If it says nothing to clean, stop.
3. Ask for confirmation; on yes run the same command without `--dry-run` and report the result in one line.
