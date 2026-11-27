# claude-orch

[![CI](https://github.com/JanCachaDev/claude-orch/actions/workflows/ci.yml/badge.svg)](https://github.com/JanCachaDev/claude-orch/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/JanCachaDev/claude-orch)](https://github.com/JanCachaDev/claude-orch/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A token-efficient agent orchestration plugin for [Claude Code](https://code.claude.com). It splits work across cheap, focused subagents, guards against destructive commands, cleans up after agents, tracks tokens and estimated cost, and pings you on Discord or WhatsApp.

| Command | What it does |
|---|---|
| `/orch:run <task>` | planner → worker → reviewer, with at most 2 review rounds; `--plan=<file>` runs an existing plan and skips planning |
| `/orch:bugfix <bug>` | reproduce → root cause → minimal fix + regression test → review (no planner) |
| `/orch:backend <task>` | planner → worker → reviewer **and** DB optimizer in parallel (N+1 queries, indexes, caching) |
| `/orch:parallel <task>` | the planner splits the task into 2–4 independent parts, workers run at the same time in separate git worktrees, then their patches are applied and reviewed |
| `/orch:plan <task>` | only plans: the planner writes `.claude/orch/plan-<session>.md`; run it with `/orch:run --plan=<file>`. Agents can call it too |
| `/orch:review [files \| --staged] [--db]` | read-only review of the uncommitted (or staged) changes, no worker; `--db` adds the DB optimizer. Agents can call it too |
| `/orch:fix [run id]` | fixes the open CRITICAL items of the last run (from `runs.log`), then one review round. Agents can call it too |
| `/orch:stats [days]` | tokens and **estimated USD** per agent and model, the **most expensive turns**, past runs (with models used), cold-cache rewrites and average context, review stats, files, cleanup, guard (`stats.php --json` for machine-readable output) |
| `/orch:global [days]` | machine-wide: disk used by `~/.claude` (transcripts, plugins, file history) and tokens + USD per project, agent and model, and the most expensive turns across all sessions |
| `/orch:prune [days]` | frees disk space: moves session transcripts unused for N days (default 30, min 7) from all projects to `~/.claude/orch-trash`, with a preview; named sessions and job sessions are never moved, short unnamed ones (1-2 prompts) go after 1 day; `--restore-last` undoes the last batch. Runs automatically once a day for unnamed sessions (userConfig `prune_days`, default 30, 0 = off). Claude Code's own cleanup deletes named sessions too, so raise `cleanupPeriodDays` (e.g. 3650) in settings |
| `/orch:budget` | this project's estimated spend today and this week against your limits |
| `/orch:init` | sets up the project: `.claude/orch.json` with a detected test command, plus deny rules for vendor/, node_modules/ and lock files |
| `/orch:cleanup` | manual temp-file cleanup with a preview |
| `/orch:restore [file]` | restores a file from the orch trash |
| `/orch:notify-test` | sends a test notification |
| `/orch:version` (`/orch:v`) | the orch version this session runs, the installed one and the newest on GitHub, and what to do when they differ |
| `/orch:help [command]` | lists all commands with examples and when to use which |

`/orch:run`, `/orch:backend`, `/orch:bugfix` and `/orch:parallel` also take inline overrides for the level: `--simple`, `--hard`, `--model=<model>` and `--no-review`. Every skill that picks models reads the project config (models, efforts, team, test command) from one injected line (`config.php`), so the orchestrator does not have to read `.claude/orch.json` itself.

## How a run flows

```
/orch:run <task>
   |
   v
 level: simple | normal | hard        (models/efforts/team from orch.json win)
   |
   +--> planner  --> plan-<session>.md          (skipped: simple, or --plan=<file>)
   |
   +--> worker(s) --> CHANGED / NEW / VERIFIED / OPEN / USED
   |
   +--> reviewer(s), one lens each --> CRITICAL, CATEGORIES, VERDICT
   |        |
   |        +-- CHANGES_REQUESTED --> fix (same worker via SendMessage) --> review round 2
   |
   v
 run-log end --> runs.log  --> /orch:stats, notification, one summary to you
```

`/orch:bugfix` skips the planner. `/orch:backend` adds the DB optimizer next to the reviewers. `/orch:parallel` always plans, runs workers in git worktrees, applies their patches in one step (`parallel-collect.php`), runs the tests once and then reviews. Whatever is still open after round 2 lands in `OPEN` and in `runs.log`; `/orch:fix` picks it up from there.

## Commands that agents can use

`/orch:plan`, `/orch:review` and `/orch:fix` are the only orch commands the model may invoke on its own (they have no `disable-model-invocation`). The expensive orchestrators (`run`, `backend`, `bugfix`, `parallel`) stay user-only.

Each of the three has two modes, chosen in its first line:

- **delegated**: the Agent tool is available and `--inline` is absent. The skill spawns `orch:planner`, `orch:reviewer` lenses (plus `orch:db-optimizer` with `--db`) or `orch:worker`.
- **inline**: no Agent tool (subagents cannot spawn subagents) or `--inline`. The skill does the work in the caller's own warm context, with the same checklist and reply shape as the agent. Inline review is read-only, even inside a worker.

In the main session you can simply say "plan this", "review my changes" or "finish the open items" and Claude calls the skill. A worker may call `orch:plan --inline` when it has no plan and the task touches more than 3 files, and `orch:review --inline` on its own diff when the prompt says `Self-review: yes` (parallel workers, where the real review comes late). `orch:fix` reads the OPEN items with `run-log.php last`; a worker told "Continue run <id>" calls it inline.

These run automatically as hooks, which cost zero tokens:

- **Guard** (`PreToolUse`): blocks commands such as `rm -rf /`, `git push --force`, `git reset --hard`, `git clean -f`, `git checkout .` / `git checkout HEAD .` / `git restore` of the whole tree, `git checkout -f`, PowerShell `Remove-Item -Recurse` on the project root, SQL `DROP`/`TRUNCATE`, `DELETE` without `WHERE`, and `curl … | sh`, also with `git -C <dir>` / `git -c k=v` before the subcommand and with `:/` as the pathspec. It is best-effort pattern matching, not a sandbox: an unusual or obfuscated command can get past it. An invalid regex in `guard.block` / `guard.allow` is ignored and logged once to `guard.log` instead of failing.
- **File tracking** (`PostToolUse`) and **temp cleanup** (`SessionEnd`), with a 7-day trash.
- **Tests on Stop** (opt-in): when tests fail, Claude gets only the last 30 lines of output. The result is remembered per working-tree fingerprint (`state/tests.json`), so an unchanged tree is not tested again.
- **Budget alerts** (async, `Stop`): when a project crosses its daily or weekly USD limit, you get one Discord/WhatsApp message per period and a warning on your next prompt. Set limits for all projects in `/plugin` → `orch` → Configure (`budget_day_usd`, `budget_week_usd`) or per project with `"budget": {"day": 10, "week": 50}`.
- **Session hint** (`SessionStart`): when the project has a named session, a one-line reminder with `claude --resume <id>`; turn off with `"sessionHint": false` in `.claude/orch.json`.
- **Auto-prune** (async, `SessionStart`): once a day moves unused unnamed sessions (default 30 days, `prune_days`, 0 = off) to `~/.claude/orch-trash`; named, job and running sessions are never pruned; a project's `memory/` moves to the trash only when no session of that project is left.
- **Context warning** (`UserPromptSubmit`): when the main session's context reaches `contextWarn` tokens (default 150000, `false` = off) you get a warning. If the cache has expired (idle longer than its 5-minute or 1-hour TTL) the message says the next turn rewrites the whole context and what that costs; if it is still warm, you get one note per 100k above the limit with the per-call read cost. Opt in to `"contextBlock": true` in `.claude/orch.json` to block the first prompt sent to such a cold, large session until you run `/compact` (sending the prompt again goes through; `/compact` and `/clear` are never blocked). The hook reads only the last 512 KB of the transcript. `SessionStart` adds the context size to the named-session hint and advises `/compact` after `--resume`.
- **Update hint** (`UserPromptSubmit`): when a newer orch is installed than the one an open session loaded, the first prompt shows a one-time hint to run `/reload-plugins`.
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

New sessions pick up the update. In sessions that are already open, run `/reload-plugins` (Claude Code loads plugins when a session starts). `/orch:version` shows which version a session runs.

Then, in each project, run `/orch:init` once (optional, recommended).

## Agents

| agent | model | tools | role |
|---|---|---|---|
| `orch:planner` | sonnet, effort medium | inherits project tools; Edit/NotebookEdit disallowed, writes only the plan file | writes the plan file named in the prompt (default `.claude/orch/plan.md`, the skills use `plan-<session>.md`; ≤ 40 lines), including a `## Parallel` split on request |
| `orch:worker` | sonnet, effort medium | inherits project tools | implements a task or plan file, or fixes only the listed review items; may call `orch:plan --inline`, `orch:review --inline` (`Self-review: yes`) and `orch:fix --inline <id>` ("Continue run <id>"); replies in ≤ 11 lines (`CHANGED / NEW / VERIFIED / OPEN / USED`) |
| `orch:reviewer` | haiku, no effort (the orchestrator may raise model and effort per lens) | inherits project tools; Edit/Write/NotebookEdit disallowed via `disallowedTools`, Bash limited by its prompt to read-only git and the test command | reviews `git diff` through one `Focus` lens (correctness, security, tests-and-conventions), reruns tests only when the worker's `VERIFIED` says "not run" or for the tests lens; returns `CRITICAL`, `CATEGORIES` and `VERDICT` |
| `orch:db-optimizer` | sonnet, effort low | inherits project tools; Edit/Write/NotebookEdit disallowed via `disallowedTools`, read-only DB queries only | N+1 queries, indexes, unbounded queries, transactions, caching; only useful (and only run) when the diff touches the database, by `/orch:backend` and `/orch:review --db` |

**Model per task:** the orchestrator rates each task as simple, normal or hard and passes `model` and `effort` on every agent call (low/medium/high by level; never on haiku, never above medium for the reviewer). Simple tasks run the worker and reviewer on haiku. Normal tasks use a sonnet worker and a haiku reviewer. Hard tasks (cross-module design, security, migrations, unclear root cause) put the planner on opus and the reviewer on sonnet. When review round 1 finds critical issues, they go to the same worker via `SendMessage` (warm context); a new, stronger worker is used only in round 2, or in round 1 for security/bug items from a haiku worker. Pin models per project with `"models": {"worker": "sonnet"}` in `.claude/orch.json`. Pin effort with `"efforts": {"worker": "high"}`. Agents load at most 1-2 skills, only on a clear match, and never meta or process skills. The models and efforts used are stored in `runs.log` and shown by `/orch:stats`.

**Project skills and team size:** agents have no `tools:` allowlist, so they see the project's skills, MCP and plugin tools and read `CLAUDE.md`. They use the ones that fit (TDD, debugging, IDE or DB tools) and skip skills that need a conversation or that commit, push or branch. Each reports a `USED:` line, stored in `runs.log` and shown by `/orch:stats`. The team scales with the level: simple = 1 worker + 1 reviewer, normal = 1 planner + 1 worker + 1 reviewer (+1 security reviewer when relevant), hard = up to 2 planners, 4 workers and 3 reviewers (one lens each: correctness, security, tests-and-conventions). Cap them with `"team"` in `.claude/orch.json`. More agents and CLAUDE.md reading mean somewhat higher token use per call.

**Where the tokens are saved:**
- The orchestrator never reads code itself.
- Agents pass file paths, not file contents.
- Replies use a fixed, short format.
- `maxTurns` caps each agent, and review is limited to 2 rounds.
- Deterministic work (tests, cleanup, guard, notifications) runs in hooks, not in the model.

**Context size is the real cost:** in a 30-day sample over 99 % of spend was the main session, under 0.2 % the orch agents. Large contexts (average 463k tokens) are re-read on every call, and after an idle gap beyond the cache TTL the whole context is written again, which cost about 28 % of spend. Three things help:
- Start `/orch:*` in a fresh or freshly compacted session.
- Set Claude Code's own `"autoCompactWindow": 200000` (minimum 100000) in `~/.claude/settings.json`. Claude Code then auto-compacts once the context reaches that size, which is the main saving; the orch warnings cover what is left.
- Watch `/orch:stats`: the line `Studená cache` shows how many turns rewrote 50k+ tokens, what that cost and the average main context (`cold` and `avg_ctx` in `--json`).

Every run is logged to `.claude/orch/runs.log` with its duration, tokens, USD, verdict, review rounds and finding categories. `/orch:stats` uses this log to show the first-try approval rate and the most common review findings, which tells you where your prompts or code need work.

## Storage

orch keeps `.claude/` bounded instead of growing forever. At `SessionEnd` the cleanup also:

- deletes `.claude/orch/plan-*.md`, `p*.patch` and stale session markers (start, turn, version check, context band and cold-cache markers) older than `trashDays` (default 7);
- trims `files.log` to `trashDays`, caps the `guard`, `notify`, `cleanup` and `runs` logs at about 1 MB (keeping the tail) and deletes `usage-cache.json` when it grows past 1 MB;
- runs `git worktree prune`. `/orch:cleanup` (the manual run) also lists leftover `.claude/worktrees/*` older than 1 day. orch never deletes worktrees by itself, so remove them with `git worktree remove` once you know they are not needed.

`prune.log` in `~/.claude/orch-trash` is capped too, and the trash keeps its 7-day purge. Transcripts and named sessions follow the rules of `/orch:prune`, which are unchanged. `/orch:stats` prints one line `Úložiště:` with the size of `.claude/orch`, the trash, the worktrees and `~/.claude/orch-trash`.

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
  "timezone": "Europe/Prague",
  "lang": "auto",
  "testCommand": "vendor/bin/phpunit --colors=never",
  "testTailLines": 30,
  "notify": true,
  "models": { "planner": "sonnet" },
  "efforts": { "worker": "high" },
  "team": { "planners": 2, "workers": 4, "reviewers": 3 },
  "budget": { "day": 10, "week": 50 },
  "contextWarn": 150000,
  "contextBlock": false,
  "guard": { "enabled": true, "block": [], "allow": [] },
  "prices": { "claude-opus-5-5": [4.0, 20.0, 0.20] }
}
```

Any key you leave out keeps its default. `timezone` is an IANA name that sets what "today" and "this week" mean for budgets, stats and log timestamps (`php -n` has no php.ini and would otherwise use UTC); the `TZ` environment variable wins, and `/orch:init` writes the detected one. `lang` is `"auto"` (default), `"cs"` or `"en"` and sets the language of the messages printed by scripts and hooks (guard blocks, warnings, `/orch:stats` and the like). With `auto` it is detected per session from your prompts (Czech diacritics and common Czech words → `cs`, otherwise `en`; with no prompt yet it falls back to `cs`). Skills and agents already reply in the language you write in. `prices` overrides the built-in USD per 1M tokens as `[input, output, cache read]`; cache writes are billed at 1.25× input (5-minute cache) or 2× input (1-hour cache). On a Pro or Max subscription you don't pay per token, so read the USD figure as a relative cost measure.

## Troubleshooting

| symptom | cause and fix |
|---|---|
| New or changed skills do not show up | open sessions load plugins at start: run `/reload-plugins`, or restart. `/orch:version` shows what the session runs. |
| The injected `Config:` line in a skill is empty | the shell preprocessing was not allowed. Run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/config.php" --project=.` once; the skill tells the model to do this itself. `invalid-json:<error>` in that line means `.claude/orch.json` does not parse. |
| `.claude/orch.json` seems ignored | it is probably not valid JSON: the session start message says so, and the `Config:` line of the skills shows `invalid-json:<error>`. Defaults apply until you fix it. |
| Hooks do nothing | check `php -v` (8.0+) and `php -n -l plugins/orch/scripts/<file>.php`. Hooks run as `php -n`, so php.ini is not loaded and only built-in extensions are available. |
| `/orch:parallel` refuses to start | the tree is dirty. Commit or stash first, because workers start from the last commit. |
| `/orch:fix` says there is nothing to fix | the last run has no OPEN items; `php -n plugins/orch/scripts/run-log.php last` shows what was logged. |
| Stats are empty or wrong after a Claude Code update | stats parse internal `.jsonl` transcripts, whose format may change. Models and efforts per run come from `runs.log`. |
| The guard blocked a command you want | add a narrow `guard.allow` pattern in `.claude/orch.json` and test it with `guard.php --check=`. |
| `.claude/` is big | see Storage above; `/orch:stats` shows the sizes, `/orch:prune` frees transcripts. |

## Development

```
claude --plugin-dir ./plugins/orch   # try local changes without installing
bash tests/hooks-test.sh             # hook tests, incl. a smoke run of every hook (CI: PHP 8.0, 8.2 and 8.4 on Linux, Windows and macOS)
php -n tools/sync-levels.php --check  # the shared Model/effort + Team block is identical in every skill (without --check it rewrites them)
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
- Worktrees contain only tracked files: untracked dependencies (`vendor/`, `node_modules/`, `.env`, build output) are missing there. Workers therefore do not run the full test suite; tests run once in the main tree after the patches are applied.
- Subagents cannot spawn subagents, so inside one `/orch:plan`, `/orch:review` and `/orch:fix` run `--inline`, and `/orch:run` and the other orchestrators are not usable there.
