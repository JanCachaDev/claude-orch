---
name: prune
description: Free disk space in ~/.claude — move session transcripts unused for N days (all projects) to ~/.claude/orch-trash, with a preview. Restorable.
argument-hint: "[days, default 30 | --restore-last]"
disable-model-invocation: true
---
Argument: "$ARGUMENTS"

Never pruned (in any mode): named sessions (`/rename`, `claude -n`, named background jobs), sessions that belong to jobs, and the current session. The preview ends with `chráněno: <n> pojmenovaná/úlohy` (protected sessions that would otherwise qualify). Short unnamed sessions (1-2 prompts, or no prompt and under 100 KB) are moved after 1 day regardless of N.

Project memory (`projects/<slug>/memory`) is moved to the same trash batch (preview line `osiřelá paměť: <slug>`) only when the project has no session transcript left; running sessions (`sessions/*.json`) are protected too.

Automatic: a SessionStart hook runs `prune.php --auto` at most once a day, silently, using userConfig `prune_days` (default 30, 0 = off); moves are logged to `~/.claude/orch-trash/prune.log` and are restorable like manual ones.

- `--restore-last`: restores the newest trash batch, which may be an automatic one; existing files are never overwritten (conflicts stay in the trash). Run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/prune.php" --restore-last` and show the output in one line.
- Otherwise (N = the argument if it is a number, else 30; minimum 7):
  1. Run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/prune.php" --days=<N> --dry-run --keep=${CLAUDE_SESSION_ID}` and show the output in a code block. If it says nothing to clean, stop.
  2. Ask for confirmation (user's language). Mention that pruned sessions disappear from `/resume` and stats, and that the trash is kept 7 days (`/orch:prune --restore-last` brings the last batch back).
  3. On yes run the same command without `--dry-run` and report the result in one line.
