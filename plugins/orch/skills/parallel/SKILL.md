---
name: parallel
description: Split a task into independent subtasks and run several workers at once, each in its own git worktree; then apply their patches and review. Flags --simple, --hard, --model=<m>, --no-review.
argument-hint: "[--simple|--hard] [--model=<m>] [--no-review] <task that splits into independent parts>"
disable-model-invocation: true
---
Run this task with parallel workers: **$ARGUMENTS**

Parallel workers are faster but cost more tokens than /orch:run (each worker has its own context). If the task is small, tell the user /orch:run is a better fit and stop.

Config: !`php -n "${CLAUDE_PLUGIN_ROOT}/scripts/config.php" --project="${CLAUDE_PROJECT_DIR}" --git`

Orchestrator rules: don't read or edit project files yourself, pass file paths not contents, no restating agent replies.

This mode ALWAYS has a planner (the split is its `## Parallel` section), even at **simple**; one planner except in **hard** with clearly separate areas. Workers run in worktrees, one per subtask. Reviewers: simple/normal 1 (+1 `Focus: security` when sensitive, normal), hard up to the `team` cap.

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

0. **Preconditions** — use `git=` and `head=` from the Config line (if absent, run `git status --porcelain` and `git rev-parse HEAD`).
   - `git=none` / no commit yet → tell the user and stop.
   - `git=dirty` → stop and tell the user: workers start from the last commit, so commit or stash first.
   - Remember `head` as BASE. Log start: `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=parallel --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="<task, max 80 chars, no double quotes>"` → run id. Send this Bash call in the SAME message as the first Agent call of step 1 (not before it).
1. **Plan** — `orch:planner` with the task, "Make a parallel plan (## Parallel section)" and "Write your plan to `.claude/orch/plan-${CLAUDE_SESSION_ID}.md`". With two planners, number subtasks P1..Pn continuously across both plans and remember each subtask's plan file. If it says not splittable, continue as /orch:run would (single worker, no isolation).
2. **Work in parallel** — in ONE message, call `orch:worker` once per subtask with `isolation: "worktree"`. Prompt each:
   "Self-review: yes. You are in an isolated git worktree. First run `git checkout -q --detach <BASE>`. Do ONLY subtask P<n> from <plan file> — read the plan from the main repo path `${CLAUDE_PROJECT_DIR}/<plan file>`. Touch only its listed files. Do NOT run the full test suite (vendor/, node_modules/ and .env are missing in the worktree; verification happens after the patches are applied); run at most a syntax check or a single targeted test if it works without them. When done run `git add -A` (do not commit) and include `WORKTREE: <output of pwd>` in your reply."
3. **Collect** — one call: `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/parallel-collect.php" --base=<BASE> --worktrees=P1=<path>,P2=<path>` (a worktree path from each worker's WORKTREE line). It applies the patches (3-way), removes the worktrees and prunes, and prints `Pn applied|failed` per subtask. Failed subtasks are noted as open; continue with the rest.
4. **Verify once** — in the main repo run the Config `testCommand` (`| tail -n 40`) once; if it is empty, VERIFIED is "not run". Failures go to the fixing worker as CRITICAL items.
5. **Review** — skip with `--no-review`. Otherwise `orch:reviewer`(s) per Team size with the task, the union of CHANGED/NEW files and VERIFIED from step 4. On CHANGES_REQUESTED, send CRITICAL items per Fix rounds to a worker (no isolation, in the main repo) and review again. Max 2 review rounds.
6. **Log end** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<last VERDICT> --rounds=<n> --categories=<from reviews or none> --open="<failed patches and unresolved items, one line, or none>" --summary="<N subtasks, M applied>"` (no double quotes inside values).
7. **Finish** — max 8 lines (user's language): subtasks and their result, applied patches, verdict, open items, skills used (one line), run line, and one suggested conventional commit message (one line). Do not commit.
