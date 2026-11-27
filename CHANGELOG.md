# Changelog

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versions follow [SemVer](https://semver.org/).

## [Unreleased]

## [0.6.0] - 2026-11-27

### Added
- `/orch:plan`, `/orch:review` and `/orch:fix`, usable by the user and by agents (no `disable-model-invocation`). Each has a delegated mode (Agent tool: `orch:planner`, `orch:reviewer` lenses plus `orch:db-optimizer` with `--db`, `orch:worker`) and an `--inline` mode for subagents, which cannot spawn subagents. Inline review is read-only. `/orch:plan` writes `.claude/orch/plan-<session>.md`; `/orch:review [files | --staged] [--db]` has no worker; `/orch:fix [run id]` takes the OPEN items of the last run, runs one review round and logs with `mode=fix`. Their `allowed-tools` pre-approve only the plugin's own scripts (`php -n "${CLAUDE_PLUGIN_ROOT}/scripts/…`), `git status` and `git diff`; `/orch:fix` proceeds only for a run with OPEN items, and inline it edits only the files those items concern.
- `/orch:run` and `/orch:backend` accept `--plan=<file>` and skip planning; all four orchestrators accept the inline level overrides `--simple`, `--hard`, `--model=<model>` and `--no-review`. `orch:worker` may call `orch:plan --inline` (no plan, more than 3 files), `orch:review --inline` (prompt says `Self-review: yes`) and `orch:fix --inline <id>` (prompt says "Continue run <id>").
- `config.php --project=<dir> [--git]`: one line with models, efforts, team, test command and `invalid-json:<error>` (with `--git` also `git=clean|dirty|none head=<sha>`), injected into the orchestration skills so the orchestrator no longer reads `.claude/orch.json`.
- `run-log.php last [--json] [--session=<id>]` prints the last finished run (id, verdict, mode, task, OPEN items, plan file). `run-log.php end` takes `--open="..."` and derives the models and the used skills from the subagent transcripts (`--models`, `--efforts`, `--skills` stay as optional overrides).
- `parallel-collect.php --base=<sha> --worktrees=P1=<path>,...` collects `/orch:parallel` workers in one step: diff against the base, a trial `git apply --3way` on a temporary index, the real apply, then worktree and branch removal; one `Pn applied|failed` line each. A failure says that patch and worktree are kept and after how many days (`trashDays`) cleanup deletes the patch; when the main tree already has uncommitted changes in a file the patch touches, it fails early with "commit or stash … first".
- `tools/levels.md` is the single source of the shared Model/effort and Team block; `tools/sync-levels.php` writes it into every skill between `<!-- orch:levels -->` markers and `--check` fails on drift (also in CI).
- Config keys: `timezone` (IANA name for "today" and "this week", `TZ` wins, `/orch:init` writes the detected one) and `lang` (`"auto"` | `"cs"` | `"en"`, default `auto`): the language of the messages printed by scripts and hooks. `auto` detects it per session from the user's prompts (Czech diacritics and common Czech words → `cs`, otherwise `en`; falls back to `cs`). Skills and agents already reply in the user's language.
- Guard rules: `git checkout .` / `git checkout HEAD .`, `git restore` of the whole tree, `git checkout|switch --force` and PowerShell `Remove-Item -Recurse` on the project root. The whole-tree pathspecs `:/`, `:/.` and `:(top)` count like `.`, and the destructive git rules also match with `git -C <dir>` / `git -c k=v` before the subcommand. An invalid user regex in `guard.block` / `guard.allow` is ignored and logged once to `guard.log`.
- Storage hygiene: cleanup removes old plans (`plan-*.md`), patches (`p*.patch`) and stale session markers, trims `files.log` to `trashDays`, caps the other logs and `usage-cache.json` at about 1 MB, runs `git worktree prune` and (in `/orch:cleanup`) lists leftover worktrees, which it never deletes; `prune.log` is capped. `/orch:stats` prints a `Úložiště:` line.
- README: "How a run flows", Troubleshooting, Storage, commands usable by agents, the worktree limitation.

### Changed
- Round-1 fixes go to the same worker via `SendMessage`; a new, stronger worker is used only in round 2 or for CRITICAL security/bug items from a haiku worker. Reviewers get the worker's `VERIFIED` line and rerun tests only when it says "not run" (or for the tests-and-conventions lens). The default reviewer is haiku without effort (the `reviewer` agent no longer pins `effort`). The DB optimizer is skipped when no database code changed and runs on sonnet/low.
- Agents load at most 1-2 skills, only on a clear match, never meta or process skills; the orchestration skills drop duplicated text and share one level block.
- Parallel runs use `Self-review: yes`, no full suite in worktrees and one test run after the patches are applied. The plan file is `.claude/orch/plan-<session>.md`. The finish step suggests one conventional commit message.
- Usage parsing is incremental: `run-log start` stores the transcript sizes, `end` reads only what came after, and `usage-cache.json` (per-file size, mtime and per-day sums) is shared with `budget.php`; a file that shrank is parsed again from 0.
- The Stop-hook test run is fingerprinted (`state/tests.json`): an unchanged working tree is not tested again after a green run (a red run always reruns on the next Stop). The fingerprint reads `git status --porcelain -z` with `core.quotepath=false`, so non-ASCII and renamed paths count correctly. `hooks.json` has explicit timeouts (10 s, the test run 600 s). `/orch:init` writes `php vendor/bin/phpunit` / `php vendor/bin/pest` so the command also works on Windows.
- Hook tests smoke-test every hook; CI runs PHP 8.0, 8.2 and 8.4 on Linux, Windows and macOS, lints all scripts and runs `php -n tools/sync-levels.php --check`.

### Fixed
- Audit bugs: text cut inside multibyte UTF-8 characters (`orch_cut`), time zones (`orch_tz`: "today" no longer starts at 01:00 or 02:00), invalid UTF-8 breaking JSON output (`JSON_INVALID_UTF8_SUBSTITUTE`), shell quoting in git calls (`proc_open` with an argument list), a repeated test run for the same tree in the Stop hook, and a broken `.claude/orch.json` being silently ignored (now reported at session start and in the `Config:` line).
- The context-band warning is announced again after a compaction.

### Skipped
- Title cache for sessions (low value).
- Merging the two process spawns in `stop-tests.php` (the fingerprint rewrite changes that code anyway).
- `/orch:ui` command.
- Translating the Czech script output wholesale: `lang` only chooses between the cs and en message sets, and the skills keep replying in the user's language.

## [0.5.2] - 2026-11-25

### Added
- The orchestrator now picks a reasoning `effort` together with the `model` for each agent call (by level and role, escalated with the fixing worker, omitted for haiku). Override with `"efforts"` in `.claude/orch.json`; `run-log.php end --efforts=agent:effort,…` stores it in `runs.log` and `/orch:stats` shows it next to the model.
- Context warning on every prompt (`UserPromptSubmit`): when the main session's context is at least `contextWarn` tokens (default 150000, `false` = off) it warns that an expired cache will be rewritten (with a cost estimate) or, while warm, once per 100k above the limit. Opt-in `"contextBlock": true` blocks the first prompt sent to a cold, large session until `/compact`. Only the last 512 KB of the transcript is read.
- The named-session hint at session start shows the session's last context size and advises `/compact` after `--resume` when it is at least `contextWarn`.
- `/orch:stats` and `/orch:global` print a `Studená cache` line (turns that rewrote 50k+ tokens, their cost and share of spend, average main context); `--json` has `cold` and `avg_ctx`.
- Docs: README and `/orch:help` recommend starting `/orch:*` in a fresh or compacted session and Claude Code's own `"autoCompactWindow": 200000` (minimum 100000) in `~/.claude/settings.json`, which auto-compacts at that context size and is the main saving; the orch warnings cover what is left.

### Changed
- `Log start` of `/orch:run`, `/orch:backend`, `/orch:bugfix` and `/orch:parallel` is sent in the same message as the first agent call (the run id is only needed at log end), saving one main-session call per run.

### Fixed
- `run-log.php end --models=orch:planner:sonnet` logged the agent as `orch`; agent and value are now split on the last colon.

## [0.5.1] - 2026-11-25

### Added
- `/orch:version` (alias `/orch:v`): the orch version this session runs, the installed one and the newest on GitHub, with the update or `/reload-plugins` step to take (`version.php --json`, `--offline`).
- On the first prompt of a session that runs an older orch than the installed one, a one-time hint to run `/reload-plugins`.

### Changed
- README and `/orch:help`: updating now ends with `/reload-plugins` in sessions that are already open.

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

[Unreleased]: https://github.com/JanCachaDev/claude-orch/compare/v0.5.2...HEAD
[0.5.2]: https://github.com/JanCachaDev/claude-orch/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/JanCachaDev/claude-orch/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/JanCachaDev/claude-orch/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/JanCachaDev/claude-orch/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/JanCachaDev/claude-orch/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/JanCachaDev/claude-orch/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/JanCachaDev/claude-orch/releases/tag/v0.1.0
