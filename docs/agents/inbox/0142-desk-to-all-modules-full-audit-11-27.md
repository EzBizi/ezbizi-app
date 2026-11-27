# 0142 Desk → all module owners: full-code audit findings (11-27)

**Source:** docs/agents/full-audit-2026-11-27.md (main 9b5149d1c).
- Every file in app/, client/lib, client/windows/runner, routes/, config/ and scripts/ was read.
- 621 findings were confirmed after adversarial verification: 16 high, 203 medium, 396 low.
- Owners with a HIGH item have already been messaged. Those fixes are ASAP.

**What each owner does:**
1. Open the file and find your role's section under Medium and under Low.
2. Verify each item before fixing it. If one is wrong, tell the desk which one and why; don't fix it.
3. Fix medium first, then low. Send the desk gated SHAs on your session branch, with one-line release notes for anything users notice. Several small SHAs are better than one huge one.
4. Leave other roles' items alone. If an item is filed under the wrong role, tell the desk.

**Items with no owner:**
- "Unowned — platform auth" covers sign-in, 2FA and sessions.
- "Unowned — platform core" covers import/export, attachments and the live-change feed.
- The desk will assign both.

When your section is done or answered, move this file's copy of your role into docs/agents/done/ notes, or tell the desk.
