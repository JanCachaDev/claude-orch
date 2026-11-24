---
name: parallel
description: Split a task into independent subtasks and run several workers at once, each in its own git worktree; then apply their patches and review.
argument-hint: <task that splits into independent parts>
disable-model-invocation: true
---
Run this task with parallel workers: **$ARGUMENTS**

Parallel workers are faster but cost more tokens than /orch:run (each worker has its own context). If the task is small, tell the user /orch:run is a better fit and stop.

Orchestrator rules: don't read or edit project files yourself, pass file paths not contents, no restating agent replies.

0. **Preconditions** — run `git status --porcelain` and `git rev-parse HEAD`.
   - Not a git repo or no commit yet → tell the user and stop.
   - Dirty working tree → stop and tell the user: workers start from the last commit, so commit or stash first.
   - Remember HEAD as BASE. Log start: `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=parallel --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="<task, max 80 chars, no double quotes>"` → run id.
1. **Plan** — `orch:planner` with the task and "Make a parallel plan (## Parallel section)". If it says not splittable, continue as /orch:run would (single worker, no isolation).
2. **Work in parallel** — in ONE message, call `orch:worker` once per subtask with `isolation: "worktree"`. Prompt each:
   "You are in an isolated git worktree. First run `git checkout -q --detach <BASE>`. Do ONLY subtask P<n> from .claude/orch/plan.md — read the plan from the main repo path `${CLAUDE_PROJECT_DIR}/.claude/orch/plan.md`. Touch only its listed files. When done run `git add -A` (do not commit) and include `WORKTREE: <output of pwd>` in your reply."
3. **Collect** — for each worker with a WORKTREE path, in the main repo run:
   `git -C "<worktree>" diff --cached --binary <BASE> > .claude/orch/p<n>.patch` then `git apply --3way .claude/orch/p<n>.patch`.
   If a patch fails to apply, note it as open and continue with the others.
4. **Review** — `orch:reviewer` with the task and the union of CHANGED/NEW files. On CHANGES_REQUESTED, send CRITICAL items to one `orch:worker` (no isolation, in the main repo) and review again. Max 2 review rounds.
5. **Clean up worktrees** — for each worker path: `git worktree remove --force "<worktree>"`; then `git worktree prune`.
6. **Log end** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<last VERDICT> --rounds=<n> --categories=<from reviews or none> --summary="<N subtasks, M applied>"`.
7. **Finish** — max 8 lines (user's language): subtasks and their result, applied patches, verdict, open items, run line. Do not commit.
