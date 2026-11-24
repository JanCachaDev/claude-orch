---
name: backend
description: Backend team — planner → worker → reviewer + DB optimizer (N+1, indexes, caching).
argument-hint: <backend task>
disable-model-invocation: true
---
Orchestrate this backend task with subagents: **$ARGUMENTS**

If the task is empty, ask for it and stop.

Orchestrator rules: don't read or edit project files yourself, pass file paths not contents, no restating agent replies.

0. **Log start** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=backend --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="<task, max 80 chars, no double quotes>"` → remember the run id.
1. **Plan** — `orch:planner` with the task plus "Include DB schema/migration changes and indexes the change needs." Skip for trivial tasks.
2. **Implement** — `orch:worker` with the task (+ "Follow .claude/orch/plan.md" if planned).
3. **Review in parallel** — call `orch:reviewer` and `orch:db-optimizer` **in the same message**, each with the task line and the worker's file list.
4. **Fix loop** — if either says CHANGES_REQUESTED, send the combined CRITICAL items (and missing INDEXES) to `orch:worker`, then run both reviewers again. Max 2 review rounds.
5. **Log end** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<APPROVED only if both approved> --rounds=<n> --categories=<from reviews; add performance for DB findings> --summary="<one line>"`.
6. **Finish** — max 8 lines (user's language): changes, indexes/migrations, verification, verdicts, run line. Do not commit.
