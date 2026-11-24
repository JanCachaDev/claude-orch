# claude-orch

[![CI](https://github.com/JanCachaDev/claude-orch/actions/workflows/ci.yml/badge.svg)](https://github.com/JanCachaDev/claude-orch/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/JanCachaDev/claude-orch)](https://github.com/JanCachaDev/claude-orch/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A token-efficient agent orchestration plugin for [Claude Code](https://code.claude.com). It splits work across cheap, focused subagents, guards against destructive commands, cleans up after agents, tracks tokens and estimated cost, and pings you on Discord or WhatsApp.

| Command | What it does |
|---|---|
| `/orch:run <task>` | planner → worker → reviewer, with at most 2 review rounds |
| `/orch:bugfix <bug>` | reproduce → root cause → minimal fix + regression test → review (no planner) |
| `/orch:backend <task>` | planner → worker → reviewer **and** DB optimizer in parallel (N+1 queries, indexes, caching) |
| `/orch:parallel <task>` | the planner splits the task into 2–4 independent parts, workers run at the same time in separate git worktrees, then their patches are applied and reviewed |
| `/orch:stats [days]` | tokens and **estimated USD** per agent and model, past runs, review stats, files, cleanup, guard (`stats.php --json` for machine-readable output) |
| `/orch:init` | sets up the project: `.claude/orch.json` with a detected test command, plus deny rules for vendor/, node_modules/ and lock files |
| `/orch:cleanup` | manual temp-file cleanup with a preview |
| `/orch:restore [file]` | restores a file from the orch trash |
| `/orch:notify-test` | sends a test notification |
| `/orch:help [command]` | lists all commands with examples and when to use which |

These run automatically as hooks, which cost zero tokens:

- **Guard** (`PreToolUse`): blocks commands such as `rm -rf /`, `git push --force`, `git reset --hard`, `git clean -f`, `DROP TABLE`, `DELETE` without `WHERE`, and `curl … | sh`.
- **File tracking** (`PostToolUse`) and **temp cleanup** (`SessionEnd`), with a 7-day trash.
- **Tests on Stop** (opt-in): when tests fail, Claude gets only the last 30 lines of output.
- **Notifications** (async): Discord and/or WhatsApp when a `/orch:*` run finishes, when a long turn ends, or when Claude is waiting for your permission.

## Requirements

`php` 8.0+, `git` and `curl` in `PATH`. Windows 10+ ships `curl`. Hooks run as `php -n`, which skips php.ini and Xdebug, so they start fast.

## Install

```
claude plugin marketplace add JanCachaDev/claude-orch
claude plugin install orch@claude-orch
```

Update:

```
claude plugin marketplace update claude-orch
claude plugin update orch@claude-orch
```

Then, in each project, run `/orch:init` once (optional, recommended).

## Agents

| agent | model | tools | role |
|---|---|---|---|
| `orch:planner` | sonnet, effort medium | Read, Grep, Glob, Write | writes `.claude/orch/plan.md` (≤ 40 lines), including a parallel split on request |
| `orch:worker` | sonnet, effort medium | Read, Edit, Write, Grep, Glob, Bash | implements the change; replies in ≤ 10 lines |
| `orch:reviewer` | haiku, effort low, no CLAUDE.md | read-only | reviews `git diff` and returns `CATEGORIES` and `VERDICT` |
| `orch:db-optimizer` | sonnet, effort low, no CLAUDE.md | read-only | N+1 queries, indexes, unbounded queries, transactions, caching |

**Where the tokens are saved:**
- The orchestrator never reads code itself.
- Agents pass file paths, not file contents.
- Replies use a fixed, short format.
- `maxTurns` caps each agent, and review is limited to 2 rounds.
- Deterministic work (tests, cleanup, guard, notifications) runs in hooks, not in the model.

Every run is logged to `.claude/orch/runs.log` with its duration, tokens, USD, verdict, review rounds and finding categories. `/orch:stats` uses this log to show the first-try approval rate and the most common review findings, which tells you where your prompts or code need work.

## Notifications (Discord / WhatsApp)

Credentials live in the plugin's **user config**. Claude Code keeps sensitive values in your OS credential store, never in the repo. Set them in `/plugin` → `orch` → **Configure**:

| option | value |
|---|---|
| `discord_webhook_url` | Discord: Server Settings → Integrations → Webhooks → New Webhook → Copy URL |
| `whatsapp_provider` | `callmebot` (free, personal use) or `twilio` |
| `whatsapp_phone` | your number, e.g. `+420123456789` |
| `whatsapp_apikey` | CallMeBot apikey, or Twilio `ACCOUNT_SID:AUTH_TOKEN` |
| `whatsapp_from` | Twilio only: sender number (sandbox `+14155238886`) |
| `notify_min_seconds` | "Claude finished" is sent only for turns at least this long (default 60) |

**CallMeBot (free) setup:**
1. Add the CallMeBot number shown on [callmebot.com → WhatsApp API](https://www.callmebot.com/blog/free-api-whatsapp-messages/) to your contacts.
2. Send it the activation message the page tells you to send.
3. You get your apikey back on WhatsApp.

Run `/orch:notify-test` to check the setup. Delivery results are logged to `.claude/orch/notify.log`, without secrets. To turn notifications off in one project, put `{"notify": false}` in `.claude/orch.json`.

## Temp file cleanup

A file is moved to `.claude/orch/trash/<time>/` at `SessionEnd` only when **all** of these hold:

- its name matches a pattern: `tmp_*`, `temp_*`, `*.tmp`, `scratch*`, `debug_*`, `*.bak`, `*.orig`, `test_output*`
- it is **not tracked by git**
- it is not in a protected dir: `.git`, `.claude`, `.idea`, `vendor`, `node_modules`
- it was created in this session, either written via Write or an untracked file changed since the session started

The trash is purged after 7 days. Use `/orch:restore` to bring a file back.

## Guard

The built-in rules are in [`guard.php`](plugins/orch/scripts/guard.php). When the guard blocks a command, Claude gets a one-line reason and the block is logged to `.claude/orch/guard.log`. You can extend or relax the rules per project:

```json
{ "guard": { "enabled": true, "block": ["\\bnpm\\s+publish\\b"], "allow": ["^git push --force origin my-branch$"] } }
```

`allow` takes precedence over `block`. Rules are case-insensitive PCRE patterns without delimiters. To test one: `php -n plugins/orch/scripts/guard.php --check="git push -f"`.

## Per-project config: `.claude/orch.json`

```json
{
  "patterns": ["tmp_*", "debug_*", "*.tmp", "*.bak"],
  "protectedDirs": [".git", ".claude", "vendor", "node_modules", "storage"],
  "scanUntracked": true,
  "trashDays": 7,
  "testCommand": "vendor/bin/phpunit --colors=never",
  "testTailLines": 30,
  "notify": true,
  "guard": { "enabled": true, "block": [], "allow": [] },
  "prices": { "claude-opus-5-5": [4.0, 20.0, 0.20] }
}
```

Any key you leave out keeps its default. `prices` overrides the built-in USD per 1M tokens as `[input, output, cache read]`; cache writes are billed at 1.25× input (5-minute cache) or 2× input (1-hour cache). On a Pro or Max subscription you don't pay per token, so read the USD figure as a relative cost measure.

## Development

```
claude --plugin-dir ./plugins/orch   # try local changes without installing
bash tests/hooks-test.sh             # hook tests (also run in CI on Linux + Windows)
claude plugin validate .             # manifest check
```

### Releasing

1. Bump `version` in `plugins/orch/.claude-plugin/plugin.json`.
2. Move the `[Unreleased]` items in `CHANGELOG.md` under the new version.
3. Commit, then run `git tag vX.Y.Z` and `git push --follow-tags`.

The tag triggers the Release workflow. It checks the version, runs the tests and publishes a GitHub release with notes from the changelog.

## Limitations

- Stats read Claude Code's internal `.jsonl` transcripts, and their format may change between versions.
- Files created via Bash are only cleaned up inside a git repo.
- The guard is a safety net, not a sandbox. It matches command text, so an obfuscated command can get past it.
- `/orch:parallel` needs a clean working tree, because workers start from the last commit.
