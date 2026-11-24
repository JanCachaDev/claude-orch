---
name: init
description: Set up orch in this project — .claude/orch.json (with detected test command) and deny rules that stop agents from reading vendor/, node_modules/, lock files.
disable-model-invocation: true
---
1. Run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/init.php" --dry-run --project="${CLAUDE_PROJECT_DIR}"` and show the output.
2. Ask the user to confirm; on yes run it without `--dry-run`.
3. Reply in max 4 lines (user's language): what was created/changed, and that `testCommand` in `.claude/orch.json` runs on every Stop when the tree is dirty (set it to null to disable).
