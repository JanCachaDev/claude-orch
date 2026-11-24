#!/usr/bin/env bash
# Simulates Claude Code hook calls on a throwaway git repo and checks the results.
# Usage: bash tests/hooks-test.sh
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
S="$ROOT/plugins/orch/scripts"
T="$(mktemp -d)"
# on Git Bash use the Windows form (C:/...) — that is what Claude Code sends to hooks
T="$(cd "$T" && (pwd -W 2>/dev/null || pwd))"
trap 'rm -rf "$T"' EXIT
FAIL=0
ok()   { echo "  ok   $1"; }
bad()  { echo "  FAIL $1"; FAIL=1; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }

echo "== lint"
for f in "$S"/*.php; do php -n -l "$f" >/dev/null || bad "lint $f"; done
ok "php -n -l"

echo "== setup"
cd "$T" && git init -q && mkdir src
echo a > src/app.php; echo keep > tmp_tracked.php
git add . && git -c user.email=t@t -c user.name=t commit -qm init
export CLAUDE_PROJECT_DIR="$T"

echo '{"session_id":"s1"}' | php -n "$S/session-start.php"
check "session start marker" '[ -f .claude/orch/sessions/s1.start ]'
sleep 1

echo x > tmp_notes.md; echo y > src/debug_query.php; echo z > src/real.php; echo w > bash_made.tmp
echo old > old_tmp_other_session.tmp; touch -d '2 days ago' old_tmp_other_session.tmp
for f in tmp_notes.md src/debug_query.php src/real.php; do
  echo '{"session_id":"s1","agent_type":"worker","tool_input":{"file_path":"'"$T/$f"'"}}' | php -n "$S/track-file.php"
done
echo '{"session_id":"s1","tool_input":{"file_path":"/somewhere/else/tmp_x"}}' | php -n "$S/track-file.php"

echo "== track-file"
check "3 entries logged"            '[ "$(wc -l < .claude/orch/files.log)" -eq 3 ]'
check "outside path ignored"        '! grep -q somewhere .claude/orch/files.log'
check "state dir gitignored"        '! git status --porcelain | grep -q .claude/orch'

echo "== cleanup (dry run)"
OUT="$(php -n "$S/cleanup.php" --manual --dry-run --project="$T")"
check "dry run lists tmp_notes"     'grep -q tmp_notes.md <<<"$OUT"'
check "dry run moves nothing"       '[ -f tmp_notes.md ]'

echo "== cleanup (SessionEnd)"
echo '{"session_id":"s1"}' | php -n "$S/cleanup.php"
check "tmp_notes.md moved"          '[ ! -f tmp_notes.md ]'
check "src/debug_query.php moved"   '[ ! -f src/debug_query.php ]'
check "bash-created tmp moved"      '[ ! -f bash_made.tmp ]'
check "older untracked tmp kept"    '[ -f old_tmp_other_session.tmp ]'
check "normal file kept"            '[ -f src/real.php ]'
check "git-tracked tmp kept"        '[ -f tmp_tracked.php ]'
check "trash keeps structure"       '[ -n "$(find .claude/orch/trash -path "*src/debug_query.php")" ]'
check "cleanup.log written"         'grep -q debug_query .claude/orch/cleanup.log'

echo "== stop-tests"
echo '{"testCommand":"php -r \"echo \\\"boom\\\"; exit(1);\""}' > .claude/orch.json
ERR="$(echo '{"stop_hook_active":false}' | php -n "$S/stop-tests.php" 2>&1)"; CODE=$?
check "failing tests → exit 2"      '[ $CODE -eq 2 ]'
check "stderr has output tail"      'grep -q boom <<<"$ERR"'
echo '{"stop_hook_active":true}' | php -n "$S/stop-tests.php"; CODE=$?
check "no loop when active"         '[ $CODE -eq 0 ]'
rm .claude/orch.json
echo '{}' | php -n "$S/stop-tests.php"; CODE=$?
check "disabled by default"         '[ $CODE -eq 0 ]'

echo "== stats"
OUT="$(php -n "$S/stats.php" --project="$T")"
check "stats counts writes"         'grep -q "worker: 3" <<<"$OUT"'

echo
[ $FAIL -eq 0 ] && echo "ALL PASSED" || { echo "SOME TESTS FAILED"; exit 1; }
