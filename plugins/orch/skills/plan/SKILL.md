---
name: plan
description: Write a short implementation plan for a coding task without implementing it. Use when the user asks to plan, design or break down a task first. Result is a plan file that /orch:run --plan=<file> can execute.
argument-hint: "<task> [--inline]"
allowed-tools: Bash(php -n "${CLAUDE_PLUGIN_ROOT}/scripts/*)
---
Plan this task (do not implement it): **$ARGUMENTS**

Config: !`php -n "${CLAUDE_PLUGIN_ROOT}/scripts/config.php" --project="${CLAUDE_PROJECT_DIR}"`
(If the line above is empty, run that command once yourself with Bash and use its output.)

If the task is empty, ask for it and stop.

**Mode** (decide in your first line, say it in 3 words):
- **delegated** — you have the Agent tool and `$ARGUMENTS` has no `--inline`. Do not read project files yourself; call subagent `orch:planner` once. Pick `model`/`effort` by difficulty (simple → `haiku`, no effort; normal → `sonnet`/`medium`; hard: cross-module design, security, migrations, unclear root cause → `opus`/`high`); `models`/`efforts` in the config line win. Pass the task (without `--inline`) and `Write your plan to .claude/orch/plan-${CLAUDE_SESSION_ID}.md`.
- **inline** — you have no Agent tool (you are a subagent), or `--inline` was given. Do the planning yourself in this context, in the same format as `orch:planner`: explore only what the task needs (Grep/Glob first, Read line ranges, never vendor/, node_modules/, lock files, build output), change no project file, and write only `.claude/orch/plan-${CLAUDE_SESSION_ID}.md`:
  - `Goal` (1 line)
  - `Steps`: numbered, each = file path + what to change (no code unless a signature matters)
  - `Verify`: exact command(s), if the project has any
  - `Risks`: max 3 real bullets
  - Keep it under 40 lines.

If the planner (or you) hits a decision only the user can make, say so and stop.

Reply (user's language) with max 5 lines: number of steps, files touched, decisions the user must make, the plan file path, and the next step: `/orch:run --plan=<file> <task>` (or, for an agent caller, "Follow <file>"). Inline mode ends with `USED: <skills/tools used, or none>`. Do not repeat the plan. Do not implement anything.
