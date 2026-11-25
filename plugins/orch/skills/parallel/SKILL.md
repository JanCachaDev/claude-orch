---
name: parallel
description: Split a task into independent subtasks and run several workers at once, each in its own git worktree; then apply their patches and review.
argument-hint: <task that splits into independent parts>
disable-model-invocation: true
---
Run this task with parallel workers: **$ARGUMENTS**

Parallel workers are faster but cost more tokens than /orch:run (each worker has its own context). If the task is small, tell the user /orch:run is a better fit and stop.

Orchestrator rules: don't read or edit project files yourself, pass file paths not contents, no restating agent replies.

**Model and effort** — before step 1, rate the task and pass `model` and `effort` on every Agent call (they override the agent's defaults). Never pass `effort` to an agent running on `haiku` (omit it).
- **simple** (rename, typo, docs/comments, config value, one obvious edit): planner skipped, worker `haiku`, reviewer `haiku` (no effort for either).
- **normal** (feature or bug in a few files, clear requirements): planner `sonnet`/`medium`, worker `sonnet`/`medium`, reviewer `haiku` (no effort).
- **hard** (cross-module design, concurrency, security/auth, payments, data migrations, unclear root cause, >5 files): planner `opus`/`high`, worker `sonnet`/`high` (`opus`/`high` if the plan says the change is intricate), reviewer `sonnet`/`medium`.
- **Escalate once**: if review round 1 returns CRITICAL items, the fixing worker goes one level up in model (haiku → sonnet → opus) and effort (haiku without effort → sonnet/`low`, then low → medium → high). Never downgrade mid-run, never use opus for the reviewer, never exceed `medium` effort for the reviewer, never pick `xhigh`/`max` automatically (only via override).
- If `.claude/orch.json` has `"models"` (e.g. `{"worker": "sonnet"}`) or `"efforts"` (e.g. `{"worker": "high"}`), those values win (still no effort on haiku). Tell the user the chosen level in one line, e.g. `Úroveň: normal (planner sonnet/medium, worker sonnet/medium, reviewer haiku)`.

**Team size** — scale the team with the level; caps from `.claude/orch.json` `"team"` (defaults `{"planners": 2, "workers": 4, "reviewers": 3}`). Parallel calls of one kind go in ONE message.
- Planner: 1 (the split is its `## Parallel` section); in **hard** with clearly separate areas up to the `team` cap (default 2), each told `Write your plan to .claude/orch/plan-<area>.md, cover only <area>` and reporting its file. Workers: one per subtask with disjoint files (max cap), each in its own worktree as below; overlapping subtasks run sequentially. Reviewers: **simple/normal** 1 (+1 `Focus: security` when sensitive, normal); **hard** up to the `team` cap (default 3) lenses in parallel.
- Reviewers get `Focus: <lens>` (correctness | security | tests-and-conventions). APPROVED only when all approve; the fixing worker gets the combined CRITICAL items; round 2 reruns only reviewers that requested changes. Still max 2 review rounds.
- Level line includes counts, e.g. `Úroveň: hard — 2× planner opus/high, 2× worker sonnet/high, 3× reviewer sonnet/medium`.

0. **Preconditions** — run `git status --porcelain` and `git rev-parse HEAD`.
   - Not a git repo or no commit yet → tell the user and stop.
   - Dirty working tree → stop and tell the user: workers start from the last commit, so commit or stash first.
   - Remember HEAD as BASE. Log start: `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=parallel --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="<task, max 80 chars, no double quotes>"` → run id. Send this Bash call in the SAME message as the first Agent call of step 1 (not before it) — the run id is only needed at Log end.
1. **Plan** — `orch:planner` with the task and "Make a parallel plan (## Parallel section)". With two planners, number subtasks P1..Pn continuously across both plans and remember each subtask's plan file (default `.claude/orch/plan.md`; patches stay `p<n>.patch`). If it says not splittable, continue as /orch:run would (single worker, no isolation).
2. **Work in parallel** — in ONE message, call `orch:worker` once per subtask with `isolation: "worktree"`. Prompt each:
   "You are in an isolated git worktree. First run `git checkout -q --detach <BASE>`. Do ONLY subtask P<n> from <plan file> — read the plan from the main repo path `${CLAUDE_PROJECT_DIR}/<plan file>`. Touch only its listed files. When done run `git add -A` (do not commit) and include `WORKTREE: <output of pwd>` in your reply."
3. **Collect** — for each worker with a WORKTREE path, in the main repo run:
   `git -C "<worktree>" diff --cached --binary <BASE> > .claude/orch/p<n>.patch` then `git apply --3way .claude/orch/p<n>.patch`.
   If a patch fails to apply, note it as open and continue with the others.
4. **Review** — `orch:reviewer`(s) per Team size with the task and the union of CHANGED/NEW files. On CHANGES_REQUESTED, send CRITICAL items to one `orch:worker` (no isolation, in the main repo) and review again. Max 2 review rounds.
5. **Clean up worktrees** — for each worker path: `git worktree remove --force "<worktree>"`; then `git worktree prune`.
6. **Log end** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<last VERDICT> --rounds=<n> --categories=<from reviews or none> --models=<agent:model,… as used> --efforts=<agent:effort,… only agents that got one> --skills=<union of the agents' USED lines, comma list, or none> --summary="<N subtasks, M applied>"`.
7. **Finish** — max 8 lines (user's language): subtasks and their result, applied patches, verdict, open items, skills used (one line), run line. Do not commit.
