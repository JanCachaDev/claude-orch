# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions follow [SemVer](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/JanCachaDev/claude-orch/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/JanCachaDev/claude-orch/releases/tag/v0.1.0
