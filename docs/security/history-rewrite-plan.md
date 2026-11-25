# Purging personal data from git history (plan, NOT run)

Written 2026-11-25 with the scrub on `session/repo-hygiene`. That commit cleans
the **current tree** only. Every earlier commit (10,496 at the time) still holds
the same real phone numbers, personal emails, customer names and street
addresses, and anyone who clones a public repo gets all of them. Making the
repository public is safe only after this rewrite, or as a fresh repository
with no history (option B below).

Nothing here has been run.

## What gets removed

The same values the scrub commit replaced, everywhere in history:

| Kind | How it is matched |
|---|---|
| US phone numbers of customers and staff | `regex:` line per number, every format (`+1…`, `(305) 724-…`, dots, bare digits), mapped to the same fictional `AAA-555-01NN` the tree now uses |
| Personal emails (gmail/yahoo/hotmail/outlook/…) of customers and staff | literal `old==>new` |
| Real customer names next to those numbers | literal `First Last==>First Fiction` |
| Real street names from customer addresses | literal `NNNN Street==>NNNN Sample…` |
| Old Twilio parent Account SID | literal, to `AC` + 32 `x` |

The values come from the scrub commit itself, so this file holds none of them:

```bash
# outside the repo; the output CONTAINS THE REAL VALUES - never commit it
python scripts/security/scrub-expressions.py <SCRUB_SHA> > ../pii-expressions.txt
```

Read the generated file before using it. The literal lines come from a
word-level diff; delete any line whose left side is a common word that would
also hit unrelated text. Add by hand anything found since the scrub
(`docs/security/repo-pii-inventory-2026-11-25.md` lists what was deliberately
left: business lines, placeholder constants, staff names).

### Paths to strip from all history

Files that never belong in a public repo, whatever their content today:

```
--path-glob '*.env' --path-glob '.env.*' --path-glob '*.jks' --path-glob '*.keystore'
--path-glob '*.p8' --path-glob '*.p12' --path-glob '*.pem' --path-glob 'key.properties'
--path-glob 'docs/**/*.xlsx' --path-glob 'docs/**/*.xls'
```

plus any CSV under `docs/` other than `docs/agents/task-labels-canonical-2026-11-19.csv`
(list them first: `git log --all --name-only --format= -- 'docs/*.csv' | sort -u`).
Check `git log --all --diff-filter=A --name-only --format= | sort -u` for anything
else (dumps, exports, screenshots of customer screens) before the run, and add
`--path <file>` for each. `.env.example` stays.

## Impact (read before deciding)

- **Every SHA changes** from the first commit that touched a scrubbed value,
  which in practice is almost all of them. Commit messages that cite a SHA
  (`b459ecb75`, release notes, `docs/` cross-references, memory files) point
  at commits that no longer exist under that name. filter-repo writes
  `.git/filter-repo/commit-map` (old -> new); keep it with the backup.
- **Every session branch and worktree is invalidated.** All `session/<role>`
  branches, the `.claude/worktrees/*` checkouts and any stash are based on old
  SHAs. Each must be rebased onto the new main (`git rebase --onto
  new-main old-main-sha session/x`) or recreated from new main and its unmerged
  commits cherry-picked. A branch pushed after the rewrite from an old base
  re-introduces the whole old history; this is the main failure mode.
- **The release system checks ancestry.** `scripts/release.sh` / `deploy.sh`
  require the deployed SHA to be an ancestor of main. After the rewrite the
  SHA in `/var/www/ezbizi-current` (and the iOS/Android/Windows build numbers
  stamped with it) is no longer reachable: the next cut fails that check
  unless the desk maps the deployed SHA through `commit-map` or does one
  forced first release from the new main.
- **Release directories on the box** are named after SHAs. They keep working
  (they are files), but rollback-by-SHA tooling and "which commit is live"
  readings must use the commit map from then on.
- **GitHub keeps old objects.** A force-push replaces branches, but
  pull-request refs (`refs/pull/*`), cached commit views, forks and any
  clone made before keep the old data. After the force-push, open a GitHub
  Support request ("remove sensitive data / purge cached views and PR refs",
  per GitHub's "Removing sensitive data from a repository" guide) with the
  list of affected old SHAs. Until Support confirms, keep the repo private.
- **Every clone must be re-cloned** (Jose's machines, the Mac relay, the app
  box if it has a checkout, CI caches). A `git pull` into an old clone merges
  the old history back.
- **The Twilio SID and any secret ever committed** must also be treated as
  exposed regardless of the rewrite: rotate secrets, never rely on the purge.

## Option B (simpler): publish a fresh repository

Create a new public repo from the scrubbed tree with one squashed commit and
keep `EzBizi/ezbizi-app` private as the working repo, or switch work to the
new one. No session is disrupted until the switch, no SHA mapping, no GitHub
Support step. Costs: public history starts at that commit. Recommended unless
the public history itself matters.

## Runbook (option A, coordinated)

1. **Decide and announce a freeze window.** Through `docs/SESSIONS.md`, every
   role merges or pushes its branch and stops committing. The deploy desk
   cuts a last release from old main and records its SHA.
2. **Back up.** `git clone --mirror git@github.com:EzBizi/ezbizi-app.git
   ezbizi-app-pre-rewrite.git` and keep it offline (it holds the personal data;
   store it like a credential, delete when no longer needed).
3. **Fresh mirror clone to rewrite** (filter-repo refuses a non-fresh clone):
   ```bash
   git clone --mirror git@github.com:EzBizi/ezbizi-app.git rewrite.git
   cd rewrite.git
   python /path/to/scrub-expressions.py <SCRUB_SHA> > ../pii-expressions.txt   # run inside a normal clone
   pip install git-filter-repo
   git filter-repo --replace-text ../pii-expressions.txt \
       --replace-message ../pii-expressions.txt \
       --invert-paths --path-glob '*.jks' --path-glob '*.keystore' \
       --path-glob '*.p8' --path-glob '*.p12' --path-glob '*.pem' \
       --path-glob 'key.properties' --path-glob 'docs/**/*.xlsx' --path-glob 'docs/**/*.xls'
   ```
   (`--invert-paths` with those globs removes those files from every commit;
   `.env*` were never tracked except `.env.example`, check before adding them.)
4. **Verify before pushing.**
   - `git log --all -p | grep -c` for a sample of the old values (taken from
     the expressions file): must be 0.
   - Run gitleaks over the full new history:
     `gitleaks git --config .gitleaks.toml --redact .` must report no
     personal-data findings.
   - Check out new main in a scratch worktree, run the backend gate and
     `flutter test`; the tree must equal the scrubbed tree byte for byte
     (`git diff <old-main> <new-main> --stat` in a clone holding both: empty).
5. **Force-push** (the one deliberate force-push):
   `git push --force --mirror origin` from `rewrite.git`. Turn branch
   protection off for the push and back on immediately after.
6. **GitHub Support** request to purge cached views and `refs/pull/*`
   (list of old SHAs from `filter-repo/commit-map`). Delete stale forks.
7. **Re-clone everywhere**; recreate worktrees; each role rebases or recreates
   its branch from new main (old SHA -> new SHA via `commit-map`).
8. **Deploy desk**: map the live SHA through `commit-map`, update the
   ancestry check's reference, cut the first release from new main, update
   `docs/` and release notes that cite old SHAs if they are used as links.
9. **Install the guard in every clone**: `bash scripts/install-hooks.sh`
   (pre-commit) and confirm the `secret-scan` workflow is green on main.
10. Only then switch the repository to public.
