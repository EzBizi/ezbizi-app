#!/usr/bin/env bash
#
# What in this tree would block a merge, and is any of it real?
#
#   ./scripts/tree-blockers.sh
#
# ── why ──────────────────────────────────────────────────────────
#
# Merging a session branch into main happens in the primary tree, and git
# refuses to overwrite a file with local modifications. On a tree that sessions
# are still moving off, that means a merge stops on a list of paths — and the
# only question that matters is whether any of them is somebody's WORK or just
# noise.
#
# Doing that by hand cost several rounds on the first branch handoff: fourteen
# files blocked a merge, thirteen were byte-identical to HEAD apart from line
# endings, and exactly one was a session's uncommitted test.
#
# Line endings are the common case here. Git reports a file as modified when the
# worktree has CRLF and the index has LF, and `git diff` then shows no content
# change at all — so `git status` says modified while `git diff` says nothing,
# which is exactly the kind of disagreement that wastes time under pressure.
#
# NOISE is safe to clear with `git checkout HEAD -- <path>`.
# REAL is somebody's work: rescue it, or ask them, and never both-at-once.
set -euo pipefail
cd "$(dirname "$0")/.."

noise=0
real=0

while IFS= read -r f; do
  [ -z "$f" ] && continue
  [ -f "$f" ] || continue

  # Compare against HEAD with line endings normalised. If they match, the only
  # difference is how the lines end and nothing anybody typed is at stake.
  a=$(git show "HEAD:$f" 2>/dev/null | tr -d '\r' | sha256sum | cut -c1-16) || continue
  b=$(tr -d '\r' < "$f" | sha256sum | cut -c1-16)

  if [ "$a" = "$b" ]; then
    noise=$((noise + 1))
    printf '  noise  %s\n' "$f"
  else
    real=$((real + 1))
    stat=$(git diff --numstat HEAD -- "$f" | awk '{printf "+%s -%s", $1, $2}')
    printf '  REAL   %s  %s\n' "$f" "$stat"
  fi
done < <(git diff --name-only HEAD)

echo
echo "  $noise line-ending only, $real with real changes"

if [ "$real" -gt 0 ]; then
  cat <<'EOF'

  The REAL ones are somebody's uncommitted work. Do not clear them to unblock
  yourself — save a copy and tell the owner, or wait. A merge is worth less than
  the thing you would delete to get it.
EOF
fi

if [ "$noise" -gt 0 ] && [ "$real" -eq 0 ]; then
  echo
  echo "  Nothing real. Safe to clear the noise:"
  echo "    git diff --name-only HEAD | xargs git checkout HEAD --"
fi
