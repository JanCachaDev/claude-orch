#!/usr/bin/env bash
# Simulates Claude Code hook calls on a throwaway git repo and checks the results.
# Usage: bash tests/hooks-test.sh
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
S="$ROOT/plugins/orch/scripts"
T="$(mktemp -d)"
# on Git Bash use the Windows form (C:/...) — that is what Claude Code sends to hooks
T="$(cd "$T" && (pwd -W 2>/dev/null || pwd))"
trap 'rm -rf "$T" "${NOGIT:-}"' EXIT
FAIL=0
ok()   { echo "  ok   $1"; }
bad()  { echo "  FAIL $1"; FAIL=1; }
check(){ if eval "$2"; then ok "$1"; else bad "$1"; fi; }
# portable (GNU and BSD/macOS) replacements for `touch -d "N days ago"` and `date -u -d "N seconds ago"`
touch_ago(){ local d="$1"; shift; php -n -r '$t = time() - (int)$argv[1] * 86400; foreach (array_slice($argv, 2) as $f) { touch($f, $t); }' "$d" "$@"; }
iso_ago(){ php -n -r 'echo gmdate("Y-m-d\TH:i:s.000\Z", time() - (int)$argv[1]);' "$1"; }

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
echo old > old_tmp_other_session.tmp; touch_ago 2 old_tmp_other_session.tmp
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
OUT="$(php -n "$S/run-log.php" end --id="$ID" --project="$T" --verdict=APPROVED --rounds=1 --categories=bug,tests --models=orch:planner:sonnet,worker:haiku --efforts=orch:planner:medium,worker:low,reviewer:bogus --skills="superpowers:test-driven-development,playwright,none,a b;rm" --summary="done")"
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
check "runs.log has efforts"        'grep -q "\"efforts\":{\"planner\":\"medium\",\"worker\":\"low\"}" "$T/.claude/orch/runs.log"'
check "runs.log models strip orch:" 'grep -q "\"models\":{\"planner\":\"sonnet\",\"worker\":\"haiku\"}" "$T/.claude/orch/runs.log"'
check "invalid effort dropped"      '! grep -q "bogus" "$T/.claude/orch/runs.log"'
check "stats shows run efforts"     'grep -q "worker:haiku/low" <<<"$OUT"'
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
touch_ago 40 "$T/home/projects/p2/old1.jsonl" "$T/home/projects/p2/old1/subagents/agent-a.jsonl" "$T/home/projects/p2/old1/subagents" "$T/home/projects/p2/old1"
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
  touch_ago 40 "$P/namedold.jsonl" "$P/jobold.jsonl" "$P/plainold.jsonl"
  touch_ago 2 "$P/shortone.jsonl" "$P/bigclaudep.jsonl"
  echo '{"type":"user"}' > "$P/live1.jsonl"; mkdir -p "$H/sessions"; echo '{"pid":1,"sessionId":"live1","kind":"interactive"}' > "$H/sessions/1.json"
  echo '{"type":"custom-title","customTitle":"ShortNamed","sessionId":"shortnamed"}' > "$P/shortnamed.jsonl"
  echo '{"type":"user"}' > "$P/resumed.jsonl"; mkdir -p "$H/jobs/jc"; echo '{"sessionId":"x","resumeSessionId":"resumed"}' > "$H/jobs/jc/state.json"
  echo '{"type":"user"}' > "$P/zeroprompt.jsonl"
  touch_ago 2 "$P/live1.jsonl" "$P/shortnamed.jsonl" "$P/resumed.jsonl" "$P/zeroprompt.jsonl"
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
echo '{"type":"user"}' > "$H6/projects/lastgone/old.jsonl"; touch_ago 40 "$H6/projects/lastgone/old.jsonl"
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
touch_ago 40 "$H4/projects/pp/recent1.jsonl"
echo '{}' | CLAUDE_CONFIG_DIR="$H4" CLAUDE_PLUGIN_OPTION_PRUNE_DAYS=30 php -n "$S/prune.php" --auto
check "auto rate-limited 24h"       '[ -f "$H4/projects/pp/recent1.jsonl" ]'
touch_ago 2 "$H4/orch-trash/.last-auto"
echo '{}' | CLAUDE_CONFIG_DIR="$H4" CLAUDE_PLUGIN_OPTION_PRUNE_DAYS=0 php -n "$S/prune.php" --auto
check "auto PRUNE_DAYS=0 is off"    '[ -f "$H4/projects/pp/recent1.jsonl" ]'
echo '{}' | CLAUDE_CONFIG_DIR="$H4" CLAUDE_PLUGIN_OPTION_PRUNE_DAYS=30 php -n "$S/prune.php" --auto
check "auto runs after 24h"         '[ ! -e "$H4/projects/pp/recent1.jsonl" ]'
CLAUDE_CONFIG_DIR="$H4" php -n "$S/prune.php" --restore-last >/dev/null
check "restore-last after auto"     '[ -f "$H4/projects/pp/recent1.jsonl" ]'

echo "== version"
H5="$T/home5"; MK="$H5/plugins/marketplaces/claude-orch/plugins/orch/.claude-plugin"; mkdir -p "$MK"
LOADED="$(php -n -r 'echo json_decode(file_get_contents($argv[1]))->version;' "$ROOT/plugins/orch/.claude-plugin/plugin.json")"
inst(){ echo "{\"version\":2,\"plugins\":{\"orch@claude-orch\":[{\"scope\":\"user\",\"version\":\"$1\"}]}}" > "$H5/plugins/installed_plugins.json"; }
inst 9.9.9; echo '{"name":"orch","version":"9.9.9"}' > "$MK/plugin.json"
VOUT="$(CLAUDE_CONFIG_DIR="$H5" php -n "$S/version.php" --offline)"
check "version shows loaded"        'grep -q "toto sezení: *$LOADED" <<<"$VOUT"'
check "version shows installed"     'grep -q "nainstalováno: *9.9.9" <<<"$VOUT"'
check "version → reload-plugins"    'grep -q "/reload-plugins" <<<"$VOUT"'
echo '{"name":"orch","version":"10.0.0"}' > "$MK/plugin.json"
VOUT="$(CLAUDE_CONFIG_DIR="$H5" php -n "$S/version.php" --offline)"
check "version → update command"    'grep -q "claude plugin update orch@claude-orch" <<<"$VOUT" && grep -q "nejnovější: *10.0.0" <<<"$VOUT"'
check "version --json"              'CLAUDE_CONFIG_DIR="$H5" php -n "$S/version.php" --offline --json | grep -q "\"reload_needed\": true"'
VP="$T/vp"; mkdir -p "$VP"
vts(){ echo "{\"session_id\":\"$1\"}" | CLAUDE_CONFIG_DIR="$H5" CLAUDE_PROJECT_DIR="$VP" php -n "$S/turn-start.php"; }
inst "$LOADED"; TS0="$(vts v1)"
check "no hint when up to date"     '[ -z "$TS0" ]'
inst 9.9.9-abc123; TS1="$(vts v1)"; TS2="$(vts v1)"
check "hint after update mid-session" 'grep -q "nainstalovaná je 9.9.9 " <<<"$TS1" && grep -q systemMessage <<<"$TS1"'
check "hint once per version"       '[ -z "$TS2" ]'
inst 9.9.10; TS3="$(vts v1)"
check "hint again for newer version" 'grep -q "9.9.10" <<<"$TS3"'
mkdir -p "$VP/.claude/orch"; echo "budget over" > "$VP/.claude/orch/budget-alert.txt"; inst 9.9.11; TS4="$(vts v1)"
check "budget + hint in one json"   'php -n -r "\$m = json_decode(stream_get_contents(STDIN), true)[\"systemMessage\"] ?? \"\"; exit(str_contains(\$m, \"budget over\") && str_contains(\$m, \"9.9.11\") ? 0 : 1);" <<<"$TS4"'
rm -f "$H5/plugins/installed_plugins.json"; TS5="$(vts v3)"
check "no installed file → silent"  '[ -z "$TS5" ]'
VOUT="$(CLAUDE_CONFIG_DIR="$H5" php -n "$S/version.php" --offline)"
check "no installed → update advice" 'grep -q "nainstalováno: *?" <<<"$VOUT" && grep -q "claude plugin update" <<<"$VOUT"'
check "remote lookup has timeout"   'start=$(date +%s); ORCH_REPO_URL="$T/no-such-repo.git" php -n -r "require \"$SW/lib-version.php\"; orch_remote_version(1);" >/dev/null; [ $(( $(date +%s) - start )) -le 4 ]'

echo "== context warning"
CP="$T/cp"; mkdir -p "$CP"; CT="$T/ctx.jsonl"
asst(){ echo "{\"type\":\"assistant\",\"isSidechain\":false,\"timestamp\":\"$(iso_ago "$1")\",\"message\":{\"id\":\"m$RANDOM\",\"model\":\"claude-opus-5-5\",\"usage\":{\"input_tokens\":2,\"cache_creation_input_tokens\":$2,\"cache_read_input_tokens\":$3,\"output_tokens\":5,\"cache_creation\":{\"ephemeral_1h_input_tokens\":$2,\"ephemeral_5m_input_tokens\":0}}}}"; }
ctxp(){ echo "{\"session_id\":\"$1\",\"transcript_path\":\"$CT\",\"prompt\":\"${2:-hello}\"}" | CLAUDE_CONFIG_DIR="$H5" CLAUDE_PROJECT_DIR="$CP" php -n "$S/turn-start.php"; }
asst 7200 1000 199000 > "$CT"
C1="$(ctxp c1)"
check "cold → cache expired warning" 'grep -q "cache vypršela (120 min), kontext 200.0k" <<<"$C1" && grep -q "~\$1.60" <<<"$C1" && grep -q systemMessage <<<"$C1"'
check "cold, no block by default"    '! grep -q "\"decision\"" <<<"$C1"'
echo '{"contextWarn":false}' > "$CP/.claude/orch.json"
check "contextWarn false → silent"   '[ -z "$(ctxp c2)" ]'
echo '{"contextWarn":300000}' > "$CP/.claude/orch.json"
check "below limit → silent"         '[ -z "$(ctxp c3)" ]'
rm "$CP/.claude/orch.json"
asst 60 1000 249000 > "$CT"
W1="$(ctxp c4)"; W2="$(ctxp c4)"
check "warm → context warning"       'grep -q "kontext 250.0k: každé volání čte 250.0k" <<<"$W1" && grep -q "zvaž .*compact" <<<"$W1"'
check "warm warning once per band"   '[ -z "$W2" ]'
asst 60 1000 349000 > "$CT"
check "next 100k band warns again"   'grep -q "kontext 350.0k" <<<"$(ctxp c4)"'
echo '{"contextBlock":true}' > "$CP/.claude/orch.json"
asst 7200 1000 199000 > "$CT"
B1="$(ctxp c5)"; B2="$(ctxp c5)"
check "contextBlock blocks cold"     'grep -q "\"decision\":\"block\"" <<<"$B1" && grep -q "/compact" <<<"$B1"'
check "second try passes through"    '! grep -q "\"decision\"" <<<"$B2"'
asst 7200 1000 199000 > "$CT"
check "/compact never blocked"       '! grep -q "\"decision\"" <<<"$(ctxp c6 "/compact")"'
rm "$CP/.claude/orch.json"
echo '{"session_id":"c7","transcript_path":"'"$T"'/missing.jsonl"}' | CLAUDE_CONFIG_DIR="$H5" CLAUDE_PROJECT_DIR="$CP" php -n "$S/turn-start.php"
check "missing transcript → exit 0"  '[ $? -eq 0 ]'
{ asst 7200 1000 199000; echo '{"type":"user","isCompactSummary":true,"message":{"content":"summary"}}'; } > "$CT"
check "after compact → silent"       '[ -z "$(ctxp c8)" ]'
head -c 3000000 /dev/zero | tr '\0' 'x' > "$T/big.jsonl"; echo >> "$T/big.jsonl"; asst 7200 1000 199000 >> "$T/big.jsonl"
check "big transcript tail read"     'php -n -r "require \"$SW/lib-usage.php\"; \$c = orch_last_context(\"$T/big.jsonl\"); exit(\$c && \$c[\"ctx\"] === 200002 && \$c[\"ttl\"] === 3600 ? 0 : 1);"'
CH="$T/home6"; CSL="$(php -n -r 'echo preg_replace("/[^A-Za-z0-9]/","-",$argv[1]);' "$CP")"; mkdir -p "$CH/projects/$CSL"
{ asst 7200 1000 199000; echo '{"type":"custom-title","customTitle":"big work","sessionId":"nb"}'; } > "$CH/projects/$CSL/nb.jsonl"
CS="$(echo '{"session_id":"new9","source":"startup","transcript_path":"'"$CH/projects/$CSL/new9.jsonl"'","cwd":"'"$CP"'"}' | CLAUDE_CONFIG_DIR="$CH" CLAUDE_PROJECT_DIR="$CP" php -n "$S/session-start.php")"
check "resume hint has context size" 'grep -q "claude --resume nb (kontext 200.0k; po --resume nejdřív /compact" <<<"$CS"'
check "stats cold line"              'grep -q "Studená cache" <<<"$(cd "$T" && CLAUDE_CONFIG_DIR="$CH" php -n "$S/stats.php" --days=1 --project="$CP")"'
check "stats --json cold + avg_ctx"  'CLAUDE_CONFIG_DIR="$CH" php -n "$S/stats.php" --days=1 --project="$CP" --json | grep -q "\"avg_ctx\": 200002"'

# ---------------------------------------------------------------------------------------------------------------
# v0.6.0: hook smoke test, UTF-8, timezone, storage hygiene, config.php, parallel-collect.php, run-log last/--open
# ---------------------------------------------------------------------------------------------------------------
slug(){ php -n -r 'echo preg_replace("/[^A-Za-z0-9]/","-",$argv[1]);' "$1"; }
gitc(){ git -c user.email=t@t -c user.name=t "$@"; }

echo "== hook smoke (every hooks.json command: exit 0, empty or JSON output)"
SMK="$T/smoke"; mkdir -p "$SMK"; (cd "$SMK" && git init -q)
SMOKE="$(php -n -r '$h = json_decode(file_get_contents($argv[1]), true);
  foreach ($h["hooks"] as $gs) foreach ($gs as $g) foreach ($g["hooks"] as $x) {
    preg_match("~scripts/([a-z-]+\.php)\"?(.*)$~", $x["command"], $m); echo $m[1], "|", trim($m[2]), "\n"; }' "$ROOT/plugins/orch/hooks/hooks.json")"
while IFS='|' read -r SCRIPT ARGS; do
  [ -z "$SCRIPT" ] && continue
  check "hook script exists: $SCRIPT" '[ -f "$S/$SCRIPT" ]'
  for INPUT in '' 'garbage{' '{}'; do
    SOUT="$(printf '%s' "$INPUT" | CLAUDE_CONFIG_DIR="$SMK/home" CLAUDE_PROJECT_DIR="$SMK" CLAUDE_PLUGIN_OPTION_PRUNE_DAYS=0 php -n "$S/$SCRIPT" $ARGS 2>/dev/null)"; SCODE=$?
    check "smoke $SCRIPT '${INPUT:0:7}' exit 0"  '[ $SCODE -eq 0 ]'
    check "smoke $SCRIPT '${INPUT:0:7}' output"  '[ -z "$SOUT" ] || printf "%s" "$SOUT" | php -n -r "exit(json_decode(stream_get_contents(STDIN)) !== null ? 0 : 1);"'
  done
done <<<"$SMOKE"

echo "== common.php helpers"
mkdir -p "$T/hlp/.claude"
echo '{"a":' > "$T/hlp/.claude/orch.json"
HRES="$(S="$S" HLP="$T/hlp" php -n -r '
require getenv("S") . "/common.php";
$t = function (string $n, bool $c) { echo ($c ? "  ok   " : "  FAIL ") . "$n\n"; };
$t("orch_cut keeps whole characters", orch_cut("čččč", 5) === "čč" && orch_cut("čččč", 4) === "čč" && orch_cut("abc", 5) === "abc");
$t("orch_cut suffix only when cut", orch_cut("ččč", 4, "…") === "čč…" && orch_cut("ab", 5, "…") === "ab");
$t("orch_cut result is valid UTF-8", preg_match("//u", orch_cut(str_repeat("ž", 400), 301, "…")) === 1);
$t("win command: slashes", orch_win_command("vendor/bin/phpunit --colors=never", true) === "vendor\\bin\\phpunit --colors=never");
$t("win command: php prefix untouched", orch_win_command("php vendor/bin/phpunit", true) === "php vendor/bin/phpunit");
$t("win command: other os untouched", orch_win_command("vendor/bin/phpunit", false) === "vendor/bin/phpunit");
$t("win command: plain word untouched", orch_win_command("composer test", true) === "composer test");
$t("slug", orch_slug("C:/a b/c") === "C--a-b-c");
$t("config error for broken json", is_string(orch_config_error(getenv("HLP"))));
$t("config falls back to defaults", orch_config(getenv("HLP"))["team"] === ["planners" => 2, "workers" => 4, "reviewers" => 3]);
file_put_contents(getenv("HLP") . "/big.log", str_repeat("line of a log file\n", 80000));
$t("log cap trims", orch_cap_log(getenv("HLP") . "/big.log") && filesize(getenv("HLP") . "/big.log") < 600000);
$t("log cap starts at a line", str_starts_with(file_get_contents(getenv("HLP") . "/big.log"), "line of a log file\n"));
$t("log cap ignores small", !orch_cap_log(getenv("HLP") . "/big.log"));
$t("git argv with spaces", orch_git(getenv("HLP"), ["rev-parse", "--is-inside-work-tree"])[0] !== 127);
')"
while read -r MARK REST; do [ "$MARK" = ok ] && ok "$REST" || bad "$REST"; done <<<"$HRES"
check "TZ env sets timezone"       '[ "$(php -n -r "putenv(\"TZ=Asia/Tokyo\"); require \"$SW/common.php\"; echo date_default_timezone_get();")" = "Asia/Tokyo" ]'
mkdir -p "$T/tz/.claude"; echo '{"timezone":"Pacific/Auckland"}' > "$T/tz/.claude/orch.json"
check "timezone config key"        '[ "$(env -u TZ php -n -r "require \"$SW/common.php\"; orch_config(\"$T/tz\"); echo date_default_timezone_get();")" = "Pacific/Auckland" ]'

echo "== invalid orch.json is reported"
mkdir -p "$T/bad/.claude"; echo '{nope' > "$T/bad/.claude/orch.json"
BADMSG="$(echo '{"session_id":"b1"}' | CLAUDE_PROJECT_DIR="$T/bad" CLAUDE_CONFIG_DIR="$T/badhome" php -n "$S/session-start.php")"
check "session-start reports it"   'grep -q "není platný JSON" <<<"$BADMSG"'
check "config.php: invalid-json"   'php -n "$S/config.php" --project="$T/bad" | grep -q "invalid-json:"'
check "config.php: defaults kept"  'php -n "$S/config.php" --project="$T/bad" | grep -q "^models={} efforts={} team="'

echo "== config.php"
NOGIT="$(mktemp -d)"; NOGIT="$(cd "$NOGIT" && (pwd -W 2>/dev/null || pwd))"
mkdir -p "$T/cfg/.claude"; (cd "$T/cfg" && git init -q && echo a > a && git add a && gitc commit -qm i)
echo '{"models":{"worker":"haiku"},"efforts":{"reviewer":"high"},"team":{"workers":2},"testCommand":"php t.php"}' > "$T/cfg/.claude/orch.json"
CFG="$(php -n "$S/config.php" --project="$T/cfg" --git)"
check "config line: models"        'grep -q "models={\"worker\":\"haiku\"}" <<<"$CFG"'
check "config line: efforts"       'grep -q "efforts={\"reviewer\":\"high\"}" <<<"$CFG"'
check "config line: team merged"   'grep -q "team={\"planners\":2,\"workers\":2,\"reviewers\":3}" <<<"$CFG"'
check "config line: testCommand"   'grep -q "testCommand=\"php t.php\"" <<<"$CFG"'
check "config line: git dirty+head" 'grep -Eq "git=dirty head=[0-9a-f]{40}" <<<"$CFG"'
check "config line: git none"      'php -n "$S/config.php" --project="$NOGIT" --git | grep -q "git=none"'
echo '{}' > "$T/cfg/.claude/orch.json"; (cd "$T/cfg" && git add -A -f && gitc commit -qm j)
check "config line: git clean"     'php -n "$S/config.php" --project="$T/cfg" --git | grep -q "git=clean head="'

echo "== init writes a portable test command"
mkdir -p "$T/ini/vendor/bin"; echo x > "$T/ini/vendor/bin/phpunit"
php -n "$S/init.php" --project="$T/ini" >/dev/null
check "php vendor/bin/phpunit"     'grep -q "\"testCommand\": \"php vendor/bin/phpunit --colors=never\"" "$T/ini/.claude/orch.json"'

echo "== guard: new rules"
for C in "git checkout HEAD -- ." "git restore -W ." "git restore --staged --worktree ." "git checkout -f main" "git switch --discard-changes dev" \
         "Remove-Item * -Recurse -Force" "Remove-Item . -Recurse" \
         "git checkout -- :/" "git restore :/" "git checkout HEAD -- ':/'" "git restore --worktree :(top)" "git restore -W -- :/." \
         "git -C /tmp/x reset --hard" "git -c core.x=y clean -fd" "git -C \"my dir\" push --force" "git -C sub -c a=b checkout -- ." "git -C sub stash clear"; do
  check "blocks: $C"               '! php -n "$S/guard.php" --check="$C" --project="$T" >/dev/null'
done
for C in "git restore --staged ." "git checkout main" "git checkout -b feat" "Remove-Item ./build -Recurse" \
         "git restore --staged :/" "git checkout -- :/src/a.php" "git -C sub status" "git -c core.x=y push --force-with-lease"; do
  check "allows: $C"               'php -n "$S/guard.php" --check="$C" --project="$T" >/dev/null'
done
mkdir -p "$T/gb/.claude"; echo '{"guard":{"enabled":true,"block":["(unclosed"]}}' > "$T/gb/.claude/orch.json"
for i in 1 2; do echo '{"tool_input":{"command":"ls"}}' | CLAUDE_PROJECT_DIR="$T/gb" php -n "$S/guard.php"; done
check "invalid regex logged once"  '[ "$(grep -c "invalid guard regex" "$T/gb/.claude/orch/guard.log")" -eq 1 ]'
check "invalid regex: --check warns" 'php -n "$S/guard.php" --check=ls --project="$T/gb" 2>&1 | grep -q "invalid regex"'

echo "== UTF-8 safety"
CZ="$(php -n -r 'echo str_repeat("Příliš žluťoučký kůň ", 30);')"
UP="$T/utf"; mkdir -p "$UP"
UID1="$(php -n "$S/run-log.php" start --mode=run --session=u1 --project="$UP" --task="$CZ")"
php -n "$S/run-log.php" end --id="$UID1" --project="$UP" --verdict=APPROVED --summary="$CZ" >/dev/null
check "runs.log stays valid JSON"  'php -n -r "foreach (file(\"$UP/.claude/orch/runs.log\") as \$l) { if (json_decode(\$l) === null) exit(1); } exit(0);"'
check "task cut on a character"    'php -n -r "foreach (file(\"$UP/.claude/orch/runs.log\") as \$l) { \$j = json_decode(\$l, true); if (!preg_match(\"//u\", \$j[\"task\"]) || strlen(\$j[\"task\"]) > 303) exit(1); } exit(0);"'
check "notification payload valid" 'php -n -r "exit(json_decode(file_get_contents(\"$UP/.claude/orch/notify-pending.json\")) !== null ? 0 : 1);"'

echo "== run-log: derived models/skills, --open, last"
RP="$T/rl"; RH="$T/rlhome"; RS="$(slug "$RP")"; mkdir -p "$RP" "$RH/projects/$RS/s9/subagents"
RT="$RH/projects/$RS/s9.jsonl"
mline(){ echo "{\"type\":\"assistant\",\"sessionId\":\"s9\",\"timestamp\":\"$(iso_ago "$1")\",\"message\":{\"id\":\"$2\",\"model\":\"$3\",$4\"usage\":{\"input_tokens\":10,\"output_tokens\":5,\"cache_creation_input_tokens\":0,\"cache_read_input_tokens\":0}}}"; }
mline 300 old1 claude-opus-5-5 '' > "$RT"
echo '{"agentType":"orch:worker"}' > "$RH/projects/$RS/s9/subagents/agent-a.meta.json"
echo "plan" > /dev/null
RID="$(CLAUDE_CONFIG_DIR="$RH" php -n "$S/run-log.php" start --mode=fix --session=s9 --project="$RP" --task="Fix it")"
check "start stores offsets"       'grep -Eq "\"offsets\":\{\"s9.jsonl\":[1-9]" "$RP/.claude/orch/runs.log"'
mline 0 new1 claude-opus-5-5 '' >> "$RT"
mline 0 sub1 claude-sonnet-5-5 '"content":[{"type":"tool_use","name":"Skill","input":{"skill":"my-skill"}}],' > "$RH/projects/$RS/s9/subagents/agent-a.jsonl"
echo "# plan" > "$RP/.claude/orch/plan.md"
CLAUDE_CONFIG_DIR="$RH" php -n "$S/run-log.php" end --id="$RID" --project="$RP" --verdict=CHANGES_REQUESTED --rounds=2 --open="rename the helper | add a test" >/dev/null
RL="$RP/.claude/orch/runs.log"
check "only the run's calls counted" 'grep "\"event\":\"end\"" "$RL" | grep -q "\"calls\":2"'
check "models derived per agent"   'grep "\"event\":\"end\"" "$RL" | grep -q "\"models\":{\"worker\":\"sonnet\"}"'
check "skills derived from subagents" 'grep "\"event\":\"end\"" "$RL" | grep -q "\"skills\":\[\"my-skill\"\]"'
check "open + plan stored"         'grep "\"event\":\"end\"" "$RL" | grep -q "\"open\":\"rename the helper | add a test\".*\"plan\":\".claude/orch/plan.md\""'
LAST="$(php -n "$S/run-log.php" last --project="$RP")"
check "last: text"                 'grep -q "CHANGES_REQUESTED mode=fix" <<<"$LAST" && grep -q "open: rename the helper" <<<"$LAST"'
check "last --json"                'php -n "$S/run-log.php" last --json --project="$RP" | php -n -r "\$j = json_decode(stream_get_contents(STDIN), true); exit(\$j[\"verdict\"] === \"CHANGES_REQUESTED\" && \$j[\"plan\"] === \".claude/orch/plan.md\" ? 0 : 1);"'
check "last: no runs → exit 1"     '! php -n "$S/run-log.php" last --project="$T/tz" 2>/dev/null'

echo "== budget: incremental usage cache"
BP="$T/bp"; BH="$T/bph"; BS="$(slug "$BP")"; mkdir -p "$BP" "$BH/projects/$BS"
BT="$BH/projects/$BS/b1.jsonl"
bline(){ echo "{\"type\":\"assistant\",\"sessionId\":\"b1\",\"timestamp\":\"$(iso_ago 30)\",\"message\":{\"id\":\"$1\",\"model\":\"claude-opus-5-5\",\"usage\":{\"input_tokens\":0,\"output_tokens\":1000000,\"cache_creation_input_tokens\":0,\"cache_read_input_tokens\":0}}}"; }
bline m1 > "$BT"
V1="$(CLAUDE_CONFIG_DIR="$BH" php -n "$S/budget.php" --project="$BP" --status | awk '/dnes/{print $2}')"
check "cache file written"         '[ -f "$BP/.claude/orch/usage-cache.json" ]'
bline m2 >> "$BT"; printf '{"type":"assistant","message":{"id":"half"' >> "$BT"
V2="$(CLAUDE_CONFIG_DIR="$BH" php -n "$S/budget.php" --project="$BP" --status | awk '/dnes/{print $2}')"
V3="$(CLAUDE_CONFIG_DIR="$BH" php -n "$S/budget.php" --project="$BP" --status | awk '/dnes/{print $2}')"
check "new bytes are added"        '[ "$(php -n -r "echo (float)$V2 > (float)$V1 * 1.5 ? 1 : 0;")" = 1 ]'
check "partial last line ignored"  '[ "$V2" = "$V3" ]'

echo "== stop-tests: unchanged tree is not re-tested"
STP="$T/stp"; mkdir -p "$STP/.claude"; (cd "$STP" && git init -q && echo a > a.txt && git add a.txt && gitc commit -qm i)
printf '<?php file_put_contents(%s, "x", FILE_APPEND); exit((int)is_file(%s));' "'$T/stp-count.log'" "'$T/stp-fail'" > "$STP/count.php"
echo '{"testCommand":"php count.php"}' > "$STP/.claude/orch.json"
stp(){ echo '{}' | CLAUDE_PROJECT_DIR="$STP" php -n "$S/stop-tests.php" 2>/dev/null; }
stp; stp
check "same tree → ran once"       '[ "$(wc -c < "$T/stp-count.log")" -eq 1 ]'
echo more >> "$STP/a.txt"; stp
check "changed tree → ran again"   '[ "$(wc -c < "$T/stp-count.log")" -eq 2 ]'
touch "$T/stp-fail"; echo again >> "$STP/a.txt"; stp; CODE1=$?
stp; CODE2=$?
check "failing run is repeated"    '[ "$(wc -c < "$T/stp-count.log")" -eq 4 ] && [ $CODE1 -eq 2 ] && [ $CODE2 -eq 2 ]'
check "no fingerprint kept when red" '[ ! -f "$STP/.claude/orch/tests.json" ]'
rm -f "$T/stp-fail"; stp; stp
check "green again → cached"       '[ "$(wc -c < "$T/stp-count.log")" -eq 5 ]'
echo jedna > "$STP/příliš žluťoučký.txt"; stp
echo "delší obsah souboru" > "$STP/příliš žluťoučký.txt"; stp
check "non-ASCII untracked path fingerprinted" '[ "$(wc -c < "$T/stp-count.log")" -eq 7 ]'

echo "== cleanup hygiene"
HY="$T/hy"; mkdir -p "$HY/.claude/orch/sessions" "$HY/.claude/worktrees/old1"; (cd "$HY" && git init -q && echo a > a && git add a && gitc commit -qm i)
HS="$HY/.claude/orch"
echo p > "$HS/plan-abc.md"; echo p > "$HS/p1.patch"; echo 1 > "$HS/sessions/z.ctxband"; echo 1 > "$HS/sessions/z.ctxcold"; echo p > "$HS/plan.md"
touch_ago 40 "$HS/plan-abc.md" "$HS/p1.patch" "$HS/sessions/z.ctxband" "$HS/sessions/z.ctxcold" "$HY/.claude/worktrees/old1"
printf '{"ts":"%s","session":"o","file":"old.tmp"}\n{"ts":"%s","session":"o","file":"new.tmp"}\n' "$(php -n -r 'echo date("c", time() - 40 * 86400);')" "$(php -n -r 'echo date("c");')" > "$HS/files.log"
php -n -r 'file_put_contents($argv[1], str_repeat("0123456789abcdef\n", 100000)); file_put_contents($argv[2], str_repeat("x", 1100000));' "$HS/guard.log" "$HS/usage-cache.json"
HOUT="$(php -n "$S/cleanup.php" --manual --project="$HY")"
check "old plan-*.md removed"      '[ ! -f "$HS/plan-abc.md" ] && [ -f "$HS/plan.md" ]'
check "old p*.patch removed"       '[ ! -f "$HS/p1.patch" ]'
check "old ctx markers removed"    '[ ! -f "$HS/sessions/z.ctxband" ] && [ ! -f "$HS/sessions/z.ctxcold" ]'
check "files.log trimmed"          '! grep -q old.tmp "$HS/files.log" && grep -q new.tmp "$HS/files.log"'
check "guard.log capped"           '[ "$(wc -c < "$HS/guard.log")" -lt 600000 ]'
check "huge usage cache dropped"   '[ ! -f "$HS/usage-cache.json" ]'
check "old worktree is listed"     'grep -q "Starý worktree" <<<"$HOUT" && [ -d "$HY/.claude/worktrees/old1" ]'
check "tracked tmp kept (batched)" '[ -f tmp_tracked.php ]'

echo "== stats: storage"
SST="$(CLAUDE_CONFIG_DIR="$RH" php -n "$S/stats.php" --days=1 --project="$HY")"
check "stats Úložiště line"        'grep -q "^Úložiště: .claude/orch" <<<"$SST"'
check "stats --json storage"       'CLAUDE_CONFIG_DIR="$RH" php -n "$S/stats.php" --days=1 --project="$HY" --json | grep -q "\"storage\""'

echo "== parallel-collect"
PC="$T/pc"; mkdir -p "$PC"; (cd "$PC" && git init -q && printf 'one\ntwo\nthree\n' > a.txt && echo keep > k.txt && git add . && gitc commit -qm i)
PB="$(git -C "$PC" rev-parse HEAD)"
git -C "$PC" worktree add -q -b worktree-p1 "$PC/wt1" && git -C "$PC" worktree add -q -b worktree-p2 "$PC/wt2" && git -C "$PC" worktree add -q -b worktree-p3 "$PC/wt3"
printf 'ONE\ntwo\nthree\n' > "$PC/wt1/a.txt"; echo new > "$PC/wt1/n.txt"
printf 'uno\ntwo\nthree\n' > "$PC/wt2/a.txt"
echo untouched > "$PC/wt3/k.txt"; git -C "$PC/wt3" checkout -q -- k.txt
PCOUT="$(php -n "$S/parallel-collect.php" --project="$PC" --base="$PB" --worktrees="P1=$PC/wt1,P2=$PC/wt2,P3=$PC/wt3" 2>/dev/null)"; PCCODE=$?
check "P1 applied (2 files)"       'grep -q "^P1 applied (2 files)" <<<"$PCOUT"'
check "P2 conflict is reported"    'grep -q "^P2 failed:" <<<"$PCOUT"'
check "P3 no changes"              'grep -q "^P3 applied (no changes)" <<<"$PCOUT"'
check "exit 1 when one failed"     '[ $PCCODE -eq 1 ]'
check "P1 landed in main tree"     'grep -q ONE "$PC/a.txt" && [ -f "$PC/n.txt" ]'
check "P2 left the tree untouched" '! grep -q uno "$PC/a.txt"'
check "applied worktrees removed"  '[ ! -d "$PC/wt1" ] && [ ! -d "$PC/wt3" ] && ! git -C "$PC" branch | grep -q "worktree-p1"'
check "failed worktree + patch kept" '[ -d "$PC/wt2" ] && [ -s "$PC/.claude/orch/p2.patch" ] && git -C "$PC" branch | grep -q "worktree-p2"'
check "unknown path refused"       'php -n "$S/parallel-collect.php" --project="$PC" --base="$PB" --worktrees="P9=$T" 2>/dev/null | grep -q "P9 failed: .* not a registered worktree"'
check "failure says how long the patch is kept" 'grep "^P2 failed:" <<<"$PCOUT" | grep -q "patch ponechán: .claude/orch/p2.patch (úklid ho smaže po 7 dnech), worktree ponechán: "'
git -C "$PC" worktree add -q -b worktree-p4 "$PC/wt4" "$PB" && printf 'one\ntwo\nTHREE\n' > "$PC/wt4/a.txt"
PC4="$(php -n "$S/parallel-collect.php" --project="$PC" --base="$PB" --worktrees="P4=$PC/wt4" 2>/dev/null)"
check "dirty main file → commit or stash first" 'grep -q "^P4 failed: .*commitni nebo stashni: a.txt;" <<<"$PC4" && ! grep -q THREE "$PC/a.txt" && [ -d "$PC/wt4" ]'
check "dirty check in English"     'echo "{\"lang\":\"en\"}" > "$PC/.claude/orch.json" && php -n "$S/parallel-collect.php" --project="$PC" --base="$PB" --worktrees="P4=$PC/wt4" 2>/dev/null | grep -q "commit or stash them first: a.txt; patch kept: .claude/orch/p4.patch (cleanup deletes it after 7 days)"; R=$?; rm -f "$PC/.claude/orch.json"; [ $R -eq 0 ]'


echo "== language (cs/en)"
LP="$T/lp"; mkdir -p "$LP"
lpp(){ echo "{\"session_id\":\"$1\",\"transcript_path\":\"$CT\",\"prompt\":\"$2\"}" | CLAUDE_CONFIG_DIR="$H5" CLAUDE_PROJECT_DIR="$LP" php -n "$S/turn-start.php"; }
asst 7200 1000 199000 > "$CT"; LC="$(lpp l1 'Udělej prosím login pro uživatele')"
check "Czech prompt → cs message"    'grep -q "cache vypršela" <<<"$LC" && [ "$(cat "$LP/.claude/orch/sessions/l1.lang")" = cs ]'
asst 7201 1000 199000 > "$CT"; LE="$(lpp l2 'add a new button to the header')"
check "English prompt → en message"  'grep -q "cache expired (120 min), context 200.0k" <<<"$LE" && grep -q "Next time /compact first" <<<"$LE" && [ "$(cat "$LP/.claude/orch/sessions/l2.lang")" = en ]'
asst 7400 1000 199000 > "$CT"; LS="$(lpp l2 'ok')"
check "short prompt keeps language"  'grep -q "cache expired" <<<"$LS" && [ "$(cat "$LP/.claude/orch/sessions/l2.lang")" = en ]'
asst 7500 1000 199000 > "$CT"; LS2="$(lpp l1 'hello')"
check "ambiguous prompt keeps cs"    'grep -q "cache vypršela" <<<"$LS2"'
asst 7600 1000 199000 > "$CT"; LF="$(lpp l1 'Udělej prosím login pro uživatele')"
echo '{"lang":"en"}' > "$LP/.claude/orch.json"
asst 7700 1000 199000 > "$CT"; LF="$(lpp l3 'Udělej prosím login pro uživatele')"
check "lang=en forces en"            'grep -q "cache expired" <<<"$LF"'
echo '{"lang":"cs"}' > "$LP/.claude/orch.json"
asst 7800 1000 199000 > "$CT"; LF2="$(lpp l4 'add a new button to the header')"
check "lang=cs forces cs"            'grep -q "cache vypršela" <<<"$LF2"'
rm "$LP/.claude/orch.json"
check "config.php prints lang"       'php -n "$SW/config.php" --project="$LP" | grep -q " lang=\"en\"\$" || [ "$(php -n "$SW/config.php" --project="$LP" --session=l2 | grep -c "lang=\"en\"")" = 1 ]'
check "config.php --session=l2 → en" 'CLAUDE_PROJECT_DIR="$LP" php -n "$SW/config.php" --project="$LP" --session=l2 | grep -q "lang=\"en\""'
check "config.php --session=l1 → cs" 'CLAUDE_PROJECT_DIR="$LP" php -n "$SW/config.php" --project="$LP" --session=l1 | grep -q "lang=\"cs\""'
check "version.php en by session"    'CLAUDE_CONFIG_DIR="$H5" CLAUDE_PROJECT_DIR="$LP" php -n "$SW/version.php" --offline --session=l2 | grep -q "this session:"'
check "version.php cs by session"    'CLAUDE_CONFIG_DIR="$H5" CLAUDE_PROJECT_DIR="$LP" php -n "$SW/version.php" --offline --session=l1 | grep -q "toto sezení:"'
check "version.php default cs"       'CLAUDE_CONFIG_DIR="$H5" CLAUDE_PROJECT_DIR="$T/nolang" php -n "$SW/version.php" --offline | grep -q "toto sezení:"'
check "stats en by session"          'CLAUDE_CONFIG_DIR="$CH" CLAUDE_PROJECT_DIR="$LP" php -n "$S/stats.php" --days=1 --project="$LP" --session=l2 | grep -q "^Storage: .claude/orch"'
check "orch_detect_lang units"       'php -n -r "require \"$SW/common.php\"; exit(orch_detect_lang(\"Udělej prosím login\") === \"cs\" && orch_detect_lang(\"add a new button to the header\") === \"en\" && orch_detect_lang(\"ok\") === null ? 0 : 1);"'
echo
[ $FAIL -eq 0 ] && echo "ALL PASSED" || { echo "SOME TESTS FAILED"; exit 1; }
