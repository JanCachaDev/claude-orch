---
name: backend
description: Backend team — planner → worker → reviewer + DB optimizer (N+1, indexes, caching).
argument-hint: <backend task>
disable-model-invocation: true
---
Orchestrate this backend task with subagents: **$ARGUMENTS**

If the task is empty, ask for it and stop.

Orchestrator rules: don't read or edit project files yourself, pass file paths not contents, no restating agent replies.

**Model choice** — before step 1, rate the task and pass `model` on every Agent call (it overrides the agent's default):
- **simple** (rename, typo, docs/comments, config value, one obvious edit): planner skipped, worker `haiku`, reviewer `haiku`.
- **normal** (feature or bug in a few files, clear requirements): planner `sonnet`, worker `sonnet`, reviewer `haiku`.
- **hard** (cross-module design, concurrency, security/auth, payments, data migrations, unclear root cause, >5 files): planner `opus`, worker `sonnet` (`opus` if the plan says the change is intricate), reviewer `sonnet`.
- **Escalate once**: if review round 1 returns CRITICAL items, the fixing worker goes one level up (haiku → sonnet → opus). Never downgrade mid-run, never use opus for the reviewer.
- If `.claude/orch.json` has `"models"` (e.g. `{"worker": "sonnet"}`), those values win. Tell the user the chosen level in one line, e.g. `Úroveň: normal (planner sonnet, worker sonnet, reviewer haiku)`.

**Team size** — scale the team with the level; caps from `.claude/orch.json` `"team"` (defaults `{"planners": 2, "workers": 4, "reviewers": 3}`). Parallel calls of one kind go in ONE message.
- **simple**: no planner, 1 worker, 1 reviewer (correctness). **normal**: 1 planner, 1 worker, 1 reviewer; +1 reviewer `Focus: security` if the change touches auth, input handling, SQL or secrets.
- **hard**: up to the `team` cap (default 2) planners in parallel only if the task clearly spans separate areas — each gets `Write your plan to .claude/orch/plan-<area>.md, cover only <area>` (plus the DB instruction) and reports its file. Workers: one per plan, max cap; if their files overlap, run those workers sequentially. Parallel workers share the tree: each touches only its files and runs only its tests. Reviewers: up to the `team` cap (default 3) lenses in parallel.
- `orch:db-optimizer` always runs in the same message as the reviewers and counts as an extra reviewer (outside the reviewer cap).
- Reviewers get `Focus: <lens>` (correctness | security | tests-and-conventions). APPROVED only when all approve; the fixing worker gets the combined CRITICAL items; round 2 reruns only reviewers that requested changes. Still max 2 review rounds.
- Level line includes counts, e.g. `Úroveň: hard — 2× planner opus, 2× worker sonnet, 3× reviewer sonnet`.

0. **Log start** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=backend --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="<task, max 80 chars, no double quotes>"` → remember the run id.
1. **Plan** — `orch:planner` with the task plus "Include DB schema/migration changes and indexes the change needs." Skip for trivial tasks.
2. **Implement** — `orch:worker` (one per plan/subtask per Team size) with the task (+ "Follow <plan file>" if planned, default `.claude/orch/plan.md`). If two planners ran, give each worker its own `.claude/orch/plan-<area>.md` (one worker per area plan, or per its `## Parallel` subtasks).
3. **Review in parallel** — call the `orch:reviewer`(s) per Team size and `orch:db-optimizer` **in the same message**, each with the task line and the worker's file list.
4. **Fix loop** — if either says CHANGES_REQUESTED, send the combined CRITICAL items (and missing INDEXES) to `orch:worker`, then rerun only those that requested changes. Max 2 review rounds.
5. **Log end** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<APPROVED only if both approved> --rounds=<n> --categories=<from reviews; add performance for DB findings> --models=<agent:model,… as used> --skills=<union of the agents' USED lines, comma list, or none> --summary="<one line>"`.
6. **Finish** — max 8 lines (user's language): changes, indexes/migrations, verification, verdicts, skills used (one line), run line. Do not commit.
