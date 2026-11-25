---
name: run
description: Run a token-efficient planner → worker → reviewer loop on a coding task.
argument-hint: <task description>
disable-model-invocation: true
---
Orchestrate this task with subagents: **$ARGUMENTS**

If the task is empty, ask for it and stop.

Rules for you (the orchestrator) — they exist to save tokens:
- Do not read or edit project files yourself. Delegate.
- Pass file paths between agents, never file contents. Do not paste the plan; point to `.claude/orch/plan.md`.
- Do not restate agent replies to the user between steps; one short status line at most.

**Model choice** — before step 1, rate the task and pass `model` on every Agent call (it overrides the agent's default):
- **simple** (rename, typo, docs/comments, config value, one obvious edit): planner skipped, worker `haiku`, reviewer `haiku`.
- **normal** (feature or bug in a few files, clear requirements): planner `sonnet`, worker `sonnet`, reviewer `haiku`.
- **hard** (cross-module design, concurrency, security/auth, payments, data migrations, unclear root cause, >5 files): planner `opus`, worker `sonnet` (`opus` if the plan says the change is intricate), reviewer `sonnet`.
- **Escalate once**: if review round 1 returns CRITICAL items, the fixing worker goes one level up (haiku → sonnet → opus). Never downgrade mid-run, never use opus for the reviewer.
- If `.claude/orch.json` has `"models"` (e.g. `{"worker": "sonnet"}`), those values win. Tell the user the chosen level in one line, e.g. `Úroveň: normal (planner sonnet, worker sonnet, reviewer haiku)`.

**Team size** — scale the team with the level; caps from `.claude/orch.json` `"team"` (defaults `{"planners": 2, "workers": 4, "reviewers": 3}`). Parallel calls of one kind go in ONE message.
- **simple**: no planner, 1 worker, 1 reviewer (correctness). **normal**: 1 planner, 1 worker, 1 reviewer; +1 reviewer `Focus: security` if the change touches auth, input handling, SQL or secrets.
- **hard**: up to the `team` cap (default 2) planners in parallel only if the task clearly spans separate areas — each gets `Write your plan to .claude/orch/plan-<area>.md, cover only <area>` and reports its file. Workers: one per plan / `## Parallel` subtask, max cap; if their files overlap, run those workers sequentially. Parallel workers share the tree: each touches only its files and runs only its tests. Reviewers: up to the `team` cap (default 3) lenses in parallel.
- Reviewers get `Focus: <lens>` (correctness | security | tests-and-conventions). APPROVED only when all approve; the fixing worker gets the combined CRITICAL items; round 2 reruns only reviewers that requested changes. Still max 2 review rounds.
- Level line includes counts, e.g. `Úroveň: hard — 2× planner opus, 2× worker sonnet, 3× reviewer sonnet`.

Steps:
0. **Log start** — run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=run --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="<task, max 80 chars, no double quotes>"` and remember the printed run id.
1. **Plan** — if the task is trivial (one obvious change in 1–2 files), skip this step. Otherwise call subagent `orch:planner` (one or two per Team size) with the task. If it reports a decision the user must make, ask the user, log end with `--verdict=ABORTED`, and stop.
2. **Implement** — call `orch:worker` (one per plan/subtask per Team size) with the task, and "Follow <plan file>" if a plan exists (`.claude/orch/plan.md`). If two planners ran, give each worker its own `.claude/orch/plan-<area>.md` (one worker per area plan, or per its `## Parallel` subtasks).
3. **Review** — call `orch:reviewer` (one per lens, in one message) with the task (one line) and the CHANGED/NEW file list from the worker.
4. **Fix loop** — if VERDICT is CHANGES_REQUESTED, call `orch:worker` with only the CRITICAL items, then review again. **Maximum 2 review rounds total.** After that, stop and report what is still open.
5. **Log end** — run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<last VERDICT> --rounds=<review rounds> --categories=<CATEGORIES from all reviews, comma list, or none> --models=<agent:model,… as used> --skills=<union of the agents' USED lines, comma list, or none> --summary="<one line, no double quotes>"`.
6. **Finish** — reply to the user (in their language) with max 8 lines: what changed (files), verification result, review verdict, open items, skills used (one line), and the run line printed in step 5. Do not commit.
