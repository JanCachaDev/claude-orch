---
name: run
description: Run a token-efficient planner → worker → reviewer loop on a coding task. Flags --simple, --hard, --model=<m>, --no-review, --plan=<file>.
argument-hint: "[--simple|--hard] [--model=<m>] [--no-review] [--plan=<file>] <task description>"
disable-model-invocation: true
---
Orchestrate this task with subagents: **$ARGUMENTS**

If the task is empty, ask for it and stop.

Config: !`php -n "${CLAUDE_PLUGIN_ROOT}/scripts/config.php" --project="${CLAUDE_PROJECT_DIR}"`

Rules for you (the orchestrator) — they exist to save tokens:
- Do not read or edit project files yourself. Delegate.
- Pass file paths between agents, never file contents. Do not paste the plan; point to the plan file.
- Do not restate agent replies to the user between steps; one short status line at most.
- Plan file: `.claude/orch/plan-${CLAUDE_SESSION_ID}.md`. With `--plan=<file>` in the arguments, use that file and skip planning (step 1).

<!-- orch:levels -->
**Model and effort** — rate the task, then pass `model` and `effort` on every Agent call (they override the agent's defaults). Never pass `effort` to an agent running on `haiku`. The Config line above has `models`, `efforts`, `team`, `testCommand`: do not Read `.claude/orch.json`; if the line is missing or empty, run that command once yourself. If it shows `invalid-json:<err>`, tell the user the config is ignored and use the defaults.
- Inline overrides in the arguments (strip them from the task): `--simple` / `--hard` force the level, `--model=<m>` sets the worker model (sonnet, opus or haiku), `--no-review` skips review (log verdict `UNREVIEWED`).
- **simple** (rename, typo, docs/comments, config value, one obvious edit): planner (if the mode uses one) skipped, worker `haiku`, reviewer `haiku` (no effort for either).
- **normal** (feature or bug in a few files, clear requirements): planner `sonnet`/`medium`, worker `sonnet`/`medium`, reviewer `haiku` (no effort).
- **hard** (cross-module design, concurrency, security/auth, payments, data migrations, unclear root cause, >5 files): planner `opus`/`high`, worker `sonnet`/`high` (`opus`/`high` if the plan says the change is intricate), reviewer `sonnet`/`medium`.
- Config `models` / `efforts` (e.g. `{"worker": "sonnet"}`) win over the level (still no effort on haiku).
- **Fix rounds**: in round 1 send the CRITICAL items to the SAME worker with `SendMessage` (warm context, same model; it is a deferred tool, load it once with ToolSearch `select:SendMessage`; if it is unavailable, start a new worker on the same model). Use a NEW worker one level up (model haiku → sonnet → opus; effort haiku → sonnet/`low`, then low → medium → high) only in round 2, or in round 1 when the worker ran on haiku and CRITICAL has `security`/`bug` items. Never downgrade mid-run, never use opus for a reviewer, never exceed `medium` effort for a reviewer, never pick `xhigh`/`max` automatically (only via override).
- Tell the user the level in ONE line, in their language, with counts, e.g. `normal: 1× planner sonnet/medium, 1× worker sonnet/medium, 1× reviewer haiku`.

**Team size** — caps from Config `team` (defaults `{"planners": 2, "workers": 4, "reviewers": 3}`). Parallel calls of one kind go in ONE message.
- **simple**: 1 worker, 1 reviewer (correctness). **normal**: 1 planner (if the mode uses one), 1 worker, 1 reviewer; +1 reviewer `Focus: security` if the change touches auth, input handling, SQL or secrets.
- **hard**: planners up to the `team` cap, in parallel, only if the task clearly spans separate areas; each gets `Write your plan to .claude/orch/plan-${CLAUDE_SESSION_ID}-<area>.md, cover only <area>` and reports its file. Workers: one per plan or `## Parallel` subtask, max cap; if their files overlap, run those workers sequentially. Parallel workers share the tree: each touches only its files and runs only its tests. Reviewers: up to the `team` cap lenses in parallel.
- Reviewers get `Focus: <lens>` (correctness | security | tests-and-conventions) and `VERIFIED: <the worker's VERIFIED line>` (reviewers rerun tests only when it says "not run" or for the tests-and-conventions lens). APPROVED only when all approve; the fixing worker gets the combined CRITICAL items; round 2 reruns only reviewers that requested changes. **Maximum 2 review rounds**; after that stop and report what is still open.
<!-- /orch:levels -->

Steps:
0. **Log start** — run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=run --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="<task, max 80 chars, no double quotes>"` and remember the printed run id. Send this Bash call in the SAME message as the first Agent call of step 1 (or 2 if planning is skipped), not before it — the run id is only needed at Log end.
1. **Plan** — skip if `--plan` was given or the task is trivial (one obvious change in 1–2 files). Otherwise call `orch:planner` (one or two per Team size) with the task and "Write your plan to `.claude/orch/plan-${CLAUDE_SESSION_ID}.md`". If it reports a decision the user must make, ask the user, log end with `--verdict=ABORTED`, and stop.
2. **Implement** — call `orch:worker` (one per plan/subtask per Team size) with the task and "Follow <plan file>" if a plan exists. If two planners ran, give each worker its own area plan file (or its `## Parallel` subtasks). With no plan and a task that may touch more than 3 files, the worker may plan inline itself (see its instructions).
3. **Review** — skip with `--no-review`. Otherwise call `orch:reviewer` (one per lens, in one message) with the task (one line), the CHANGED/NEW file list and the worker's VERIFIED line.
4. **Fix loop** — if VERDICT is CHANGES_REQUESTED, send only the CRITICAL items to the worker per Fix rounds, then review again (max 2 review rounds total).
5. **Log end** — run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<last VERDICT> --rounds=<review rounds> --categories=<CATEGORIES from all reviews, comma list, or none> --open="<unresolved CRITICAL/OPEN items, one line, or none>" --summary="<one line>"` (no double quotes inside values; models, efforts and skills are derived by the script).
6. **Finish** — reply to the user (in their language) with max 8 lines: what changed (files), verification result, review verdict, open items, skills used (one line), and the run line printed in step 5. Add one suggested conventional commit message (one line); do not commit.
