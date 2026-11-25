---
name: parallel
description: Split a task into independent subtasks and run several workers at once, each in its own git worktree; then apply their patches and review.
argument-hint: <task that splits into independent parts>
disable-model-invocation: true
---
Run this task with parallel workers: **$ARGUMENTS**

Parallel workers are faster but cost more tokens than /orch:run (each worker has its own context). If the task is small, tell the user /orch:run is a better fit and stop.

Orchestrator rules: don't read or edit project files yourself, pass file paths not contents, no restating agent replies.

**Model choice** — before step 1, rate the task and pass `model` on every Agent call (it overrides the agent's default):
- **simple** (rename, typo, docs/comments, config value, one obvious edit): planner skipped, worker `haiku`, reviewer `haiku`.
- **normal** (feature or bug in a few files, clear requirements): planner `sonnet`, worker `sonnet`, reviewer `haiku`.
- **hard** (cross-module design, concurrency, security/auth, payments, data migrations, unclear root cause, >5 files): planner `opus`, worker `sonnet` (`opus` if the plan says the change is intricate), reviewer `sonnet`.
- **Escalate once**: if review round 1 returns CRITICAL items, the fixing worker goes one level up (haiku → sonnet → opus). Never downgrade mid-run, never use opus for the reviewer.
- If `.claude/orch.json` has `"models"` (e.g. `{"worker": "sonnet"}`), those values win. Tell the user the chosen level in one line, e.g. `Úroveň: normal (planner sonnet, worker sonnet, reviewer haiku)`.

**Team size** — scale the team with the level; caps from `.claude/orch.json` `"team"` (defaults `{"planners": 2, "workers": 4, "reviewers": 3}`). Parallel calls of one kind go in ONE message.
- Planner: 1 (the split is its `## Parallel` section); in **hard** with clearly separate areas up to the `team` cap (default 2), each told `Write your plan to .claude/orch/plan-<area>.md, cover only <area>` and reporting its file. Workers: one per subtask with disjoint files (max cap), each in its own worktree as below; overlapping subtasks run sequentially. Reviewers: **simple/normal** 1 (+1 `Focus: security` when sensitive, normal); **hard** up to the `team` cap (default 3) lenses in parallel.
- Reviewers get `Focus: <lens>` (correctness | security | tests-and-conventions). APPROVED only when all approve; the fixing worker gets the combined CRITICAL items; round 2 reruns only reviewers that requested changes. Still max 2 review rounds.
- Level line includes counts, e.g. `Úroveň: hard — 2× planner opus, 2× worker sonnet, 3× reviewer sonnet`.

0. **Preconditions** — run `git status --porcelain` and `git rev-parse HEAD`.
   - Not a git repo or no commit yet → tell the user and stop.
   - Dirty working tree → stop and tell the user: workers start from the last commit, so commit or stash first.
   - Remember HEAD as BASE. Log start: `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=parallel --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="<task, max 80 chars, no double quotes>"` → run id.
1. **Plan** — `orch:planner` with the task and "Make a parallel plan (## Parallel section)". With two planners, number subtasks P1..Pn continuously across both plans and remember each subtask's plan file (default `.claude/orch/plan.md`; patches stay `p<n>.patch`). If it says not splittable, continue as /orch:run would (single worker, no isolation).
2. **Work in parallel** — in ONE message, call `orch:worker` once per subtask with `isolation: "worktree"`. Prompt each:
   "You are in an isolated git worktree. First run `git checkout -q --detach <BASE>`. Do ONLY subtask P<n> from <plan file> — read the plan from the main repo path `${CLAUDE_PROJECT_DIR}/<plan file>`. Touch only its listed files. When done run `git add -A` (do not commit) and include `WORKTREE: <output of pwd>` in your reply."
3. **Collect** — for each worker with a WORKTREE path, in the main repo run:
   `git -C "<worktree>" diff --cached --binary <BASE> > .claude/orch/p<n>.patch` then `git apply --3way .claude/orch/p<n>.patch`.
   If a patch fails to apply, note it as open and continue with the others.
4. **Review** — `orch:reviewer`(s) per Team size with the task and the union of CHANGED/NEW files. On CHANGES_REQUESTED, send CRITICAL items to one `orch:worker` (no isolation, in the main repo) and review again. Max 2 review rounds.
5. **Clean up worktrees** — for each worker path: `git worktree remove --force "<worktree>"`; then `git worktree prune`.
6. **Log end** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<last VERDICT> --rounds=<n> --categories=<from reviews or none> --models=<agent:model,… as used> --skills=<union of the agents' USED lines, comma list, or none> --summary="<N subtasks, M applied>"`.
7. **Finish** — max 8 lines (user's language): subtasks and their result, applied patches, verdict, open items, skills used (one line), run line. Do not commit.
