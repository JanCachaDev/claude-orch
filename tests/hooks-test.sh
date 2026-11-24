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

echo "== restore"
OUT="$(php -n "$S/restore.php" --list --project="$T")"
check "list shows trashed file"     'grep -q tmp_notes.md <<<"$OUT"'
php -n "$S/restore.php" --file=src/debug_query.php --project="$T" >/dev/null
check "file restored"               '[ -f src/debug_query.php ]'
php -n "$S/restore.php" --file=src/debug_query.php --project="$T" >/dev/null 2>&1; CODE=$?
check "second restore fails"        '[ $CODE -ne 0 ]'

echo "== guard"
guard(){ echo "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":$(php -n -r 'echo json_encode($argv[1]);' "$1")}}" | php -n "$S/guard.php"; }
check "blocks rm -rf /"             'guard "rm -rf /" | grep -q "\"deny\""'
check "blocks git push --force"     'guard "git push --force origin main" | grep -q deny'
check "blocks DROP TABLE"           'guard "mysql -e \"DROP TABLE users\"" | grep -q deny'
check "allows rm -rf build/"        '[ -z "$(guard "rm -rf build/")" ]'
check "allows --force-with-lease"   '[ -z "$(guard "git push --force-with-lease")" ]'
check "guard.log written"           'grep -q "force push" .claude/orch/guard.log'
echo '{"guard":{"allow":["^git push --force origin main$"]}}' > .claude/orch.json
check "allow rule overrides"        '[ -z "$(guard "git push --force origin main")" ]'
echo '{"guard":{"block":["\\bnpm\\s+publish\\b"]}}' > .claude/orch.json
check "custom block rule"           'guard "npm publish" | grep -q deny'
rm .claude/orch.json

echo "== run-log + notify"
export ORCH_NOTIFY_OUTBOX="$T/outbox.txt"
export CLAUDE_PLUGIN_OPTION_DISCORD_WEBHOOK_URL="https://discord.example/webhook/1"
export CLAUDE_PLUGIN_OPTION_WHATSAPP_PHONE="+420111222333"
export CLAUDE_PLUGIN_OPTION_WHATSAPP_APIKEY="k3y"
ID="$(php -n "$S/run-log.php" start --mode=run --session=s1 --project="$T" --task="Add feature X")"
check "run id printed"              '[ -n "$ID" ]'
OUT="$(php -n "$S/run-log.php" end --id="$ID" --project="$T" --verdict=APPROVED --rounds=1 --categories=bug,tests --summary="done")"
check "run end summary"             'grep -q "APPROVED, 1 rounds" <<<"$OUT"'
check "notification queued"         '[ -f .claude/orch/notify-pending.json ]'
echo '{"session_id":"s1","last_assistant_message":"hi"}' | php -n "$S/notify.php" --event=stop
check "pending sent + removed"      '[ ! -f .claude/orch/notify-pending.json ]'
check "discord payload has task"    'grep -q "Add feature X" "$ORCH_NOTIFY_OUTBOX"'
check "whatsapp callmebot url"      'grep -q "api.callmebot.com/whatsapp.php?phone=%2B420111222333" "$ORCH_NOTIFY_OUTBOX"'
check "secret not in notify.log"    '! grep -q k3y .claude/orch/notify.log'
: > "$ORCH_NOTIFY_OUTBOX"
echo '{"session_id":"s1"}' | php -n "$S/turn-start.php"
echo '{"session_id":"s1","last_assistant_message":"quick"}' | php -n "$S/notify.php" --event=stop
check "short turn → no message"     '[ ! -s "$ORCH_NOTIFY_OUTBOX" ]'
echo $(( $(date +%s) - 120 )) > .claude/orch/sessions/s1.turn
echo '{"session_id":"s1","last_assistant_message":"long work done"}' | php -n "$S/notify.php" --event=stop
check "long turn → message"         'grep -q "long work done" "$ORCH_NOTIFY_OUTBOX"'
: > "$ORCH_NOTIFY_OUTBOX"
echo '{"session_id":"s1","message":"Claude needs your permission to use Bash","notification_type":"permission_prompt"}' | php -n "$S/notify.php" --event=notification
check "permission notification"     'grep -q "needs your permission" "$ORCH_NOTIFY_OUTBOX"'
: > "$ORCH_NOTIFY_OUTBOX"
export CLAUDE_PLUGIN_OPTION_WHATSAPP_PROVIDER=twilio CLAUDE_PLUGIN_OPTION_WHATSAPP_APIKEY="ACxx:tok" CLAUDE_PLUGIN_OPTION_WHATSAPP_FROM="+14155238886"
echo '{"message":"x"}' | php -n "$S/notify.php" --event=notification
check "twilio request"              'grep -q "Accounts/ACxx/Messages.json" "$ORCH_NOTIFY_OUTBOX" && grep -q "To=whatsapp:+420111222333" "$ORCH_NOTIFY_OUTBOX"'
echo '{"notify":false}' > .claude/orch.json; : > "$ORCH_NOTIFY_OUTBOX"
echo '{"message":"x"}' | php -n "$S/notify.php" --event=notification
check "notify:false disables"       '[ ! -s "$ORCH_NOTIFY_OUTBOX" ]'
rm .claude/orch.json

echo "== init"
mkdir -p vendor/bin && touch vendor/bin/phpunit && echo '{"permissions":{"allow":["Bash(ls)"],"deny":["Read(./vendor/**)"]}}' > .claude/settings.json
php -n "$S/init.php" --project="$T" >/dev/null
check "orch.json with phpunit"      'grep -q "vendor/bin/phpunit" .claude/orch.json'
check "deny rules merged"           'grep -q "composer.lock" .claude/settings.json && grep -q "Bash(ls)" .claude/settings.json'
check "no duplicate deny"           '[ "$(grep -c "Read(./vendor/\*\*)" .claude/settings.json)" -eq 1 ]'
rm -rf vendor .claude/orch.json

echo "== prices"
cost(){ php -n -r 'require $argv[1]; var_export(orch_cost($argv[2], ["in"=>1000000,"out"=>1000000]));' "$S/common.php" "$1"; }
check "opus 5.5 cost = 24 USD"      '[ "$(cost claude-opus-5-5)" = "24.0" ]'
check "haiku 5.5 cost = 0.6 USD"    '[ "$(cost claude-haiku-5-5)" = "0.6" ]'
check "unknown model → null"        '[ "$(cost gpt-x)" = "NULL" ]'

echo "== stats"
OUT="$(php -n "$S/stats.php" --project="$T")"
check "stats counts writes"         'grep -q "worker 3" <<<"$OUT"'
check "stats shows runs"            'grep -q "Add feature X" <<<"$OUT"'
check "stats shows guard"           'grep -q "Pojistka zablokovala" <<<"$OUT"'

echo
[ $FAIL -eq 0 ] && echo "ALL PASSED" || { echo "SOME TESTS FAILED"; exit 1; }
