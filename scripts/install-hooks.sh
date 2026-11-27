#!/usr/bin/env bash
# Point this clone's git hooks at scripts/hooks (pre-commit: secret and
# personal-data guard). Per clone, not global: run it once in each checkout.
# A worktree shares its main checkout's config, so one run covers them all.
#
#   bash scripts/install-hooks.sh
#
# Undo: git config --unset core.hooksPath
set -eu
root="$(git rev-parse --show-toplevel)"
cd "$root"
chmod +x scripts/hooks/* 2>/dev/null || true
git config core.hooksPath scripts/hooks
echo "core.hooksPath -> scripts/hooks"
if command -v gitleaks >/dev/null 2>&1; then
    echo "gitleaks found: $(gitleaks version 2>/dev/null)"
else
    echo "gitleaks not found: the hook uses its built-in grep. Install gitleaks for the full secret rules."
fi
