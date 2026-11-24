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

Steps:
1. **Plan** — if the task is trivial (one obvious change in 1–2 files), skip this step. Otherwise call subagent `orch:planner` with the task. If it reports a decision the user must make, ask the user and stop.
2. **Implement** — call `orch:worker` with the task, and "Follow .claude/orch/plan.md" if a plan exists.
3. **Review** — call `orch:reviewer` with the task (one line) and the CHANGED/NEW file list from the worker.
4. **Fix loop** — if VERDICT is CHANGES_REQUESTED, call `orch:worker` with only the CRITICAL items, then review again. **Maximum 2 review rounds total.** After that, stop and report what is still open.
5. **Finish** — reply to the user (in their language) with max 8 lines: what changed (files), verification result, review verdict, open items. Do not commit.
