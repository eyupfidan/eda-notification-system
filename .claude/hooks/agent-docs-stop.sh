#!/usr/bin/env bash
# Claude Code Stop hook: do not let a turn end with structural changes that the agent docs
# (AGENTS.md, docs/ARCHITECTURE.md) do not describe. Runs scripts/check-agent-docs.sh.
input=$(cat)

# Second stop attempt after a block: let it through (no infinite loop).
if printf '%s' "$input" | grep -Eq '"stop_hook_active"[[:space:]]*:[[:space:]]*true'; then
    exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel 2>/dev/null || pwd)}" || exit 0
[ -x scripts/check-agent-docs.sh ] || exit 0

if report=$(scripts/check-agent-docs.sh 2>&1); then
    exit 0
fi

REPORT="$report" python3 -c '
import json, os
print(json.dumps({
    "decision": "block",
    "reason": os.environ["REPORT"] + "\n\nUpdate AGENTS.md (code map, rules) and/or docs/ARCHITECTURE.md "
              "(components, data model, flows, Redis, configuration) so they describe these changes, then finish.",
}))'
