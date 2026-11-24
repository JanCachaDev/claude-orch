---
name: restore
description: Restore files that orch cleanup moved to .claude/orch/trash.
argument-hint: "[file path | --list]"
disable-model-invocation: true
---
Argument: "$ARGUMENTS"

- Empty or `--list`: run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/restore.php" --list --project="${CLAUDE_PROJECT_DIR}"` and show the output in a code block.
- A file path: run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/restore.php" --file="<path>" --project="${CLAUDE_PROJECT_DIR}"`. If it says the file exists, ask before re-running with `--force`.

Reply with the script output and nothing more than one extra line.
