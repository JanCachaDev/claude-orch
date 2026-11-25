---
name: bugfix
description: Bugfix team — reproduce, minimal fix, regression test, review. No planner.
argument-hint: <bug description, error message or failing test>
disable-model-invocation: true
---
Fix this bug with subagents: **$ARGUMENTS**

If the description is empty, ask for it and stop.

Orchestrator rules: don't read or edit project files yourself, pass file paths not contents, no restating agent replies.

**Model and effort** — before step 1, rate the task and pass `model` and `effort` on every Agent call (they override the agent's defaults). Never pass `effort` to an agent running on `haiku` (omit it).
- **simple** (rename, typo, docs/comments, config value, one obvious edit): planner skipped, worker `haiku`, reviewer `haiku` (no effort for either).
- **normal** (feature or bug in a few files, clear requirements): planner `sonnet`/`medium`, worker `sonnet`/`medium`, reviewer `haiku` (no effort).
- **hard** (cross-module design, concurrency, security/auth, payments, data migrations, unclear root cause, >5 files): planner `opus`/`high`, worker `sonnet`/`high` (`opus`/`high` if the plan says the change is intricate), reviewer `sonnet`/`medium`.
- **Escalate once**: if review round 1 returns CRITICAL items, the fixing worker goes one level up in model (haiku → sonnet → opus) and effort (haiku without effort → sonnet/`low`, then low → medium → high). Never downgrade mid-run, never use opus for the reviewer, never exceed `medium` effort for the reviewer, never pick `xhigh`/`max` automatically (only via override).
- If `.claude/orch.json` has `"models"` (e.g. `{"worker": "sonnet"}`) or `"efforts"` (e.g. `{"worker": "high"}`), those values win (still no effort on haiku). Tell the user the chosen level in one line, e.g. `Úroveň: normal (planner sonnet/medium, worker sonnet/medium, reviewer haiku)`.

**Team size** — scale the team with the level; caps from `.claude/orch.json` `"team"` (defaults `{"planners": 2, "workers": 4, "reviewers": 3}`). Parallel calls of one kind go in ONE message.
- No planner. Always 1 worker. Reviewers: **simple** 1 (correctness); **normal** 1, +1 `Focus: security` if the fix touches auth, input handling, SQL or secrets; **hard** up to the `team` cap (default 3) lenses in parallel.
- Reviewers get `Focus: <lens>` (correctness | security | tests-and-conventions). APPROVED only when all approve; the fixing worker gets the combined CRITICAL items; round 2 reruns only reviewers that requested changes. Still max 2 review rounds.
- Level line includes counts, e.g. `Úroveň: normal — 1× worker sonnet/medium, 2× reviewer haiku`.

0. **Log start** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=bugfix --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="<bug, max 80 chars, no double quotes>"` → remember the run id. Send this Bash call in the SAME message as the Agent call of step 1, not before it — the run id is only needed at Log end, so this saves one round trip.
1. **Fix** — call `orch:worker` with:
   "Bug: <description>. 1) Reproduce it first (failing test or exact command) — if you cannot reproduce, stop and report what you tried. 2) Find the root cause, not a workaround. 3) Make the minimal fix. 4) Add or extend a regression test when the project has tests. 5) Run the relevant tests."
2. **Review** — call `orch:reviewer` (one per lens per Team size) with: "Bugfix for: <description>. Check the fix addresses the root cause and a regression test exists if the project has tests." plus the worker's file list.
3. **Fix loop** — on CHANGES_REQUESTED, send only the CRITICAL items to `orch:worker`, review again. Max 2 review rounds.
4. **Log end** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<last VERDICT, or ABORTED if not reproducible> --rounds=<n> --categories=<from reviews or none> --models=<agent:model,… as used> --efforts=<agent:effort,… only agents that got one> --skills=<union of the agents' USED lines, comma list, or none> --summary="<root cause in one line>"`.
5. **Finish** — max 8 lines to the user (their language): root cause, fix (files), test result, verdict, skills used (one line), run line from step 4. Do not commit.
