---
name: bugfix
description: Bugfix team — reproduce, minimal fix, regression test, review. No planner.
argument-hint: <bug description, error message or failing test>
disable-model-invocation: true
---
Fix this bug with subagents: **$ARGUMENTS**

If the description is empty, ask for it and stop.

Orchestrator rules: don't read or edit project files yourself, pass file paths not contents, no restating agent replies.

0. **Log start** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" start --mode=bugfix --session=${CLAUDE_SESSION_ID} --project="${CLAUDE_PROJECT_DIR}" --task="<bug, max 80 chars, no double quotes>"` → remember the run id.
1. **Fix** — call `orch:worker` with:
   "Bug: <description>. 1) Reproduce it first (failing test or exact command) — if you cannot reproduce, stop and report what you tried. 2) Find the root cause, not a workaround. 3) Make the minimal fix. 4) Add or extend a regression test when the project has tests. 5) Run the relevant tests."
2. **Review** — call `orch:reviewer` with: "Bugfix for: <description>. Check the fix addresses the root cause and a regression test exists if the project has tests." plus the worker's file list.
3. **Fix loop** — on CHANGES_REQUESTED, send only the CRITICAL items to `orch:worker`, review again. Max 2 review rounds.
4. **Log end** — `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/run-log.php" end --id=<run id> --project="${CLAUDE_PROJECT_DIR}" --verdict=<last VERDICT, or ABORTED if not reproducible> --rounds=<n> --categories=<from reviews or none> --summary="<root cause in one line>"`.
5. **Finish** — max 8 lines to the user (their language): root cause, fix (files), test result, verdict, run line from step 4. Do not commit.
