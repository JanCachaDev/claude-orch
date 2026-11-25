---
name: v
description: Short alias of /orch:version — show the loaded, installed and newest orch version.
disable-model-invocation: true
---
Run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/version.php"` and show the output in a code block. Do not run anything else.
If a line starts with `→` and asks for an update or `/reload-plugins`, add one line (user's language) telling the user to run it themselves — `/reload-plugins` is a Claude Code command you cannot run.
