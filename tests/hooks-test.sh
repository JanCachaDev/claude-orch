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
OUT="$(php -n "$S/run-log.php" end --id="$ID" --project="$T" --verdict=APPROVED --rounds=1 --categories=bug,tests --models=orch:planner:sonnet,worker:haiku --skills="superpowers:test-driven-development,playwright,none,a b;rm" --summary="done")"
check "run end summary"             'grep -q "APPROVED, 1 rounds" <<<"$OUT"'
check "runs.log has skills"          'grep -q "\"skills\":\[\"superpowers:test-driven-development\",\"playwright\"\]" .claude/orch/runs.log'
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
check "stats shows run models"      'grep -q "worker:haiku" <<<"$OUT"'
check "stats shows skills"           'grep -q "Použité skills: superpowers:test-driven-development (1)" <<<"$OUT"'
check "stats shows guard"           'grep -q "Pojistka zablokovala" <<<"$OUT"'
JOUT="$(php -n "$S/stats.php" --project="$T" --json)"
check "stats --json valid"          'php -n -r '"'"'exit(is_array(json_decode(stream_get_contents(STDIN), true)) ? 0 : 1);'"'"' <<<"$JOUT"'
check "stats --json runs+files"     'php -n -r '"'"'$d = json_decode(stream_get_contents(STDIN), true); exit(isset($d["runs"], $d["files"]) ? 0 : 1);'"'"' <<<"$JOUT"'

echo "== global"
mkdir -p "$T/home/projects/p1/s1/subagents" "$T/home/plugins"
php -n -r 'echo json_encode(["cwd" => "C:\x\proj", "sessionId" => "s1", "timestamp" => gmdate("c"), "message" => ["id" => "m1", "model" => "claude-opus-5-5", "usage" => ["input_tokens" => 1000, "output_tokens" => 500]]]), "\n";' > "$T/home/projects/p1/s1.jsonl"
GOUT="$(CLAUDE_CONFIG_DIR="$T/home" php -n "$S/global.php")"
check "global shows project path"   'grep -q "C:/x/proj" <<<"$GOUT"'
check "global counts tokens"        'grep -q "claude-opus-5-5" <<<"$GOUT"'
check "global --json valid"         'CLAUDE_CONFIG_DIR="$T/home" php -n "$S/global.php" --json | php -n -r "exit(isset(json_decode(stream_get_contents(STDIN), true)[\"projects\"]) ? 0 : 1);"'
php -n -r 'echo json_encode(["type" => "user", "promptId" => "pr1", "sessionId" => "s1", "message" => ["content" => "hello expensive prompt"]]), "\n";' >> "$T/home/projects/p1/s1.jsonl"
php -n -r 'echo json_encode(["type" => "assistant", "sessionId" => "s1", "timestamp" => gmdate("c"), "message" => ["id" => "m2", "model" => "claude-opus-5-5", "usage" => ["input_tokens" => 100000, "output_tokens" => 9000]]]), "\n";' >> "$T/home/projects/p1/s1.jsonl"
GOUT="$(CLAUDE_CONFIG_DIR="$T/home" php -n "$S/global.php")"
check "global top turns + prompt"   'grep -q "hello expensive prompt" <<<"$GOUT"'

echo "== budget"
BP="$T/bp"; mkdir -p "$BP/.claude"
SLUG="$(php -n -r 'echo preg_replace("/[^A-Za-z0-9]/", "-", $argv[1]);' "$BP")"
mkdir -p "$T/home/projects/$SLUG"
php -n -r 'echo json_encode(["sessionId" => "b1", "timestamp" => gmdate("c"), "message" => ["id" => "b1", "model" => "claude-opus-5-5", "usage" => ["input_tokens" => 1000000, "output_tokens" => 0]]]), "\n";' > "$T/home/projects/$SLUG/b1.jsonl"
echo '{"budget":{"day":1}}' > "$BP/.claude/orch.json"
: > "$ORCH_NOTIFY_OUTBOX"
CLAUDE_CONFIG_DIR="$T/home" php -n "$S/budget.php" --project="$BP"
check "budget alert file"           'grep -q "rozpočet překročen" "$BP/.claude/orch/budget-alert.txt"'
check "budget alert notified"       'grep -q "rozpo" "$ORCH_NOTIFY_OUTBOX"'
rm -f "$BP/.claude/orch/budget-alert.txt"; : > "$ORCH_NOTIFY_OUTBOX"
CLAUDE_CONFIG_DIR="$T/home" php -n "$S/budget.php" --project="$BP" --now=$(( $(date +%s) + 400 ))
check "budget alerts once per day"  '[ ! -f "$BP/.claude/orch/budget-alert.txt" ] && [ ! -s "$ORCH_NOTIFY_OUTBOX" ]'
echo x > "$BP/.claude/orch/budget-alert.txt"
TS="$(cd "$BP" && echo '{"session_id":"b1"}' | CLAUDE_PROJECT_DIR="$BP" php -n "$S/turn-start.php")"
check "turn-start shows alert"      'grep -q systemMessage <<<"$TS" && [ ! -f "$BP/.claude/orch/budget-alert.txt" ]'
check "budget --status"             'CLAUDE_CONFIG_DIR="$T/home" php -n "$S/budget.php" --project="$BP" --status | grep -q "limit 1.00"'

echo "== prune"
mkdir -p "$T/home/projects/p2/old1/subagents" "$T/home/file-history/old1"
echo '{}' > "$T/home/projects/p2/old1.jsonl"; echo '{}' > "$T/home/projects/p2/old1/subagents/agent-a.jsonl"
echo '{}' > "$T/home/projects/p2/new1.jsonl"; echo h > "$T/home/file-history/old1/f"
touch -d "40 days ago" "$T/home/projects/p2/old1.jsonl" "$T/home/projects/p2/old1/subagents/agent-a.jsonl" "$T/home/projects/p2/old1/subagents" "$T/home/projects/p2/old1"
POUT="$(CLAUDE_CONFIG_DIR="$T/home" php -n "$S/prune.php" --days=30 --dry-run)"
check "prune preview lists old"     'grep -q "p2 .* 1 sezení" <<<"$POUT" && [ -f "$T/home/projects/p2/old1.jsonl" ]'
CLAUDE_CONFIG_DIR="$T/home" php -n "$S/prune.php" --days=30 >/dev/null
check "prune moved old session"     '[ ! -e "$T/home/projects/p2/old1.jsonl" ] && [ ! -e "$T/home/projects/p2/old1" ] && [ ! -e "$T/home/file-history/old1" ]'
check "prune kept recent session"   '[ -f "$T/home/projects/p2/new1.jsonl" ]'
CLAUDE_CONFIG_DIR="$T/home" php -n "$S/prune.php" --restore-last >/dev/null
check "prune --restore-last"        '[ -f "$T/home/projects/p2/old1.jsonl" ] && [ -f "$T/home/projects/p2/old1/subagents/agent-a.jsonl" ] && [ -f "$T/home/file-history/old1/f" ]'

echo "== named sessions"
H2="$T/home2"; SL="proj-slug"; mkdir -p "$H2/projects/$SL" "$H2/jobs/j1"
{ head -c 400000 /dev/zero | tr '\0' 'x'; echo; echo '{"type":"custom-title","customTitle":"Outside","sessionId":"named1"}'; head -c 100000 /dev/zero | tr '\0' 'y'; echo; echo '{"type":"custom-title","customTitle":"Old","sessionId":"named1"}'; head -c 50000 /dev/zero | tr '\0' 'z'; echo; echo '{"type":"custom-title","customTitle":"Studygraph","sessionId":"named1"}'; } > "$H2/projects/$SL/named1.jsonl"
echo '{"type":"user"}' > "$H2/projects/$SL/plain1.jsonl"
echo "{\"sessionId\":\"job1\",\"resumeSessionId\":\"job2\",\"name\":\"TJS\",\"nameSource\":\"user\",\"cwd\":\"$T\"}" > "$H2/jobs/j1/state.json"
printf '%s
' '{"sessionId":"plain1","project":"x","timestamp":1,"display":"a"}' '{"sessionId":"plain1","project":"x","timestamp":2,"display":"b"}' '{"sessionId":"named1","project":"x","timestamp":3,"display":"c"}' > "$H2/history.jsonl"
mkdir -p "$H2/jobs/j2" "$H2/projects/other"; echo '{"sessionId":"job3","name":"","nameSource":"user","cwd":"x"}' > "$H2/jobs/j2/state.json"
echo '{"type":"custom-title","customTitle":"Elsewhere","sessionId":"oth1"}' > "$H2/projects/other/oth1.jsonl"
TP="$H2/projects/$SL/new1.jsonl"
HOUT="$(echo "{\"session_id\":\"new1\",\"source\":\"startup\",\"transcript_path\":\"$TP\",\"cwd\":\"$T\"}" | CLAUDE_CONFIG_DIR="$H2" php -n "$S/session-start.php")"
check "hint names session"          'grep -q Studygraph <<<"$HOUT" && grep -q "claude --resume named1" <<<"$HOUT"'
check "hint uses last title"        '! grep -q "Old\|Outside" <<<"$HOUT"'
check "hint is valid json"          'php -n -r "exit(isset(json_decode(file_get_contents(\"php://stdin\"),true)[\"systemMessage\"])?0:1);" <<<"$HOUT"'
ROUT="$(echo "{\"session_id\":\"new1\",\"source\":\"resume\",\"transcript_path\":\"$TP\",\"cwd\":\"$T\"}" | CLAUDE_CONFIG_DIR="$H2" php -n "$S/session-start.php")"
check "no hint on resume"           '[ -z "$ROUT" ]'
SOUT="$(echo "{\"session_id\":\"named1\",\"source\":\"startup\",\"transcript_path\":\"$H2/projects/$SL/named1.jsonl\",\"cwd\":\"$T\"}" | CLAUDE_CONFIG_DIR="$H2" php -n "$S/session-start.php")"
check "no hint for itself"          '[ -z "$SOUT" ]'
mkdir -p "$T/.claude"; echo '{"sessionHint":false}' > "$T/.claude/orch.json"
OOUT="$(echo "{\"session_id\":\"new1\",\"source\":\"startup\",\"transcript_path\":\"$TP\",\"cwd\":\"$T\"}" | CLAUDE_CONFIG_DIR="$H2" php -n "$S/session-start.php")"
check "sessionHint:false silences"  '[ -z "$OOUT" ]'
rm -f "$T/.claude/orch.json"
SW="$(cd "$S" && (pwd -W 2>/dev/null || pwd))"
LIB="$(CLAUDE_CONFIG_DIR="$H2" php -n -r 'require "'"$SW"'/lib-sessions.php"; $h=orch_claude_home(); echo json_encode([orch_named_sessions($h), orch_job_sessions($h), orch_prompt_counts($h)]);')"
check "lib named includes job name" 'grep -q "\"job1\":{\"name\":\"TJS\"" <<<"$LIB"'
check "lib job sessions"            'grep -q "\"job2\":true" <<<"$LIB"'
check "lib skips empty job name"    '! grep -q "\"job3\":{" <<<"$LIB"'
check "hint ignores other projects" '! grep -q Elsewhere <<<"$HOUT"'
check "lib prompt counts"           'grep -q "\"plain1\":2" <<<"$LIB"'

echo "== prune protection + auto"
mkprune() { # $1 = home dir
  local H="$1" P="$1/projects/pp"; mkdir -p "$P" "$H/jobs/jb"
  echo '{"type":"custom-title","customTitle":"Keepme","sessionId":"namedold"}' > "$P/namedold.jsonl"
  echo '{"type":"user"}' > "$P/jobold.jsonl"
  echo '{"type":"user"}' > "$P/plainold.jsonl"
  echo '{"type":"user"}' > "$P/shortone.jsonl"
  echo '{"type":"user"}' > "$P/recent1.jsonl"
  head -c 150000 /dev/zero | tr '\0' 'x' > "$P/bigclaudep.jsonl"
  echo "{\"sessionId\":\"jobold\",\"name\":\"\",\"cwd\":\"x\"}" > "$H/jobs/jb/state.json"
  printf '%s
' '{"sessionId":"plainold"}' '{"sessionId":"plainold"}' '{"sessionId":"plainold"}' '{"sessionId":"shortone"}' '{"sessionId":"recent1"}' > "$H/history.jsonl"
  touch -d "40 days ago" "$P/namedold.jsonl" "$P/jobold.jsonl" "$P/plainold.jsonl"
  touch -d "2 days ago" "$P/shortone.jsonl" "$P/bigclaudep.jsonl"
  echo '{"type":"user"}' > "$P/live1.jsonl"; mkdir -p "$H/sessions"; echo '{"pid":1,"sessionId":"live1","kind":"interactive"}' > "$H/sessions/1.json"
  echo '{"type":"custom-title","customTitle":"ShortNamed","sessionId":"shortnamed"}' > "$P/shortnamed.jsonl"
  echo '{"type":"user"}' > "$P/resumed.jsonl"; mkdir -p "$H/jobs/jc"; echo '{"sessionId":"x","resumeSessionId":"resumed"}' > "$H/jobs/jc/state.json"
  echo '{"type":"user"}' > "$P/zeroprompt.jsonl"
  touch -d "2 days ago" "$P/live1.jsonl" "$P/shortnamed.jsonl" "$P/resumed.jsonl" "$P/zeroprompt.jsonl"
  printf '%s
' '{"sessionId":"live1"}' '{"sessionId":"shortnamed"}' '{"sessionId":"resumed"}' >> "$H/history.jsonl"
}
H3="$T/home3"; mkprune "$H3"
PV="$(CLAUDE_CONFIG_DIR="$H3" php -n "$S/prune.php" --days=30 --dry-run)"
check "prune preview counts protected" 'grep -q "chráněno: 5 (pojmenovaná, úlohy, běžící)" <<<"$PV"'
check "prune preview: plain + short"   'grep -q "pp .* 3 sezení" <<<"$PV"'
CLAUDE_CONFIG_DIR="$H3" php -n "$S/prune.php" --days=30 >/dev/null
check "prune keeps named+job"       '[ -f "$H3/projects/pp/namedold.jsonl" ] && [ -f "$H3/projects/pp/jobold.jsonl" ]'
check "prune moves short session"   '[ ! -e "$H3/projects/pp/shortone.jsonl" ] && [ ! -e "$H3/projects/pp/plainold.jsonl" ] && [ ! -e "$H3/projects/pp/zeroprompt.jsonl" ]'
check "prune keeps live session"    '[ -f "$H3/projects/pp/live1.jsonl" ]'
check "short named session kept"    '[ -f "$H3/projects/pp/shortnamed.jsonl" ]'
check "resumeSessionId job kept"    '[ -f "$H3/projects/pp/resumed.jsonl" ]'
check "prune keeps recent + big unlisted" '[ -f "$H3/projects/pp/recent1.jsonl" ] && [ -f "$H3/projects/pp/bigclaudep.jsonl" ]'
CLAUDE_CONFIG_DIR="$H3" php -n "$S/prune.php" --restore-last >/dev/null
check "restore-last ignores stamp files" '[ -f "$H3/projects/pp/shortone.jsonl" ]'
H5="$T/home5"; mkprune "$H5"; echo 'garbage{' > "$H5/jobs/jb/state.json"
FC="$(CLAUDE_CONFIG_DIR="$H5" php -n "$S/prune.php" --days=30)"
check "corrupt job state aborts"    '[ -f "$H5/projects/pp/plainold.jsonl" ] && [ -f "$H5/projects/pp/shortone.jsonl" ] && [ -n "$FC" ]'
echo '{}' | CLAUDE_CONFIG_DIR="$H5" CLAUDE_PLUGIN_OPTION_PRUNE_DAYS=30 php -n "$S/prune.php" --auto
check "auto corrupt: nothing, no stamp" '[ -f "$H5/projects/pp/plainold.jsonl" ] && [ ! -f "$H5/orch-trash/.last-auto" ]'
H5b="$T/home5b"; mkprune "$H5b"; mkdir -p "$H5b/sessions"; echo 'bad{' > "$H5b/sessions/2.json"
FS="$(CLAUDE_CONFIG_DIR="$H5b" php -n "$S/prune.php" --days=30)"
check "corrupt sessions file aborts" '[ -f "$H5b/projects/pp/plainold.jsonl" ] && [ -n "$FS" ]'
echo '{}' | CLAUDE_CONFIG_DIR="$H5b" CLAUDE_PLUGIN_OPTION_PRUNE_DAYS=30 php -n "$S/prune.php" --auto
check "auto corrupt sessions: nothing" '[ -f "$H5b/projects/pp/plainold.jsonl" ] && [ ! -f "$H5b/orch-trash/.last-auto" ]'

echo "== orphaned memory"
H6="$T/home6"; mkdir -p "$H6/projects"
for d in onlymem hassess lastgone; do mkdir -p "$H6/projects/$d/memory"; echo m > "$H6/projects/$d/memory/m.md"; done
echo '{"type":"user"}' > "$H6/projects/hassess/s1.jsonl"
echo '{"type":"user"}' > "$H6/projects/lastgone/old.jsonl"; touch -d "40 days ago" "$H6/projects/lastgone/old.jsonl"
printf '%s
' '{"sessionId":"old"}' '{"sessionId":"old"}' '{"sessionId":"old"}' > "$H6/history.jsonl"
OV="$(CLAUDE_CONFIG_DIR="$H6" php -n "$S/prune.php" --days=30 --dry-run)"
check "orphan preview"              'grep -q "osiřelá paměť: onlymem" <<<"$OV" && grep -q "osiřelá paměť: lastgone" <<<"$OV" && ! grep -q "osiřelá paměť: hassess" <<<"$OV" && [ -d "$H6/projects/onlymem/memory" ]'
CLAUDE_CONFIG_DIR="$H6" php -n "$S/prune.php" --days=30 >/dev/null
check "memory moved: only memory/"  '[ ! -e "$H6/projects/onlymem" ]'
check "memory moved: last session gone" '[ ! -e "$H6/projects/lastgone" ]'
check "memory kept: has session"    '[ -f "$H6/projects/hassess/memory/m.md" ]'
CLAUDE_CONFIG_DIR="$H6" php -n "$S/prune.php" --restore-last >/dev/null
check "restore-last brings memory"  '[ -f "$H6/projects/onlymem/memory/m.md" ] && [ -f "$H6/projects/lastgone/memory/m.md" ] && [ -f "$H6/projects/lastgone/old.jsonl" ]'

H7="$T/home7"; mkdir -p "$H7/projects/memonly/memory" "$H7/projects/newproj/memory" "$H7/projects/livep/memory" "$H7/sessions"
echo a > "$H7/projects/memonly/memory/a.md"; echo n > "$H7/projects/newproj/memory/n.md"; echo l > "$H7/projects/livep/memory/l.md"
echo "{\"pid\":1,\"sessionId\":\"zz\",\"cwd\":\"$T/live-proj\"}" > "$H7/sessions/1.json"
mkdir -p "$H7/projects/$(printf '%s' "$T/live-proj" | sed 's/[^A-Za-z0-9]/-/g')/memory"; echo l > "$H7/projects/$(printf '%s' "$T/live-proj" | sed 's/[^A-Za-z0-9]/-/g')/memory/l.md"
echo "{\"session_id\":\"zz\",\"transcript_path\":\"$H7/projects/newproj/zz.jsonl\",\"cwd\":\"$T/elsewhere\"}" | CLAUDE_CONFIG_DIR="$H7" CLAUDE_PLUGIN_OPTION_PRUNE_DAYS=30 php -n "$S/prune.php" --auto
check "auto keeps hook project memory" '[ -f "$H7/projects/newproj/memory/n.md" ]'
check "live cwd protects memory"      '[ -f "$H7/projects/$(printf "%s" "$T/live-proj" | sed "s/[^A-Za-z0-9]/-/g")/memory/l.md" ]'
check "auto moved unrelated memory"   '[ ! -e "$H7/projects/memonly" ]'
mkdir -p "$H7/projects/memonly/memory"; echo NEW > "$H7/projects/memonly/memory/b.md"; echo changed > "$H7/projects/memonly/memory/a.md"
RO="$(CLAUDE_CONFIG_DIR="$H7" php -n "$S/prune.php" --restore-last)"
check "restore merges, keeps both"    '[ -f "$H7/projects/memonly/memory/b.md" ] && grep -q changed "$H7/projects/memonly/memory/a.md"'
check "restore leaves conflict in trash" '[ -n "$(find "$H7/orch-trash" -name a.md)" ] && grep -q "a.md" <<<"$RO"'

H4="$T/home4"; mkprune "$H4"
AO="$(echo '{"session_id":"plainold"}' | CLAUDE_CONFIG_DIR="$H4" CLAUDE_PLUGIN_OPTION_PRUNE_DAYS=30 php -n "$S/prune.php" --auto)"
check "auto is silent"              '[ -z "$AO" ]'
check "auto honours hook session id" '[ -f "$H4/projects/pp/plainold.jsonl" ] && [ ! -e "$H4/projects/pp/shortone.jsonl" ]'
check "auto keeps named+job"        '[ -f "$H4/projects/pp/namedold.jsonl" ] && [ -f "$H4/projects/pp/jobold.jsonl" ]'
check "auto writes log + stamp"     '[ -s "$H4/orch-trash/prune.log" ] && [ -f "$H4/orch-trash/.last-auto" ]'
touch -d "40 days ago" "$H4/projects/pp/recent1.jsonl"
echo '{}' | CLAUDE_CONFIG_DIR="$H4" CLAUDE_PLUGIN_OPTION_PRUNE_DAYS=30 php -n "$S/prune.php" --auto
check "auto rate-limited 24h"       '[ -f "$H4/projects/pp/recent1.jsonl" ]'
touch -d "2 days ago" "$H4/orch-trash/.last-auto"
echo '{}' | CLAUDE_CONFIG_DIR="$H4" CLAUDE_PLUGIN_OPTION_PRUNE_DAYS=0 php -n "$S/prune.php" --auto
check "auto PRUNE_DAYS=0 is off"    '[ -f "$H4/projects/pp/recent1.jsonl" ]'
echo '{}' | CLAUDE_CONFIG_DIR="$H4" CLAUDE_PLUGIN_OPTION_PRUNE_DAYS=30 php -n "$S/prune.php" --auto
check "auto runs after 24h"         '[ ! -e "$H4/projects/pp/recent1.jsonl" ]'
CLAUDE_CONFIG_DIR="$H4" php -n "$S/prune.php" --restore-last >/dev/null
check "restore-last after auto"     '[ -f "$H4/projects/pp/recent1.jsonl" ]'

echo
[ $FAIL -eq 0 ] && echo "ALL PASSED" || { echo "SOME TESTS FAILED"; exit 1; }
