---
name: review
description: Review uncommitted changes (or given files, or the staged diff) for bugs and return a verdict. Read-only, edits nothing. Use when the user asks for a review of current changes, or an agent wants a second look at its own diff.
argument-hint: "[files... | --staged] [--db] [--inline]"
allowed-tools: Bash(php -n "${CLAUDE_PLUGIN_ROOT}/scripts/*), Bash(git status*), Bash(git diff*)
---
Review the current changes: **$ARGUMENTS**

Config: !`php -n "${CLAUDE_PLUGIN_ROOT}/scripts/config.php" --project="${CLAUDE_PROJECT_DIR}"`
(If the line above is empty, run that command once yourself with Bash and use its output.)

Scope: files listed in the arguments, else `--staged` = `git diff --staged`, else all uncommitted changes (`git status --short` + `git diff`). If there is no diff, say so and stop. You never edit files or implement fixes.

**Mode** (decide in your first line, say it in 3 words):
- **delegated** — you have the Agent tool and no `--inline`. Do not read the diff yourself. Count the changed files and lines (`git diff --stat`), then call `orch:reviewer` (all calls in ONE message), passing the file list and "Review the current uncommitted changes" (or "staged changes"):
  - small change (up to ~3 files / ~100 lines): 1 reviewer, `haiku`, no effort, `Focus: correctness`
  - larger: 2-3 reviewers `sonnet`/`medium`, `Focus: correctness`, `security`, `tests-and-conventions` (cap: `team.reviewers` from the config line)
  - `--db`: add 1 `orch:db-optimizer` (`sonnet`, effort `low`) with the file list; skip it if no changed file touches the database (queries, models, migrations).
  - `models`/`efforts` in the config line win. Do not run tests yourself; the reviewers do when needed.
- **inline** — you have no Agent tool (you are a subagent), or `--inline` was given. Review in your own context, read-only: `git status --short`, `git diff` (or the given files; read new untracked files), read surrounding code only when the diff is not enough. Look for bugs, broken edge cases, security issues (injection, missing escaping/auth), obvious performance problems (N+1, queries in loops), changes that do not match the task. With `--db`, also check database access (N+1 queries, missing indexes, unbounded queries, transactions) when a changed file touches queries, models or migrations. Ignore style nitpicks. Run no tests unless the caller says VERIFIED is "not run". Inside a worker this stays read-only: report findings, do not fix them here.

Merge all replies into one result (user's language), exactly this shape:
CRITICAL:
- <file:line> <problem> → <fix>   (or "none")
SUGGESTIONS:
- <max 3, or "none">
CATEGORIES: <comma list from: bug, security, performance, mismatch, tests, other — or "none">
VERDICT: APPROVED | CHANGES_REQUESTED
USED: <skills/tools used, or none>

CHANGES_REQUESTED only when CRITICAL is not empty. If CHANGES_REQUESTED, add one line: `Next: /orch:fix` (fixes these items).
