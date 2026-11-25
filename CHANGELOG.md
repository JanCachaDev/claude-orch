# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions follow [SemVer](https://semver.org/).

## [Unreleased]

## [0.5.0] - 2026-11-25

### Added
- Agents use the project's skills, MCP and plugin tools and report a `USED:` line; `run-log.php end --skills=` stores it and `/orch:stats` prints "Použité skills".
- Team size scales with the task level in `/orch:run`, `/orch:backend`, `/orch:bugfix` and `/orch:parallel` (parallel planners, workers and reviewers with different focus lenses), capped by `team` in `.claude/orch.json`.
- Session start shows a hint with the project's named session and how to resume it (`claude --resume <id>`); turn off with `"sessionHint": false` in `.claude/orch.json`.
- `/orch:prune` never moves named sessions, job sessions or the current session, and moves short unnamed sessions (1-2 prompts) after 1 day.
- Automatic prune: async `SessionStart` hook `prune.php --auto` moves unused unnamed sessions once a day (userConfig `prune_days`, default 30, 0 = off; log in `~/.claude/orch-trash/prune.log`).
- Prune also moves a project's `memory/` to the trash when its last session is gone (restorable with the same batch).
- Note: Claude Code's own cleanup deletes old sessions including named ones; raise `cleanupPeriodDays` in settings (e.g. 3650) to keep them.

### Changed
- Agents no longer have a `tools:` allowlist: they inherit the project's tools, and read-only agents use `disallowedTools`.
- Agents now read `CLAUDE.md` (reviewer and db-optimizer previously skipped it), so token use per agent call is slightly higher.

## [0.4.0] - 2026-11-24

### Added
- Most expensive turns (one prompt plus everything it triggered, subagents included) in `/orch:stats` and `/orch:global`.
- Budget alerts: async `Stop` hook `budget.php` with daily/weekly USD limits (`budget` in `.claude/orch.json` or userConfig `budget_day_usd` / `budget_week_usd`). Sends one notification per period and shows a warning on the next prompt. `/orch:budget` shows the current spend.
- `/orch:prune [days]`: moves transcripts unused for N days from all projects to `~/.claude/orch-trash` (preview, 7-day trash, `--restore-last`).
- `/orch:help` covers the new commands.

### Changed
- The Discord/WhatsApp sender moved to `lib-notify.php`.

## [0.3.0] - 2026-11-24

### Added
- `/orch:global [days]`: disk usage of `~/.claude` and tokens/USD per project, agent and model across all sessions (`global.php --json` too).
- Per-task model choice in `/orch:run`, `/orch:bugfix`, `/orch:backend` and `/orch:parallel` (simple → haiku, normal → sonnet, hard → opus planner), with one-step escalation after a failed review and a `models` override in `.claude/orch.json`.
- `run-log.php end --models=agent:model,…`; `/orch:stats` shows the models used per run.

## [0.2.0] - 2026-11-24

### Added
- `/orch:help [command]`: overview of all commands with examples.
- `/orch:bugfix`, `/orch:backend` (with `orch:db-optimizer`) and `/orch:parallel` (git worktrees).
- Guard hook against destructive commands, configurable via `guard.block` / `guard.allow`.
- Discord / WhatsApp notifications (CallMeBot or Twilio), `/orch:notify-test`.
- Run journal `.claude/orch/runs.log`; `/orch:stats` shows estimated USD, runs and review stats.
- `stats.php --json` for machine-readable output.
- `/orch:init` and `/orch:restore`.

### Fixed
- Run cost is counted from the start of the user turn.
- `--categories=none` is no longer logged as a review finding category.

## [0.1.0] - 2026-11-24

### Added
- `/orch:run <task>`: planner → worker → reviewer orchestration with at most 2 review rounds.
- Agents `orch:planner` (sonnet), `orch:worker` (sonnet) and `orch:reviewer` (haiku, no CLAUDE.md).
- A `PostToolUse` hook that tracks files written by agents in `.claude/orch/files.log`.
- Automatic temp file cleanup on `SessionEnd` into `.claude/orch/trash/`, purged after 7 days.
- `/orch:cleanup`: manual cleanup with a preview.
- `/orch:stats [days]`: token usage per agent and model, plus file stats.
- Optional tests on the `Stop` hook (`testCommand` in `.claude/orch.json`).

[Unreleased]: https://github.com/JanCachaDev/claude-orch/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/JanCachaDev/claude-orch/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/JanCachaDev/claude-orch/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/JanCachaDev/claude-orch/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/JanCachaDev/claude-orch/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/JanCachaDev/claude-orch/releases/tag/v0.1.0
