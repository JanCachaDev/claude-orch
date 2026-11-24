# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions follow [SemVer](https://semver.org/).

## [Unreleased]

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
