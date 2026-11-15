#!/usr/bin/env bash
# Prints the version a build of this checkout gets: 0.<minor>.<patch>.
# minor comes from version.properties; patch is the number of commits on the first-parent
# history since the one that last changed version.properties. On main every commit is a
# release (.github/workflows/plugin.yml), so patch goes up by one per release.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
minor=$(sed -n 's/^minor=//p' "$REPO/version.properties")
since=$(git -C "$REPO" log -1 --first-parent --format=%H -- version.properties 2>/dev/null || true)
if [ -n "$since" ]; then
	patch=$(git -C "$REPO" rev-list --count --first-parent "$since..HEAD")
else
	patch=0 # version.properties not committed yet
fi
echo "0.$minor.$patch"
