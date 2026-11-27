---
name: bugfix
description: Bugfix team — reproduce, minimal fix, regression test, review. No planner. Flags --simple, --hard, --model=<m>, --no-review.
argument-hint: "[--simple|--hard] [--model=<m>] [--no-review] <bug description, error message or failing test>"
disable-model-invocation: true
---
Fix this bug with subagents: **$ARGUMENTS**

If the description is empty, ask for it and stop.

Config: !`php -n "${CLAUDE_PLUGIN_ROOT}/scripts/config.php" --project="${CLAUDE_PROJECT_DIR}"`

Orchestrator rules: don't read or edit project files yourself, pass file paths not contents, no restating agent replies.

This mode has NO planner: always 1 worker, whatever the level; ignore the planner parts below. Reviewers: simple 1, normal 1 (+1 security if sensitive), hard up to the `team` cap.

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

0. **Log start** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=bugfix --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="<bug, max 80 chars, no double quotes>"` → remember the run id. Send this Bash call in the SAME message as the Agent call of step 1, not before it.
1. **Fix** — call `orch:worker` with:
   "Bug: <description>. 1) Reproduce it first (failing test or exact command) — if you cannot reproduce, stop and report what you tried. 2) Find the root cause, not a workaround. 3) Make the minimal fix. 4) Add or extend a regression test when the project has tests. 5) Run the relevant tests."
2. **Review** — skip with `--no-review`. Otherwise call `orch:reviewer` (one per lens per Team size) with: "Bugfix for: <description>. Check the fix addresses the root cause and a regression test exists if the project has tests." plus the worker's file list and VERIFIED line.
3. **Fix loop** — on CHANGES_REQUESTED, send only the CRITICAL items to the worker per Fix rounds, review again. Max 2 review rounds.
4. **Log end** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<last VERDICT, or ABORTED if not reproducible> --rounds=<n> --categories=<from reviews or none> --open="<unresolved items, one line, or none>" --summary="<root cause in one line>"` (no double quotes inside values).
5. **Finish** — max 8 lines to the user (their language): root cause, fix (files), test result, verdict, skills used (one line), run line from step 4, and one suggested conventional commit message (one line). Do not commit.
