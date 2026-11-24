---
name: budget
description: Show this project's estimated spend today and this week against the budget limits, and how to set them.
disable-model-invocation: true
---
Run `php -n "${CLAUDE_PLUGIN_ROOT}/scripts/budget.php" --status --project="${CLAUDE_PROJECT_DIR}"` and show the output in a code block.

Then add at most 3 lines (user's language): limits are set per project in `.claude/orch.json` as `"budget": {"day": 10, "week": 50}` (USD), or for all projects in `/plugin` → orch → Configure (`budget_day_usd`, `budget_week_usd`). When a limit is crossed, orch sends one Discord/WhatsApp message per day/week and shows a warning on the next prompt. USD is an API-price estimate.
