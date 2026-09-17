#!/usr/bin/env bash
#
# What would the NEXT bare `git commit` in this tree do that nobody asked for?
#
#   ./scripts/index-landmines.sh
#
# ── why ──────────────────────────────────────────────────────────
#
# Fifteen sessions share one `.git/index`. Any entry in it is a SNAPSHOT OF THE
# PAST the moment HEAD moves past that path — so the next bare `git commit` by
# anybody re-applies it, silently reverting work that has already landed, under
# a message about something else entirely.
#
# IT DOES NOT TAKE TWO SESSIONS. This header used to say one session stages a
# path and another commits it, which made it sound occasional and like somebody
# else's doing. It is mechanical, and you do it to yourself:
#
#   A private-index commit leaves the SHARED index untouched — which is the
#   whole point of it. So the shared index still holds that path's pre-commit
#   blob, and the instant `update-ref` moves HEAD, that untouched entry becomes
#   an armed revert of the commit you just made.
#
# Observed on a five-line comment-only commit: clean before, `+0 -5` against
# itself after, with nobody having staged anything. Every careful private-index
# commit leaves one behind for every path it touches. Run this AFTER committing,
# not only before.
#
# Three of these appeared on 2026-08-28 alone:
#
#   - a .gitignore from before the credential hardening: committing it would
#     have reinstated `*credentials*`, which does not match CREDENTIALS.md on
#     any case-sensitive filesystem — every Linux checkout, CI runner and
#     GitHub Action;
#   - docs/ACTIVE-CONSTRAINTS.md minus 16 lines;
#   - two www/ pages staged as the exact inverse of the commit that had just
#     landed, found sixty seconds later by a session that happened to run
#     `git status` one more time after calling the work finished.
#
# All three were caught by a person looking. That is not a control.
#
# ── what it can and cannot tell you ──────────────────────────────
#
# STALE means the staged blob is byte-identical to some commit in history:
# a snapshot of a commit main still carries, so not anybody's work in progress, so clearing it
# with `git reset -q HEAD -- <path>` can only remove a landmine.
#
# LIVE means the staged blob matches no commit anywhere. That is somebody's
# edit, staged deliberately, and it is not yours to clear. The script refuses
# to print a clearing command while any of those are present, for the same
# reason tree-blockers.sh refuses on the working tree: a merge, or a tidy
# index, is worth less than the thing you would delete to get it.
#
# `git reset` writes the INDEX only and never the working tree. Its
# "Unstaged changes after reset:" output lists every path where worktree and
# index differ repo-wide, not what it touched — ignore it and verify instead.
set -euo pipefail
# Silence must never read as clean. Under set -e a crash anywhere above the
# summary used to produce no output at all - the same shape as a quiet
# success when piped through tail. Now a crash says so.
trap 'echo "  index-landmines.sh ABORTED before its summary (line $LINENO) - the index is UNKNOWN, not clean" >&2' ERR
cd "$(dirname "$0")/.."

# ── THE SHARED INDEX, WHATEVER SHELL ASKS ────────────────────────
#
# Every git command below reads whatever $GIT_INDEX_FILE names. The question
# this script exists to answer is about the index the NEXT bare `git commit`
# will use — the shared one — so being pointed at a private index makes it
# answer a question nobody asked.
#
# It is worse than useless there, because of the shape of the recipe it exists
# to protect. A private-index commit does this:
#
#     export GIT_INDEX_FILE=/tmp/mine
#     git read-tree "$BASE"; git update-index --add -- <paths>
#     git commit-tree ... ; git update-ref refs/heads/main "$COMMIT" "$BASE"
#     bash scripts/index-landmines.sh          # <- still exported
#
# That private index was built from the tree just committed, so it agrees with
# HEAD by construction. The check CANNOT report anything. It prints "0 stale,
# 0 live", the session records that its index is clean, and the shared index
# goes on holding the pre-commit snapshot this script was written to catch.
#
# Measured on 2026-09-10 rather than argued: the same private index missing one
# file HEAD carries reports "1 stale — staged DELETION"; built from HEAD it
# reports "0 stale, 0 live" no matter what the shared index holds. Four commits
# that day ended with the check running under the export, and a peer found
# three landmines the session had already declared clean — one a staged
# deletion of a migration, which a bare commit would have removed from the repo.
#
# ── WARN, ANSWER ANYWAY, AND STILL FAIL ─────────────────────────
#
# This refused outright at first. Leads argued it back, and they were right:
# exiting leaves the shared index UNEXAMINED at the exact moment landmines are
# made — the second after `update-ref` — which is when somebody most needs the
# answer and least expects to be told to come back.
#
# So: say it loudly, unset the variable for this script's own git calls, and
# report the shared index properly. `unset` here cannot reach the caller — a
# child process never edits its parent's environment — so the shell that ran
# this still has its private index for whatever it does next, exactly as
# before. The objection that stopped me doing this was that unsetting would be
# silent; it is not silent, and it exits non-zero, which is what makes the
# habit correct itself instead of being tutted at.
if [ -n "${GIT_INDEX_FILE:-}" ]; then
  echo "  index-landmines.sh was run with GIT_INDEX_FILE set." >&2
  echo "  That names $GIT_INDEX_FILE, not the shared index — and an index built" >&2
  echo "  from the commit you just made agrees with HEAD by construction, so" >&2
  echo "  reading it would print '0 stale, 0 live' and mean nothing." >&2
  echo "  Ignoring it and reading the SHARED index below. Run this in a separate" >&2
  echo "  shell from the private-index commit and the warning goes away." >&2
  echo >&2
  unset GIT_INDEX_FILE

  # NON-ZERO WHATEVER THE INDEX SAYS, and enforced at exit rather than at one
  # branch: the summary below leaves by several routes, and a clean tree
  # reached the wrong way must still not read as a pass to whatever ran this.
  # A route that already fails keeps its own code.
  trap 'code=$?; [ "$code" = 0 ] && exit 2; exit "$code"' EXIT
fi

HEAD_SHA=$(git rev-parse HEAD)
stale=0
live=0
stale_paths=""

while IFS= read -r f; do
  [ -z "$f" ] && continue

  # What committing this entry would do, relative to HEAD. Deletions are the
  # dangerous direction: they are the revert.
  stat=$(git diff-index --cached --numstat "$HEAD_SHA" -- "$f" | awk '{printf "+%s -%s", $1, $2}')
  [ -z "$stat" ] && continue          # entry agrees with HEAD; nothing staged in effect

  # A STAGED DELETION FIRST, because it has no blob to look up and would
  # otherwise fall through to "live" — protecting the most dangerous entry
  # there is. Committing one removes a file that HEAD has; when that file was
  # just added by somebody else commit, the deletion is the exact inverse of
  # their work.
  #
  # The discriminator is the WORKING TREE. Nobody deletes a file and leaves it
  # on disk, so "staged D, still present in the worktree" is a stale snapshot
  # taken before the file existed — clearable. "Staged D and gone from the
  # worktree" is somebody actually removing it, which is theirs.
  if [ "$(git diff --cached --name-status -- "$f" | cut -f1)" = "D" ]; then
    if [ -f "$f" ]; then
      stale=$((stale + 1)); stale_paths="$stale_paths $f"
      printf '  STALE  %-46s %-10s  staged DELETION of a file HEAD has and the worktree still holds
' "$f" "$stat"
    else
      live=$((live + 1))
      printf '  live   %-46s %-10s  staged deletion, and gone from the worktree — somebody means it
' "$f" "$stat"
    fi
    continue
  fi

  # Is the staged content a snapshot of some commit ON MAIN, or somebody's edit?
  #
  # main, not --all. On 2026-09-04 a commit that had been on main for one
  # minute and was then soft-reset off it survived inside another session's
  # branch (they had merged main inside that minute). --all found it, this
  # script called the staged file STALE, and its clearing advice would have
  # deleted the only copy of twelve lines of unpublished work. A blob that
  # matches a commit main never kept is not a snapshot of anything; it is
  # somebody's work, and it stays LIVE.
  blob=$(git ls-files --stage -- "$f" | awk '{print $2}')
  hit=""
  if [ -n "$blob" ]; then
    hit=$(git rev-list main -- "$f" | while read -r c; do
            b=$(git rev-parse "$c:$f" 2>/dev/null) || continue
            [ "$b" = "$blob" ] && { echo "$c"; break; }
          done | head -1) || true
    # || true: with no match the loop ends on a failed read after a false
    # test, the pipeline returns non-zero, and set -e would kill the script
    # right here - before the "live" line below could print. An empty hit is
    # the answer, not a failure. Found by the Website session on 2026-09-04
    # by staging the case rather than running the script on a clean index.
  fi

  if [ -n "$hit" ]; then
    stale=$((stale + 1))
    stale_paths="$stale_paths $f"
    printf '  STALE  %-46s %-10s  snapshot of %s\n' "$f" "$stat" \
      "$(git log -1 --format='%h %ad %s' --date=short "$hit" | cut -c1-46)"
  else
    live=$((live + 1))
    printf '  live   %-46s %-10s  matches no commit — somebody is working on it\n' "$f" "$stat"
  fi
done < <(git diff --cached --name-only)

# ── a migration whose model is not in git ────────────────────────
#
# A DIFFERENT landmine from anything above, and the one this script missed on
# 2026-08-31. Nothing was staged, so everything here read "0 stale, 0 live" and
# the tree looked clean. Meanwhile a commit had landed a migration creating
# `phone_trust_observations` while its model sat UNTRACKED on disk.
#
# That ships a table to production with no retention policy: invisible to
# `ezbizi:expire-data`, so it accumulates forever. RetentionTest catches it, but
# only twenty minutes into a deploy, and only after it has blocked everybody
# else's release.
#
# An untracked file under app/Models is almost never deliberate for long. It is
# either about to be committed or about to be forgotten, and the second one is
# silent.
UNTRACKED_MODELS=$(git ls-files --others --exclude-standard app/Models/ 2>/dev/null)

if [ -n "$UNTRACKED_MODELS" ]; then
  echo
  echo "  ⚠ MODELS THAT EXIST ONLY ON THIS DISK — git has never seen them:"
  echo "$UNTRACKED_MODELS" | sed 's/^/       /'
  echo
  echo "     If a migration for any of these is already COMMITTED, production"
  echo "     gets the table without the model — no retention policy, nothing to"
  echo "     expire it, and RetentionTest will block every deploy until it lands."
  echo "     Commit them with their migration, or neither."
fi

# ── THE TWO FILES EVERY MODULE EDITS ─────────────────────────────
#
# 2026-09-17: three sessions were bitten by routes/api.php in one afternoon,
# from three different directions, and every one of them was textually clean
# and showed as an ordinary `M`:
#
#   * a stale index holding a pre-merge copy  — 10 insertions, 108 DELETIONS
#   * a formatter — Pint reflowed the whole file while somebody formatted
#     their own five lines. The nastiest of the three, because you run a
#     formatter ON PURPOSE to be careful and it produces the same time bomb
#     as forgetting.
#   * a checkout restoring the file under somebody else's uncommitted work
#
# A whole-file operation on either of these is a silent revert of every other
# module. Insertions tell you nothing; the DELETION count is the instrument.
# The recovery is the same in all three cases: restore the file to HEAD and
# re-apply your own lines by hand.
for shared in routes/api.php app/Providers/ModuleServiceProvider.php; do
  [ -f "$shared" ] || continue
  dels=$(git diff HEAD --numstat -- "$shared" | awk '{print $2+0}')
  [ -n "${dels:-}" ] || continue
  if [ "$dels" -gt 12 ]; then
    echo
    echo "  ** $shared removes $dels lines against HEAD. **"
    echo "     Every module edits that file. Check you are not reverting"
    echo "     somebody else before you commit it:"
    echo "       git diff HEAD -- $shared"
    echo "     If those deletions are not yours: git checkout HEAD -- $shared"
    echo "     then re-apply your own lines by hand."
  fi
done
echo
echo "  $stale stale, $live live"

if [ "$stale" -eq 0 ] && [ "$live" -eq 0 ]; then
  echo "  Nothing staged. The next bare commit would take nothing unexpected."
  exit 0
fi

if [ "$live" -gt 0 ]; then
  cat <<'EOF'

  A live entry is somebody's staged work. Do NOT clear it to tidy the index —
  ask them. No clearing command is printed while one is present, deliberately.
EOF

  # A live entry is dangerous in the OPPOSITE direction from a stale one, and
  # the warning above only covers half of it. Stale entries make you LOSE
  # somebody's landed work. Live ones make you PUBLISH somebody's unfinished
  # work — under whatever message you were writing, from a tree they were still
  # editing.
  #
  # A migration is the sharp case: it ships on the next deploy and runs against
  # production before anybody reviews it. Named separately for that reason.
  ADDED=$(git diff --cached --name-only --diff-filter=A HEAD 2>/dev/null)
  if [ -n "$ADDED" ]; then
    echo
    echo "  And a bare commit right now would PUBLISH these, unfinished:"
    echo "$ADDED" | sed 's/^/       /'
    echo
    echo "  If these are YOURS and you are about to commit them deliberately, this is"
    echo "  the expected reading, and \"0 stale\" above is your all-clear. The script"
    echo "  cannot tell intent from exposure. What it is warning about is the window:"
    echo "  anybody else committing while these sit here publishes them for you."
    MIGS=$(echo "$ADDED" | grep -c '^database/migrations/' || true)
    if [ "${MIGS:-0}" -gt 0 ]; then
      echo
      echo "  ** $MIGS of those is a MIGRATION. ** It would run against production on"
      echo "     the next deploy, before anybody reviewed it. Tell whoever staged it"
      echo "     to work in a worktree: ./scripts/session-worktree.sh <module>"
    fi
  fi
  exit 1
fi

echo
echo "  All stale. Safe to clear (index only, working tree untouched):"
echo "    git reset -q HEAD --$stale_paths"
echo "  Then re-run this to confirm it reads 0 stale, 0 live."
