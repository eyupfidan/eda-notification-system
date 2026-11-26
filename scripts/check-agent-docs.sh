#!/usr/bin/env bash
# Fails when structural code changed but neither AGENTS.md nor docs/ARCHITECTURE.md did.
#
# Usage:
#   scripts/check-agent-docs.sh            working tree (incl. untracked) + unpushed commits
#   scripts/check-agent-docs.sh <base>     compare merge-base(<base>, HEAD)..HEAD (used by CI)
#
# "Structural" = files added, deleted or renamed under app/ or tests/, and any change to migrations,
# routes, docker setup, composer.json or config/notifications.php. Plain edits to existing classes
# (bug fixes) do not require a doc update.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [ "$#" -eq 0 ]; then
    # Uncommitted + untracked work, plus commits not pushed yet (so committing first is no way around it).
    unpushed=""
    if upstream=$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null); then
        unpushed=$(git diff --name-status "$upstream"...HEAD)
    fi
    changes=$( { git diff --name-status HEAD; git ls-files --others --exclude-standard | sed 's/^/A\t/'; printf '%s\n' "$unpushed"; } )
else
    changes=$(git diff --name-status "$1"...HEAD)
fi

structural=$(printf '%s\n' "$changes" | awk -F'\t' '
    $1 ~ /^(A|D|R)/ && ($NF ~ /^app\// || $NF ~ /^tests\//) { print $NF; next }
    $NF ~ /^(database\/migrations\/|routes\/|docker\/)/ { print $NF; next }
    $NF ~ /^(docker-compose\.yml|composer\.json|config\/notifications\.php)$/ { print $NF }
' | sort -u)

docs=$(printf '%s\n' "$changes" | awk -F'\t' '$NF ~ /^(AGENTS\.md|docs\/ARCHITECTURE\.md)$/ { print $NF }')

if [ -n "$structural" ] && [ -z "$docs" ]; then
    echo "Structural changes without an update to AGENTS.md or docs/ARCHITECTURE.md:"
    printf '%s\n' "$structural" | sed 's/^/  - /'
    echo
    echo "Describe what was added, removed or renamed in AGENTS.md (code map / rules) and/or"
    echo "docs/ARCHITECTURE.md (components, data model, flows, Redis, configuration)."
    exit 1
fi
