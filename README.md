# claude-orch

[![CI](https://github.com/JanCachaDev/claude-orch/actions/workflows/ci.yml/badge.svg)](https://github.com/JanCachaDev/claude-orch/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/JanCachaDev/claude-orch)](https://github.com/JanCachaDev/claude-orch/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A token-efficient agent orchestration plugin for [Claude Code](https://code.claude.com).

- **`/orch:run <task>`**: runs planner → worker → reviewer with at most 2 review rounds
- **Automatic cleanup** of temp files that agents leave in your project. They are moved to a trash folder, never deleted outright.
- **`/orch:stats [days]`**: token usage per agent and per model, plus files written and cleaned up
- **`/orch:cleanup`**: manual cleanup with a preview
- **Optional tests on Stop** (opt-in): when tests fail, Claude gets only the last 30 lines of output

## Requirements

`php` (8.0+) and `git` in `PATH`. Hooks run as `php -n`, which skips `php.ini` and Xdebug, so they start fast.

## Install

```
claude plugin marketplace add JanCachaDev/claude-orch
claude plugin install orch@claude-orch
```

Inside a running session, use `/plugin marketplace add JanCachaDev/claude-orch` and then `/plugin install orch@claude-orch`.

Update to the latest release:

```
claude plugin marketplace update claude-orch
claude plugin update orch@claude-orch
```

## Agents

| agent | model | tools | role |
|---|---|---|---|
| `orch:planner` | sonnet, effort medium | Read, Grep, Glob, Write | writes a plan of at most 40 lines to `.claude/orch/plan.md` |
| `orch:worker` | sonnet, effort medium | Read, Edit, Write, Grep, Glob, Bash | implements the change and replies in at most 10 lines |
| `orch:reviewer` | haiku, effort low, no CLAUDE.md | read-only | reviews `git diff` and returns a `VERDICT` |

**Where the tokens are saved:**
- The main thread never reads code itself.
- Agents pass file paths to each other, not file contents.
- Every agent replies in a fixed, short format.
- `maxTurns` caps how long each agent can run.
- Review is limited to 2 rounds.
- Tests and cleanup run as hooks, which cost zero tokens.

## Temp file cleanup

- `PostToolUse(Write)` logs every file an agent writes to `.claude/orch/files.log`, with the time, session and agent.
- `SessionEnd` moves a file to `.claude/orch/trash/<time>/` only when **all** of these hold:
  - its name matches a pattern: `tmp_*`, `temp_*`, `*.tmp`, `scratch*`, `debug_*`, `*.bak`, `*.orig`, `test_output*`
  - it is **not tracked by git**
  - it is not in a protected dir: `.git`, `.claude`, `.idea`, `vendor`, `node_modules`
  - it was created in this session, either written via Write or an untracked file modified since the session started. The second case also catches files created via Bash.
- Trash folders older than 7 days are purged automatically.
- `.claude/orch/` contains its own `.gitignore`, so it never gets committed.

To restore a file, move it back out of `.claude/orch/trash/…`.

## Per-project config (optional): `.claude/orch.json`

```json
{
  "patterns": ["tmp_*", "debug_*", "*.tmp", "*.bak"],
  "protectedDirs": [".git", ".claude", "vendor", "node_modules", "storage"],
  "scanUntracked": true,
  "trashDays": 7,
  "testCommand": "vendor/bin/phpunit --colors=never",
  "testTailLines": 30
}
```

Any key you leave out keeps its default. `testCommand` is off by default. When set, tests run on every Stop while the working tree has changes.

## Tip: block reading junk

Add this to your project's `.claude/settings.json`:

```json
{
  "permissions": {
    "deny": [
      "Read(./vendor/**)", "Read(./node_modules/**)", "Read(./var/cache/**)",
      "Read(./storage/logs/**)", "Read(./**/*.min.js)", "Read(./**/*.lock)"
    ]
  }
}
```

## Development

```
claude --plugin-dir ./plugins/orch   # try the plugin without installing
bash tests/hooks-test.sh             # hook tests
claude plugin validate .             # manifest check
```

### Releasing

1. Bump `version` in `plugins/orch/.claude-plugin/plugin.json`.
2. Move `[Unreleased]` items in `CHANGELOG.md` under the new version.
3. Commit, then run `git tag vX.Y.Z` and `git push --follow-tags`.

The tag triggers the Release workflow. It checks that the tag matches `plugin.json`, runs the tests and publishes a GitHub release with notes taken from the changelog.

## Layout

```
.claude-plugin/marketplace.json   marketplace "claude-orch"
plugins/orch/
  .claude-plugin/plugin.json
  agents/    planner.md, worker.md, reviewer.md
  skills/    run/, cleanup/, stats/   → /orch:run, /orch:cleanup, /orch:stats
  hooks/hooks.json
  scripts/   *.php
tests/hooks-test.sh
```

## Limitations

- `stats.php` reads Claude Code's internal `.jsonl` transcripts, and their format may change between versions.
- Files created via Bash are only cleaned up inside a git repo, because they are found through `git ls-files --others`.
