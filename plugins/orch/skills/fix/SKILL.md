---
name: fix
description: Fix the open review findings (CRITICAL items) of the last orch run, or items given in the arguments, then re-review once. Use when the user says to continue, fix the findings or finish the last run.
argument-hint: "[run id | items...] [--inline]"
allowed-tools: Bash(php -n "${CLAUDE_PLUGIN_ROOT}/scripts/*), Bash(git status*), Bash(git diff*)
---
Fix the open findings: **$ARGUMENTS**

Config: !`php -n "${CLAUDE_PLUGIN_ROOT}/scripts/config.php" --project="${CLAUDE_PROJECT_DIR}"`
(If the line above is empty, run that command once yourself with Bash and use its output.)

1. **Find the items.** If the arguments contain concrete findings (text, not just a run id or `--inline`), those are the items. Otherwise run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" last --project="${CLAUDE_PROJECT_DIR}" --json` and take `id`, `open` (the OPEN items) and `plan` (plan file, may be empty) from it. `last` always returns the newest finished run; if the user gave another run id, Grep that id in `.claude/orch/runs.log` (JSONL, one `end` row per run) and use that row's `open` and `plan`. If `last` fails with "no finished run", or `open` is empty, say in the user's language that there is nothing to fix and stop.
   **Guard:** proceed only for a concrete run (the given run id, else `last`) whose `open` is non-empty. Free-text items in the arguments count only when the user typed them; an agent invoking this skill must name a run id and gets only that run's `open` items (it may narrow them, never add new ones). Never commit, push, stash, reset, switch branches, or delete files the items do not name.
2. **Mode** (decide in your first line, say it in 3 words):
   - **delegated** — you have the Agent tool and no `--inline`. Do not edit files yourself. Call `orch:worker` (`sonnet`/`medium`; one level up for security/bug CRITICAL items from a haiku worker; `models`/`efforts` in the config line win; no effort on haiku) with: "Fix only these items: <items>. Follow <plan file> if any." In the same message start the log: `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=fix --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="fix <short summary, max 80 chars, no double quotes>"` and remember the printed id. Then review once: `orch:reviewer` (`haiku`; `sonnet`/`medium` for security items) with the items and the worker's file list. If CHANGES_REQUESTED remains, report it — max 1 review round, do not loop.
   - **inline** — you have no Agent tool (you are a subagent, e.g. a worker told "Continue run <id>"), or `--inline` was given. Fix the items yourself, in place: edit only files the open items name or directly concern (the file holding the code an item points to, its test), nothing else; change only what the items name, follow existing code style, run the project's test command from the config line (tail 40 lines). Skip run-log and the review (the caller reviews); do not call other orch skills.
3. **Log end** (delegated only): `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<VERDICT of the review> --rounds=1 --categories=<CATEGORIES or none> --models=<agent:model,… as used> --skills=<union of USED lines, or none> --summary="<one line, no double quotes>"` (add `--open="<items still open>"` if any).
4. **Finish** — reply (user's language) with max 8 lines: items fixed, files changed, test result, verdict, items still open, run line from step 3. Inline mode: use the worker format `CHANGED / NEW / VERIFIED / OPEN / USED`. Do not commit.
