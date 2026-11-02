#!/usr/bin/env bash
#
# Build and deploy EzBizi.
#
#   ./scripts/deploy.sh          code + UI
#   ./scripts/deploy.sh --code   code only (skip the Flutter build)
#
# Two halves, because they travel differently:
#
#   CODE  goes through git — versioned, atomic, rollback-able.
#   UI    is a 36 MB derived artifact, so it is built here and uploaded to a
#         shared directory on the server. Committing it would add a fresh
#         36 MB blob to history on every rebuild, permanently.
#
set -euo pipefail

REMOTE=app                       # ~/.ssh/config alias
# The box's bare repo, pushed to BY URL. There is deliberately no git remote
# named "production" any more: on 2026-09-08 a session ran a plain
# `git push production main` believing it was pushing to GitHub, and the
# post-receive hook deployed it — no staging, no desk. A remote nobody has
# cannot be pushed to by accident; a release still gets here through this
# script and its receive-pack.
PRODUCTION_REPO="$REMOTE:/var/repos/ezbizi.git"
SHARED=/var/www/ezbizi/public-app
# GATES ARE OFF BY DEFAULT. Pass --gates to run them.
#
# Jose's decision, 1 Sep, made twice: first "no gate, i need it fast", then
# "skip gates should be the default". The concern — that this is the one control
# stopping a red build reaching production — was put to him and he confirmed. It
# is his call and it is recorded here so nobody quietly reverts it.
#
# What decided it: the v0.0.0130 run spent 24 minutes passing 2693 backend tests
# and then refused to deploy over two *info* lints in a test file
# (no_leading_underscores_for_local_identifiers). `flutter analyze` exits
# non-zero on info, so two variable names blocked nine commits of work. A gate
# that stops a release for something that cannot break anything is one people
# route around, and routing around it is worse than turning it off honestly.
#
# --skip-gates is still accepted so existing callers and scripts do not break.
SKIP_GATES=1
CODE_ONLY=""
DEPLOY_SHA=""
while [ $# -gt 0 ]; do
  case "$1" in
    --skip-gates) SKIP_GATES=1; shift ;;
    --gates)      SKIP_GATES=0; shift ;;
    --code)       CODE_ONLY=--code; shift ;;
    --sha)        DEPLOY_SHA="${2:?--sha needs a full commit SHA}"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

cd "$(dirname "$0")/.."

# ── tests before anything ships ──────────────────────────────────
# There is no CI yet (see DEPLOY.md), so this is the gate. Deploying a red
# build is the one thing the pipeline cannot undo for you.
#
# The working tree is synced to the box FIRST. Without that, the gate ran
# against whatever was last left in the sandbox — reporting green for code
# that was never tested, which is worse than no gate at all. Releases install
# --no-dev and have no phpunit, so this sandbox (full vendor, own .env, own
# test database) is where tests run.
#
# `git archive "$GATED_SHA"` — the exact tree about to ship, not the working directory
# and not whatever was last left on the box. The old gate ran against a stale
# sandbox and reported green for code that was never tested, which is worse than
# no gate at all.
#
# Commit before deploying: this tests main, not your uncommitted edits.
#
# The SHA is pinned here, once, and everything downstream uses it. Thirteen
# sessions share this working tree, and main moves while the gate runs — the
# suite takes about sixteen minutes. Gating `main` and then pushing `main`
# means anything committed in between ships having been tested by nothing,
# and the announcement protocol that was supposed to prevent it relies on
# everyone being careful at exactly the moment they are busiest. Asking
# people to be careful is not a control; pinning the SHA is.
if [ -n "$DEPLOY_SHA" ]; then
  [[ "$DEPLOY_SHA" =~ ^[0-9a-f]{40}$ ]] || { echo "--sha must be a full commit SHA" >&2; exit 2; }
  GATED_SHA=$(git rev-parse --verify "$DEPLOY_SHA^{commit}")
  [ "$GATED_SHA" = "$DEPLOY_SHA" ] || exit 2
else
  # Standalone deployment intentionally defaults to main. The orchestrator
  # always supplies its immutable release commit, including from detached HEAD.
  GATED_SHA=$(git rev-parse main)
fi
if [ -n "${EZBIZI_RELEASE_SHA:-}" ] && [ "$GATED_SHA" != "$EZBIZI_RELEASE_SHA" ]; then
  echo "✗ deploy SHA disagrees with the orchestrator release SHA" >&2; exit 1
fi

if [ "$SKIP_GATES" -eq 1 ]; then
  echo "⚠ GATES SKIPPED (--skip-gates) — $GATED_SHA is being shipped untested."
  BACKEND_TESTS=""
else
echo "→ testing the tree about to ship ($GATED_SHA)"
# Captured, not just streamed. STATUS.md's test count was retyped by hand and was
# wrong three times in one day; the number is right here, in the run that gates
# the deploy, so it is taken from there rather than remembered.
# Every top-level entry the archive carries, so nothing tracked can be left
# behind stale in the sandbox. See the note beside `rm -rf $WIPE` below.
WIPE=$(git ls-tree --name-only "$GATED_SHA" | grep -vx storage | tr '
' ' ')
# `-c core.autocrlf=false`, and it is not cosmetic. This archive is built HERE,
# on Windows, where core.autocrlf=true — and `git archive` applies that policy on
# the way out. So the tar that lands on the Linux sandbox had a CR injected into
# every line of every file git considers text, while PRODUCTION is fed by
# `git push` and checks out on the box with its own config, in LF.
#
# Measured on 2026-08-31: 1791 of 2131 files differed, 430 KB of pure CR. The gate
# was therefore testing a tree that production never receives, on every deploy it
# has ever run.
#
# It stayed invisible because PHP does not care about line endings. What finally
# showed it was one anchored regex — `/^Stable tag:\s*(\S+)$/m` against the
# plugin readme, where `$` cannot match with a CR sitting before the newline. A
# green suite was never evidence the trees matched; it was evidence that almost
# nothing in the suite can tell.
#
# Do NOT fix this by setting core.autocrlf in the repo config. That changes every
# checkout for every session on this machine and shows the whole tree as modified.
# The conversion is only wrong for what we SHIP, so it is turned off only here.
# The gate runs DETACHED on the box and is polled, not streamed (2026-10-05).
#
# It used to run inside one SSH session for its whole ~25 minutes. Three times in
# one afternoon that session died ("client_loop: send disconnect: Connection reset
# by peer"; sshd and the kernel logged nothing) while phpunit kept going on the
# box, orphaned and holding the lock, and the deploy failed for a reason that had
# nothing to do with the code. Each cost a full rerun.
#
# So the archive and the script are uploaded, the script is started with setsid
# so no disconnect can reach it, and short SSH calls ask for its exit status. A
# dropped poll is simply retried; the gate never notices. GATE_RUN names this
# run's files, so a rerun of the same SHA can never read an earlier run's result.
# `.gate-runs` sits inside the sandbox but is not a top-level entry of the
# archive, so $WIPE never touches it; files older than a week are removed.
GATE_RUNS=/opt/ezbizi-test/.gate-runs
GATE_RUN="${GATED_SHA}-$(date +%s)-$$"
GATE_LOCAL=$(mktemp -d)
{
  printf 'WIPE=%q\nGATE_RUNS=%q\nGATE_RUN=%q\n' "$WIPE" "$GATE_RUNS" "$GATE_RUN"
  # The exit status lands in $GATE_RUN.status whatever happens: that file is
  # what deploy.sh polls for, so a gate that dies early still answers.
  echo 'trap '"'"'rc=$?; echo "$rc" > "$GATE_RUNS/$GATE_RUN.status.tmp"; mv "$GATE_RUNS/$GATE_RUN.status.tmp" "$GATE_RUNS/$GATE_RUN.status"'"'"' EXIT'
  cat <<'GATE'
  set -e
  # /opt, not /var/www. The sandbox holds dev dependencies and a full Laravel
  # tree; sitting it beside the production .env is what let `--env=testing` fall
  # back to production and wipe it. There is no .env here at all.
  mkdir -p /opt/ezbizi-test
  # Two deploys sharing this directory is how bootstrap/app.php vanished
  # mid-suite: the second `rm -rf bootstrap` while the first was still in
  # phpunit. The test database is shared too, so they cannot run in parallel
  # even in separate trees. Wait rather than wipe.
  exec 9>/opt/ezbizi-test/.deploy-gate.lock
  flock -w 3600 9 || { echo "sandbox gate locked by another deploy — wait and retry"; exit 1; }
  cd /opt/ezbizi-test
  # Removed files must disappear too, or a deleted class keeps passing.
  #
  # This used to be a hand-written list of eight directories, and it was wrong
  # twice for the same reason: a directory is only added to it after a test goes
  # looking and fails. `client` was added when ClientCallsRealRoutesTest found
  # seven dead API calls in `client/lib/screens/workflows_screen.dart` — a file
  # deleted in the workflows-to-automations rename and still sitting here from an
  # extraction on 10 August. On 2026-08-28 `public` was missing from the list, and
  # the WebManagementTest guard against the snippet moving back into public/ went
  # red against a stale `public/ezbizi.js` while the tree it was gating did not
  # contain that file at all. A correct commit, refused.
  #
  # So the list is no longer written by hand. $WIPE is every top-level entry the
  # archive is about to provide, derived from that same tree listing, which means
  # a directory can never be forgotten again. `storage` is the one exclusion: it
  # is tracked, but it holds no source — only logs, and the refusal log is worth
  # more than the churn. Untracked things (vendor, .env.testing, .git) are absent
  # from the tree listing and therefore survive, which is the point.
  #
  # A ghost that only exists in the gate is the worst place for one: it fails
  # honest work, and it reads as "your change is broken" rather than "this
  # directory is stale".
  rm -rf $WIPE
  # Untracked, so $WIPE cannot reach it, and it is a documented way to lose a
  # run: it grows until phpunit dies inside DefaultResultCache on a 128M limit.
  rm -f .phpunit.result.cache
  tar -x -f "$GATE_RUNS/$GATE_RUN.tar"
  rm -f "$GATE_RUNS/$GATE_RUN.tar"
  # Releases install --no-dev and have no phpunit; this sandbox keeps dev deps,
  # its own .env and its own test database.
  composer install --no-interaction -q
  php8.4 -d memory_limit=1G vendor/bin/phpunit --colors=never --log-junit /opt/ezbizi-test/backend-junit.xml
  php8.4 scripts/phpunit-count.php /opt/ezbizi-test/backend-junit.xml
GATE
} > "$GATE_LOCAL/gate.sh"
git -c core.autocrlf=false archive "$GATED_SHA" > "$GATE_LOCAL/tree.tar"
gate_ssh() {  # a short call to the box, retried: a dropped connection is not a failed gate
  local try
  for try in 1 2 3 4 5 6; do
    ssh -o ConnectTimeout=20 "$REMOTE" "$@" < /dev/null && return 0
    sleep 10
  done
  return 1
}
gate_put() {  # gate_put <local file> <remote path>: re-opened on every try, and the size checked
  local try size
  size=$(wc -c < "$1" | tr -d ' ')
  for try in 1 2 3 4 5 6; do
    ssh -o ConnectTimeout=20 "$REMOTE" "cat > '$2.part' && [ \$(stat -c %s '$2.part') = $size ] && mv '$2.part' '$2'" < "$1" && return 0
    sleep 10
  done
  return 1
}
if ! { gate_ssh "mkdir -p '$GATE_RUNS' && find '$GATE_RUNS' -type f -mtime +7 -delete" \
    && gate_put "$GATE_LOCAL/tree.tar" "$GATE_RUNS/$GATE_RUN.tar" \
    && gate_put "$GATE_LOCAL/gate.sh" "$GATE_RUNS/$GATE_RUN.sh" \
    && gate_ssh "setsid nohup bash '$GATE_RUNS/$GATE_RUN.sh' > '$GATE_RUNS/$GATE_RUN.log' 2>&1 < /dev/null &"; }; then
  rm -rf "$GATE_LOCAL"; echo "✗ could not start the gate on the box — nothing deployed"; exit 1
fi
rm -rf "$GATE_LOCAL"
echo "   gate running on the box: $GATE_RUNS/$GATE_RUN.log"
GATE_STATUS=""
for ((poll = 1; poll <= 450; poll++)); do   # 450 x 20 s = 2.5 h: an hour's lock wait, then the run
  sleep 20
  GATE_STATUS=$(ssh -o ConnectTimeout=20 "$REMOTE" "cat '$GATE_RUNS/$GATE_RUN.status' 2>/dev/null" 2>/dev/null || true)
  [ -n "$GATE_STATUS" ] && break
done
BACKEND_OUT=$(gate_ssh "cat '$GATE_RUNS/$GATE_RUN.log'" 2>&1 || echo "(could not read $GATE_RUNS/$GATE_RUN.log)")
[ -n "$GATE_STATUS" ] || { echo "$BACKEND_OUT" | tail -20; echo "✗ the gate did not answer within 2.5 hours — nothing deployed ($GATE_RUNS/$GATE_RUN.log)"; exit 1; }
[ "$GATE_STATUS" = "0" ] || { echo "$BACKEND_OUT"; echo "✗ backend tests failed — nothing deployed"; exit 1; }
echo "$BACKEND_OUT" | tail -3
BACKEND_TESTS=$(echo "$BACKEND_OUT" | sed -n 's/^EZBIZI_BACKEND_TESTS=\([0-9][0-9]*\)$/\1/p' | tail -1)
[ -n "$BACKEND_TESTS" ] || { echo "✗ backend test report missing — nothing deployed"; exit 1; }
fi

if [ "$CODE_ONLY" != "--code" ]; then
  # The client is checked and built in a WORKTREE AT THE PINNED SHA, never in
  # the working directory.
  #
  # The backend half has always tested `git archive` — the tree about to ship.
  # This half did not: it ran `flutter analyze` where you are standing. Thirteen
  # sessions share this directory, so that meant any one of them holding a
  # half-written widget blocked everybody's deploy. It happened: six analyze
  # errors, all from two sessions' uncommitted edits, none of them in the commit
  # being shipped. The same tree at its pinned SHA analyzed with no issues.
  #
  # The false PASS is the worse half, and it is why this is a worktree rather
  # than a nicer error message. Somebody's uncommitted local fix could make
  # analyze green for code that does not contain the fix, and the broken build
  # would ship having been checked and approved.
  #
  # A worktree rather than `git archive` because build-web.sh asks git who it is
  # (`rev-parse --short HEAD`) and whether the tree is dirty. An archive has no
  # .git and both questions fail. A worktree has real git, and is clean by
  # construction — so build-web.sh's dirty-tree refusal passes on its own terms
  # and stamps a version that can actually be reproduced from the commit.
  #
  # Nothing is linked into it. A junction from a worktree back to the main tree
  # is what deleted this repo's vendor/ once already — `git worktree remove`
  # follows it and deletes through. Flutter needs no vendor, so there is nothing
  # to link and no reason to.
  WORKTREE=$(mktemp -d)
  trap 'git worktree remove --force "$WORKTREE" >/dev/null 2>&1 || true' EXIT
  git worktree add --detach "$WORKTREE" "$GATED_SHA" >/dev/null 2>&1 || {
    echo "✗ could not check out $GATED_SHA to test — nothing deployed"; exit 1;
  }

  # `pub get` runs either way — it is not a gate, the web build below needs it.
  if [ "$SKIP_GATES" -eq 1 ]; then
    echo "⚠ client analyze + tests SKIPPED (--skip-gates)"
  else
    echo "→ client analyze + tests (at $GATED_SHA, not your working tree)"
  fi
  CLIENT_OUT=$( (cd "$WORKTREE/client" && flutter pub get \
    && { [ "$SKIP_GATES" -eq 1 ] || { flutter analyze && flutter test; }; }) 2>&1 ) || {
    echo "$CLIENT_OUT"; echo "✗ client checks failed — nothing deployed"; exit 1;
  }

  # `flutter pub get` rewrites the generated plugin registrants for linux, macos
  # and windows, and those files are tracked. So the worktree — which was
  # pristine by construction, the whole reason for using one — is dirty by the
  # time build-web.sh looks, and its refusal to stamp an unreproducible build
  # fires on artifacts the web build never reads.
  #
  # Restoring the worktree to the pinned commit is the honest fix rather than
  # passing --allow-dirty: the build then genuinely is the commit it claims,
  # and the guard keeps its meaning for the case it was written for.
  git -C "$WORKTREE" checkout -- . 2>/dev/null || true
  echo "$CLIENT_OUT" | tail -2
  # `|| true` because this file runs under `set -euo pipefail`, and with
  # --skip-gates there is no "+1188" in the output to find. grep exits 1,
  # pipefail promotes it to the pipeline's status, and set -e then kills the
  # deploy — silently, between the pub get and the web build, with no error
  # printed. That is exactly how the first v0.0.0129 run failed: web never
  # shipped, and the only visible symptom was output stopping mid-file.
  # An absent test count is expected here, not an error.
  CLIENT_TESTS=$(echo "$CLIENT_OUT" | grep -oE '\+[0-9]+' | tail -1 | tr -d '+') || true

  echo "→ building web"
  # Same-origin by default, so the built app talks to whatever host serves it
  # and can never point at the wrong API.
  # No --base-href: the default already is "/", and the app IS the site
  # (app.ezbizi.com), not a subdirectory. Passing it explicitly is worse than
  # useless here — Git Bash on Windows rewrites a lone "/" into a drive path and
  # the build fails with a base-href of "C:/Program Files/Git/".
  # One script, so a build can never ship without its version stamped in.
  (cd "$WORKTREE" && scripts/build-web.sh)

fi

# Stage artifacts first; the receive wrapper activates code before UI under one lock.
echo "→ pushing code"
# Pinning the SHA closes the window where main MOVES. It does not close the
# window where main is REWRITTEN. If a session amends or rebases published main
# while this runs, the gated commit can stop being reachable from the branch —
# and pushing it anyway would ship code that no longer exists on main, silently.
# Checked here rather than assumed, because an abandoned commit reaching
# production looks exactly like a successful deploy.
git merge-base --is-ancestor "$GATED_SHA" main || {
  echo "✗ $GATED_SHA is no longer an ancestor of main — somebody rewrote history"
  echo "  while this deploy ran. Nothing pushed."
  # What main became, not just that it changed: a guard that fires and says only
  # "refusing" sends the next person to re-derive what it already knew.
  echo "  main is now $(git rev-parse --short main):"
  git log --oneline "$GATED_SHA"..main | sed 's/^/    /'
  echo "  Re-run to gate what main is now."
  exit 1
}

# Upload immutable artifacts before acquiring the server's API/UI activation lock.
DEPLOY_STAGE=$(ssh "$REMOTE" "mktemp -d /tmp/ezbizi-deploy.XXXXXXXX")
[[ "$DEPLOY_STAGE" =~ ^/tmp/ezbizi-deploy\.[a-zA-Z0-9]+$ ]] || exit 1
git -c core.autocrlf=false archive "$GATED_SHA" deploy/post-receive deploy/receive-release | ssh "$REMOTE" "tar -x --strip-components=1 -C '$DEPLOY_STAGE'"
UI_STAGE=code
if [ "$CODE_ONLY" != "--code" ]; then
  UI_STAGE=$(ssh "$REMOTE" "mktemp -d '$SHARED.stage.XXXXXXXX'")
  [[ "$UI_STAGE" =~ ^/var/www/ezbizi/public-app\.stage\.[a-zA-Z0-9]+$ ]] || exit 1
  printf '%s\n' "$GATED_SHA" > "$WORKTREE/client/build/web/.ezbizi-release-sha"
  tar -cz -C "$WORKTREE/client/build/web" . | ssh "$REMOTE" "tar -xz -C '$UI_STAGE'"
fi
# receive-release holds one server flock through the hook and UI activation.
# Explicit SSH command arguments are restricted above to fixed paths and hex SHA.
git push --receive-pack="bash $DEPLOY_STAGE/receive-release $GATED_SHA $UI_STAGE" "$PRODUCTION_REPO" "$GATED_SHA":main
# GitHub gets what actually shipped, and only once it has shipped.
#
# Until 2026-08-28 every commit in this project existed on one disk. The backup
# is worth nothing if it is a step somebody has to remember, so it runs here
# rather than by hand — and AFTER the production push, so origin never shows a
# release that did not land.
#
# Non-fatal on purpose. GitHub being unreachable is not a reason to fail a
# deploy that already succeeded; it is a reason to say so and let the next
# deploy catch up. The failure is announced rather than swallowed, because a
# backup that quietly stops is worse than one that was never set up.
if git remote get-url origin >/dev/null 2>&1; then
  echo "→ pushing $GATED_SHA to GitHub"
  git push origin "$GATED_SHA":main 2>&1 | tail -2 || {
    # Most often this is not a failure at all: a session pushed to origin
    # directly, so origin is AHEAD of the pinned SHA and the push is refused as
    # a non-fast-forward. Say which it is rather than guessing, because "origin
    # is behind" sent me looking for a broken backup that was already fine.
    if git merge-base --is-ancestor "$GATED_SHA" origin/main 2>/dev/null; then
      echo "  (origin already carries $GATED_SHA — it is ahead, not behind)"
      git push origin main 2>&1 | tail -1 || true
    else
      echo "⚠ GitHub push FAILED and origin does NOT carry this release."
      echo "  Run: git push origin main"
    fi
  }
fi

# The four volatile rows of STATUS.md, from the things themselves. This is the
# one moment all of them are known at once — both suites have just run and the
# release is live — and the reason it is automated at all is that every one of
# them was hand-written, correct at the time, and wrong within hours.
echo "→ refreshing the STATUS numbers"
scripts/status-numbers.sh "${BACKEND_TESTS:-}" "${CLIENT_TESTS:-}" || true

# After both halves of the release, not between them. The watchdog is a
# snapshot in /usr/local/bin, not a symlink into the release — so a fix that
# only lives in deploy/ stays dead on the box until something copies it.
# First install is still the paste in DEPLOY.md (missing dest is a no-op, so
# a box that never had the units does not get them). After that, copy only
# when md5 differs.
#
# This step is housekeeping. A sudo prompt, a missing source, or a
# daemon-reload failure must not abort a deploy that already pushed the API
# — that would leave a new backend behind an old UI, the state the code-then-
# UI order exists to avoid. Keep set -e inside the remote shell so a bad
# step still stops *itself*; swallow the ssh exit so the deploy does not.
#
# This SSH is the app box. watch-peer-app.sh lives on 32.194.222.151 and is
# not updated here. The systemd drop-in that supplies EZBIZI_PEER_URL is
# local config and is never overwritten.
echo "→ refreshing the peer watchdog if the live copy drifted"
ssh "$REMOTE" 'set -euo pipefail
ROOT=$(readlink -f /var/www/ezbizi-current 2>/dev/null || readlink -f /var/www/ezbizi)
updated=0
refresh() {
  src=$1 dest=$2 mode=$3
  if [ ! -f "$dest" ]; then
    return 0
  fi
  if [ ! -f "$src" ]; then
    echo "missing $src" >&2
    return 1
  fi
  a=$(md5sum "$src"); a=${a%% *}
  b=$(md5sum "$dest"); b=${b%% *}
  if [ "$a" = "$b" ]; then
    return 0
  fi
  sudo install -m "$mode" "$src" "$dest"
  echo "updated $dest"
  updated=1
}
# The backup verifier runs from a SNAPSHOT in /usr/local/bin, not from the
# release. On 2026-08-28 its six-table list was replaced with a derived one
# (f52faa8) — and the fix sat in deploy/ through four releases while the copy
# that actually runs every night kept the old list. Nothing looked wrong: the
# file was in every release, git said it was fixed, and the verifier went on
# checking the six tables a truncated dump leaves full.
#
# Same lesson as the peer watchdog directly below, which is why it now sits
# beside it: shipping a file into deploy/ is not installing it.
refresh "$ROOT/deploy/restore-check.sh" /usr/local/bin/ezbizi-restore-check.sh 0755
# backup.sh is identical to its installed copy today, and is here so that stays
# true. Every script in deploy/ that runs from /usr/local/bin belongs on this
# list; refresh() is a no-op when the destination does not exist, so listing one
# a box has never installed costs nothing.
refresh "$ROOT/deploy/backup.sh" /usr/local/bin/ezbizi-backup.sh 0755
refresh "$ROOT/deploy/watch-peer-websites.sh" /usr/local/bin/ezbizi-watch-peer-websites.sh 0755
refresh "$ROOT/deploy/ezbizi-watch-peer.service" /etc/systemd/system/ezbizi-watch-peer.service 0644
refresh "$ROOT/deploy/ezbizi-watch-peer.timer" /etc/systemd/system/ezbizi-watch-peer.timer 0644
refresh "$ROOT/deploy/ezbizi-watch-peer-failed.service" /etc/systemd/system/ezbizi-watch-peer-failed.service 0644
refresh "$ROOT/deploy/ezbizi-transcribe.service" /etc/systemd/system/ezbizi-transcribe.service 0644
refresh "$ROOT/deploy/ezbizi-transcribe.timer" /etc/systemd/system/ezbizi-transcribe.timer 0644
refresh "$ROOT/deploy/ezbizi-label.service" /etc/systemd/system/ezbizi-label.service 0644
refresh "$ROOT/deploy/ezbizi-label.timer" /etc/systemd/system/ezbizi-label.timer 0644
refresh "$ROOT/deploy/ezbizi-worker-staff.service" /etc/systemd/system/ezbizi-worker-staff.service 0644
if [ "$updated" -eq 1 ]; then
  sudo systemctl daemon-reload
else
  echo "peer watchdog unchanged"
fi
' || echo "warning: peer watchdog refresh failed; box may be on an older copy" >&2

# Live URLs that no test in this repo can check.
#
# On 2026-08-28 /api/v1/web/ezbizi.js returned an nginx 404 for a week while the
# route was registered, the controller correct and the file in the release.
# `location /api` was a plain prefix and the static-asset block is a regex, and a
# regex location outranks a plain prefix one — so every API path ending in an
# asset extension was answered from the Flutter root. Every in-repo check was
# green throughout, because they all exercise Laravel router and nginx sits
# upstream of anything the test framework can reach.
#
# CONTENT-TYPE, not just status. A 200 of the Flutter index.html was the other
# way this fails, and a status-only assertion would have passed it.
#
# Not only routes with a literal extension: any route whose last segment is a
# user-supplied parameter can be pushed into that regex by the caller
# (/api/v1/web/sites/foo.js). Nothing reaches a controller, but the wrong 404
# comes back. Add a line here for anything with that shape.
# What the SCHEDULER holds, asked of the scheduler.
#
# A diff of routes/console.php proves a line was added to a file. It does not
# prove the scheduler took it — which is the failure mode this exists for. On
# 2026-08-28 ezbizi:publish-due-social-posts had to be confirmed by hand on the
# box, because a schedule entry that silently does not register looks exactly
# like one that does: the posts sit at "scheduled" forever and nothing goes red.
#
# It keeps the PREVIOUS release list and prints only what changed. Printing the
# whole list every time is the weak form — a list nobody reads is not a control,
# and a command that disappears has to show up as an ABSENCE to be noticed at
# all. Proven both ways against the box before shipping: a doctored baseline
# reported one addition and one disappearance rather than only ever saying fine.
#
# The empty guard matters more than it looks. If schedule:list fails, the list
# is empty and every command reads as REMOVED — a fleet-wide false alarm, which
# is how a check teaches people to ignore it.
echo "→ what the scheduler actually holds"
ssh "$REMOTE" '
  cd /var/www/ezbizi-current
  PREV=/var/www/ezbizi/last-schedule.txt
  NOW=$(mktemp)
  sudo -u www-data php8.4 artisan schedule:list 2>/dev/null | grep -oE "artisan [a-z0-9:._-]+" | sed "s/^artisan //" | sort -u > "$NOW"
  if [ ! -s "$NOW" ]; then
    echo "   ✗ schedule:list returned nothing — the scheduler holds no commands, or the call failed"
  elif [ -f "$PREV" ]; then
    A=$(comm -13 "$PREV" "$NOW"); R=$(comm -23 "$PREV" "$NOW")
    if [ -z "$A" ] && [ -z "$R" ]; then
      echo "   ok   $(wc -l < "$NOW") scheduled commands, unchanged"
    else
      [ -n "$A" ] && echo "$A" | sed "s/^/   + now scheduled: /"
      [ -n "$R" ] && echo "$R" | sed "s/^/   - NO LONGER scheduled: /"
    fi
    cp "$NOW" "$PREV"
  else
    echo "   (first run) $(wc -l < "$NOW") scheduled commands recorded as the baseline"
    cp "$NOW" "$PREV"
  fi
  rm -f "$NOW"
' || echo "⚠ could not read the schedule from the box" >&2

echo "→ smoke: URLs only the live server can answer"
while read -r url want; do
  [ -z "$url" ] && continue
  got=$(curl -sS -o /dev/null -w "%{http_code} %{content_type}" "$url" || echo "no answer")
  case "$got" in
    "$want"*) echo "   ok   $url" ;;
    *)        echo "   ✗    $url -> $got (wanted $want)" >&2 ;;
  esac
done <<SMOKE
https://app.ezbizi.com/api/v1/web/ezbizi.js 200 application/javascript
https://app.ezbizi.com/api/v1/web/public/values 400 application/json
SMOKE

# The last unverified hop. The UI does not travel through git — it is a 36 MB
# tarball unpacked into a shared directory — so everything above proves what the
# SERVER holds and nothing proves what a BROWSER is handed. A UI upload that
# silently served the previous build looks exactly like a healthy release: new
# API, old client, every user on yesterday.
#
# build-web.sh compiles the identity in with --dart-define=BUILD_ID, so the SHA
# is a string inside the bundle. Do NOT reach for /version.json instead: it
# reports pubspec's 1.0.0 build 1, which has never changed and never will.
if [ "$CODE_ONLY" != "--code" ]; then
  # To a file, not a pipe. `grep -q` exits on the first match and curl then dies
  # writing into a closed pipe, printing "Failure writing output to destination"
  # on a check that PASSED — an alarming line under a tick.
  BUNDLE=$(mktemp)
  curl -sS -o "$BUNDLE" https://app.ezbizi.com/main.dart.js || true
  if grep -q "$(git rev-parse --short "$GATED_SHA")" "$BUNDLE"; then
    echo "   ok   main.dart.js carries $(git rev-parse --short "$GATED_SHA")"
  else
    echo "   ✗    main.dart.js does NOT carry $(git rev-parse --short "$GATED_SHA") — browsers may still be served the previous build" >&2
  fi
  rm -f "$BUNDLE"
fi

# The marketing site is not published from here any more. Since 2026-11-02
# www.ezbizi.com is WordPress on the websites box, and www/ in this repo no
# longer goes live. Comparing the live pages with www/ printed a red line on
# every cut. Edit the WordPress pages instead (see docs/WEBSITE-CLAIMS.md).
# /download/* is still served from /var/www/www, and release.sh writes it.

echo "✓ done — https://app.ezbizi.com"
