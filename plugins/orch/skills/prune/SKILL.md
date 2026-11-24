---
name: prune
description: Free disk space in ~/.claude — move session transcripts unused for N days (all projects) to ~/.claude/orch-trash, with a preview. Restorable.
argument-hint: "[days, default 30 | --restore-last]"
disable-model-invocation: true
---
Argument: "$ARGUMENTS"

- `--restore-last`: run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/prune.php" --restore-last` and show the output in one line.
- Otherwise (N = the argument if it is a number, else 30; minimum 7):
  1. Run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/prune.php" --days=<N> --dry-run --keep=${CLAUDE_SESSION_ID}` and show the output in a code block. If it says nothing to clean, stop.
  2. Ask for confirmation (user's language). Mention that pruned sessions disappear from `/resume` and stats, and that the trash is kept 7 days (`/orch:prune --restore-last` brings the last batch back).
  3. On yes run the same command without `--dry-run` and report the result in one line.
